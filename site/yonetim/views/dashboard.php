<div class="page-head">
    <h1>Özet</h1>
    <p>Yeni siparişler, terk sepet ve yorum kuyruğu.</p>
</div>
<div class="stats">
    <a class="stat" href="<?= h(site_url('yonetim/urunler/')) ?>"><b><?= (int) $stats['products'] ?></b> ürün</a>
    <a class="stat hot" href="<?= h(site_url('yonetim/siparisler/')) ?>"><b><?= (int) $stats['orders'] ?></b> yeni sipariş</a>
    <a class="stat" href="<?= h(site_url('yonetim/siparisler/')) ?>"><b><?= (int) ($stats['pending'] ?? 0) ?></b> onay bekliyor</a>
    <div class="stat"><b><?= h(money($stats['revenue'])) ?></b> ciro</div>
    <a class="stat" href="<?= h(site_url('yonetim/sepetler/')) ?>"><b><?= (int) ($stats['carts'] ?? 0) ?></b> açık sepet</a>
    <a class="stat" href="<?= h(site_url('yonetim/yorumlar/')) ?>"><b><?= (int) ($stats['reviews'] ?? 0) ?></b> yorum bekliyor</a>
    <a class="stat" href="<?= h(site_url('yonetim/kullanicilar/')) ?>"><b><?= (int) ($stats['dealers'] ?? 0) ?></b> bayi</a>
    <a class="stat" href="<?= h(site_url('yonetim/mesajlar/')) ?>"><b><?= (int) $stats['messages'] ?></b> mesaj</a>
</div>
<p>
    <a class="btn" href="<?= h(site_url('yonetim/siparisler/yeni/')) ?>">Panelden sipariş</a>
</p>
<div class="card">
    <h2>Son siparişler</h2>
    <?php if (!$orders): ?>
        <p class="hint">Henüz sipariş yok.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table>
            <tr><th>Kod</th><th>Ad</th><th>Kanal</th><th>Tutar</th><th>Durum</th><th></th></tr>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td><a href="<?= h(site_url('yonetim/siparisler/' . $o['code'] . '/')) ?>"><?= h($o['code']) ?></a></td>
                    <td><?= h($o['name']) ?></td>
                    <td><?= h($o['channel'] ?? 'web') ?></td>
                    <td><?= h(money($o['total'])) ?></td>
                    <td><span class="badge badge-<?= h(hfs_order_badge($o)) ?>"><?= h(hfs_order_status_for($o)) ?></span></td>
                    <td>
                        <?php if (Catalog::canDelete($o)): ?>
                            <form method="post" onsubmit="return confirm('Bu sipariş silinsin mi?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="sil_kod" value="<?= h($o['code']) ?>">
                                <button class="textlink" type="submit">Sil</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    <?php endif; ?>
</div>
