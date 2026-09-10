<?php

declare(strict_types=1);

namespace MySQLDumpScheduler;

use InvalidArgumentException;

final class Config
{
    private const SETTINGS_KEYS = [
        'mysql-binary', 'mysqldump-binary', 'restore-enabled', 'localhost-host',
        'localhost-port', 'localhost-username', 'localhost-password', 'restore-databases',
        'retention-days', 'process-timeout', 'slack-url', 'slack-timeout', 'slack-ca-bundle',
    ];

    private const DATABASE_KEYS = ['host', 'port', 'username', 'password', 'database', 'ignore-table'];
    private const SYSTEM_DATABASES = ['mysql', 'sys', 'information_schema', 'performance_schema'];

    private function __construct(
        public readonly string $rootPath,
        public readonly array $settings,
        public readonly array $jobs,
    ) {
    }

    public static function load(string $rootPath): self
    {
        $root = realpath($rootPath);
        if ($root === false || !is_dir($root)) {
            throw new InvalidArgumentException('The configuration root must be an existing directory.');
        }

        $input = self::readIni($root . DIRECTORY_SEPARATOR . 'settings.ini', 'settings.ini');
        self::checkKeys($input, self::SETTINGS_KEYS, 'settings.ini');
        $settings = self::settings($input, $root);
        $sources = self::readIni($root . DIRECTORY_SEPARATOR . 'database.ini', 'database.ini');
        $jobs = [];
        $seenSources = [];
        $seenJobs = [];
        $restoreNames = [];

        foreach ($sources as $source => $fields) {
            $source = (string) $source;
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $source) || !is_array($fields)) {
                throw new InvalidArgumentException('database.ini requires named [source-id] sections with valid source identifiers.');
            }
            $sourceKey = strtolower($source);
            if (isset($seenSources[$sourceKey])) {
                throw new InvalidArgumentException('database.ini contains duplicate source identifiers (case-insensitive).');
            }
            $seenSources[$sourceKey] = true;
            $context = 'database.ini [' . $source . ']';
            self::checkKeys($fields, self::DATABASE_KEYS, $context);
            $connection = [
                'host' => self::string($fields, 'host', $context),
                'port' => self::integer($fields, 'port', 3306, 1, 65535, $context),
                'username' => self::string($fields, 'username', $context),
                'password' => self::string($fields, 'password', $context, true),
            ];
            $databases = self::indexed($fields, 'database', $context, true);
            $ignore = array_key_exists('ignore-table', $fields)
                ? self::indexed($fields, 'ignore-table', $context, false)
                : [];
            if (array_diff_key($ignore, $databases) !== []) {
                throw new InvalidArgumentException($context . ': every ignore-table index must match a database index.');
            }

