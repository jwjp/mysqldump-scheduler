<?php

declare(strict_types=1);

use MySQLDumpScheduler\Config;

require_once dirname(__DIR__) . '/src/Config.php';

$database = <<<'INI'
[primary]
host = db.example.test
username = backup
password = "secret"
database[0] = inventory
database[2] = orders
ignore-table[2] = cache, queue
INI;

$withConfig = static function (string $settings, string $sources, callable $check, ?callable $prepare = null): void {
    $root = tempDir();
    try {
        file_put_contents($root . '/settings.ini', $settings);
        file_put_contents($root . '/database.ini', $sources);
        if ($prepare !== null) {
            $prepare($root);
        }
        $check($root);
    } finally {
        removeTree($root);
    }
};

$restoreSettings = <<<'INI'
restore-enabled = true
localhost-username = restore_user
localhost-password = ""
restore-databases[] = inventory
restore-databases[] = orders
INI;

return [
    'raw quoted credentials do not hide later duplicate settings' => static function () use ($withConfig): void {
        $sources = <<<'INI'
[primary]
host = db.example.test
username = backup
password = "a"b\c^&"
database[] = app
INI;
        $withConfig('restore-enabled = false', $sources, static function ($root): void {
            assertSameValue('a"b\c^&', Config::load($root)->jobs[0]['connection']['password']);
        });
        $withConfig('restore-enabled = false', $sources . "\ndatabase[0] = replaced", static function ($root): void {
            assertThrows(static fn() => Config::load($root), 'duplicate');
        });
    },
    'unsupported multiline raw credentials are rejected without exposing contents' => static function () use ($withConfig): void {
        $sources = <<<'INI'
[primary]
host = db.example.test
username = backup
password = "line one
[primary]
host = part of password
line two"
database[] = app
INI;
        $withConfig('restore-enabled = false', $sources, static function ($root): void {
            assertThrows(static fn() => Config::load($root), 'valid, non-empty INI');
        });
    },
    'config defaults disable restore and preserve database indexes' => static function () use ($database, $withConfig): void {
        $withConfig('slack-url = ""', $database, static function ($root): void {
            $config = Config::load($root);
            assertSameValue(realpath($root), $config->rootPath);
            assertSameValue(false, $config->settings['restore-enabled']);
            assertSameValue(30, $config->settings['retention-days']);
            assertSameValue(3600, $config->settings['process-timeout']);
            assertSameValue(10, $config->settings['slack-timeout']);
            assertSameValue('primary', $config->jobs[0]['source']);
            assertSameValue(3306, $config->jobs[0]['connection']['port']);
            assertSameValue([], $config->jobs[0]['ignoreTables']);
            assertSameValue(['cache', 'queue'], $config->jobs[1]['ignoreTables']);
        });
    },
    'legacy local credentials do not implicitly enable restore' => static function () use ($database, $withConfig): void {
        $withConfig("localhost-host = localhost\nlocalhost-username = root\nlocalhost-password = root", $database, static function ($root): void {
            assertSameValue(false, Config::load($root)->settings['restore-enabled']);
        });
    },
    'raw INI scanner preserves reserved password words and special characters' => static function () use ($withConfig): void {
        foreach (['true', 'null', 'false', 'yes', 'no', '123456', 'p@ss^&|%!;$()'] as $password) {
            $sources = "[source]\nhost = db.example.test\nusername = backup\npassword = \"" . $password . "\"\ndatabase = inventory";
            $withConfig('slack-url = ""', $sources, static function ($root) use ($password): void {
                assertSameValue($password, Config::load($root)->jobs[0]['connection']['password']);
            });
        }
    },
    'explicit empty source and restore passwords are valid' => static function () use ($database, $restoreSettings, $withConfig): void {
        $withConfig($restoreSettings, str_replace('password = "secret"', 'password = ""', $database), static function ($root): void {
            $config = Config::load($root);
            assertSameValue('', $config->jobs[0]['connection']['password']);
            assertSameValue('', $config->settings['localhost-password']);
            assertSameValue(true, $config->settings['restore-enabled']);
        });
    },
    'missing empty malformed and comment-only INI files are rejected' => static function () use ($database, $withConfig): void {
        foreach (['', '; only a comment', '[broken'] as $settings) {
            $withConfig($settings, $database, static function ($root): void {
                assertThrows(static fn () => Config::load($root), 'settings.ini');
            });
        }
        $withConfig('slack-url = ""', $database, static function ($root): void {
            unlink($root . '/database.ini');
            assertThrows(static fn () => Config::load($root), 'database.ini');
        });
    },
    'root must exist' => static function (): void {
        $root = tempDir();
        removeTree($root);
        assertThrows(static fn () => Config::load($root), 'existing directory');
    },
    'unknown configuration keys are rejected without echoing their values' => static function () use ($database, $withConfig): void {
        $withConfig('typo = "do-not-print-this-secret"', $database, static function ($root): void {
            try {
                Config::load($root);
                throw new RuntimeException('Expected an unknown setting failure.');
            } catch (InvalidArgumentException $error) {
                assertTrue(str_contains($error->getMessage(), 'unknown settings'));
                assertTrue(!str_contains($error->getMessage(), 'do-not-print-this-secret'));
            }
        });
        $withConfig('slack-url = ""', $database . "\nusernmae = backup", static function ($root): void {
            assertThrows(static fn () => Config::load($root), 'unknown settings');
        });
    },
    'required source fields and scalar types are checked' => static function () use ($database, $withConfig): void {
        foreach ([
            str_replace('password = "secret"', '', $database),
            str_replace('host = db.example.test', 'host[] = db.example.test', $database),
            str_replace('username = backup', 'username = ""', $database),
        ] as $sources) {
            $withConfig('slack-url = ""', $sources, static function ($root): void {
                assertThrows(static fn () => Config::load($root), 'must be');
            });
        }
    },
    'numeric bounds and boolean settings are strict' => static function () use ($database, $withConfig): void {
        foreach ([
            'retention-days = 0', 'retention-days = 36501', 'retention-days = 1.5',
            'process-timeout = 86401', 'process-timeout = 0', 'slack-timeout = 61',
            'localhost-port = 65536', 'localhost-port = nope', 'restore-enabled = perhaps',
            'restore-enabled[] = false',
        ] as $settings) {
            $withConfig($settings, $database, static function ($root): void {
                assertThrows(static fn () => Config::load($root), 'settings.ini');
            });
        }
        $withConfig('slack-url = ""', $database . "\nport = 0", static function ($root): void {
            assertThrows(static fn () => Config::load($root), 'port');
        });
    },
    'path traversal option-looking names and associative indexes are rejected' => static function () use ($database, $withConfig): void {
        foreach ([
            str_replace('[primary]', '[../primary]', $database),
            str_replace('inventory', '../inventory', $database),
            str_replace('inventory', '-inventory', $database),
            str_replace('cache, queue', 'cache, ../queue', $database),
            str_replace('database[0]', 'database[arbitrary]', $database),
            str_replace('ignore-table[2]', 'ignore-table[7]', $database),
        ] as $sources) {
            $withConfig('slack-url = ""', $sources, static function ($root): void {
                assertThrows(static fn () => Config::load($root));
            });
        }
    },
    'scalar database and table exclusions remain supported' => static function () use ($withConfig): void {
        $withConfig('slack-url = ""', "[primary]\nhost = remote\nusername = backup\npassword = null\ndatabase = inventory\nignore-table = cache, queue, cache", static function ($root): void {
            $config = Config::load($root);
            assertSameValue('null', $config->jobs[0]['connection']['password']);
            assertSameValue(['cache', 'queue'], $config->jobs[0]['ignoreTables']);
        });
    },
    'duplicate jobs sections and overwritten INI entries are rejected' => static function () use ($database, $withConfig): void {
        foreach ([
            str_replace('orders', 'INVENTORY', $database),
            $database . "\n" . $database,
            $database . "\ndatabase[0] = replaced",
            $database . "\npassword = replacement",
            $database . "\n" . str_replace('[primary]', '[PRIMARY]', $database),
        ] as $sources) {
            $withConfig('slack-url = ""', $sources, static function ($root): void {
                assertThrows(static fn () => Config::load($root), 'duplicate');
            });
        }
    },
    'restore requires explicit credentials and indexed database allowlist' => static function () use ($database, $restoreSettings, $withConfig): void {
        foreach ([
            'restore-enabled = true',
            str_replace('localhost-password = ""', '', $restoreSettings),
            str_replace('localhost-username = restore_user', '', $restoreSettings),
            str_replace("restore-databases[] = inventory\nrestore-databases[] = orders", 'restore-databases = inventory', $restoreSettings),
            str_replace('restore-databases[] = orders', '', $restoreSettings),
            str_replace('restore-databases[] = inventory', 'restore-databases[] = Inventory', $restoreSettings),
        ] as $settings) {
            $withConfig($settings, $database, static function ($root): void {
                assertThrows(static fn () => Config::load($root));
            });
        }
    },
    'restore rejects remote targets and source target loopback collisions' => static function () use ($database, $restoreSettings, $withConfig): void {
        $withConfig($restoreSettings . "\nlocalhost-host = remote.example.test", $database, static function ($root): void {
            assertThrows(static fn () => Config::load($root), 'localhost-host');
        });
        foreach (['localhost', '127.0.0.1', '::1'] as $host) {
            $withConfig($restoreSettings, str_replace('db.example.test', $host, $database), static function ($root): void {
                assertThrows(static fn () => Config::load($root), 'same loopback');
            });
        }
        $withConfig($restoreSettings . "\nlocalhost-port = 3307", str_replace('db.example.test', '127.0.0.1', $database), static function ($root): void {
            assertSameValue(2, count(Config::load($root)->jobs));
        });
    },
    'restore rejects duplicate database names across sources' => static function () use ($database, $restoreSettings, $withConfig): void {
        $sources = $database . "\n" . str_replace('[primary]', '[secondary]', $database);
        $withConfig($restoreSettings, $sources, static function ($root): void {
            assertThrows(static fn () => Config::load($root), 'unique database names');
        });
        $withConfig('slack-url = ""', $sources, static function ($root): void {
            assertSameValue(4, count(Config::load($root)->jobs));
        });
    },
    'restore rejects MySQL system databases' => static function () use ($database, $restoreSettings, $withConfig): void {
        foreach (['mysql', 'sys', 'information_schema', 'performance_schema', 'MYSQL'] as $name) {
            $withConfig(str_replace('inventory', $name, $restoreSettings), str_replace('inventory', $name, $database), static function ($root): void {
                assertThrows(static fn () => Config::load($root), 'system database');
            });
        }
    },
    'Slack webhooks require an approved HTTPS host and clean URL' => static function () use ($database, $withConfig): void {
        foreach ([
            'http://hooks.slack.com/services/T1/B2/secret',
            'https://hooks.slack.com.example.test/services/T1/B2/secret',
            'https://user@hooks.slack.com/services/T1/B2/secret',
            'https://hooks.slack.com/services/T1/B2/secret?token=private',
            'https://hooks.slack.com/services/T1/B2/secret#fragment',
            'https://hooks.slack.com:8443/services/T1/B2/secret',
            'https://hooks.slack.com/other/T1/B2/secret',
        ] as $url) {
            $withConfig('slack-url = "' . $url . '"', $database, static function ($root): void {
                assertThrows(static fn () => Config::load($root), 'slack-url');
            });
        }
        foreach (['hooks.slack.com', 'hooks.slack-gov.com'] as $host) {
            $url = 'https://' . $host . '/services/T1/B2/secret';
            $withConfig('slack-url = "' . $url . '"', $database, static function ($root) use ($url): void {
                assertSameValue($url, Config::load($root)->settings['slack-url']);
            });
        }
    },
    'relative executables and CA bundle resolve from the project root' => static function () use ($database, $withConfig): void {
        $settings = "mysql-binary = mysql-custom\nmysqldump-binary = tools/mysqldump.exe\nslack-ca-bundle = certs.pem";
        $withConfig($settings, $database, static function ($root): void {
            $config = Config::load($root);
            assertSameValue('mysql-custom', $config->settings['mysql-binary']);
            assertSameValue(realpath($root) . DIRECTORY_SEPARATOR . 'tools/mysqldump.exe', $config->settings['mysqldump-binary']);
            assertSameValue(realpath($root . '/certs.pem'), $config->settings['slack-ca-bundle']);
        }, static function ($root): void {
            file_put_contents($root . '/certs.pem', 'test certificate placeholder');
        });
        $withConfig('slack-ca-bundle = missing.pem', $database, static function ($root): void {
            assertThrows(static fn () => Config::load($root), 'slack-ca-bundle');
        });
    },
];
