<?php

declare(strict_types=1);

namespace MySQLDumpScheduler;

use Closure;
use RuntimeException;
use Throwable;

class Scheduler
{
    private ProcessRunner $runner;
    private BackupStorage $storage;
    private Closure $notifier;
    private mixed $log = null;
    private array $manifest = [];

    public function __construct(private readonly Config $config, ?ProcessRunner $runner = null,
        ?BackupStorage $storage = null, ?callable $notifier = null)
    {
        $this->runner = $runner ?? new ProcessRunner();
        $this->storage = $storage ?? new BackupStorage($config->rootPath);
        $this->notifier = Closure::fromCallable($notifier ?? new SlackNotifier($config->settings));
    }

    public function run(): int
    {
        $lockPath = $this->config->rootPath . '/.mysqldump.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('Refusing a linked scheduler lock.');
        }
        $lock = fopen($lockPath, 'c+');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the scheduler lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            fwrite(STDERR, "Another backup is already running for this configuration directory.\n");
            return 3;
        }

        $started = microtime(true);
        try {
            $logDirectory = $this->config->rootPath . '/logs';
            BackupStorage::ensureDirectory($logDirectory, $this->config->rootPath);
            if (is_link($logDirectory . '/scheduler.log')) {
                throw new RuntimeException('Refusing a linked scheduler log.');
            }
            $this->log = fopen($logDirectory . '/scheduler.log', 'ab');
            if ($this->log === false) {
                throw new RuntimeException('Cannot open the scheduler log.');
            }
            $runPath = $this->storage->begin();
            $this->manifest = [
                'schema' => 1,
                'runId' => basename($runPath),
                'startedAt' => date(DATE_ATOM),
                'completedAt' => null,
                'status' => 'running',
                'jobs' => [],
            ];
            foreach ($this->config->jobs as $index => $job) {
                $this->manifest['jobs'][] = [
                    'source' => $job['source'],
                    'database' => $job['database'],
                    'filename' => sprintf('dump-%03d-%s-%s.sql', $index + 1, $job['source'], $job['database']),
                    'dump' => 'pending',
                    'archive' => 'pending',
                    'restore' => $this->config->settings['restore-enabled'] ? 'pending' : 'disabled',
                ];
            }
            $this->storage->saveManifest($this->manifest);
            $this->writeLog('info', 'Backup started: ' . $this->manifest['runId']);
            $notificationsOk = $this->notify('MySQL backup started: ' . $this->manifest['runId']);

            if ($this->config->settings['restore-enabled']) {
                $this->verifyRestoreServers();
            }
            foreach ($this->config->jobs as $index => $job) {
                $this->dump($index, $job);
                $this->storage->saveManifest($this->manifest);
            }
            if ($this->config->settings['restore-enabled']) {
                foreach ($this->config->jobs as $index => $job) {
                    if ($this->manifest['jobs'][$index]['archive'] !== 'success') {
                        $this->manifest['jobs'][$index]['restore'] = 'skipped';
                        continue;
                    }
                    $this->restore($index);
                    $this->storage->saveManifest($this->manifest);
                }
            }

            $success = $this->jobsSucceeded() && $notificationsOk;
            if ($success) {
                try {
                    $this->storage->prune($this->config->settings['retention-days']);
                    $this->manifest['retention'] = 'success';
                } catch (Throwable $error) {
                    $success = false;
                    $this->manifest['retention'] = 'failed';
                    $this->manifest['error'] = $this->redact($error->getMessage());
                    $this->writeLog('error', 'Retention failed: ' . $error->getMessage());
                }
            } else {
                $this->manifest['retention'] = 'skipped';
            }
            $elapsed = (int) (microtime(true) - $started);
            $message = $this->summary($success, $elapsed);
            $finalNotificationOk = $this->notify($message);
            $success = $success && $finalNotificationOk;
            $this->manifest['notifications'] = $notificationsOk && $finalNotificationOk ? 'success' : 'failed';
            $this->manifest['status'] = $success ? 'success' : 'failed';
            $this->manifest['completedAt'] = date(DATE_ATOM);
            $this->manifest['elapsedSeconds'] = $elapsed;
            $this->storage->saveManifest($this->manifest);
            $this->writeLog($success ? 'info' : 'error', $this->summary($success, $elapsed));
            return $success ? 0 : 1;
        } catch (Throwable $error) {
            $message = $this->redact($error->getMessage());
            if ($this->manifest !== []) {
                $this->manifest['status'] = 'failed';
                $this->manifest['completedAt'] = date(DATE_ATOM);
                $this->manifest['error'] = $message;
                try {
                    $this->storage->saveManifest($this->manifest);
                } catch (Throwable) {
                    fwrite(STDERR, "Cannot persist the failed run manifest.\n");
                }
            }
            fwrite(STDERR, 'Backup failed: ' . $message . PHP_EOL);
            try {
                $this->writeLog('error', $message);
                $this->notify('MySQL backup failed: ' . $message);
            } catch (Throwable) {
                // The process exit code and stderr remain available if logging also failed.
            }
            return 1;
        } finally {
            if (is_resource($this->log)) {
                fclose($this->log);
            }
            $this->log = null;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function dump(int $index, array $job): void
    {
        $result = &$this->manifest['jobs'][$index];
        $phase = 'dump';
        try {
            $arguments = [$this->config->settings['mysqldump-binary'],
                '--set-gtid-purged=OFF', '--databases', '--add-drop-database', '--single-transaction',
                '--quick', '--routines', '--events', '--triggers', '--hex-blob', '--no-tablespaces',
                '--column-statistics=0', '--result-file=' . $this->storage->path($result['filename']) . '.partial'];
            foreach ($job['ignoreTables'] as $table) {
                $arguments[] = '--ignore-table=' . $job['database'] . '.' . $table;
            }
            $arguments[] = $job['database'];
            $this->checkResult($this->runner->run($arguments, $job['connection'], null,
                $this->config->settings['process-timeout']));
            $result['dump'] = 'success';
            $phase = 'archive';
            $path = $this->storage->publish($result['filename']);
            $result['bytes'] = filesize($path);
            $result['archive'] = 'success';
            $this->writeLog('info', 'Archived ' . $result['filename']);
        } catch (Throwable $error) {
            $result[$phase] = 'failed';
            if ($phase === 'dump') {
                $result['archive'] = 'skipped';
            }
            $result['error'] = $this->redact($error->getMessage());
            $this->writeLog('error', $phase . ' failed for ' . $result['filename'] . ': ' . $error->getMessage());
        }
    }

    private function restore(int $index): void
    {
        $result = &$this->manifest['jobs'][$index];
        try {
            $this->checkResult($this->runner->run([$this->config->settings['mysql-binary'], '--binary-mode'],
                $this->localConnection(), $this->storage->path($result['filename']),
                $this->config->settings['process-timeout']));
            $result['restore'] = 'success';
            $this->writeLog('info', 'Restored ' . $result['filename']);
        } catch (Throwable $error) {
            $result['restore'] = 'failed';
            $result['error'] = $this->redact($error->getMessage());
            $this->writeLog('error', 'Restore failed for ' . $result['filename'] . ': ' . $error->getMessage());
        }
    }

    private function verifyRestoreServers(): void
    {
        $targetId = $this->serverId($this->localConnection());
        $checked = [];
        foreach ($this->config->jobs as $job) {
            if (isset($checked[$job['source']])) {
                continue;
            }
            if ($targetId === $this->serverId($job['connection'])) {
                throw new RuntimeException('Restore target is the same MySQL server as source ' . $job['source'] . '.');
            }
            $checked[$job['source']] = true;
        }
    }

    private function serverId(array $connection): string
    {
        $result = $this->runner->run([$this->config->settings['mysql-binary'], '--batch', '--skip-column-names',
            '--execute=SELECT @@server_uuid'], $connection, null, min(30, $this->config->settings['process-timeout']));
        $this->checkResult($result);
        $id = strtolower(trim($result['stdout']));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id)) {
            throw new RuntimeException('Cannot verify MySQL server identity; restore was refused.');
        }
        return $id;
    }

    private function localConnection(): array
    {
        $settings = $this->config->settings;
        return ['host' => $settings['localhost-host'], 'port' => $settings['localhost-port'],
            'username' => $settings['localhost-username'], 'password' => $settings['localhost-password']];
    }

    private function checkResult(array $result): void
    {
        if ($result['exitCode'] !== 0) {
            $diagnostic = trim($result['stderr'] . "\n" . $result['stdout']);
            if ($diagnostic === '') {
                $diagnostic = 'No diagnostic output was returned.';
            }
            throw new RuntimeException('MySQL command failed (exit ' . $result['exitCode'] . '): ' . $this->redact($diagnostic));
        }
    }

    private function jobsSucceeded(): bool
    {
        foreach ($this->manifest['jobs'] as $job) {
            if ($job['dump'] !== 'success' || $job['archive'] !== 'success'
                || !in_array($job['restore'], ['success', 'disabled'], true)) {
                return false;
            }
        }
        return true;
    }

    private function summary(bool $success, int $elapsed): string
    {
        $jobs = $this->manifest['jobs'];
        $count = static fn(string $phase): int => count(array_filter($jobs, static fn(array $job): bool => $job[$phase] === 'success'));
        $message = sprintf('MySQL backup %s [%s]: dump %d/%d, archive %d/%d, restore %s, elapsed %ds.',
            $success ? 'succeeded' : 'failed', $this->manifest['runId'], $count('dump'), count($jobs),
            $count('archive'), count($jobs), $this->config->settings['restore-enabled'] ? $count('restore') . '/' . count($jobs) : 'disabled', $elapsed);
        foreach ($jobs as $job) {
            if (isset($job['error'])) {
                $message .= "\n" . $job['filename'] . ': ' . $job['error'];
            }
        }
        if (isset($this->manifest['error'])) {
            $message .= "\n" . $this->manifest['error'];
        }
        return $message;
    }

    private function notify(string $message): bool
    {
        try {
            if (!(($this->notifier)($this->redact($message)))) {
                throw new RuntimeException('Slack notification returned failure.');
            }
            return true;
        } catch (Throwable $error) {
            $this->writeLog('error', $error->getMessage());
            return false;
        }
    }

    private function writeLog(string $level, string $message): void
    {
        $line = json_encode(['time' => date(DATE_ATOM), 'level' => $level, 'message' => $this->redact($message)],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (!is_resource($this->log) || fwrite($this->log, $line) !== strlen($line) || !fflush($this->log)) {
            throw new RuntimeException('Cannot write the scheduler log.');
        }
        echo $line;
    }

    private function redact(string $message): string
    {
        $secrets = [$this->config->settings['localhost-password'], $this->config->settings['slack-url']];
        foreach ($this->config->jobs as $job) {
            $secrets[] = $job['connection']['password'];
        }
        $secrets = array_values(array_unique(array_filter($secrets, static fn(string $secret): bool => $secret !== '')));
        usort($secrets, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
        return str_replace($secrets, '[REDACTED]', $message);
    }
}
