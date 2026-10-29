<div class="page-head"><h1>Sayfalar</h1></div>
<div class="table-wrap">
<table>
    <tr><th>Başlık</th><th>Slug</th></tr>
    <?php foreach ($items as $p): ?>
        <tr>
            <td><a href="<?= h(site_url('yonetim/sayfalar/' . $p['id'] . '/')) ?>"><?= h($p['title']) ?></a></td>
            <td><?= h($p['slug']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>
</div>
