<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
$passed = 0;
$failed = 0;
foreach (glob(__DIR__ . '/*_test.php') as $file) {
    foreach (require $file as $name => $test) {
        ob_start();
        try {
            $test();
            ob_end_clean();
            echo "PASS $name\n";
            $passed++;
        } catch (Throwable $error) {
            ob_end_clean();
            fwrite(STDERR, "FAIL $name: {$error->getMessage()}\n");
            $failed++;
        }
    }
}
echo "$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
