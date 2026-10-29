<?php $ok = !empty($ok); ?>
<div class="page-head">
    <h1>Yazılar</h1>
    <a class="btn" href="<?= h(site_url('yonetim/yazilar/yeni/')) ?>">Yeni yazı</a>
</div>
<?php if ($ok): ?>
    <p class="ok">Yazı silindi.</p>
<?php endif; ?>
<div class="table-wrap">
<table>
    <tr><th>Başlık</th><th>Durum</th><th></th></tr>
    <?php foreach ($items as $p): ?>
        <tr>
            <td><a href="<?= h(site_url('yonetim/yazilar/' . $p['id'] . '/')) ?>"><?= h($p['title']) ?></a></td>
            <td><?= h($p['status']) ?></td>
            <td>
                <form method="post" onsubmit="return confirm('Bu yazı silinsin mi?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <button class="textlink" type="submit">Sil</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
</div>
