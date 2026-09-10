<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/MySQLDump.php';
date_default_timezone_set('Asia/Seoul');

function assertSameValue(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $message = ''): void
{
    if (!$condition) {
        throw new RuntimeException($message ?: 'Assertion failed.');
    }
}

function assertThrows(callable $callback, string $contains = ''): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        assertTrue($contains === '' || str_contains($error->getMessage(), $contains), 'Unexpected exception: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected an exception.');
}

function tempDir(): string
{
    $path = sys_get_temp_dir() . '/mysqldump-scheduler-test-' . bin2hex(random_bytes(8));
    if (!mkdir($path, 0700)) {
        throw new RuntimeException('Cannot create test directory.');
    }
    return (string) realpath($path);
}

function removeTree(string $path): void
{
    // Tests may delete only their own generated temporary trees.
    $normalized = str_replace('\\', '/', $path);
    $temp = rtrim(str_replace('\\', '/', (string) realpath(sys_get_temp_dir())), '/') . '/mysqldump-scheduler-test-';
    if (!str_starts_with(strtolower($normalized), strtolower($temp))
        || str_contains($normalized, '/../') || str_ends_with($normalized, '/..')) {
        throw new RuntimeException('Refusing to delete a non-test path.');
    }
    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Cannot remove test file.');
        }
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
        removeTree($path . '/' . $entry);
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Cannot remove test directory.');
    }
}
