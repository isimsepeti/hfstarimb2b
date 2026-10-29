<?php
declare(strict_types=1);

final class HfsDebug
{
    public static function enabled(): bool
    {
        return defined('BMN_DEBUG') && BMN_DEBUG;
    }

    /** Bayrak / config/debug.php — app.php yüklenmeden önce */
    public static function bootEarly(): void
    {
        if (self::flagEnabled()) {
            define('BMN_DEBUG', true);
            self::activate();
        }
    }

    /** @param array<string,mixed> $cfg */
    public static function applyConfig(array $cfg): void
    {
        if (!empty($cfg['debug']) && !defined('BMN_DEBUG')) {
            define('BMN_DEBUG', true);
        }
        if (self::enabled() && !defined('HFS_DEBUG_ACTIVE')) {
            self::activate();
        }
    }

    public static function logThrowable(Throwable $e, string $label = ''): void
    {
        if (!self::enabled()) {
            return;
        }
        $head = ($label !== '' ? $label . ': ' : '') . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine();
        self::write($head . "\n" . $e->getTraceAsString());
    }

    public static function onError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        self::write("PHP[$severity] $message @ $file:$line");
        return true;
    }

    public static function onException(Throwable $e): void
    {
        self::write('Uncaught ' . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    }

    public static function onShutdown(): void
    {
        $err = error_get_last();
        if (!is_array($err)) {
            return;
        }
        $fatals = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (!in_array($err['type'] ?? 0, $fatals, true)) {
            return;
        }
        self::write('Fatal[' . ($err['type'] ?? 0) . '] ' . ($err['message'] ?? '')
            . ' @ ' . ($err['file'] ?? '') . ':' . ($err['line'] ?? 0));
    }

    private static function flagEnabled(): bool
    {
        $root = BMN_ROOT;
        if (is_file($root . '/storage/debug.on')) {
            return true;
        }
        $f = $root . '/config/debug.php';
        if (!is_file($f)) {
            return false;
        }
        $v = include $f;
        return $v === true || $v === 1;
    }

    private static function activate(): void
    {
        if (defined('HFS_DEBUG_ACTIVE')) {
            return;
        }
        define('HFS_DEBUG_ACTIVE', true);
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        $dir = BMN_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $log = $dir . '/php-errors.log';
        ini_set('error_log', $log);
        set_error_handler([self::class, 'onError']);
        set_exception_handler([self::class, 'onException']);
        register_shutdown_function([self::class, 'onShutdown']);
        self::write('--- debug session start ' . ($_SERVER['REQUEST_URI'] ?? 'cli') . ' ---');
    }

    private static function write(string $line): void
    {
        $file = BMN_ROOT . '/storage/logs/php-errors.log';
        @file_put_contents($file, '[' . date('c') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
        error_log($line);
    }
}
