<?php
declare(strict_types=1);

/** B2B ↔ Bulut ↔ Bayt iptal / tahsilat senkronu. */
final class HfsSync
{
    private static $lockFp = null;

    /** @return array{bulut:int,bayt:int,col_fail:int} */
    public static function run(bool $cron = false): array
    {
        bmn_session_release();
        if ($cron) {
            return self::runCronSlice();
        }
        $out = ['bulut' => 0, 'bayt' => 0, 'col_fail' => 0];
        try {
            $out['bulut'] = HfsPay::reconcilePayments(12);
        } catch (Throwable) {
        }
        try {
            $out['bayt'] = HfsBayt::pullCancellations(20);
        } catch (Throwable) {
        }
        try {
            HfsBayt::pushApprovedWaybills(8);
        } catch (Throwable) {
        }
        try {
            $c = HfsBayt::retryVoidedCollections(12);
            $out['col_fail'] = (int) ($c['fail'] ?? 0);
        } catch (Throwable) {
        }
        try {
            HfsBayt::pushPendingCollections(10);
        } catch (Throwable) {
        }
        if ($out['bulut'] > 0 || $out['bayt'] > 0 || $out['col_fail'] > 0) {
            HfsIntegrLog::write('sync', 'iptal', 'bulut=' . $out['bulut'] . ' bayt=' . $out['bayt'] . ' col_fail=' . $out['col_fail'], true);
        }
        return $out;
    }

    /** Cron: her seferinde tek iş — hesap CPU limitine takılmaz. */
    private static function runCronSlice(): array
    {
        @set_time_limit(45);
        $out = ['bulut' => 0, 'bayt' => 0, 'col_fail' => 0];
        $phase = (int) setting('hfs_sync_phase', '0');
        try {
            setting_set('hfs_sync_phase', (string) (($phase + 1) % 5));
        } catch (Throwable) {
        }
        try {
            match ($phase % 5) {
                0 => $out['bulut'] = HfsPay::reconcilePayments(4),
                1 => $out['bayt'] = HfsBayt::pullCancellations(8),
                2 => HfsBayt::pushApprovedWaybills(3),
                3 => $out['col_fail'] = (int) (HfsBayt::retryVoidedCollections(4)['fail'] ?? 0),
                default => HfsBayt::pushPendingCollections(4),
            };
        } catch (Throwable) {
        }
        if ($phase === 4) {
            try {
                HfsIntegrLog::purgeOld();
            } catch (Throwable) {
            }
        }
        if ($out['bulut'] > 0 || $out['bayt'] > 0 || $out['col_fail'] > 0) {
            HfsIntegrLog::write('sync', 'cron', 'phase=' . $phase . ' bulut=' . $out['bulut'] . ' bayt=' . $out['bayt'], true);
        }
        return $out;
    }

    public static function tryLock(): bool
    {
        if (self::$lockFp !== null) {
            return true;
        }
        $dir = BMN_ROOT . '/storage/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $fp = @fopen($dir . '/hfs-sync.lock', 'c+');
        if ($fp === false) {
            return false;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }
        self::$lockFp = $fp;
        return true;
    }

    public static function unlock(): void
    {
        if (self::$lockFp === null) {
            return;
        }
        flock(self::$lockFp, LOCK_UN);
        fclose(self::$lockFp);
        self::$lockFp = null;
    }

    /** Yönetim sayfalarında senkron kapalı — yalnızca cron (503 önlemi). */
    public static function maybeRunForAdmin(): void
    {
    }
}
