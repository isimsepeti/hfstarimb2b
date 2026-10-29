<article class="page wrap">
    <h1>Siparişi değerlendir</h1>
    <?php if (!empty($ok)): ?>
        <p class="ok">Yorumun alındı, yayına alınca üründe görünür.</p>
    <?php elseif ($order): ?>
        <p><?= h($order['code']) ?></p>
        <form class="stack" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <label>Ürün
                <select name="product_id" required>
                    <?php foreach ($order['items'] as $it): ?>
                        <option value="<?= (int) $it['product_id'] ?>"><?= h($it['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Puan
                <select name="rating">
                    <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?></option><?php endfor; ?>
                </select>
            </label>
            <label>Yorum <textarea name="body" rows="5" required></textarea></label>
            <label>Görsel (jpg/png/webp, max 1 MB) <input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
            <button class="btn" type="submit">Gönder</button>
        </form>
    <?php endif; ?>
</article>
