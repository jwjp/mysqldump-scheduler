# MySQL Backup Scheduler

A PHP CLI tool for Windows Task Scheduler. It backs up databases from multiple MySQL servers to SQL files and can optionally restore them to a dedicated local MySQL server. Execution results and errors are recorded in files, with optional Slack notifications.

**The default behavior is to create SQL backups only.** Local restoration requires both `restore-enabled = true` and an explicit list of databases allowed for restoration. When enabled, restoration deletes each target database with `DROP DATABASE` and recreates it.

## Installation

1. Install **PHP 8.3 or later CLI** on Windows and add `php.exe` to PATH. Composer is not required. Slack notifications require the PHP cURL extension and a trusted CA certificate configuration.
2. Install MySQL 8.0 or later clients (`mysql.exe` and `mysqldump.exe`) compatible with the remote server version. Add them to PATH or specify their absolute paths in `settings.ini`. A local MySQL server is not required when creating SQL backups only. This tool targets MySQL; compatibility with MariaDB client options and server identification is not guaranteed.
3. Copy the example configuration files, then edit the connection details and backup targets.

```powershell
Copy-Item .\database.ini.example .\database.ini
Copy-Item .\settings.ini.example .\settings.ini
```

4. Validate the configuration.

```powershell
php.exe .\MySQLDump.php --check
```

`--check` validates configuration only. It does not connect to databases, send Slack requests, or create directories, logs, or backup files. Connectivity, account permissions, and successful dumping and restoration must therefore be verified separately.

## Usage

```powershell
# Default configuration directory: the directory containing MySQLDump.php
php.exe .\MySQLDump.php

# Separate configuration directory: use an absolute path for scheduled runs
php.exe .\MySQLDump.php --config-dir="D:\MySQL Backups"

# Validate configuration only
php.exe .\MySQLDump.php --config-dir="D:\MySQL Backups" --check

# Show help
php.exe .\MySQLDump.php --help
```

The configuration directory must contain `database.ini` and `settings.ini`. Backups, execution records, and the lock file are also stored beneath this directory. The default configuration directory is based on the script location, so the PHP source does not need path changes for Task Scheduler. A relative path supplied through `--config-dir` is resolved against the current working directory.

| Exit code | Meaning |
| --- | --- |
| `0` | Complete success, or successful help output or configuration validation |
| `1` | Configuration, backup, restoration, file storage or cleanup, logging, or Slack notification error |
| `2` | Invalid CLI arguments |
| `3` | Another execution is already using the same configuration directory |

## Remote database configuration

Each section in `database.ini` represents a source server. See [database.ini.example](database.ini.example) for the configuration format.

```ini
[source01]
host = "db-host.internal"
port = 3306
username = "backup_reader"
password = "replace-with-your-password"
database[0] = "app"
database[1] = "analytics"
ignore-table[1] = "temporary_report,import_staging"
```

- Section names must be 1–64 characters long and begin with an ASCII letter or digit. Subsequent characters may also include `_` and `-`. Source names that differ only in case are treated as duplicates.
- Set `host`, `username`, `password`, and the `database[index]` entries to back up. The default `port` is `3306`.
- Database and table names must contain only ASCII letters, digits, `_`, `$`, and `-`, with a maximum length of 64 characters. The first character must be a letter, digit, or `_`.
- `ignore-table[index]` is an optional comma-separated list of tables to exclude from the database with the same index. Both the structure and data of excluded tables are omitted.
- Configuration is read using `INI_SCANNER_RAW`. Do not apply the shell escaping used by the previous implementation, such as replacing `^` with `^^`. Outer double quotes are removed, but backslashes within values are preserved, so do not add escapes such as `\\` or `\"`. For example, if the actual password is `a"b\c^&`, write `password = "a"b\c^&"`. Single quotes are treated as part of the value in this mode.
- Write each configuration value on one line. Passwords containing line breaks are not supported by this INI format.

Use a dedicated backup account with access only to the databases it needs. The permissions required to read tables, views, triggers, events, and stored routines depend on the MySQL version and server configuration. Avoid using a shared `root/root` account, and consult the [mysqldump documentation for your MySQL version](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html).

