<?php

declare(strict_types=1);

namespace MySQLDumpScheduler;

use RuntimeException;

/** Runs MySQL clients without a shell or passwords in their command lines. */
class ProcessRunner
{
    private const OUTPUT_LIMIT = 65536;

    /** @return array{exitCode: int, stdout: string, stderr: string} */
    public function run(array $arguments, array $connection, ?string $inputFile = null, int $timeoutSeconds = 3600): array
    {
        $this->validate($arguments, $connection, $timeoutSeconds);
        if ($inputFile !== null && (!is_file($inputFile) || !is_readable($inputFile))) {
            throw new RuntimeException('The process input file is not readable.');
        }

        $directory = $this->privateDirectory();
        $credentials = $directory . DIRECTORY_SEPARATOR . 'client.cnf';
        try {
            $environment = getenv();
            // Even --no-defaults/--defaults-file reads login paths on MySQL 8.0.
            foreach (array_keys($environment) as $key) {
                if (in_array(strtoupper($key), ['MYSQL_PWD', 'MYSQL_TEST_LOGIN_FILE'], true)) {
                    unset($environment[$key]);
                }
            }
            $environment['MYSQL_TEST_LOGIN_FILE'] = $directory . DIRECTORY_SEPARATOR . 'absent-login.cnf';

            $contents = "[client]\n"
                . 'host=' . $this->quoteOption($connection['host']) . "\n"
                . 'port=' . $connection['port'] . "\n"
                . 'user=' . $this->quoteOption($connection['username']) . "\n"
                . 'password=' . $this->quoteOption($connection['password']) . "\n";
            $file = @fopen($credentials, 'x+b');
            if ($file === false) {
                throw new RuntimeException('Unable to create the private client option file.');
            }
            try {
                if (PHP_OS_FAMILY !== 'Windows' && !@chmod($credentials, 0600)) {
                    throw new RuntimeException('Unable to protect the client option file.');
                }
                if (fwrite($file, $contents) !== strlen($contents) || !fflush($file)) {
                    throw new RuntimeException('Unable to write the private client option file.');
                }
            } finally {
                fclose($file);
            }

            $command = [$arguments[0], '--defaults-file=' . $credentials, '--protocol=TCP'];
            array_push($command, ...array_slice($arguments, 1));
            return $this->execute($command, $inputFile, $timeoutSeconds, $environment, $directory, $connection['password']);
        } finally {
            $removed = !file_exists($credentials) || @unlink($credentials);
            if (!@rmdir($directory) || !$removed) {
                throw new RuntimeException('Unable to remove private process files: ' . $directory);
            }
        }
    }

