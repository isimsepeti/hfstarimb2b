<?php $s = $s ?? HfsBayt::settings(); ?>
<div class="page-head">
    <h1>Muhasebe (Bayt)</h1>
    <p>Bayi siparişleri önce <strong>satış siparişi</strong>, ardından <strong>gönderilecek satış irsaliyesi</strong> olarak Bayt’a gider (e-İrsaliye/GİB ayrı süreç). <strong>İptal senkronu:</strong> B2B panel → Bayt + Bulut; Bulut panel → B2B + Bayt; Bayt silme/iptal → B2B + Bulut (cron <code>/hfs-sync/</code> veya yönetim açılışında arka plan).</p>
</div>
<?php if (!empty($ok)): ?><p class="ok">Ayarlar kaydedildi.</p><?php endif; ?>
<?php if (!empty($sendMsg)): ?><p class="ok"><?= h((string) $sendMsg) ?></p><?php endif; ?>
<?php if (!empty($test)): ?>
    <div class="card">
        <p class="<?= !empty($test['ok']) ? 'ok' : 'err' ?>"><strong><?= !empty($test['ok']) ? 'Test başarılı' : 'Test başarısız' ?></strong> — <?= h((string) ($test['message'] ?? '')) ?></p>
        <?php if (!empty($test['steps'])): ?>
            <ul><?php foreach ($test['steps'] as $step): ?><li><?= h($step) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
<?php endif; ?>
<form class="stack dealer-form" method="post">
    <?= csrf_field() ?>
    <div class="card">
        <h2>Bağlantı</h2>
        <label class="cat"><input type="checkbox" name="enabled" value="1"<?= ($s['enabled'] ?? '0') === '1' ? ' checked' : '' ?>> Onaylı siparişi otomatik gönder</label>
        <label class="cat"><input type="checkbox" name="auto_waybill" value="1"<?= ($s['auto_waybill'] ?? '0') === '1' ? ' checked' : '' ?>> Otomatik gönderimde irsaliyeyi de aç (kapalı: yalnız satış siparişi; sevkte panelden irsaliye)</label>
        <label class="cat"><input type="checkbox" name="bayt_edi_stage" value="1"<?= ($s['bayt_edi_stage'] ?? '1') === '1' ? ' checked' : '' ?>> İrsaliye sonrası STK_FIS e-İrsaliye alanları (EIRSALIYE=1, fiili sevk) — resmi API yokken ExecuteQuery</label>
        <p class="field-hint">7272/7273 notu: liste için STK_FIS.EIRSALIYE=1 gerekir; GİB gönderimi ayrı operatör adımıdır.</p>
        <label>
            <span>Belge tipi</span>
            <select name="document_type">
                <option value="waybill" selected>Satış irsaliyesi (gönderilecek irsaliye)</option>
            </select>
            <span class="field-hint">Site faturayı kesmez. Belge BYTE’de Gönderilecek irsaliye listesine düşer; muhasebe onaylar veya iptal eder.</span>
        </label>
        <div class="row2">
            <label>
                <span>Protokol</span>
                <select name="protocol">
                    <option value="http"<?= ($s['protocol'] ?? '') === 'http' ? ' selected' : '' ?>>http</option>
                    <option value="https"<?= ($s['protocol'] ?? '') === 'https' ? ' selected' : '' ?>>https</option>
                </select>
            </label>
            <label><span>API host</span><input name="api_host" type="text" value="<?= h((string) ($s['api_host'] ?? '')) ?>"></label>
        </div>
        <div class="row2">
            <label><span>API port</span><input name="api_port" type="text" value="<?= h((string) ($s['api_port'] ?? '')) ?>"></label>
            <label><span>CompanyId</span><input name="company_id" type="text" value="<?= h((string) ($s['company_id'] ?? '')) ?>"></label>
        </div>
        <div class="row2">
            <label><span>Partner kullanıcı (GetToken)</span><input name="partner_user" type="text" value="<?= h((string) ($s['partner_user'] ?? '')) ?>" autocomplete="off"></label>
            <label><span>Partner şifre</span><input name="partner_pass" type="password" value="" autocomplete="new-password" placeholder="Değişmezse boş"></label>
        </div>
        <div class="row2">
            <label><span>Capital kullanıcı (Connect)</span><input name="connect_user" type="text" value="<?= h((string) ($s['connect_user'] ?? '')) ?>" autocomplete="off"></label>
            <label><span>Capital şifre</span><input name="connect_pass" type="password" value="" autocomplete="new-password" placeholder="Değişmezse boş"></label>
        </div>
        <label class="cat"><input type="checkbox" name="clear_connect_pass" value="1"<?= trim((string) ($s['connect_pass'] ?? '')) === '' ? ' checked' : '' ?>> Capital şifresi yok (BYTE boş)</label>
        <p class="hint">Token: Partner kullanıcı (GetToken). Bayt oturumu: Capital kullanıcı BYTE, şifre boş. SQL user yok.</p>
        <label><span>GetToken URL</span><input name="token_url" type="text" value="<?= h((string) ($s['token_url'] ?? '')) ?>"></label>
        <label class="cat"><input type="checkbox" name="auto_create_customer" value="1"<?= ($s['auto_create_customer'] ?? '1') === '1' ? ' checked' : '' ?>> Cari yoksa Bayt’ta oluştur</label>
        <p class="hint">Yeni ana bayi kaydında sipariş beklemeden cari açılır. Alt hesaplar ayrı cari açmaz.</p>
        <label class="cat"><input type="checkbox" name="vat_included" value="1"<?= ($s['vat_included'] ?? '1') === '1' ? ' checked' : '' ?>> KDV dahil fiyat</label>
        <div class="row2">
            <label><span>Depo ID</span><input name="store_id" type="text" value="<?= h((string) ($s['store_id'] ?? '')) ?>"></label>
            <label><span>Döviz ID</span><input name="currency_id" type="text" value="<?= h((string) ($s['currency_id'] ?? '')) ?>"></label>
        </div>
        <div class="row2">
            <label><span>Birim ID</span><input name="unit_id" type="text" value="<?= h((string) ($s['unit_id'] ?? '')) ?>"></label>
            <label><span>Satış temsilcisi ID</span><input name="sales_rep_id" type="text" value="<?= h((string) ($s['sales_rep_id'] ?? '')) ?>"></label>
        </div>
        <label><span>Cari kod öneki</span><input name="customer_code_prefix" type="text" value="<?= h((string) ($s['customer_code_prefix'] ?? '')) ?>"></label>
        <div class="row2">
            <label><span>Kasa ID (nakit tahsilat)</span><input name="cash_id" type="text" value="<?= h((string) ($s['cash_id'] ?? '1')) ?>"></label>
            <label><span>Banka ID (havale yedek)</span><input name="bank_id" type="text" value="<?= h((string) ($s['bank_id'] ?? '-1')) ?>"></label>
        </div>
        <div class="row2">
            <label><span>Ziraat banka ID (ZIRAATPAY)</span><input name="bank_id_ziraat" type="text" value="<?= h((string) ($s['bank_id_ziraat'] ?? '12')) ?>"></label>
            <label><span>Kuveyt banka ID</span><input name="bank_id_kuveyt" type="text" value="<?= h((string) ($s['bank_id_kuveyt'] ?? '9')) ?>"></label>
        </div>
        <div class="row2">
            <label><span>Ziraat merchant</span><input name="pos_merchant_ziraat" type="text" value="<?= h((string) ($s['pos_merchant_ziraat'] ?? '00922')) ?>"></label>
            <label><span>Kuveyt merchant</span><input name="pos_merchant_kuveyt" type="text" value="<?= h((string) ($s['pos_merchant_kuveyt'] ?? '00205')) ?>"></label>
        </div>
        <p class="hint">Bulut kart tahsilatı <strong>bt_pos_bank</strong> + merchant ile yönlenir: ZIRAATPAY/00922 → banka <strong>12</strong> (aktif Ziraat), KUVEYTTÜRK/00205 → banka <strong>9</strong> (Kuveyt Beşevler). Bilinmeyen POS varsayılana (eski 5 / Garanti Euro) gitmez; manuel kalır. Nakit → kasa (ctCash).</p>
        <div class="toolbar">
            <button class="btn" type="submit" name="bayt_act" value="save">Ayarları kaydet</button>
            <button class="btn ghost" type="submit" name="bayt_act" value="test">Bağlantıyı test et</button>
            <button class="btn ghost" type="submit" name="bayt_act" value="push_dealers">Gönderilmemiş carileri yolla</button>
        </div>
    </div>
