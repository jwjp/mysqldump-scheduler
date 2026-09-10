<?php

declare(strict_types=1);

use MySQLDumpScheduler\ProcessRunner;

require_once __DIR__ . '/../src/ProcessRunner.php';

class FixtureProcessRunner extends ProcessRunner
{
    public array $clientArguments = [];
    public ?string $credentialsPath = null;
    public bool $failLaunch = false;
    public ?int $credentialMode = null;
    public ?int $directoryMode = null;

    protected function startProcess(array $arguments, array $descriptors, ?array $environment)
    {
        if ($arguments[0] === 'runner-fixture') {
            $this->clientArguments = $arguments;
            $this->credentialsPath = substr($arguments[1], strlen('--defaults-file='));
            $this->credentialMode = fileperms($this->credentialsPath) & 0777;
            $this->directoryMode = fileperms(dirname($this->credentialsPath)) & 0777;
            if ($this->failLaunch) {
                return false;
            }
            $arguments = [PHP_BINARY, __DIR__ . '/fixtures/runner_client.php', ...array_slice($arguments, 1)];
        }
        return parent::startProcess($arguments, $descriptors, $environment);
    }
}

function runnerConnection(string $password = 'fixture-secret'): array
{
    return ['host' => '127.0.0.1', 'port' => 3307, 'username' => 'backup user', 'password' => $password];
}

return [
    'runner preserves literal argv, byte input, and protected credentials' => function (): void {
        $directory = tempDir();
        $runner = new FixtureProcessRunner();
        $password = " leading \\\"#;\tline\r\nend ";
        $connection = runnerConnection($password);
        $input = $directory . DIRECTORY_SEPARATOR . 'input with spaces.sql';
        $bytes = "SELECT '한글';\r\n\x00\x1a\xff\n";
        file_put_contents($input, $bytes);
        $inheritedPassword = getenv('MYSQL_PWD');
        $inheritedLogin = getenv('MYSQL_TEST_LOGIN_FILE');
        putenv('MYSQL_PWD=unrelated-inherited-password');
        putenv('MYSQL_TEST_LOGIN_FILE=unrelated-login-file.cnf');
        try {
            $literalArguments = ['--file=C:\\path with spaces\\tail\\', 'a"b', '& | > < ^ %PATH% !value!', '', '한글 데이터'];
            $result = $runner->run(['runner-fixture', 'inspect', ...$literalArguments], $connection, $input, 10);
            assertSameValue(0, $result['exitCode']);
            assertSameValue('client diagnostic', $result['stderr']);
            $data = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
            assertSameValue($literalArguments, array_slice($data['arguments'], 3));
            assertSameValue('--protocol=TCP', $data['arguments'][1]);
            assertSameValue(base64_encode($bytes), $data['stdinBase64']);
            assertSameValue(false, $data['passwordEnvironment']);
            assertSameValue(false, $data['loginFileExists']);
            assertSameValue(dirname($runner->credentialsPath), dirname($data['loginFile']));
            // Golden option-file text includes backslash, quote, #, ;, tab, CR/LF and edge spaces.
            $expected = <<<'CNF'
[client]
host="127.0.0.1"
port=3307
user="backup user"
password=" leading \\\"#;\tline\r\nend "
CNF;
            $expected .= "\n";
            assertSameValue(hash('sha256', $expected), $data['optionsHash']);
            assertTrue(!str_contains(implode(' ', $runner->clientArguments), $password), 'The password must not enter argv.');
            assertTrue(!file_exists($runner->credentialsPath), 'Credentials must be removed.');
            assertTrue(!is_dir(dirname($runner->credentialsPath)), 'The private directory must be removed.');
            if (PHP_OS_FAMILY !== 'Windows') {
                assertSameValue(0600, $runner->credentialMode);
                assertSameValue(0700, $runner->directoryMode);
            }
        } finally {
            putenv($inheritedPassword === false ? 'MYSQL_PWD' : 'MYSQL_PWD=' . $inheritedPassword);
            putenv($inheritedLogin === false ? 'MYSQL_TEST_LOGIN_FILE' : 'MYSQL_TEST_LOGIN_FILE=' . $inheritedLogin);
            removeTree($directory);
        }
    },
    'runner consumes concurrent stdout and stderr without pipe deadlock' => function (): void {
        $runner = new FixtureProcessRunner();
        $result = $runner->run(['runner-fixture', 'pressure'], runnerConnection(), null, 10);
        assertSameValue(0, $result['exitCode']);
        assertTrue(strlen($result['stdout']) < 66000, 'stdout capture must be bounded.');
        assertTrue(strlen($result['stderr']) < 66000, 'stderr capture must be bounded.');
        assertTrue(str_contains($result['stdout'], '[output truncated]'));
        assertTrue(str_contains($result['stderr'], '[output truncated]'));
        assertTrue(!is_dir(dirname($runner->credentialsPath)));
    },
    'runner times out and removes credentials' => function (): void {
        $runner = new FixtureProcessRunner();
        $started = microtime(true);
        $result = $runner->run(['runner-fixture', 'timeout'], runnerConnection(), null, 1);
        assertSameValue(124, $result['exitCode']);
        assertTrue(microtime(true) - $started < 8, 'The timeout must terminate the child promptly.');
        assertTrue(str_contains($result['stderr'], 'timeout'));
        assertTrue(!file_exists($runner->credentialsPath));
        assertTrue(!is_dir(dirname($runner->credentialsPath)));
    },
    'runner preserves client failures' => function (): void {
        $runner = new FixtureProcessRunner();
        $result = $runner->run(['runner-fixture', 'failure'], runnerConnection(), null, 5);
        assertSameValue(23, $result['exitCode']);
        assertSameValue('simulated client failure', $result['stderr']);
    },
    'runner redacts passwords before truncating diagnostics' => function (): void {
        $runner = new FixtureProcessRunner();
        $password = 'never-print-this-secret';
        $result = $runner->run(['runner-fixture', 'redact'], runnerConnection($password), null, 5);
        assertSameValue(0, $result['exitCode']);
        assertTrue(!str_contains($result['stdout'], 'never-'), 'A password crossing the output boundary must be redacted.');
        assertTrue(!str_contains($result['stderr'], $password));
        assertTrue(str_contains($result['stderr'], '[REDACTED]'));
    },
    'runner removes credentials when process launch fails' => function (): void {
        $runner = new FixtureProcessRunner();
        $runner->failLaunch = true;
        assertThrows(fn() => $runner->run(['runner-fixture'], runnerConnection()), 'Unable to start');
        assertTrue($runner->credentialsPath !== null);
        assertTrue(!file_exists($runner->credentialsPath));
        assertTrue(!is_dir(dirname($runner->credentialsPath)));
    },
    'runner rejects invalid invocation before launching' => function (): void {
        $runner = new FixtureProcessRunner();
        assertThrows(fn() => $runner->run([], runnerConnection()), 'executable');
        assertThrows(fn() => $runner->run(['runner-fixture', "bad\0argument"], runnerConnection()), 'null bytes');
        assertThrows(fn() => $runner->run(['runner-fixture'], runnerConnection(), null, 0), 'positive');
        assertThrows(fn() => $runner->run(['runner-fixture'], runnerConnection(), __DIR__ . '/missing-runner-input.sql'), 'not readable');
        assertSameValue([], $runner->clientArguments);
    },
];
