<?php
declare(strict_types=1);

/** B2B ↔ Bulut ↔ Bayt entegrasyon günlüğü (panel + haftalık temizlik). */
final class HfsIntegrLog
{
    private const RETAIN_DAYS = 7;

    public static function ensureSchema(): void
    {
        HfsMigrate::up();
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function write(
        string $source,
        string $event,
        string $message,
        bool $ok = true,
        int $orderId = 0,
        string $orderCode = '',
        array $context = []
    ): void {
        try {
            self::ensureSchema();
            $source = mb_substr(trim($source), 0, 16);
            $event = mb_substr(trim($event), 0, 64);
            $message = mb_substr(trim($message), 0, 2000);
            $orderCode = mb_substr(trim($orderCode), 0, 32);
            $ctx = $context === [] ? '' : mb_substr(json_encode($context, JSON_UNESCAPED_UNICODE) ?: '', 0, 8000);
            db()->prepare('INSERT INTO integration_log (source,event,ok,order_id,order_code,message,context,created_at) VALUES (?,?,?,?,?,?,?,NOW())')
                ->execute([$source, $event, $ok ? 1 : 0, max(0, $orderId), $orderCode, $message, $ctx]);
        } catch (Throwable) {
        }
    }

    /** @return list<array<string,mixed>> */
    public static function rows(int $limit = 200, string $source = ''): array
    {
        self::ensureSchema();
        $lim = max(1, min(500, $limit));
        $source = mb_strtolower(trim($source));
        if ($source !== '' && preg_match('/^[a-z0-9_-]+$/', $source)) {
            $st = db()->prepare('SELECT * FROM integration_log WHERE source=? ORDER BY id DESC LIMIT ' . $lim);
            $st->execute([$source]);
            return $st->fetchAll() ?: [];
        }
        return db()->query('SELECT * FROM integration_log ORDER BY id DESC LIMIT ' . $lim)->fetchAll() ?: [];
    }

    public static function purgeOld(): int
    {
        try {
            self::ensureSchema();
            $last = (int) setting('integr_log_purge_ts', '0');
            if ($last > 0 && time() - $last < 86400 * 6) {
                return 0;
            }
            $st = db()->prepare('DELETE FROM integration_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
            $st->execute([self::RETAIN_DAYS]);
            $n = $st->rowCount();
            setting_set('integr_log_purge_ts', (string) time());
            if ($n > 0) {
                self::write('sistem', 'purge', $n . ' kayıt silindi (>' . self::RETAIN_DAYS . ' gün)', true);
            }
            return $n;
        } catch (Throwable) {
            return 0;
        }
    }
}
