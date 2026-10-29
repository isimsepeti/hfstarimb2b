<div class="page-head">
    <h1>Varyant özellikleri</h1>
    <p>Kahve ve benzeri ürünlerde gramaj, öğütme, kavurma gibi seçenekler. Ürün kartında işaretleyip kombinasyon üretirsin.</p>
</div>
<form class="card stack" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="add_group">
    <h2>Yeni özellik grubu</h2>
    <div class="row2">
        <label><span>Ad</span><input name="name" type="text" required placeholder="Örn. Gramaj"></label>
        <label><span>Slug</span><input name="slug" type="text" placeholder="otomatik"></label>
    </div>
    <label><span>Sıra</span><input name="sort" type="number" value="0"></label>
    <button class="btn" type="submit">Grup ekle</button>
</form>
<?php foreach ($groups as $g): ?>
<div class="card" style="margin-top:20px">
    <form class="toolbar" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="save_group">
        <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
        <input name="name" type="text" value="<?= h($g['name']) ?>" required>
        <input class="compact" name="sort" type="number" value="<?= (int) $g['sort'] ?>" title="Sıra">
        <button class="btn ghost" type="submit">Kaydet</button>
    </form>
    <form method="post" onsubmit="return confirm('Grup ve değerleri silinsin mi?');">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="delete_group">
        <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
        <button class="textlink" type="submit">Grubu sil</button>
    </form>
    <div class="table-wrap" style="margin:12px 0">
        <table>
            <tr><th>Değer</th><th>Sıra</th><th></th></tr>
            <?php foreach ($g['values'] as $v): ?>
                <tr>
                    <td>
                        <form class="toolbar" method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="act" value="save_value">
                            <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                            <input name="name" type="text" value="<?= h($v['name']) ?>" required>
                            <input class="compact" name="sort" type="number" value="<?= (int) $v['sort'] ?>">
                            <button class="btn ghost" type="submit">Kaydet</button>
                        </form>
                    </td>
                    <td><?= (int) $v['sort'] ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Değer silinsin mi?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="act" value="delete_value">
                            <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                            <button class="textlink" type="submit">Sil</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <form class="toolbar" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="add_value">
        <input type="hidden" name="group_id" value="<?= (int) $g['id'] ?>">
        <input name="name" type="text" required placeholder="Yeni değer">
        <button class="btn" type="submit">Değer ekle</button>
    </form>
</div>
<?php endforeach; ?>
