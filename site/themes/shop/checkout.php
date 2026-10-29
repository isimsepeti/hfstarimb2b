<?php
$u = $dealer ?? current_user();
$errMsg = is_string($err ?? null) && $err !== '' && $err !== '1' ? $err : (!empty($err) ? 'Eksik alan veya ödeme hatası.' : '');
$blocked = !empty($mins) || (!empty($gate) && empty($gate['ok']));
$phoneVal = trim((string) ($u['phone'] ?? ''));
?>
<div class="hfs-checkout wrap">
    <header class="hfs-checkout__head">
        <p class="hfs-checkout__kicker">Sipariş</p>
        <h1>Ödeme</h1>
        <p>Teslimat adresini kontrol edin, ödeme yöntemini seçin. Kart bilgisi bu sitede tutulmaz.</p>
    </header>

    <?php if ($errMsg): ?><p class="err"><?= h($errMsg) ?></p><?php endif; ?>
    <?php if (!empty($mins)): ?>
        <?php foreach ($mins as $m): ?><p class="err"><?= h($m) ?></p><?php endforeach; ?>
    <?php endif; ?>
    <?php if (!empty($gate) && empty($gate['ok'])): ?>
        <p class="err"><?= h($gate['msg']) ?></p>
    <?php endif; ?>

    <?php if (!empty($snap)): ?>
    <section class="hfs-cari-summary" aria-label="Cari özet">
        <article class="hfs-cari-card"><span>Açık bakiye</span><strong><?= h(money($snap['total_open'])) ?></strong></article>
        <article class="hfs-cari-card"><span>Kredi limiti</span><strong><?= $snap['credit_limit'] > 0 ? h(money($snap['credit_limit'])) : 'Tanımsız' ?></strong></article>
        <article class="hfs-cari-card"><span>Kalan limit</span><strong><?= h(money($snap['remaining_limit'])) ?></strong></article>
    </section>
    <?php endif; ?>

    <div class="hfs-checkout__grid">
        <form class="hfs-checkout__form" method="post">
            <?= csrf_field() ?>
            <p class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></p>

            <section class="hfs-checkout__card">
                <h2>Teslimat bilgileri</h2>
                <div class="hfs-checkout__fields">
                    <label>Ad soyad / unvan <input name="name" required autocomplete="name" value="<?= h($u['display_name'] ?? $u['company'] ?? '') ?>"></label>
                    <div class="row2">
                        <label>E-posta <input name="email" type="email" required autocomplete="email" value="<?= h($savedEmail ?? $u['email'] ?? '') ?>"></label>
                        <label>Telefon <input name="phone" type="tel" required autocomplete="tel" inputmode="tel" placeholder="5xxxxxxxxx" value="<?= h($phoneVal) ?>"></label>
                    </div>
                    <div class="hfs-checkout__addr">
                        <label>Ülke
                            <select name="country">
                                <?php foreach ($countries as $code => $lab): ?>
                                    <option value="<?= h($code) ?>"<?= ($country ?? 'TR') === $code ? ' selected' : '' ?>><?= h($lab) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>İl <input name="city" required autocomplete="address-level1" value="<?= h($u['city'] ?? '') ?>"></label>
                        <label>İlçe <input name="district" autocomplete="address-level2" value="<?= h($u['district'] ?? '') ?>"></label>
                    </div>
                    <label>Adres <textarea name="address" rows="3" required autocomplete="street-address"><?= h($u['address'] ?? '') ?></textarea></label>
                </div>
            </section>

            <section class="hfs-checkout__card">
                <h2>Ödeme yöntemi</h2>
                <fieldset class="hfs-pay">
                    <legend class="hp">Ödeme</legend>
                    <label class="hfs-pay__opt">
                        <input type="radio" name="payment" value="havale" checked>
                        <span>
                            <strong>Havale / EFT</strong>
                            <small>Sipariş sonrası IBAN’a transfer</small>
                        </span>
                    </label>
                    <?php if (!empty($openOk['ok'])): ?>
                        <label class="hfs-pay__opt">
                            <input type="radio" name="payment" value="acik_hesap">
                            <span>
                                <strong>Açık hesap</strong>
                                <small>Cari bakiyeye işlenir, fatura ile kapanır</small>
                            </span>
                        </label>
                    <?php elseif (!empty($openOk['msg'])): ?>
                        <p class="hint"><?= h($openOk['msg']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($btOn)): ?>
                        <label class="hfs-pay__opt">
                            <input type="radio" name="payment" value="bulut">
                            <span>
                                <strong>Kredi kartı</strong>
                                <small>BulutTahsilat güvenli ödeme sayfası</small>
                            </span>
                        </label>
                    <?php endif; ?>
                </fieldset>
            </section>

            <section class="hfs-checkout__card hfs-checkout__card--mute">
                <h2>Ek bilgiler <span>(isteğe bağlı)</span></h2>
                <div class="hfs-checkout__fields">
                    <label>Satın alma sipariş no (PO) <input name="po_number" maxlength="80" placeholder="Yoksa boş bırakın"></label>
                    <label>Sipariş notu <textarea name="note" rows="2" placeholder="Teslimat veya fatura notu"></textarea></label>
                </div>
            </section>

            <section class="hfs-checkout__card hfs-checkout__consents" aria-label="Yasal onaylar">
                <h2>Yasal onaylar</h2>
                <label class="hfs-checkout__consent">
                    <input type="checkbox" name="consent_kvkk" value="1" required>
                    <span><a href="<?= h(site_url('privacy/')) ?>" data-hfs-legal="privacy">KVKK aydınlatma metnini</a> okudum; kişisel verilerimin belirtilen amaçlarla işlenmesini kabul ediyorum.</span>
                </label>
                <label class="hfs-checkout__consent">
                    <input type="checkbox" name="consent_terms" value="1" required>
                    <span><a href="<?= h(site_url('terms/')) ?>" data-hfs-legal="terms">Bayi sipariş ve ödeme koşullarını</a> okudum ve kabul ediyorum.</span>
                </label>
            </section>

            <button class="btn hfs-checkout__submit" type="submit"<?= $blocked ? ' disabled' : '' ?>>
                Siparişi tamamla · <?= h(money($cart['total'])) ?>
            </button>
        </form>

        <aside class="hfs-checkout__sum">
            <h2>Sipariş özeti</h2>
            <ul>
                <?php foreach ($cart['lines'] as $line): ?>
                    <li>
                        <span>
                            <?= (int) $line['qty'] ?>× <?= h($line['title']) ?>
                            <?php if (!empty($line['free_qty'])): ?><small><?= (int) $line['free_qty'] ?> bedelsiz</small><?php endif; ?>
                        </span>
                        <strong><?= h(money($line['line'])) ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($cart['discount'] > 0): ?>
                <p>İndirim <strong>−<?= h(money($cart['discount'])) ?></strong></p>
            <?php endif; ?>
            <p>Kargo <strong><?= h(shipping_display_label((float) ($cart['shipping'] ?? 0))) ?></strong></p>
            <p class="tot">Toplam <strong><?= h(money($cart['total'])) ?></strong></p>
            <?php if ((string) setting('iban', '') !== ''): ?>
                <p class="hfs-checkout__iban">IBAN <?= h((string) setting('iban')) ?><br><?= h((string) setting('iban_name', 'HFS Tarım')) ?></p>
            <?php endif; ?>
            <p class="hfs-checkout__secure">Kart bilgisi HFS sunucusunda saklanmaz.</p>
        </aside>
    </div>
</div>
