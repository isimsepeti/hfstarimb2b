<div class="page-head"><h1>Panelden sipariş</h1></div>
<form class="stack dealer-form" method="post">
    <?= csrf_field() ?>
    <div class="card">
        <h2>Sipariş</h2>
        <div class="row2">
            <label>
                <span>Kanal</span>
                <select name="channel">
                    <option value="panel">Panel</option>
                    <option value="trendyol">Trendyol</option>
                    <option value="hepsiburada">Hepsiburada</option>
                    <option value="n11">N11</option>
                    <option value="amazon">Amazon</option>
                    <option value="etsy">Etsy</option>
                </select>
            </label>
            <label>
                <span>Adet</span>
                <input name="qty" type="number" value="1" min="1">
            </label>
        </div>
        <label>
            <span>Ürün</span>
            <select name="product_id" required>
                <?php foreach ($products as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"><?= h($p['title']) ?> — <?= h(money($p['price'])) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="card">
        <h2>Müşteri</h2>
        <div class="row2">
            <label><span>Ad soyad</span><input name="name" type="text" required></label>
            <label><span>E-posta</span><input name="email" type="email" required></label>
        </div>
        <div class="row2">
            <label><span>Telefon</span><input name="phone" type="text" required></label>
            <label><span>İl</span><input name="city" type="text" required></label>
        </div>
        <label><span>Adres</span><textarea name="address" rows="2" required></textarea></label>
    </div>
    <button class="btn" type="submit">Sipariş oluştur</button>
</form>
<p class="hint">Pazaryeri API’si yok; gelen siparişi buradan kaydedersin, stok düşer.</p>
