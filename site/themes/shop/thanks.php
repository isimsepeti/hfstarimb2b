<article class="page wrap hfs-order">
    <p class="kicker">Sipariş</p>
    <h1><?= h($order['code']) ?></h1>
    <p>Toplam <?= h(money($order['total'])) ?>. Ödeme:
        <?php
        $pay = (string) ($order['payment'] ?? '');
        echo h(['havale' => 'havale / EFT', 'acik_hesap' => 'açık hesap', 'bulut' => 'kart (BulutTahsilat)'][$pay] ?? $pay);
        ?>.
    </p>
    <p class="hfs-order__status">Durum: <strong><?= h(hfs_order_status_for($order)) ?></strong></p>
    <?php if (($order['bayt_status'] ?? '') === 'sent' && ($order['bayt_approval'] ?? '') !== 'approved' && ($order['status'] ?? '') !== 'iptal'): ?>
        <p class="hint">Sipariş muhasebeye iletildi. Onay veya iptal BYTE tarafında yapılır; burada aynı durum görünür.</p>
    <?php endif; ?>
    <?php
    $track = trim((string) ($order['tracking'] ?? ''));
    $isCariStub = (int) ($order['is_cari'] ?? 0) === 1;
    ?>
    <?php if (!$isCariStub): ?>
        <section class="hfs-track" aria-label="Kargo takibi">
            <h2>Kargo takibi</h2>
            <?php if ($track !== ''): ?>
                <p class="hfs-track__no"><?= h($track) ?></p>
                <p class="hint">Yurtiçi Kargo’da sorgulayın.</p>
                <p class="hfs-track__links">
                    <?php foreach (hfs_track_carriers($track) as $c): ?>
                        <a class="btn ghost" href="<?= h($c['href']) ?>" target="_blank" rel="noopener"><?= h($c['name']) ?></a>
                    <?php endforeach; ?>
                </p>
            <?php else: ?>
                <p><?= h(hfs_track_note($order)) ?></p>
            <?php endif; ?>
            <p><a href="<?= h(site_url('hesabim/kargo/')) ?>">Tüm kargo takipleri</a></p>
        </section>
    <?php endif; ?>
    <?php $btErr = HfsPay::consumeError(); if (!empty($_GET['hata']) || $btErr !== ''): ?>
        <p class="err">Kart ödemesi başlatılamadı.<?= $btErr !== '' ? ' ' . h($btErr) : ' Yönetim → Ayarlar’dan BulutTahsilat bilgilerini kontrol edin.' ?></p>
    <?php endif; ?>
    <?php if (($order['status'] ?? '') === 'pending_b2b'): ?>
        <p class="hint">Bu sipariş onay limitini aştığı için firma onaylayıcısının onayını bekliyor.</p>
    <?php endif; ?>
    <?php if (trim((string) ($order['po_number'] ?? '')) !== ''): ?>
        <p>PO: <strong><?= h($order['po_number']) ?></strong></p>
    <?php endif; ?>
    <?php if (trim((string) ($order['address'] ?? '')) !== ''): ?>
        <p class="hint">Teslimat: <?= h($order['address']) ?><?= !empty($order['district']) ? ', ' . h($order['district']) : '' ?> <?= h($order['city'] ?? '') ?></p>
    <?php endif; ?>
    <ul class="facts">
        <?php foreach ($order['items'] as $it): ?>
            <li><?= (int) $it['qty'] ?>× <?= h($it['title']) ?> — <?= h(money($it['price'])) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($order['payment'] === 'havale' && setting('iban')): ?>
        <p>Havale açıklamasına sipariş kodunu yaz: <strong><?= h($order['code']) ?></strong><br>
        IBAN <?= h((string) setting('iban')) ?> — <?= h((string) setting('iban_name', 'HFS Tarım')) ?></p>
    <?php endif; ?>
    <?php if (HfsPay::hasDekont($order)): ?>
        <?php
        $inst = trim((string) ($order['bt_installment'] ?? ''));
        $mask = trim((string) ($order['bt_card_mask'] ?? ''));
        $auth = trim((string) ($order['bt_auth_code'] ?? ''));
        ?>
        <section class="hfs-dekont" aria-label="Tahsilat dekontu">
            <h2>Tahsilat dekontu</h2>
            <p class="hint">
                <?php if ($auth !== ''): ?>Provizyon <?= h($auth) ?><?php endif; ?>
                <?php if ($inst !== '' && $inst !== '0' && $inst !== '1'): ?><?= $auth !== '' ? ' · ' : '' ?><?= h($inst) ?> taksit<?php endif; ?>
                <?php if ($mask !== ''): ?><?= ($auth !== '' || ($inst !== '' && $inst !== '0' && $inst !== '1')) ? ' · ' : '' ?>Kart <?= h($mask) ?><?php endif; ?>
            </p>
            <p><a class="btn ghost" href="<?= h(site_url('siparis/' . $order['code'] . '/dekont/')) ?>" target="_blank" rel="noopener">Dekont indir (PDF)</a></p>
        </section>
    <?php endif; ?>
    <p>
        <a class="btn" href="<?= h(site_url()) ?>">Mağazaya dön</a>
        <?php if (!in_array((string) ($order['status'] ?? ''), ['iptal', 'odeme'], true) && !$isCariStub): ?>
            <a class="btn ghost" href="<?= h(site_url('siparis/' . $order['code'] . '/irsaliye/')) ?>">İrsaliye / PDF</a>
        <?php endif; ?>
        <?php if (!$isCariStub && ($order['status'] ?? '') !== 'pending_b2b'): ?>
            <a class="btn ghost" href="<?= h(site_url('siparis/' . $order['code'] . '/') . '?tekrar=1') ?>">Tekrar sipariş</a>
        <?php endif; ?>
    </p>
</article>
