<?php

declare(strict_types=1);

use MySQLDumpScheduler\BackupStorage;
use MySQLDumpScheduler\Config;
use MySQLDumpScheduler\ProcessRunner;
use MySQLDumpScheduler\Scheduler;

class BackupFixtureRunner extends ProcessRunner
{
    public int $dumpExit = 0;
    public int $restoreExit = 0;
    public bool $sameServer = false;
    public bool $emptyDump = false;
    public string $diagnostic = '';
    public array $calls = [];

    public function run(array $arguments, array $connection, ?string $inputFile = null, int $timeoutSeconds = 3600): array
    {
        $this->calls[] = ['arguments' => $arguments, 'inputFile' => $inputFile];
        if (in_array('--execute=SELECT @@server_uuid', $arguments, true)) {
            $uuid = ($this->sameServer || $connection['host'] === '127.0.0.1')
                ? '11111111-1111-1111-1111-111111111111' : '22222222-2222-2222-2222-222222222222';
            return ['exitCode' => 0, 'stdout' => $uuid . "\n", 'stderr' => ''];
        }
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--result-file=')) {
                file_put_contents(substr($argument, strlen('--result-file=')), $this->emptyDump ? '' : "-- fixture SQL; no real database is contacted\nSELECT 1;\n");
                return ['exitCode' => $this->dumpExit, 'stdout' => '', 'stderr' => $this->diagnostic];
            }
        }
        assertTrue($inputFile !== null && str_ends_with($inputFile, '.sql'), 'Only published SQL may be restored.');
        assertTrue(is_file($inputFile) && filesize($inputFile) > 0, 'Restore must read the archived file.');
        return ['exitCode' => $this->restoreExit, 'stdout' => '', 'stderr' => $this->diagnostic];
    }
}

function schedulerConfig(string $root, bool $restore = false): Config
{
    $settings = "slack-url = \"\"\nrestore-enabled = " . ($restore ? 'true' : 'false') . "\n";
    if ($restore) {
        $settings .= "localhost-host = 127.0.0.1\nlocalhost-username = restore_user\nlocalhost-password = restore-secret\nrestore-databases[] = app\n";
    }
    file_put_contents($root . '/settings.ini', $settings);
    file_put_contents($root . '/database.ini', "[primary]\nhost = source.example.test\nusername = backup_user\npassword = backup-secret\ndatabase[] = app\nignore-table[0] = cache,queue\n");
    return Config::load($root);
}

