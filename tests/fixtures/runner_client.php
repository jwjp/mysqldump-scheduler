<?php

declare(strict_types=1);

// A real PHP subprocess stands in for the MySQL client; it never uses a network.
$optionPath = substr($argv[1], strlen('--defaults-file='));
$options = file_get_contents($optionPath);
$mode = $argv[3] ?? 'inspect';

if ($mode === 'inspect') {
    echo json_encode([
        'arguments' => array_slice($argv, 1),
        'optionsHash' => hash('sha256', $options),
        'stdinBase64' => base64_encode(stream_get_contents(STDIN)),
        'loginFile' => getenv('MYSQL_TEST_LOGIN_FILE'),
        'loginFileExists' => file_exists(getenv('MYSQL_TEST_LOGIN_FILE')),
        'passwordEnvironment' => getenv('MYSQL_PWD'),
    ], JSON_THROW_ON_ERROR);
    fwrite(STDERR, 'client diagnostic');
} elseif ($mode === 'pressure') {
    for ($index = 0; $index < 512; ++$index) {
        fwrite(STDOUT, str_repeat('O', 8192));
        fwrite(STDERR, str_repeat('E', 8192));
    }
} elseif ($mode === 'timeout') {
    echo (string) getmypid();
    fflush(STDOUT);
    sleep(30);
    exit(0);
} elseif ($mode === 'failure') {
    fwrite(STDERR, 'simulated client failure');
    exit(23);
} elseif ($mode === 'redact') {
    $password = parse_ini_string($options, true, INI_SCANNER_RAW)['client']['password'];
    echo str_repeat('x', 65530) . $password . ' after boundary';
    fwrite(STDERR, 'authentication failed for ' . $password);
} else {
    fwrite(STDERR, 'unrecognized fixture mode');
    exit(2);
}
