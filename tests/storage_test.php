<?php

declare(strict_types=1);

use MySQLDumpScheduler\BackupStorage;

function oldBackup(string $root, string $run, string $status = 'success'): string
{
    $path = $root . '/sql_storage/20000101/' . $run;
    mkdir($path, 0700, true);
    file_put_contents($path . '/dump-001-source-app.sql', 'old SQL');
    file_put_contents($path . '/manifest.json', json_encode([
        'schema' => 1, 'runId' => $run, 'status' => $status, 'completedAt' => '2000-01-01T00:00:00+09:00',
        'jobs' => [['filename' => 'dump-001-source-app.sql', 'dump' => 'success', 'archive' => 'success', 'restore' => 'disabled']],
    ], JSON_THROW_ON_ERROR));
    return $path;
}

return [
    'retention deletes only recorded successful expired runs' => function (): void {
        $root = tempDir();
        try {
            $success = oldBackup($root, '010101-0000000000000001');
            $failed = oldBackup($root, '010101-0000000000000002', 'failed');
            $running = oldBackup($root, '010101-0000000000000003', 'running');
            $unknown = oldBackup($root, '010101-0000000000000004');
            file_put_contents($unknown . '/keep.txt', 'unknown file');
            $legacy = $root . '/sql_storage/20000101/dump-old.sql';
            file_put_contents($legacy, 'legacy SQL');
            $storage = new BackupStorage($root);
            $current = $storage->begin();
            $storage->prune(30);
            assertTrue(!is_dir($success));
            foreach ([$failed, $running, $unknown, $current] as $preserved) { assertTrue(is_dir($preserved)); }
            assertTrue(is_file($legacy));
        } finally {
            removeTree($root);
        }
    },
    'retention preserves malformed manifests and unrecognized directories' => function (): void {
        $root = tempDir();
        try {
            $broken = oldBackup($root, '010101-0000000000000001');
            file_put_contents($broken . '/manifest.json', '{');
            $traversal = oldBackup($root, '010101-0000000000000002');
            $manifest = json_decode(file_get_contents($traversal . '/manifest.json'), true);
            $manifest['jobs'][0]['filename'] = '../outside.sql';
            file_put_contents($traversal . '/manifest.json', json_encode($manifest));
            $storage = new BackupStorage($root);
            $storage->begin();
            $storage->prune(30);
            assertTrue(is_file($broken . '/dump-001-source-app.sql'));
            assertTrue(is_file($traversal . '/dump-001-source-app.sql'));
        } finally {
            removeTree($root);
        }
    },
    'storage refuses path traversal and archive replacement' => function (): void {
        $root = tempDir();
        try {
            $storage = new BackupStorage($root);
            $storage->begin();
            assertThrows(fn() => $storage->path('../outside.sql'), 'Invalid');
            $filename = 'dump-001-source-app.sql';
            $path = $storage->path($filename);
            file_put_contents($path, 'good backup');
            file_put_contents($path . '.partial', 'replacement');
            assertThrows(fn() => $storage->publish($filename), 'Cannot publish');
            assertSameValue('good backup', file_get_contents($path));
            assertSameValue('replacement', file_get_contents($path . '.partial'));
        } finally {
            removeTree($root);
        }
    },
    'storage refuses files masquerading as storage directories' => function (): void {
        $root = tempDir();
        try {
            file_put_contents($root . '/sql_storage', 'preserve this file');
            assertThrows(fn() => (new BackupStorage($root))->begin(), 'real directory');
            assertSameValue('preserve this file', file_get_contents($root . '/sql_storage'));
        } finally {
            removeTree($root);
        }
    },
];
