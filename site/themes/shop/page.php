<?php require __DIR__ . '/partials/crumbs.php'; ?>
<article class="page wrap">
    <h1><?= h($post['title']) ?></h1>
    <div class="prose"><?= $post['content'] ?></div>
    <?php if (!empty($isContact)): ?>
        <?php if (!empty($contactOk)): ?><p class="ok">Mesajın alındı.</p><?php endif; ?>
        <?php if (!empty($contactErr)): ?><p class="err">Formu kontrol et.</p><?php endif; ?>
        <form class="stack" method="post" action="<?= h(site_url('iletisim/')) ?>">
            <?= csrf_field() ?>
            <p class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></p>
            <label>Ad <input name="name" required></label>
            <label>E-posta <input name="email" type="email" required></label>
            <label>Konu <input name="subject"></label>
            <label>Mesaj <textarea name="message" rows="6" required></textarea></label>
            <button class="btn" type="submit">Gönder</button>
        </form>
    <?php endif; ?>
</article>
