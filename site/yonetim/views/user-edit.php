<?php
$row = $row ?? null;
$isNew = empty($row);
$snap = $snap ?? null;
$admin = (current_user()['role'] ?? '') === 'admin';
$b2b = (string) ($row['b2b_role'] ?? 'company_admin');
$parent = (int) ($row['parent_user_id'] ?? 0);
?>
<div class="page-head">
    <div>
        <h1><?= $isNew ? 'Yeni bayi' : h($row['company'] ?: $row['display_name'] ?: $row['username']) ?></h1>
        <p><a href="<?= h(site_url('yonetim/kullanicilar/')) ?>">Bayiler</a></p>
    </div>
</div>
<?php if (!empty($ok)): ?>
    <p class="ok"><?= ($_GET['ok'] ?? '') === 'cari' ? 'Cari tahsilat kaydedildi.' : 'Kaydedildi.' ?></p>
<?php endif; ?>
<?php if (!empty($_GET['bayt'])): ?><p class="ok">Tahsilat Bayt muhasebeye gönderildi.</p><?php endif; ?>
<?php if (!empty($_GET['baytcari'])): ?><p class="ok">Bayt carisi açıldı (sipariş gerekmedi).</p><?php endif; ?>
<?php if (!empty($_GET['btexp'])): ?><p class="ok">BulutTahsilat cari kodu (PaymentExpCode) alındı.</p><?php endif; ?>
<?php if (!empty($_GET['baythata'])): ?><p class="err">Bayt: <?= h((string) $_GET['baythata']) ?></p><?php endif; ?>
<?php if (!empty($err)): ?><p class="err"><?= h((string) $err) ?></p><?php endif; ?>

