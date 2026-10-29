<div class="page-head"><h1>İadeler</h1></div>
<?php foreach ($items as $r): ?>
<div class="card">
    <p><a href="<?= h(site_url('yonetim/siparisler/' . $r['code'] . '/')) ?>"><?= h($r['code']) ?></a> · <?= h($r['name']) ?> · <?= h($r['status']) ?></p>
    <p><?= h($r['reason']) ?></p>
    <form method="post" class="toolbar">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <select name="status">
            <?php foreach (['yeni','onay','red','tamam'] as $s): ?>
                <option value="<?= $s ?>"<?= $r['status']===$s?' selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <input name="note" type="text" value="<?= h($r['note'] ?? '') ?>" placeholder="Not">
        <button class="btn" type="submit">Kaydet</button>
    </form>
</div>
<?php endforeach; ?>