    private function validate(array $arguments, array $connection, int $timeoutSeconds): void
    {
        if (!array_is_list($arguments) || $arguments === [] || !is_string($arguments[0]) || $arguments[0] === '') {
            throw new RuntimeException('A client executable is required.');
        }
        foreach ($arguments as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new RuntimeException('Process arguments must be strings without null bytes.');
            }
        }
        foreach (['host', 'username', 'password'] as $key) {
            if (!isset($connection[$key]) || !is_string($connection[$key]) || str_contains($connection[$key], "\0")) {
                throw new RuntimeException('The client connection contains an invalid field.');
            }
        }
        if (!isset($connection['port']) || !is_int($connection['port']) || $connection['port'] < 1 || $connection['port'] > 65535) {
            throw new RuntimeException('The client port must be between 1 and 65535.');
        }
        if ($timeoutSeconds < 1) {
            throw new RuntimeException('The process timeout must be positive.');
        }
    }

    private function quoteOption(string $value): string
    {
        return '"' . strtr($value, [
            '\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t', "\x08" => '\\b',
        ]) . '"';
    }

    private function privateDirectory(): string
    {
        $directory = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'mysqldump-credentials-' . bin2hex(random_bytes(16));
        if (!@mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create a private process directory.');
        }
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $systemRoot = getenv('SystemRoot');
                if (!is_string($systemRoot) || !preg_match('/^[A-Za-z]:[\\\\\/]/', $systemRoot)) {
                    throw new RuntimeException('The Windows system directory is unavailable.');
                }
                $systemDirectory = rtrim($systemRoot, '/\\') . '\\System32\\';
                foreach (['whoami.exe', 'icacls.exe'] as $utility) {
                    if (!is_file($systemDirectory . $utility)) {
                        throw new RuntimeException('A required Windows permission utility is unavailable.');
                    }
                }
                $identity = $this->execute([$systemDirectory . 'whoami.exe', '/user', '/fo', 'csv', '/nh'], null, 10);
                if ($identity['exitCode'] !== 0 || !preg_match('/"(S-1-[0-9-]+)"/', $identity['stdout'], $matches)) {
                    throw new RuntimeException('Unable to determine the Windows process identity.');
                }
                // Protect the empty parent BEFORE creating any files containing secrets.
                $permissions = $this->execute([
                    $systemDirectory . 'icacls.exe', $directory,
                    '/inheritance:r', '/grant:r', '*' . $matches[1] . ':(OI)(CI)F', '/Q',
                ], null, 10);
                if ($permissions['exitCode'] !== 0) {
                    throw new RuntimeException('Unable to restrict access to the private process directory.');
                }
            } elseif (!@chmod($directory, 0700)) {
                throw new RuntimeException('Unable to protect the private process directory.');
            }
            return $directory;
        } catch (\Throwable $error) {
            @rmdir($directory);
            throw $error;
        }
    }

    /** A narrow launch seam allows tests to substitute a local PHP client fixture. */
    protected function startProcess(array $arguments, array $descriptors, ?array $environment)
    {
        return @proc_open($arguments, $descriptors, $pipes, null, $environment, [
            'bypass_shell' => true,
            'suppress_errors' => true,
        ]);
    }

    /** @return array{exitCode: int, stdout: string, stderr: string} */
    private function execute(array $arguments, ?string $inputFile, int $timeoutSeconds, ?array $environment = null, ?string $directory = null, string $password = ''): array
    {
        $streams = [];
        $paths = [];
        $process = null;
        try {
            foreach ([1, 2] as $descriptor) {
                if ($directory === null) {
                    $streams[$descriptor] = @tmpfile();
                } else {
                    $paths[$descriptor] = $directory . DIRECTORY_SEPARATOR . 'capture-' . $descriptor;
                    $streams[$descriptor] = @fopen($paths[$descriptor], 'x+b');
                }
                if ($streams[$descriptor] === false) {
                    throw new RuntimeException('Unable to create a process output file.');
                }
            }
            $descriptors = [
                0 => ['file', $inputFile ?? (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), 'rb'],
                1 => $streams[1],
                2 => $streams[2],
            ];
            $process = $this->startProcess($arguments, $descriptors, $environment);
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start the client process. Check its executable and permissions.');
            }
            $deadline = hrtime(true) + $timeoutSeconds * 1_000_000_000;
            $timedOut = false;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (hrtime(true) >= $deadline) {
                    $timedOut = true;
                    $this->terminate($process);
                    break;
                }
                usleep(20000);
            } while (true);
            $closedCode = proc_close($process);
            $process = null;
            $exitCode = $timedOut ? 124 : ($status['exitcode'] >= 0 ? $status['exitcode'] : $closedCode);
            $stdout = $this->capture($streams[1], $password);
            $stderr = $this->capture($streams[2], $password);
            if ($timedOut) {
                $stderr .= "\nClient process exceeded its timeout of {$timeoutSeconds} seconds.";
            }
            return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
        } finally {
            if (is_resource($process)) {
                $this->terminate($process);
                proc_close($process);
            }
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            foreach ($paths as $path) {
                if (file_exists($path) && !@unlink($path)) {
                    throw new RuntimeException('Unable to remove a private process output file.');
                }
            }
        }
    }

    private function terminate($process): void
    {
        @proc_terminate($process);
        $deadline = hrtime(true) + 2_000_000_000;
        while (proc_get_status($process)['running'] && hrtime(true) < $deadline) {
            usleep(20000);
        }
        if (proc_get_status($process)['running']) {
            @proc_terminate($process, 9);
        }
    }

    private function capture($stream, string $password): string
    {
        rewind($stream);
        // Read past the limit so a password crossing the boundary is redacted in full.
        $escapedPassword = substr($this->quoteOption($password), 1, -1);
        $readLimit = self::OUTPUT_LIMIT + max(strlen($password), strlen($escapedPassword)) + 1;
        $output = stream_get_contents($stream, $readLimit);
        if ($output === false) {
            throw new RuntimeException('Unable to read process diagnostics.');
        }
        if ($password !== '') {
            $output = str_replace(array_unique([$escapedPassword, $password]), '[REDACTED]', $output);
        }
        $truncated = strlen($output) > self::OUTPUT_LIMIT || !feof($stream);
        return substr($output, 0, self::OUTPUT_LIMIT) . ($truncated ? "\n[output truncated]" : '');
    }
}