<form class="stack dealer-form" method="post">
    <?= csrf_field() ?>
    <div class="card">
        <h2>Kimlik</h2>
        <div class="row2">
            <label>
                <span>Kullanıcı adı</span>
                <input name="username" type="text" required value="<?= h((string) ($row['username'] ?? '')) ?>" <?= $admin ? '' : 'readonly' ?>>
            </label>
            <label>
                <span>E-posta</span>
                <input name="email" type="email" required value="<?= h((string) ($row['email'] ?? '')) ?>">
            </label>
        </div>
        <div class="row2">
            <label>
                <span>Unvan / ad soyad</span>
                <input name="display_name" type="text" value="<?= h((string) ($row['display_name'] ?? '')) ?>">
            </label>
            <label>
                <span>Firma</span>
                <input name="company" type="text" value="<?= h((string) ($row['company'] ?? '')) ?>">
            </label>
        </div>
        <label>
            <span>Telefon</span>
            <input name="phone" type="text" value="<?= h((string) ($row['phone'] ?? '')) ?>">
        </label>
        <?php
        $requireTrAddress = $parent === 0 && (($row['role'] ?? 'dealer') === 'dealer');
        require BMN_ROOT . '/themes/shop/partials/address-tr.php';
        ?>
        <label>
            <span>Adres</span>
            <span class="field-hint">Bayt cari kartına gider (ülke TR, il / ilçe / posta kodu zorunlu)</span>
            <textarea name="address" rows="2"<?= $requireTrAddress ? ' required' : '' ?>><?= h((string) ($row['address'] ?? '')) ?></textarea>
        </label>
        <div class="row2">
            <label>
                <span>Vergi dairesi</span>
                <span class="field-hint">BulutTahsilat ve Bayt cari kaydına gider</span>
                <input name="tax_office" type="text" value="<?= h((string) ($row['tax_office'] ?? '')) ?>" <?= $parent > 0 ? '' : 'required' ?>>
            </label>
            <label>
                <span>Vergi / T.C. no</span>
                <span class="field-hint">VKN 10, T.C. 11 hane</span>
                <input name="tax_number" type="text" inputmode="numeric" maxlength="11" value="<?= h((string) ($row['tax_number'] ?? '')) ?>" <?= $parent > 0 ? '' : 'required' ?>>
            </label>
        </div>
        <div class="row2">
            <label>
                <span>Şifre</span>
                <?php if (!$isNew): ?><span class="field-hint">Boş bırakırsanız değişmez</span><?php endif; ?>
                <input name="password" type="password" <?= $isNew ? 'required minlength="8"' : 'minlength="8"' ?> autocomplete="new-password">
            </label>
            <label>
                <span>Hesap türü</span>
                <select name="role" <?= $admin ? '' : 'disabled' ?>>
                    <option value="dealer"<?= (($row['role'] ?? 'dealer') !== 'admin') ? ' selected' : '' ?>>Bayi</option>
                    <option value="admin"<?= (($row['role'] ?? '') === 'admin') ? ' selected' : '' ?>>Yönetici</option>
                </select>
            </label>
        </div>
    </div>
    <div class="card">
        <h2>Kurumsal</h2>
        <div class="row2">
            <label>
                <span>Ana bayi</span>
                <span class="field-hint">Alt hesapsa ana firmayı seçin</span>
                <select name="parent_user_id">
                    <option value="0">Ana hesap</option>
                    <?php foreach ($mains as $m): ?>
                        <option value="<?= (int) $m['id'] ?>"<?= $parent === (int) $m['id'] ? ' selected' : '' ?>>
                            <?= h(($m['company'] ?: $m['display_name'] ?: $m['username'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Bayi rolü</span>
                <select name="b2b_role">
                    <?php foreach (HfsDealers::b2bRoles() as $k => $lab): ?>
                        <option value="<?= h($k) ?>"<?= $b2b === $k ? ' selected' : '' ?>><?= h($lab) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </div>
    <div class="card">
        <h2>Fiyat ve limit</h2>
        <div class="row2">
            <label>
                <span>İskonto %</span>
                <input name="discount_percent" type="number" step="0.01" min="0" max="100" value="<?= h((string) ($row['discount_percent'] ?? '0')) ?>">
            </label>
            <label>
                <span>Kademe min. adet</span>
                <input name="tier_min_qty" type="number" min="0" value="<?= h((string) ($row['tier_min_qty'] ?? '0')) ?>">
            </label>
        </div>
        <div class="row2">
            <label>
                <span>Kademe ekstra iskonto %</span>
                <input name="tier_discount" type="number" step="0.01" min="0" max="100" value="<?= h((string) ($row['tier_discount'] ?? '0')) ?>">
            </label>
            <label>
                <span>Kredi limiti (TL)</span>
                <span class="field-hint">0 = tanımsız</span>
                <input name="credit_limit" type="number" step="0.01" min="0" value="<?= h((string) ($row['credit_limit'] ?? '0')) ?>">
            </label>
        </div>
        <div class="row2">
            <label>
                <span>Açılış bakiyesi</span>
                <span class="field-hint">Borç artı (+) yazılır</span>
                <input name="opening_balance" type="number" step="0.01" value="<?= h((string) ($row['opening_balance'] ?? '0')) ?>">
            </label>
            <label>
                <span>Aylık satınalma limiti</span>
                <input name="monthly_limit" type="number" step="0.01" min="0" value="<?= h((string) ($row['monthly_limit'] ?? '0')) ?>">
            </label>
        </div>
        <div class="row2">
            <label>
                <span>Onay limiti (TL)</span>
                <span class="field-hint">Satın almacı bu tutarı aşınca sipariş onay bekler. 0 = kapalı</span>
                <input name="approval_limit" type="number" step="0.01" min="0" value="<?= h((string) ($row['approval_limit'] ?? '0')) ?>">
            </label>
            <label>
                <span>Bayt cari kodu</span>
                <input name="bayt_customer_code" type="text" value="<?= h((string) ($row['bayt_customer_code'] ?? '')) ?>">
            </label>
        </div>
        <label>
            <span>Bayt cari ID</span>
            <input name="bayt_customer_id" type="number" min="0" value="<?= h((string) ($row['bayt_customer_id'] ?? '0')) ?>">
        </label>
        <label>
            <span>BulutTahsilat PaymentExpCode</span>
            <input name="bt_exp_code" type="text" value="<?= h((string) ($row['bt_exp_code'] ?? '')) ?>" placeholder="PN…">
        </label>
        <p class="hint">Cari kart ödemesinde listede görünen unvan bu koda bağlıdır. Boşsa kayıtta SubFirmAddNew ile alınır; bunun için Ayarlar’daki WEB API kullanıcısı gerekir. Sanal POS kullanıcısı cari açamaz. WEB API yoksa kodu buraya yapıştırın.</p>
        <?php if (!$isNew && $admin && (int) ($row['parent_user_id'] ?? 0) === 0 && ($row['role'] ?? '') === 'dealer'): ?>
            <?php if ((int) ($row['bayt_customer_id'] ?? 0) < 1): ?>
                <button class="btn ghost" type="submit" name="bayt_push_customer" value="1">Bayt’a cari gönder</button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php if (!$isNew): ?>
    <div class="card">
        <h2>Ürün teklifleri</h2>
        <p class="hint">Özel fiyat liste iskontosunun yerine geçer. Miktar fiyat: 50:120, 100:99. Hediye: 50:2, 100:10</p>
        <?php
        $offers = $offers ?? [];
        $offers[] = ['product_id' => 0, 'sku' => '', 'price' => '', 'gifts' => '', 'qty_prices' => ''];
        $offers[] = ['product_id' => 0, 'sku' => '', 'price' => '', 'gifts' => '', 'qty_prices' => ''];
        ?>
        <?php foreach ($offers as $of): ?>
            <div class="toolbar">
                <input type="hidden" name="offer_pid[]" value="<?= (int) ($of['product_id'] ?? 0) ?>">
                <input name="offer_sku[]" type="text" placeholder="SKU" value="<?= h((string) ($of['sku'] ?? '')) ?>">
                <input name="offer_price[]" type="number" step="0.01" min="0" placeholder="Düz fiyat" value="<?= h((string) ($of['price'] ?? '')) ?>">
                <input name="offer_qty[]" type="text" placeholder="50:120, 100:99" value="<?= h((string) ($of['qty_prices'] ?? '')) ?>">
                <input name="offer_gifts[]" type="text" placeholder="50:2" value="<?= h((string) ($of['gifts'] ?? '')) ?>">
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($admin): ?>
        <button class="btn" type="submit">Kaydet</button>
    <?php endif; ?>
</form>

<?php if ($snap): ?>
<section class="card">
    <h2>Cari özet</h2>
    <div class="stats">
        <div class="stat"><b><?= h(money($snap['total_open'])) ?></b> açık bakiye</div>
        <div class="stat"><b><?= h(money($snap['exposure'])) ?></b> risk (açılış + açık sipariş)</div>
        <div class="stat"><b><?= h(money($snap['cari_paid'])) ?></b> tahsilat</div>
        <div class="stat"><b><?= h(money($snap['open_orders'])) ?></b> açık sipariş</div>
    </div>
    <?php if ($admin): ?>
        <form class="toolbar" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="manual_cari" value="1">
            <input name="cari_amount" type="number" step="0.01" min="0.01" placeholder="Manuel tahsilat (TL)" required>
            <input name="cari_note" type="text" placeholder="Not (nakit / havale / kart)">
            <button class="btn" type="submit">Cariye işle</button>
        </form>
        <p class="hint">Bayt açıksa tahsilat muhasebeye (kasa tahsilatı) gider.</p>
    <?php endif; ?>
    <?php if (!empty($snap['payments'])): ?>
        <h2>Tahsilat geçmişi</h2>
        <div class="table-wrap">
            <table>
                <tr><th>Tarih</th><th>Tutar</th><th>Not</th><th>Bayt</th></tr>
                <?php foreach ($snap['payments'] as $p): ?>
                    <tr>
                        <td><?= h($p['created_at']) ?></td>
                        <td><?= h(money($p['amount'])) ?></td>
                        <td><?= h($p['note'] ?? '') ?><?php if (($p['status'] ?? '') === 'iptal'): ?> · <strong>İptal</strong><?php endif; ?></td>
                        <td>
                            <?php
                            $bst = (string) ($p['bayt_status'] ?? '');
                            if (($p['status'] ?? '') === 'iptal'): ?>iptal<?php if ($bst === 'deleted'): ?> · muhasebeden silindi<?php endif; ?>
                            <?php elseif ($bst === 'sent'): ?>gönderildi<?php if (!empty($p['bayt_ref'])): ?> · <?= h((string) $p['bayt_ref']) ?><?php endif; ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('Tahsilat sistemde ve muhasebede iptal edilsin mi?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="cari_void" value="1">
                                    <input type="hidden" name="ledger_id" value="<?= (int) $p['id'] ?>">
                                    <button class="btn ghost" type="submit">İptal et</button>
                                </form>
                            <?php elseif ($bst === 'error'): ?>
                                <small><?= h((string) ($p['bayt_error'] ?? 'hata')) ?></small>
                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="bayt_cari_send" value="1">
                                    <input type="hidden" name="ledger_id" value="<?= (int) $p['id'] ?>">
                                    <button class="btn ghost" type="submit">Tekrar gönder</button>
                                </form>
                                <form method="post" style="display:inline" onsubmit="return confirm('Tahsilat iptal edilsin mi?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="cari_void" value="1">
                                    <input type="hidden" name="ledger_id" value="<?= (int) $p['id'] ?>">
                                    <button class="btn ghost" type="submit">İptal et</button>
                                </form>
                            <?php else: ?>
                                bekliyor
                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="bayt_cari_send" value="1">
                                    <input type="hidden" name="ledger_id" value="<?= (int) $p['id'] ?>">
                                    <button class="btn ghost" type="submit">Muhasebeye gönder</button>
                                </form>
                                <form method="post" style="display:inline" onsubmit="return confirm('Tahsilat iptal edilsin mi?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="cari_void" value="1">
                                    <input type="hidden" name="ledger_id" value="<?= (int) $p['id'] ?>">
                                    <button class="btn ghost" type="submit">İptal et</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
    <?php if (!empty($snap['orders'])): ?>
        <h2>Son siparişler</h2>
        <div class="table-wrap">
            <table>
                <tr><th>Kod</th><th>Durum</th><th>Tutar</th><th>Tarih</th></tr>
                <?php foreach (array_slice($snap['orders'], 0, 12) as $o): ?>
                    <tr>
                        <td><a href="<?= h(site_url('yonetim/siparisler/' . $o['code'] . '/')) ?>"><?= h($o['code']) ?></a></td>
                        <td><?= h($o['status']) ?></td>
                        <td><?= h(money($o['total'])) ?></td>
                        <td><?= h($o['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<script src="<?= h(asset('hfs-address.js')) ?>" defer></script>
<link rel="stylesheet" href="<?= h(asset('hfs.css')) ?>">
