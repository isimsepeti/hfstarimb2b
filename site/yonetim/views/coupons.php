<div class="page-head"><h1>Kuponlar</h1></div>
<form class="card stack" method="post">
    <?= csrf_field() ?>
    <div class="toolbar">
        <input name="code" type="text" placeholder="KOD10" required>
        <select name="type">
            <option value="percent">Yüzde</option>
            <option value="fixed">Tutar</option>
            <option value="free_ship">Ücretsiz kargo</option>
        </select>
        <input name="value" type="number" step="0.01" placeholder="10">
        <input name="min_subtotal" type="number" placeholder="Min sepet">
        <input name="max_uses" type="number" placeholder="Max kullanım (0=sınırsız)">
        <button class="btn" type="submit">Ekle</button>
    </div>
</form>
<div class="table-wrap">
<table>
    <tr><th>Kod</th><th>Tür</th><th>Değer</th><th>Min</th><th>Kullanım</th></tr>
    <?php foreach ($items as $c): ?>
        <tr>
            <td><?= h($c['code']) ?></td>
            <td><?= h($c['type']) ?></td>
            <td><?= h((string) $c['value']) ?></td>
            <td><?= h(money($c['min_subtotal'])) ?></td>
            <td><?= (int) $c['used'] ?> / <?= (int) $c['max_uses'] ?></td>
        </tr>
    <?php endforeach; ?>
</table>
</div>
<p class="hint">Sepet terk ikinci e-postası SEP10 kullanır. Katlı indirim ayarlardan yüzde olarak açılır.</p>