function onlyManifest(string $root): array
{
    $files = glob($root . '/sql_storage/*/*/manifest.json');
    assertSameValue(1, count($files));
    return json_decode(file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
}

function backupCalls(BackupFixtureRunner $runner): array
{
    return array_values(array_filter($runner->calls, static fn(array $call): bool => !in_array('--execute=SELECT @@server_uuid', $call['arguments'], true)));
}

return [
    'scheduler archives unique complete backups on same-day reruns' => function (): void {
        $root = tempDir();
        try {
            $config = schedulerConfig($root);
            $runner = new BackupFixtureRunner();
            assertSameValue(0, (new Scheduler($config, $runner))->run());
            assertSameValue(0, (new Scheduler($config, $runner))->run());
            $files = glob($root . '/sql_storage/*/*/*.sql');
            assertSameValue(2, count($files));
            assertTrue(dirname($files[0]) !== dirname($files[1]));
            assertSameValue([], glob($root . '/sql_storage/*/*/*.partial'));
            foreach (glob($root . '/sql_storage/*/*/manifest.json') as $path) {
                $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                assertSameValue('success', $manifest['status']);
                assertSameValue('disabled', $manifest['jobs'][0]['restore']);
            }
            $argv = $runner->calls[0]['arguments'];
            foreach (['--routines', '--events', '--triggers', '--single-transaction', '--ignore-table=app.cache', '--ignore-table=app.queue'] as $flag) {
                assertTrue(in_array($flag, $argv, true), 'Missing backup scope: ' . $flag);
            }
        } finally {
            removeTree($root);
        }
    },
    'scheduler preserves earlier backups and never restores a failed partial dump' => function (): void {
        $root = tempDir();
        try {
            $config = schedulerConfig($root, true);
            $runner = new BackupFixtureRunner();
            assertSameValue(0, (new Scheduler($config, $runner))->run());
            $archive = glob($root . '/sql_storage/*/*/*.sql')[0];
            $before = hash_file('sha256', $archive);
            $runner->calls = [];
            $runner->dumpExit = 2;
            $runner->diagnostic = 'dump failed with backup-secret';
            assertSameValue(1, (new Scheduler($config, $runner))->run());
            assertSameValue($before, hash_file('sha256', $archive));
            assertSameValue(1, count(glob($root . '/sql_storage/*/*/*.sql')));
            assertSameValue(1, count(glob($root . '/sql_storage/*/*/*.partial')));
            assertSameValue(1, count(backupCalls($runner)));
            foreach (glob($root . '/sql_storage/*/*/manifest.json') as $manifest) {
                assertTrue(!str_contains(file_get_contents($manifest), 'backup-secret'));
            }
            assertTrue(!str_contains(file_get_contents($root . '/logs/scheduler.log'), 'backup-secret'));
        } finally {
            removeTree($root);
        }
    },
    'scheduler refuses an empty successful dump and skips restore' => function (): void {
        $root = tempDir();
        try {
            $runner = new BackupFixtureRunner();
            $runner->emptyDump = true;
            assertSameValue(1, (new Scheduler(schedulerConfig($root, true), $runner))->run());
            $job = onlyManifest($root)['jobs'][0];
            assertSameValue('success', $job['dump']);
            assertSameValue('failed', $job['archive']);
            assertSameValue('skipped', $job['restore']);
            assertSameValue([], glob($root . '/sql_storage/*/*/*.sql'));
            assertSameValue(1, count(backupCalls($runner)));
        } finally {
            removeTree($root);
        }
    },
    'scheduler treats archive errors as failure and preserves the partial' => function (): void {
        $root = tempDir();
        try {
            $storage = new class($root) extends BackupStorage {
                public function publish(string $filename): string { throw new RuntimeException('simulated archive failure'); }
            };
            $runner = new BackupFixtureRunner();
            assertSameValue(1, (new Scheduler(schedulerConfig($root, true), $runner, $storage))->run());
            assertSameValue('failed', onlyManifest($root)['jobs'][0]['archive']);
            assertSameValue(1, count(glob($root . '/sql_storage/*/*/*.partial')));
            assertSameValue(1, count(backupCalls($runner)));
        } finally {
            removeTree($root);
        }
    },
    'scheduler rejects equal server UUIDs before dumping or restoring' => function (): void {
        $root = tempDir();
        try {
            $runner = new BackupFixtureRunner();
            $runner->sameServer = true;
            assertSameValue(1, (new Scheduler(schedulerConfig($root, true), $runner))->run());
            assertSameValue([], backupCalls($runner));
            assertSameValue('failed', onlyManifest($root)['status']);
            assertTrue(str_contains(onlyManifest($root)['error'], 'same MySQL server'));
        } finally {
            removeTree($root);
        }
    },
    'scheduler keeps the archived dump when restoration fails without diagnostics' => function (): void {
        $root = tempDir();
        try {
            $runner = new BackupFixtureRunner();
            $runner->restoreExit = 17;
            assertSameValue(1, (new Scheduler(schedulerConfig($root, true), $runner))->run());
            $manifest = onlyManifest($root);
            assertSameValue('failed', $manifest['status']);
            assertSameValue('success', $manifest['jobs'][0]['archive']);
            assertSameValue('failed', $manifest['jobs'][0]['restore']);
            assertTrue(str_contains($manifest['jobs'][0]['error'], 'exit 17'));
            assertTrue(str_contains($manifest['jobs'][0]['error'], 'No diagnostic output'));
            assertSameValue(1, count(glob($root . '/sql_storage/*/*/*.sql')));
        } finally {
            removeTree($root);
        }
    },
    'scheduler continues backup after notification failure but returns failure' => function (): void {
        $root = tempDir();
        try {
            $notifications = 0;
            $notifier = static function () use (&$notifications): bool { $notifications++; return false; };
            assertSameValue(1, (new Scheduler(schedulerConfig($root), new BackupFixtureRunner(), null, $notifier))->run());
            assertSameValue(2, $notifications);
            assertSameValue('failed', onlyManifest($root)['notifications']);
            assertSameValue('success', onlyManifest($root)['jobs'][0]['archive']);
        } finally {
            removeTree($root);
        }
    },
    'scheduler reports retention failure in manifest and exit status' => function (): void {
        $root = tempDir();
        try {
            $storage = new class($root) extends BackupStorage {
                public function prune(int $retentionDays): void { throw new RuntimeException('simulated retention failure'); }
            };
            assertSameValue(1, (new Scheduler(schedulerConfig($root), new BackupFixtureRunner(), $storage))->run());
            assertSameValue('failed', onlyManifest($root)['retention']);
            assertSameValue('failed', onlyManifest($root)['status']);
        } finally {
            removeTree($root);
        }
    },
    'scheduler prevents concurrent runs with a persistent lock file' => function (): void {
        $root = tempDir();
        $lock = null;
        try {
            $config = schedulerConfig($root);
            $lock = fopen($root . '/.mysqldump.lock', 'c+');
            assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            $runner = new BackupFixtureRunner();
            assertSameValue(3, (new Scheduler($config, $runner))->run());
            assertSameValue([], $runner->calls);
            assertTrue(!is_dir($root . '/sql_storage'));
            flock($lock, LOCK_UN);
            fclose($lock);
            $lock = null;
            assertSameValue(0, (new Scheduler($config, $runner))->run());
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
            removeTree($root);
        }
    },
];
