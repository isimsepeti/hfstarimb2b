<div class="page-head">
    <h1><?= h($order['code']) ?></h1>
    <p><?= h($order['channel'] ?? 'web') ?> · <?= h($order['country'] ?? 'TR') ?></p>
</div>
<form method="post" class="stack">
    <?= csrf_field() ?>
    <div class="card">
        <h2>Müşteri</h2>
        <div class="row2">
            <label><span>Ad</span><input name="name" type="text" value="<?= h($order['name']) ?>"></label>
            <label><span>E-posta</span><input name="email" type="email" value="<?= h($order['email']) ?>"></label>
        </div>
        <div class="row2">
            <label><span>Telefon</span><input name="phone" type="text" value="<?= h($order['phone']) ?>"></label>
            <label><span>Ülke</span><input name="country" type="text" value="<?= h($order['country'] ?? 'TR') ?>" maxlength="2"></label>
        </div>
        <div class="row2">
            <label><span>İl</span><input name="city" type="text" value="<?= h($order['city']) ?>"></label>
            <label><span>İlçe</span><input name="district" type="text" value="<?= h($order['district']) ?>"></label>
        </div>
        <label><span>Adres</span><textarea name="address" rows="2"><?= h($order['address']) ?></textarea></label>
        <label><span>Not</span><textarea name="note" rows="2"><?= h($order['note'] ?? '') ?></textarea></label>
        <div class="row2">
            <label><span>Kargo takip</span><input name="tracking" type="text" value="<?= h($order['tracking'] ?? '') ?>"><small class="field-hint">Yurtiçi takip no. Elle veya aşağıdaki YK butonlarıyla gelir; kaydedince / YK’dan çekilince bayiye mail gider.</small></label>
            <label><span>Saat dilimi</span><input name="ship_slot" type="text" value="<?= h($order['ship_slot'] ?? '') ?>"></label>
        </div>
        <?php if (!empty($order['yk_job_id']) || !empty($order['yk_status']) || !empty($order['yk_error'])): ?>
            <p class="hint">YK: job <?= h((string) ($order['yk_job_id'] ?: '—')) ?> · <?= h((string) ($order['yk_status'] ?: '—')) ?>
                <?php if (!empty($order['yk_error'])): ?> · <?= h((string) $order['yk_error']) ?><?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>Kalemler</h2>
        <?php foreach ($order['items'] as $it): ?>
            <p>
                <?= h($it['title']) ?>
                <?php if (!empty($it['note'])): ?><small>(<?= h($it['note']) ?>)</small><?php endif; ?>
                — <?= h(money($it['price'])) ?>
                <input class="compact" name="item_qty[<?= (int) $it['id'] ?>]" type="number" min="0" value="<?= (int) $it['qty'] ?>">
            </p>
        <?php endforeach; ?>
        <p>Ara <?= h(money($order['subtotal'])) ?>
            <?php if ((float) ($order['discount'] ?? 0) > 0): ?> · İndirim <?= h(money($order['discount'])) ?> (<?= h($order['coupon_code'] ?? '') ?>)<?php endif; ?>
            · Kargo <?= h(money($order['shipping'])) ?>
            · <strong><?= h(money($order['total'])) ?></strong></p>
        <p><?php
            $pay = (string) ($order['payment'] ?? '');
            echo $pay === 'kapida' ? 'Kapıda' : ($pay === 'acik_hesap' ? 'Açık hesap' : ($pay === 'bulut' ? 'Kart (BulutTahsilat)' : 'Havale / EFT'));
        ?>
            <?php if ((int) ($order['is_cari'] ?? 0) === 1): ?> · Cari tahsilat<?php endif; ?>
            <?php
                $payStatusLabel = HfsPay::paymentStatusLabel($order);
                if ($payStatusLabel !== ''):
            ?> · <?= h($payStatusLabel) ?><?php endif; ?>
            <?php if (($order['payment_ref'] ?? '') !== ''): ?> · Ref <?= h((string) $order['payment_ref']) ?><?php endif; ?>
            <?php if (($order['bt_guid'] ?? '') !== ''): ?> · GUID <?= h((string) $order['bt_guid']) ?><?php endif; ?>
            <?php if (trim((string) ($order['bt_auth_code'] ?? '')) !== ''): ?> · Provizyon <?= h((string) $order['bt_auth_code']) ?><?php endif; ?>
            <?php if (trim((string) ($order['bt_installment'] ?? '')) !== ''): ?> · Taksit <?= h((string) $order['bt_installment']) ?><?php endif; ?>
            <?php if (trim((string) ($order['bt_card_mask'] ?? '')) !== ''): ?> · Kart <?= h((string) $order['bt_card_mask']) ?><?php endif; ?>
            <?php if (trim((string) ($order['bt_card_bank'] ?? '')) !== ''): ?> · <?= h((string) $order['bt_card_bank']) ?><?php endif; ?>
            <?php if (trim((string) ($order['bt_pos_bank'] ?? '')) !== ''): ?> · POS <?= h((string) $order['bt_pos_bank']) ?><?php if (trim((string) ($order['bt_merchant_id'] ?? '')) !== ''): ?> — <?= h((string) $order['bt_merchant_id']) ?><?php endif; ?><?php endif; ?>
            <?php if (trim((string) ($order['po_number'] ?? '')) !== ''): ?> · PO <?= h($order['po_number']) ?><?php endif; ?>
        </p>
        <?php if (!empty($order['consent_kvkk_at'])): ?>
            <p class="hint">Yasal onay: KVKK <?= h((string) $order['consent_kvkk_at']) ?> (sürüm <?= h((string) ($order['consent_kvkk_ver'] ?? '')) ?>)
                · Koşullar <?= h((string) ($order['consent_terms_at'] ?? '')) ?> (sürüm <?= h((string) ($order['consent_terms_ver'] ?? '')) ?>)</p>
        <?php endif; ?>
        <?php if (HfsPay::hasDekont($order)): ?>
            <p><a class="btn ghost" href="<?= h(site_url('yonetim/siparisler/' . $order['code'] . '/dekont/')) ?>" target="_blank" rel="noopener">Tahsilat dekontu (PDF)</a></p>
        <?php endif; ?>
        <?php if (!empty($order['bayt_status']) || !empty($order['bayt_phase']) || (int) ($order['bayt_order_id'] ?? 0) > 0): ?>
            <p>Bayt: <?= h((string) ($order['bayt_status'] ?: '—')) ?>
                <?php if (!empty($order['bayt_phase'])): ?> · aşama <?= h((string) $order['bayt_phase']) ?><?php endif; ?>
                <?php if ((int) ($order['bayt_order_id'] ?? 0) > 0): ?> · sipariş #<?= (int) $order['bayt_order_id'] ?><?php endif; ?>
                <?php if ((int) ($order['bayt_id'] ?? 0) > 0): ?> · irsaliye #<?= (int) $order['bayt_id'] ?><?php endif; ?>
                <?php if (!empty($order['bayt_ref'])): ?> · <?= h($order['bayt_ref']) ?><?php endif; ?>
                <?php if (!empty($order['bayt_error'])): ?> · <?= h($order['bayt_error']) ?><?php endif; ?>
            </p>
        <?php endif; ?>
        <p><strong>Muhasebe:</strong> <?= h(hfs_order_status_for($order)) ?></p>
        <p class="field-hint">Sipariş onayı ve iptali BYTE muhasebeden gelir. Aşağıdaki durum hazırlık / kargo içindir.</p>
    </div>
    <div class="toolbar">
        <select name="status">
            <?php foreach (['odeme','bekliyor','pending_b2b','yeni','hazirlaniyor','kargoda','tamam','iptal'] as $s): ?>
                <option value="<?= $s ?>"<?= $order['status'] === $s ? ' selected' : '' ?>><?= h(hfs_order_status($s)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit">Siparişi kaydet</button>
        <a class="btn ghost" href="<?= h(site_url('yonetim/siparisler/' . $order['code'] . '/irsaliye/')) ?>" target="_blank" rel="noopener">Sevk irsaliyesi</a>
        <a class="btn ghost" href="<?= h(site_url('yonetim/siparisler/' . $order['code'] . '/e-irsaliye/')) ?>" target="_blank" rel="noopener">e-İrsaliye ön izleme</a>
    </div>
</form>
<?php if (Catalog::canDelete($order)): ?>
<form method="post" class="toolbar" onsubmit="return confirm('Bu sipariş kalıcı silinsin mi?');">
    <?= csrf_field() ?>
    <button class="btn ghost" type="submit" name="sil_siparis" value="1">Siparişi sil</button>
</form>
<?php endif; ?>
<?php if (($order['status'] ?? '') === 'pending_b2b'): ?>
<form method="post" class="toolbar">
    <?= csrf_field() ?>
    <button class="btn" type="submit" name="b2b_act" value="approve">Onayla</button>
    <button class="btn ghost" type="submit" name="b2b_act" value="reject">Reddet</button>
</form>
<?php endif; ?>
<?php if (!empty($_GET['baytprobe']) && !empty($_SESSION['bayt_probe']) && is_array($_SESSION['bayt_probe'])): ?>
<div class="card">
    <h2>e-İrsaliye API denemesi</h2>
    <p class="<?= !empty($_SESSION['bayt_probe']['ok']) ? 'ok' : 'err' ?>">Aşama: <?= h((string) ($_SESSION['bayt_probe']['phase'] ?? '')) ?></p>
    <ul><?php foreach ((array) ($_SESSION['bayt_probe']['steps'] ?? []) as $step): ?><li><?= h((string) $step) ?></li><?php endforeach; ?></ul>
</div>
<?php unset($_SESSION['bayt_probe']); endif; ?>
<?php if ((int) ($order['is_cari'] ?? 0) !== 1): ?>
<form method="post" class="toolbar">
    <?= csrf_field() ?>
    <label>Fiili sevk <input type="datetime-local" name="dispatch_at" value="<?= h(str_replace(' ', 'T', substr((string) ($order['bayt_dispatch_at'] ?? ''), 0, 16))) ?>"></label>
    <button class="btn ghost" type="submit" name="bayt_send_order" value="1">Bayt satış siparişi</button>
    <button class="btn ghost" type="submit" name="bayt_send_waybill" value="1">Sevk irsaliyesi oluştur</button>
    <button class="btn ghost" type="submit" name="bayt_stage_edi" value="1">e-İrsaliye alanlarını uygula</button>
    <button class="btn ghost" type="submit" name="bayt_send" value="1" title="Ayarda otomatik irsaliye açıksa ikisini birden gönderir">Hepsini gönder</button>
</form>
<form method="post" class="toolbar">
    <?= csrf_field() ?>
    <button class="btn" type="submit" name="yk_create" value="1" <?= HfsYurtici::configured() ? '' : 'disabled title="Ayarlar → Yurtiçi Kargo"' ?>>YK’ya kargo ver (AÖ)</button>
    <button class="btn ghost" type="submit" name="yk_track" value="1" <?= HfsYurtici::configured() ? '' : 'disabled' ?>>YK takip çek</button>
    <span class="hint">cargoKey = <?= h((string) $order['code']) ?></span>
</form>
<?php endif; ?>
<?php if (!empty($_GET['yk']) && !empty($_SESSION['yk_flash']) && is_array($_SESSION['yk_flash'])): ?>
<div class="card">
    <p class="<?= !empty($_SESSION['yk_flash']['ok']) ? 'ok' : 'err' ?>"><?= h((string) ($_SESSION['yk_flash']['msg'] ?? '')) ?></p>
</div>
<?php unset($_SESSION['yk_flash']); endif; ?>
