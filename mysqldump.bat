@echo off
setlocal DisableDelayedExpansion

if defined PHP_BINARY (
    "%PHP_BINARY%" "%~dp0MySQLDump.php" %*
) else (
    php.exe "%~dp0MySQLDump.php" %*
)

set "scheduler_exit_code=%ERRORLEVEL%"
endlocal & exit /b %scheduler_exit_code%
