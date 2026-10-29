<?php $can = !empty($can); $items = $items ?? [];
$accTitle = 'Sipariş onayları';
$accLead = 'Onay limiti aşılan satın almacı siparişleri burada bekler. Onaylayan: onaylayıcı veya finans müdürü.';
require __DIR__ . '/partials/account-tabs.php';
?>
<?php if (!$can): ?>
    <p class="wrap hint">Bu hesap onaylayamaz. Firma yöneticinizden onaylayıcı veya finans müdürü rolü isteyin.</p>
<?php elseif (!$items): ?>
    <p class="wrap empty">Bekleyen onay yok.</p>
<?php else: ?>
    <ul class="wrap hfs-order-list">
        <?php foreach ($items as $o): ?>
            <li>
                <a href="<?= h(site_url('siparis/' . $o['code'] . '/')) ?>"><strong><?= h($o['code']) ?></strong></a>
                <?= h($o['name']) ?> · <?= h(money($o['total'])) ?>
                <small><?= h($o['created_at']) ?></small>
                <form method="post" class="hfs-approve-acts">
                    <?= csrf_field() ?>
                    <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                    <button class="btn" name="act" value="approve" type="submit">Onayla</button>
                    <button class="btn ghost" name="act" value="reject" type="submit">Reddet</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
