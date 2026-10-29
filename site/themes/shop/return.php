<article class="page wrap">
    <h1>İade talebi</h1>
    <?php if (!empty($ok)): ?><p class="ok">Talep alındı. Panelden dönüş yaparız.</p><?php endif; ?>
    <?php if (!empty($err)): ?><p class="err">Kod ve e-posta siparişle eşleşmedi.</p><?php endif; ?>
    <form class="stack" method="post">
        <?= csrf_field() ?>
        <p class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></p>
        <label>Sipariş kodu <input name="code" required placeholder="BMN-XXXXXX"></label>
        <label>Sipariş e-postası <input name="email" type="email" required></label>
        <label>Neden <textarea name="reason" rows="4" required></textarea></label>
        <button class="btn" type="submit">Gönder</button>
    </form>
</article>