## Execution and notification settings

See [settings.ini.example](settings.ini.example) for a complete example.

| Key | Default or purpose |
| --- | --- |
| `mysql-binary` | `mysql.exe`; executable name on PATH or path to the executable |
| `mysqldump-binary` | `mysqldump.exe`; executable name on PATH or path to the executable |
| `restore-enabled` | `false`; enables local restoration |
| `localhost-host` | `127.0.0.1`; only local loopback addresses are allowed |
| `localhost-port` | `3306` |
| `localhost-username` | Dedicated restoration account; required when restoration is enabled |
| `localhost-password` | Restoration account password; required when restoration is enabled |
| `restore-databases[]` | Explicit list of databases allowed for restoration; required when restoration is enabled |
| `retention-days` | `30`; retention period for successful executions, in days |
| `process-timeout` | `3600`; timeout for an external process, in seconds |
| `slack-url` | Optional; Slack notifications are disabled when omitted |
| `slack-timeout` | `10`; timeout for each Slack request, in seconds |
| `slack-ca-bundle` | Optional path to a trusted CA certificate bundle |

Enclose executable paths containing spaces in double quotes in the INI file. Specify the executable only; do not append command options. Relative executable paths containing a directory separator and relative CA file paths are resolved against the configuration directory.

```ini
mysql-binary = "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe"
mysqldump-binary = "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqldump.exe"
```

Only HTTPS Slack webhook URLs under `hooks.slack.com/services/...` or `hooks.slack-gov.com/services/...` are accepted. TLS certificates are verified. If needed, set `slack-ca-bundle` to the absolute path of a CA bundle file. Backup work continues if a notification fails, but the final exit code is `1`.

## Local restoration

Prepare a dedicated local MySQL server and restoration account, then configure them as follows:

```ini
restore-enabled = true
localhost-host = "127.0.0.1"
localhost-port = 3306
localhost-username = "backup_restore"
localhost-password = "replace-with-your-password"
restore-databases[] = "app"
restore-databases[] = "analytics"
```

When restoration is enabled, **every** database in the remote backup configuration must appear in the allowed list with exactly the same spelling and case. Database names must be unique across all jobs, including jobs from different sources; names that differ only in case are also treated as duplicates. Target databases retain their source names. The allowed list does not map or rename databases. The system databases `mysql`, `sys`, `information_schema`, and `performance_schema` cannot be restored.

Before starting the backup, the tool compares the remote and local servers' `server_uuid` values and rejects restoration to the same server. Local connection addresses are restricted to loopback addresses. Configure the restoration server and account separately from production databases even with these checks in place.

**Restoration deletes the entire existing database and recreates it.** Tables excluded by `ignore-table` are also deleted if they exist in the local database, and they are not recreated because they are absent from the dump. Existing local changes are not preserved. If restoration fails, some SQL statements may already have been applied; there is no automatic rollback. Grant deletion, creation, and restoration permissions only for the databases in the allowed list.

## Backup files and retention

```text
configuration-directory/
├─ database.ini
├─ settings.ini
├─ .mysqldump.lock
├─ logs/
│  └─ scheduler.log
└─ sql_storage/
   └─ YYYYMMDD/
      └─ <timestamp-random-run-id>/
         ├─ dump-001-source01-app.sql
         └─ manifest.json
```

- Each execution creates a unique directory, so rerunning the tool on the same day does not overwrite earlier backups. SQL filenames also include a job sequence number to prevent collisions between source and database name combinations.
- Dumps are written to `.sql.partial` files. After a successful dump, the file is finalized as `.sql` before restoration begins. Failed dumps remain as `.sql.partial` files and are never restored. A failure to store the completed file also counts as a job failure.
- Each execution records its results in `manifest.json` and `logs/scheduler.log`. Review these records alongside the SQL files.
- The `.mysqldump.lock` file prevents simultaneous executions using the same configuration directory. Do not manually delete the lock file while an execution is running.
- Automatic cleanup runs once, only after the current execution's backups, file storage, enabled restoration, and start notification have all succeeded. It considers only executions whose date directories are outside the retention period and whose manifests record complete success. Failed or incomplete executions, files from the previous storage layout, unrecorded files, and symbolic links are not automatically deleted. The default 30-day retention keeps the most recent 30 calendar dates, including today.
- Failed executions remain on disk. Monitor storage usage and inspect them before removing them manually. This tool does not compress backups or replicate them to another device.

