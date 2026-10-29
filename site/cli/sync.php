<?php
declare(strict_types=1);

// cPanel cron (5 dk): /usr/local/bin/php /home/KULLANICI/b2b.hfstarim.com/cli/sync.php
// Eski /hfs-sync/ curl cron'unun yerine: web süreç limitini (LVE entry process) kullanmaz.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

if (!HfsSync::tryLock()) {
    echo "busy\n";
    exit(0);
}
try {
    // Her çalıştırmada 5 fazın hepsi: bulut tahsilat, bayt iptal, irsaliye, tahsilat tekrar, tahsilat gönder.
    for ($i = 0; $i < 5; $i++) {
        $phase = (int) setting('hfs_sync_phase', '0');
        $r = HfsSync::run(true);
        echo date('c') . ' phase=' . $phase . ' bulut=' . $r['bulut'] . ' bayt=' . $r['bayt'] . ' col_fail=' . $r['col_fail'] . "\n";
    }
} finally {
    HfsSync::unlock();
}
