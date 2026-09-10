<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/ProcessRunner.php';
require_once __DIR__ . '/src/BackupStorage.php';
require_once __DIR__ . '/src/SlackNotifier.php';
require_once __DIR__ . '/src/Scheduler.php';

// Including this file for tests or another CLI does not start a backup.
if (PHP_SAPI !== 'cli' || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

date_default_timezone_set('Asia/Seoul');
$rootPath = __DIR__;
$check = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        echo "Usage: php MySQLDump.php [--config-dir=PATH] [--check]\n";
        echo "--check validates configuration without connecting to MySQL or Slack.\n";
        exit(0);
    }
    if ($argument === '--check') {
        $check = true;
    } elseif (str_starts_with($argument, '--config-dir=') && strlen($argument) > 13) {
        $rootPath = substr($argument, 13);
    } else {
        fwrite(STDERR, "Unknown or invalid argument. Use --help.\n");
        exit(2);
    }
}

try {
    $config = MySQLDumpScheduler\Config::load($rootPath);
    if ($check) {
        echo sprintf("Configuration OK: %d database(s), restore %s.\n", count($config->jobs),
            $config->settings['restore-enabled'] ? 'enabled' : 'disabled');
        exit(0);
    }
    exit((new MySQLDumpScheduler\Scheduler($config))->run());
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