            foreach ($databases as $index => $database) {
                self::identifier($database, $context . ': database');
                // A structured key avoids collisions between identifiers containing separators.
                $jobKey = $sourceKey . "\0" . strtolower($database);
                if (isset($seenJobs[$jobKey])) {
                    throw new InvalidArgumentException($context . ': duplicate database jobs (case-insensitive) are not allowed.');
                }
                $seenJobs[$jobKey] = true;
                $tables = [];
                if (isset($ignore[$index]) && trim($ignore[$index]) !== '') {
                    foreach (explode(',', $ignore[$index]) as $table) {
                        $table = trim($table);
                        self::identifier($table, $context . ': ignore-table');
                        $tables[] = $table;
                    }
                    $tables = array_values(array_unique($tables));
                }

                if ($settings['restore-enabled']) {
                    $databaseKey = strtolower($database);
                    if (in_array($databaseKey, self::SYSTEM_DATABASES, true)) {
                        throw new InvalidArgumentException($context . ': restoring a MySQL system database is forbidden.');
                    }
                    if (isset($restoreNames[$databaseKey])) {
                        throw new InvalidArgumentException('Restore requires unique database names across all sources (case-insensitive).');
                    }
                    $restoreNames[$databaseKey] = true;
                    // Exact spelling is deliberate: MySQL database names may be case-sensitive.
                    if (!in_array($database, $settings['restore-databases'], true)) {
                        throw new InvalidArgumentException($context . ': every restored database must appear in restore-databases with the same spelling.');
                    }
                    if (self::isLoopback($connection['host']) && $connection['port'] === $settings['localhost-port']) {
                        throw new InvalidArgumentException($context . ': the source and restore target must not use the same loopback server and port.');
                    }
                }

                $jobs[] = [
                    'source' => $source,
                    'database' => $database,
                    'connection' => $connection,
                    'ignoreTables' => $tables,
                ];
            }
        }

        return new self($root, $settings, $jobs);
    }

    private static function readIni(string $path, string $label): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            throw new InvalidArgumentException($label . ' is missing, unreadable, or empty.');
        }
        // Do not expose parser warnings: they can contain passwords or webhook URLs.
        $parsed = @parse_ini_string($contents, true, INI_SCANNER_RAW);
        if ($parsed === false || $parsed === []) {
            throw new InvalidArgumentException($label . ' must contain valid, non-empty INI configuration.');
        }
        self::rejectDuplicateEntries($contents, $label);
        return $parsed;
    }

    private static function rejectDuplicateEntries(string $contents, string $label): void
    {
        $sections = [];
        $entries = [];
        $section = '';
        // RAW INI values are single-line; parse_ini_string has already rejected
        // malformed/multiline strings. Embedded quotes are literal value bytes.
        foreach (preg_split('/\r\n|\r|\n/', $contents) as $line) {
            if (preg_match('/^\s*\[([^\]]*)\]/', $line, $match)) {
                $section = $match[1];
                if (isset($sections[$section])) {
                    throw new InvalidArgumentException($label . ' contains a duplicate section.');
                }
                $sections[$section] = true;
            } elseif (preg_match('/^\s*([^\s=\[\];#]+)(?:\[([^\]]*)\])?\s*=/', $line, $match)) {
                $key = $section . "\0" . $match[1];
                $array = array_key_exists(2, $match);
                $kind = $array ? 'array' : 'scalar';
                if (isset($entries[$key]['kind']) && $entries[$key]['kind'] !== $kind) {
                    throw new InvalidArgumentException($label . ' mixes scalar and array values for one setting.');
                }
                $entries[$key]['kind'] = $kind;
                $index = $array ? ($match[2] !== '' ? $match[2] : ($entries[$key]['next'] ?? 0)) : '';
                if (isset($entries[$key]['indexes'][$index])) {
                    throw new InvalidArgumentException($label . ' contains a duplicate setting or array index.');
                }
                $entries[$key]['indexes'][$index] = true;
                if ($array && preg_match('/^[0-9]+$/D', (string) $index)) {
                    $entries[$key]['next'] = max($entries[$key]['next'] ?? 0, (int) $index + 1);
                }
            }
        }
    }

    private static function settings(array $input, string $root): array
    {
        $context = 'settings.ini';
        $restore = self::boolean($input, 'restore-enabled', false, $context);
        $host = self::string($input, 'localhost-host', $context, false, '127.0.0.1');
        if ($restore && !self::isLoopback($host)) {
            throw new InvalidArgumentException('settings.ini: localhost-host must be localhost, 127.0.0.1, or ::1 when restore is enabled.');
        }
        $databases = array_key_exists('restore-databases', $input)
            ? self::indexed($input, 'restore-databases', $context, false, false)
            : [];
        if ($restore && $databases === []) {
            throw new InvalidArgumentException('settings.ini: restore-databases must explicitly list every database when restore is enabled.');
        }
        $seen = [];
        foreach ($databases as $database) {
            self::identifier($database, 'settings.ini: restore-databases');
            if (isset($seen[strtolower($database)])) {
                throw new InvalidArgumentException('settings.ini: restore-databases contains duplicate names (case-insensitive).');
            }
            $seen[strtolower($database)] = true;
        }

        $url = self::string($input, 'slack-url', $context, true, '');
        if ($url !== '') {
            $parts = parse_url($url);
            if ($parts === false
                || ($parts['scheme'] ?? '') !== 'https'
                || !in_array($parts['host'] ?? '', ['hooks.slack.com', 'hooks.slack-gov.com'], true)
                || !preg_match('~^/services/[A-Za-z0-9_-]+/[A-Za-z0-9_-]+/[A-Za-z0-9_-]+$~D', $parts['path'] ?? '')
                || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])
                || (isset($parts['port']) && $parts['port'] !== 443)
            ) {
                throw new InvalidArgumentException('settings.ini: slack-url must be an HTTPS Slack incoming webhook URL without credentials, a query, or a fragment.');
            }
        }

        $caBundle = self::string($input, 'slack-ca-bundle', $context, true, '');
        if ($caBundle !== '') {
            $resolved = realpath(self::resolvePath($root, $caBundle));
            if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
                throw new InvalidArgumentException('settings.ini: slack-ca-bundle must point to an existing readable file.');
            }
            $caBundle = $resolved;
        }

        $mysql = self::string($input, 'mysql-binary', $context, false, PHP_OS_FAMILY === 'Windows' ? 'mysql.exe' : 'mysql');
        $mysqldump = self::string($input, 'mysqldump-binary', $context, false, PHP_OS_FAMILY === 'Windows' ? 'mysqldump.exe' : 'mysqldump');
        return [
            'mysql-binary' => self::resolveExecutable($root, $mysql),
            'mysqldump-binary' => self::resolveExecutable($root, $mysqldump),
            'restore-enabled' => $restore,
            'localhost-host' => $host,
            'localhost-port' => self::integer($input, 'localhost-port', 3306, 1, 65535, $context),
            'localhost-username' => $restore
                ? self::string($input, 'localhost-username', $context)
                : self::string($input, 'localhost-username', $context, true, ''),
            'localhost-password' => $restore
                ? self::string($input, 'localhost-password', $context, true)
                : self::string($input, 'localhost-password', $context, true, ''),
            'restore-databases' => array_values($databases),
            'retention-days' => self::integer($input, 'retention-days', 30, 1, 36500, $context),
            'process-timeout' => self::integer($input, 'process-timeout', 3600, 1, 86400, $context),
            'slack-url' => $url,
            'slack-timeout' => self::integer($input, 'slack-timeout', 10, 1, 60, $context),
            'slack-ca-bundle' => $caBundle,
        ];
    }

    private static function checkKeys(array $input, array $allowed, string $context): void
    {
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException($context . ': unknown settings found; allowed keys are ' . implode(', ', $allowed) . '.');
        }
    }

    private static function string(array $input, string $key, string $context, bool $allowEmpty = false, ?string $default = null): string
    {
        $value = array_key_exists($key, $input) ? $input[$key] : $default;
        if (!is_string($value) || (!$allowEmpty && trim($value) === '') || str_contains($value, "\0")) {
            throw new InvalidArgumentException($context . ': ' . $key . ' must be an explicitly provided ' . ($allowEmpty ? 'string (empty is allowed).' : 'non-empty string.'));
        }
        return $value;
    }

    private static function integer(array $input, string $key, int $default, int $minimum, int $maximum, string $context): int
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }
        $value = $input[$key];
        if (!is_string($value) || !preg_match('/^[0-9]+$/D', $value)
            || (float) $value < $minimum || (float) $value > $maximum
        ) {
            throw new InvalidArgumentException($context . ': ' . $key . ' must be an integer between ' . $minimum . ' and ' . $maximum . '.');
        }
        return (int) $value;
    }

    private static function boolean(array $input, string $key, bool $default, string $context): bool
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }
        if (!is_string($input[$key])) {
            throw new InvalidArgumentException($context . ': ' . $key . ' must be true or false.');
        }
        return match (strtolower($input[$key])) {
            'true', 'yes', 'on', '1' => true,
            'false', 'no', 'off', '0' => false,
            default => throw new InvalidArgumentException($context . ': ' . $key . ' must be true or false.'),
        };
    }

    private static function indexed(array $input, string $key, string $context, bool $required, bool $allowScalar = true): array
    {
        if (!array_key_exists($key, $input)) {
            if ($required) {
                throw new InvalidArgumentException($context . ': ' . $key . ' is required.');
            }
            return [];
        }
        $value = $input[$key];
        if (is_string($value) && $allowScalar) {
            return [$value];
        }
        if (!is_array($value) || $value === []) {
            throw new InvalidArgumentException($context . ': ' . $key . ' must be a non-empty indexed array' . ($allowScalar ? ' or a string.' : '.'));
        }
        foreach ($value as $index => $item) {
            if ((!is_int($index) || $index < 0) || !is_string($item) || str_contains($item, "\0")) {
                throw new InvalidArgumentException($context . ': ' . $key . ' must use non-negative integer indexes and string values.');
            }
        }
        return $value;
    }

    private static function identifier(string $value, string $context): void
    {
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_$-]{0,63}$/D', $value)) {
            throw new InvalidArgumentException($context . ' must use a 1-64 character ASCII identifier containing letters, digits, _, $, or - and cannot start with $ or -.');
        }
    }

    private static function isLoopback(string $host): bool
    {
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function resolveExecutable(string $root, string $path): string
    {
        return strpbrk($path, '/\\') === false ? $path : self::resolvePath($root, $path);
    }

    private static function resolvePath(string $root, string $path): string
    {
        if (preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $path)) {
            return $path;
        }
        return $root . DIRECTORY_SEPARATOR . $path;
    }
}
