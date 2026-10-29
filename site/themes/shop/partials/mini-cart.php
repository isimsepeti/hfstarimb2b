<?php
$miniCart = $miniCart ?? Cart::hydrated();
$miniLines = $miniCart['lines'] ?? [];
$miniCount = (int) ($miniCart['count'] ?? 0);
$backHere = page_url();
?>
<div class="cart-backdrop" data-cart-close></div>
<aside id="mini-cart" class="cart-drawer" aria-labelledby="mini-cart-title" aria-hidden="true">
    <header class="cart-drawer-head">
        <h2 id="mini-cart-title">Sepet<?= $miniCount > 0 ? ' <small>(' . $miniCount . ')</small>' : '' ?></h2>
        <button class="cart-drawer-close" type="button" data-cart-close aria-label="Sepeti kapat">×</button>
    </header>
    <?php if ($miniLines === []): ?>
        <div class="cart-drawer-empty">
            <p>Sepetin boş.</p>
            <a class="btn ghost" href="<?= h(site_url()) ?>" data-cart-close>Koleksiyona dön</a>
        </div>
    <?php else: ?>
        <ul class="cart-drawer-lines">
            <?php foreach ($miniLines as $line):
                $p = $line['product'];
                $vid = (int) ($line['variant']['id'] ?? 0);
                $qty = (int) $line['qty'];
                $cartNote = (string) ($line['cart_note'] ?? $line['note'] ?? '');
                $lineIx = (int) ($line['ix'] ?? -1);
                ?>
                <li>
                    <a href="<?= h(permalink_product($p)) ?>">
                        <img src="<?= h(hfs_product_thumb($p)) ?>" alt="" width="64" height="80">
                    </a>
                    <div>
                        <a href="<?= h(permalink_product($p)) ?>"><strong><?= h($line['title']) ?></strong></a>
                        <p><?= h(money($line['price'])) ?><?php if ($line['note']): ?> · <?= h($line['note']) ?><?php endif; ?></p>
                        <div class="cart-drawer-qty">
                            <form method="post" action="<?= h(site_url('sepet/')) ?>" data-cart-line>
                                <?= csrf_field() ?>
                                <input type="hidden" name="act" value="update">
                                <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                                <input type="hidden" name="variant_id" value="<?= $vid ?>">
                                <input type="hidden" name="line_ix" value="<?= $lineIx ?>">
                                <input type="hidden" name="note" value="<?= h($cartNote) ?>">
                                <input type="hidden" name="qty" value="<?= max(0, $qty - 1) ?>">
                                <input type="hidden" name="back" value="<?= h($backHere) ?>">
                                <button type="submit" aria-label="Azalt">−</button>
                            </form>
                            <span><?= $qty ?></span>
                            <form method="post" action="<?= h(site_url('sepet/')) ?>" data-cart-line>
                                <?= csrf_field() ?>
                                <input type="hidden" name="act" value="update">
                                <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                                <input type="hidden" name="variant_id" value="<?= $vid ?>">
                                <input type="hidden" name="line_ix" value="<?= $lineIx ?>">
                                <input type="hidden" name="note" value="<?= h($cartNote) ?>">
                                <input type="hidden" name="qty" value="<?= min(20, $qty + 1) ?>">
                                <input type="hidden" name="back" value="<?= h($backHere) ?>">
                                <button type="submit" aria-label="Artır"<?= $qty >= 20 ? ' disabled' : '' ?>>+</button>
                            </form>
                            <form method="post" action="<?= h(site_url('sepet/')) ?>" data-cart-line>
                                <?= csrf_field() ?>
                                <input type="hidden" name="act" value="update">
                                <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                                <input type="hidden" name="variant_id" value="<?= $vid ?>">
                                <input type="hidden" name="line_ix" value="<?= $lineIx ?>">
                                <input type="hidden" name="note" value="<?= h($cartNote) ?>">
                                <input type="hidden" name="qty" value="0">
                                <input type="hidden" name="back" value="<?= h($backHere) ?>">
                                <button class="textlink" type="submit">Kaldır</button>
                            </form>
                        </div>
                    </div>
                    <p class="line-sum"><?= h(money($line['line'])) ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
        <footer class="cart-drawer-foot">
            <?php if (($miniCart['shipping'] ?? 0) > 0): ?>
                <p class="hint"><?= h(money(free_ship_min() - (($miniCart['subtotal'] ?? 0) - ($miniCart['discount'] ?? 0)))) ?> daha ekle, kargo düşsün.</p>
            <?php endif; ?>
            <?php if (($miniCart['discount'] ?? 0) > 0): ?>
                <p>İndirim <strong>−<?= h(money($miniCart['discount'])) ?></strong></p>
            <?php endif; ?>
            <p>Kargo <strong><?= h(shipping_display_label((float) ($miniCart['shipping'] ?? 0))) ?></strong></p>
            <p class="tot">Toplam <strong><?= h(money($miniCart['total'] ?? 0)) ?></strong></p>
            <a class="btn" href="<?= h(site_url('odeme/')) ?>">Ödemeye geç</a>
            <a class="textlink" href="<?= h(site_url('sepet/')) ?>">Sepeti düzenle</a>
        </footer>
    <?php endif; ?>
</aside>
