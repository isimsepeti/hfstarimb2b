<header class="archive-head wrap">
    <h1>Sepet</h1>
    <p><?= (int) $cart['count'] ?> ürün. Bu sayfa indekslenmez.</p>
</header>
<?php if (!empty($_GET['hatirlat'])): ?><p class="wrap ok">E-posta kaydı alındı. Sepeti bırakırsan hatırlatırız.</p><?php endif; ?>
<?php if (!empty($couponErr)): ?><p class="wrap err">Kupon geçersiz veya min tutar yok.</p><?php endif; ?>
<?php if (($_GET['sepet_hata'] ?? '') === 'csrf'): ?><p class="wrap err">Oturum doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.</p><?php endif; ?>
<?php if ($cart['lines'] === []): ?>
    <p class="wrap empty">Sepet boş. <a href="<?= h(site_url()) ?>">Koleksiyona dön</a>.</p>
    <form method="post" class="wrap" style="margin-top:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="clear">
        <button class="btn ghost" type="submit">Sepet kaydını sıfırla</button>
    </form>
<?php else: ?>
<div class="cart wrap">
    <ul class="cart-lines">
        <?php foreach ($cart['lines'] as $line):
            $p = $line['product'];
            $vid = (int) ($line['variant']['id'] ?? 0);
            $cartNote = (string) ($line['cart_note'] ?? $line['note'] ?? '');
            $lineIx = (int) ($line['ix'] ?? -1);
            ?>
            <li>
                <a href="<?= h(permalink_product($p)) ?>">
                    <img src="<?= h(hfs_product_thumb($p)) ?>" alt="" width="72" height="90">
                </a>
                <div>
                    <a href="<?= h(permalink_product($p)) ?>"><strong><?= h($line['title']) ?></strong></a>
                    <p><?= h(money($line['price'])) ?>
                        <?php if (!empty($line['free_qty'])): ?> · <strong><?= (int) $line['free_qty'] ?> adet bedelsiz</strong><?php endif; ?>
                        <?php if (!empty($line['qty_note'])): ?> · <?= h($line['qty_note']) ?><?php endif; ?>
                        <?php if ($line['note']): ?> · <?= h($line['note']) ?><?php endif; ?></p>
                    <form method="post" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                        <?= csrf_field() ?>
                        <input type="hidden" name="act" value="update">
                        <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                        <input type="hidden" name="variant_id" value="<?= $vid ?>">
                        <input type="hidden" name="line_ix" value="<?= $lineIx ?>">
                        <input type="hidden" name="note" value="<?= h($cartNote) ?>">
                        <label>Adet <input type="number" name="qty" value="<?= (int) $line['qty'] ?>" min="0" max="999"></label>
                        <button class="btn ghost" type="submit">Güncelle</button>
                        <button class="textlink" type="submit" name="qty" value="0">Kaldır</button>
                    </form>
                </div>
                <p class="line-sum"><?= h(money($line['line'])) ?></p>
            </li>
        <?php endforeach; ?>
    </ul>
    <aside class="cart-sum">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="coupon">
            <label>Kupon <input name="code" value="<?= h($cart['coupon_code']) ?>" placeholder="BMN10"></label>
            <button class="btn ghost" type="submit">Uygula</button>
        </form>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="remind">
            <label>Hatırlatma e-postası <input type="email" name="email" value="<?= h($savedEmail ?? '') ?>" required></label>
            <button class="btn ghost" type="submit">Kaydet</button>
        </form>
        <p>Ara toplam <strong><?= h(money($cart['subtotal'])) ?></strong></p>
        <?php if ($cart['discount'] > 0): ?>
            <p>İndirim <strong>−<?= h(money($cart['discount'])) ?></strong></p>
        <?php endif; ?>
        <p>Kargo <strong><?= h(shipping_display_label((float) ($cart['shipping'] ?? 0))) ?></strong></p>
        <p class="tot">Toplam <strong><?= h(money($cart['total'])) ?></strong></p>
        <?php if ($cart['shipping'] > 0): ?>
            <p class="hint"><?= h(money(free_ship_min() - ($cart['subtotal'] - $cart['discount']))) ?> daha ekle, kargo düşsün.</p>
        <?php endif; ?>
        <a class="btn" href="<?= h(site_url('odeme/')) ?>">Ödemeye geç</a>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="clear">
            <button class="textlink" type="submit">Sepeti boşalt</button>
        </form>
    </aside>
</div>
<?php if (!empty($cross)): ?>
<section class="shelf wrap">
    <header class="sec-head"><h2>Sepete ekle</h2></header>
    <div class="pgrid">
        <?php require_once __DIR__ . '/partials/card.php';
        foreach ($cross as $rp) { shop_card($rp); } ?>
    </div>
</section>
<?php endif; ?>
<?php endif; ?>
