<?php

declare(strict_types=1);

function runCliFixture(array $arguments, string $cwd): array
{
    $stdout = tmpfile();
    $stderr = tmpfile();
    try {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/MySQLDump.php', ...$arguments],
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'rb'], 1 => $stdout, 2 => $stderr],
            $pipes, $cwd, null, ['bypass_shell' => true]);
        assertTrue(is_resource($process));
        $code = proc_close($process);
        rewind($stdout);
        rewind($stderr);
        return [$code, stream_get_contents($stdout), stream_get_contents($stderr)];
    } finally {
        fclose($stdout);
        fclose($stderr);
    }
}

return [
    'CLI check accepts space paths from another working directory without side effects' => function (): void {
        $root = tempDir();
        try {
            $configPath = $root . '/config with spaces';
            mkdir($configPath);
            copy(dirname(__DIR__) . '/database.ini.example', $configPath . '/database.ini');
            copy(dirname(__DIR__) . '/settings.ini.example', $configPath . '/settings.ini');
            [$code, $out, $err] = runCliFixture(['--config-dir=' . $configPath, '--check'], $root);
            assertSameValue(0, $code);
            assertSameValue('', $err);
            assertTrue(str_contains($out, 'restore disabled'));
            assertSameValue(['database.ini', 'settings.ini'], array_values(array_diff(scandir($configPath), ['.', '..'])));
        } finally {
            removeTree($root);
        }
    },
    'CLI configuration and usage failures return distinct nonzero exit codes' => function (): void {
        $root = tempDir();
        try {
            [$code, $out, $err] = runCliFixture(['--config-dir=' . $root, '--check'], $root);
            assertSameValue(1, $code);
            assertTrue(str_contains($err, 'settings.ini'));
            [$code] = runCliFixture(['--invalid'], $root);
            assertSameValue(2, $code);
            [$code, $out, $err] = runCliFixture(['--help'], $root);
            assertSameValue(0, $code);
            assertTrue(str_contains($out, '--check'));
            assertSameValue('', $err);
        } finally {
            removeTree($root);
        }
    },
];
