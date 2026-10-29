<div class="page-head">
    <h1>Bayi teklifleri</h1>
    <p>Bayiye özel fiyat ve miktar kademesi. Örnek: 50 adet → 120 ₺ yazımı <code>50:120</code>. Hediye ayrı: <code>50:2</code>.</p>
</div>
<form class="card stack" method="post">
    <?= csrf_field() ?>
    <div class="row2">
        <label>
            <span>Bayi</span>
            <select name="company_id" required>
                <option value="">—</option>
                <?php foreach ($dealers as $d): ?>
                    <option value="<?= (int) $d['id'] ?>"><?= h($d['company'] ?: $d['display_name'] ?: $d['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>SKU</span><input name="sku" type="text" required placeholder="Ürün kodu"></label>
    </div>
    <div class="row2">
        <label><span>Düz fiyat (TL)</span><input name="price" type="number" step="0.01" min="0" placeholder="Boş = yok"></label>
        <label><span>Miktar → fiyat</span><input name="qty_prices" type="text" placeholder="50:120, 100:99"></label>
    </div>
    <label><span>Bedelsiz kademe</span><input name="gifts" type="text" placeholder="50:2, 100:10"></label>
    <button class="btn" type="submit">Kaydet</button>
</form>
<div class="table-wrap">
<table>
    <tr><th>Bayi</th><th>SKU</th><th>Ürün</th><th>Düz fiyat</th><th>Miktar fiyat</th><th>Hediye</th><th></th></tr>
    <?php foreach ($items as $o): ?>
        <tr>
            <td><?= h($o['company'] ?: $o['display_name'] ?: $o['username']) ?></td>
            <td><?= h($o['sku']) ?></td>
            <td><?= h($o['title']) ?></td>
            <td><?= $o['price'] !== null && $o['price'] !== '' ? h(money($o['price'])) : '—' ?></td>
            <td><?= h($o['qty_prices'] ?: '—') ?></td>
            <td><?= h($o['gifts'] ?: '—') ?></td>
            <td>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="del_cid" value="<?= (int) $o['company_id'] ?>">
                    <input type="hidden" name="del_pid" value="<?= (int) $o['product_id'] ?>">
                    <button class="textlink" type="submit">Sil</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
</div>