Dumps include stored procedures, functions, events, and triggers, and use `--set-gtid-purged=OFF`. The `--single-transaction` option provides a consistent read of InnoDB data. Nontransactional tables and schema changes during a dump have limitations. Databases are dumped sequentially, so snapshots of multiple databases are not guaranteed to represent the same point in time. See the [MySQL mysqldump documentation](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html) for these limitations.

## Windows Task Scheduler

1. Run `--check` and a manual backup using the Windows account that will run the scheduled task.
2. Create a task in Task Scheduler and set the desired schedule.
3. Set the action's **Program/script** to the absolute path of `mysqldump.bat`. To use a separate configuration directory, add `--config-dir="D:\MySQL Backups"` to the arguments.
4. Configure the task's multiple-instance policy so that it does not start a new instance while one is running. The tool also applies its own lock per configuration directory.
5. Check the task's last run result and `logs/scheduler.log`.

The batch file locates the PHP script through `%~dp0`, so you do not need to set a specific starting directory or add `cd`. It forwards arguments to PHP and returns PHP's exit code unchanged. By default, it uses `php.exe` from PATH. To select another PHP executable, set the task account's `PHP_BINARY` environment variable to its absolute path.

```powershell
# Select the PHP executable for a batch run from the current PowerShell session
$env:PHP_BINARY = 'C:\Tools\PHP 8.3\php.exe'
& .\mysqldump.bat --check
```

For Task Scheduler to use this variable, it must also be set in the environment of the account running the task. Do not include quotation marks in the environment variable's value itself.

## Configuration and file permissions

`database.ini` and `settings.ini` may contain passwords and webhooks, and SQL files contain source data. Restrict the configuration directory's Windows ACL so that only the scheduled task account and necessary administrators can access it. Do not place it in a publicly served web directory.

Database passwords are stored in temporary MySQL option files with restricted access, rather than passed as command-line arguments. These files are removed when execution ends. Grant the task account the required read and write access to the configuration directory, temporary directory, and backup storage. After an abnormal termination, such as a forced operating system shutdown, inspect temporary files and execution records.

Actual configuration files, SQL backups, logs, lock files, and `.env` files are excluded by `.gitignore`. Adding an already tracked secret file to `.gitignore` does not remove it from Git tracking. Remove it from tracking separately and rotate any exposed credentials.

## Migrating from the previous version

1. Stop the existing scheduled task and confirm that any active execution has finished.
2. Preserve a separate copy of the existing configuration and SQL backups.
3. Prepare PHP 8.3 or later and review configuration keys and database names against the examples. Replace manually shell-escaped passwords, such as those containing `^^`, with their actual values.
4. The previous version automatically restored dumps locally. In the new version, **restoration is disabled by default**. To continue restoring, explicitly set `restore-enabled = true`, local connection details, and `restore-databases[]`. Confirm that database names do not overlap across sources.
5. Use `--config-dir` instead of editing `$rootPath` in the PHP source or hardcoded paths in the batch file. Use an absolute path for scheduled runs.
6. Validate with `--check`, test backup and restoration manually in a dedicated environment, and then enable the scheduled task. The new storage layout uses a subdirectory and manifest for each execution. Manage backups from the previous layout separately; do not assume that automatic cleanup will remove them.

## Development checks

```powershell
php.exe -l .\MySQLDump.php
php.exe .\tests\run.php
```

Syntax checks and automated tests were run on Windows with PHP 8.3.25. MySQL 8.0.46 client option parsing was also checked without connecting to a server. The tests run without real database connections or network requests and do not replace backup and restoration testing against an actual server. Before deployment, verify restoration using your server version, account permissions, and data size.
