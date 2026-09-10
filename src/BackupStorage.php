<?php

declare(strict_types=1);

namespace MySQLDumpScheduler;

use DateTimeImmutable;
use RuntimeException;

class BackupStorage
{
    private string $basePath;
    private ?string $runPath = null;

    public function __construct(private readonly string $rootPath)
    {
        $this->basePath = $rootPath . '/sql_storage';
    }

    public function begin(): string
    {
        self::ensureDirectory($this->basePath, $this->rootPath);
        $datePath = $this->basePath . '/' . date('Ymd');
        self::ensureDirectory($datePath, $this->basePath);
        $this->runPath = $datePath . '/' . date('His') . '-' . bin2hex(random_bytes(8));
        if (!mkdir($this->runPath, 0700)) {
            throw new RuntimeException('Cannot create a unique backup directory.');
        }
        return $this->runPath;
    }

    public function path(string $filename): string
    {
        if ($this->runPath === null || !preg_match('/^dump-[0-9]+-[A-Za-z0-9_$-]+\.sql$/D', $filename)) {
            throw new RuntimeException('Invalid backup filename or uninitialized storage.');
        }
        return $this->runPath . '/' . $filename;
    }

    public function publish(string $filename): string
    {
        $destination = $this->path($filename);
        $partial = $destination . '.partial';
        clearstatcache(true, $partial);
        if (is_link($partial) || !is_file($partial) || filesize($partial) === 0) {
            throw new RuntimeException('The dump is missing or empty; it was not archived.');
        }
        if (file_exists($destination) || is_link($destination) || !rename($partial, $destination)) {
            throw new RuntimeException('Cannot publish the dump; the partial file has been preserved.');
        }
        return $destination;
    }

    public function saveManifest(array $manifest): void
    {
        if ($this->runPath === null) {
            throw new RuntimeException('Backup storage is not initialized.');
        }
        $temporary = $this->runPath . '/manifest.json.tmp';
        $destination = $this->runPath . '/manifest.json';
        if (is_link($temporary) || is_link($destination)) {
            throw new RuntimeException('Refusing a linked backup manifest.');
        }
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !rename($temporary, $destination)) {
            throw new RuntimeException('Cannot persist the backup manifest.');
        }
    }

    /** Delete only complete, successful, recognized runs; preserve unknown contents. */
    public function prune(int $retentionDays): void
    {
        $cutoff = (new DateTimeImmutable('today'))->modify('-' . $retentionDays . ' days')->format('Ymd');
        foreach ($this->entries($this->basePath) as $date) {
            if (!preg_match('/^[0-9]{8}$/D', $date) || $date > $cutoff) {
                continue;
            }
            $parsed = DateTimeImmutable::createFromFormat('!Ymd', $date);
            if (!$parsed || $parsed->format('Ymd') !== $date) {
                continue;
            }
            $datePath = $this->basePath . '/' . $date;
            if (!$this->isDirectDirectory($datePath, $this->basePath)) {
                continue;
            }
            foreach ($this->entries($datePath) as $run) {
                if (!preg_match('/^[0-9]{6}-[a-f0-9]{16}$/D', $run)) {
                    continue;
                }
                $runPath = $datePath . '/' . $run;
                if (!$this->isDirectDirectory($runPath, $datePath) || $runPath === $this->runPath) {
                    continue;
                }
                $manifestPath = $runPath . '/manifest.json';
                if (is_link($manifestPath) || !is_file($manifestPath) || filesize($manifestPath) > 1048576) {
                    continue;
                }
                $content = file_get_contents($manifestPath);
                if ($content === false) {
                    throw new RuntimeException('Cannot read an archived manifest.');
                }
                $manifest = json_decode($content, true);
                if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 1
                    || ($manifest['status'] ?? null) !== 'success'
                    || ($manifest['runId'] ?? null) !== $run
                    || empty($manifest['completedAt']) || empty($manifest['jobs']) || !is_array($manifest['jobs'])) {
                    continue;
                }
                $expected = ['manifest.json'];
                $valid = true;
                foreach ($manifest['jobs'] as $job) {
                    $filename = $job['filename'] ?? '';
                    if (!is_string($filename) || !preg_match('/^dump-[0-9]+-[A-Za-z0-9_$-]+\.sql$/D', $filename)
                        || ($job['dump'] ?? '') !== 'success' || ($job['archive'] ?? '') !== 'success'
                        || !in_array($job['restore'] ?? '', ['success', 'disabled'], true)) {
                        $valid = false;
                        break;
                    }
                    $expected[] = $filename;
                }
                $actual = $this->entries($runPath);
                sort($expected);
                sort($actual);
                if (!$valid || $expected !== $actual) {
                    continue;
                }
                foreach ($expected as $filename) {
                    $file = $runPath . '/' . $filename;
                    if (is_link($file) || !is_file($file) || !self::samePath(dirname((string) realpath($file)), $runPath)) {
                        $valid = false;
                        break;
                    }
                }
                if (!$valid) {
                    continue;
                }
                // Keep the manifest until all SQL files have been removed.
                foreach (array_diff($expected, ['manifest.json']) as $filename) {
                    if (!unlink($runPath . '/' . $filename)) {
                        throw new RuntimeException('Cannot remove an expired backup file.');
                    }
                }
                if (!unlink($manifestPath) || !rmdir($runPath)) {
                    throw new RuntimeException('Cannot remove an expired backup directory.');
                }
            }
            if ($this->entries($datePath) === [] && !rmdir($datePath)) {
                throw new RuntimeException('Cannot remove an empty backup date directory.');
            }
        }
    }

    public static function ensureDirectory(string $path, string $parent): void
    {
        if (is_link($path) || (file_exists($path) && !is_dir($path))) {
            throw new RuntimeException('Storage path must be a real directory.');
        }
        if (!is_dir($path) && !mkdir($path, 0700)) {
            throw new RuntimeException('Cannot create a storage directory.');
        }
        if (!self::samePath(dirname((string) realpath($path)), $parent)) {
            throw new RuntimeException('Storage directory resolves outside its expected parent.');
        }
    }

    private function isDirectDirectory(string $path, string $parent): bool
    {
        return !is_link($path) && is_dir($path) && self::samePath(dirname((string) realpath($path)), $parent);
    }

    private static function samePath(string $left, string $right): bool
    {
        $left = str_replace('\\', '/', $left);
        $right = str_replace('\\', '/', (string) realpath($right));
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    private function entries(string $path): array
    {
        $entries = scandir($path);
        if ($entries === false) {
            throw new RuntimeException('Cannot enumerate a backup directory.');
        }
        return array_values(array_diff($entries, ['.', '..']));
    }
}