</form>
<?php if (!empty($pending)): ?>
<div class="card">
    <h2>Gönderilmemiş siparişler</h2>
    <div class="table-wrap">
        <table>
            <tr><th>Kod</th><th>Tutar</th><th>Durum</th><th></th></tr>
            <?php foreach ($pending as $o): ?>
                <tr>
                    <td><a href="<?= h(site_url('yonetim/siparisler/' . $o['code'] . '/')) ?>"><?= h($o['code']) ?></a></td>
                    <td><?= h(money($o['total'])) ?></td>
                    <td><?= h((string) ($o['bayt_status'] ?: 'bekliyor')) ?><?php if (!empty($o['bayt_error'])): ?><br><small><?= h($o['bayt_error']) ?></small><?php endif; ?></td>
                    <td>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                            <button class="btn" type="submit" name="bayt_act" value="send">Gönder</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<?php if (!empty($pendingCari)): ?>
<div class="card">
    <h2>Gönderilmemiş cari tahsilatlar</h2>
    <div class="table-wrap">
        <table>
            <tr><th>Tarih</th><th>Bayi</th><th>Tutar</th><th>Durum</th><th></th></tr>
            <?php foreach ($pendingCari as $p): ?>
                <tr>
                    <td><?= h((string) ($p['created_at'] ?? '')) ?></td>
                    <td><?= h((string) ($p['display_name'] ?: $p['username'] ?: ('#' . $p['company_id']))) ?></td>
                    <td><?= h(money((float) $p['amount'])) ?></td>
                    <td><?= h((string) ($p['bayt_status'] ?: 'bekliyor')) ?><?php if (!empty($p['bayt_error'])): ?><br><small><?= h($p['bayt_error']) ?></small><?php endif; ?></td>
                    <td>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="ledger_id" value="<?= (int) $p['id'] ?>">
                            <button class="btn" type="submit" name="bayt_act" value="send_collection">Gönder</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<p class="hint"><a href="<?= h(site_url('yonetim/entegrasyon/')) ?>">Entegrasyon log</a> — B2B / Bulut / Bayt tüm olaylar (7 gün).</p>
<?php if (!empty($log)): ?>
<div class="card">
    <h2>Son Bayt log</h2>
    <div class="table-wrap">
        <table>
            <tr><th>Tarih</th><th>Sipariş</th><th>Sonuç</th><th>Mesaj</th></tr>
            <?php foreach ($log as $row): ?>
                <tr>
                    <td><?= h($row['created_at']) ?></td>
                    <td><?= (int) $row['order_id'] ?></td>
                    <td><?= !empty($row['ok']) ? 'OK' : 'Hata' ?></td>
                    <td><?= h($row['message']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
