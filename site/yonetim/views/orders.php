<div class="page-head"><h1>Siparişler</h1></div>
<div class="table-wrap table-wrap--fit">
<table class="orders-table">
    <tr>
        <th>Kod</th>
        <th>Ad</th>
        <th>Tutar</th>
        <th>Durum</th>
        <th>Bulut</th>
        <th>Bayt</th>
        <th>Tarih</th>
        <th></th>
    </tr>
    <?php foreach ($items as $o):
        $bulut = hfs_order_bulut_sync($o);
        $bayt = hfs_order_bayt_sync($o);
        ?>
        <tr>
            <td><a href="<?= h(site_url('yonetim/siparisler/' . $o['code'] . '/')) ?>"><?= h($o['code']) ?></a></td>
            <td><?= h($o['name']) ?><br><small><?= h($o['email']) ?></small></td>
            <td class="nowrap"><?= h(money($o['total'])) ?></td>
            <td><span class="badge badge-<?= h(hfs_order_badge($o)) ?>"><?= h(hfs_order_status_for($o)) ?></span></td>
            <td>
                <span class="sync-pill <?= h($bulut['class']) ?>" title="<?= h($bulut['title']) ?>"><?= h($bulut['label']) ?></span>
            </td>
            <td>
                <span class="sync-pill <?= h($bayt['class']) ?>" title="<?= h($bayt['title']) ?>"><?= h($bayt['label']) ?></span>
            </td>
            <td class="nowrap"><?= h($o['created_at']) ?></td>
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
