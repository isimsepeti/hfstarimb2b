<div class="page-head"><h1>Yorumlar</h1></div>
<?php foreach ($items as $r): ?>
<div class="card">
    <p><strong><?= (int) $r['rating'] ?>/5</strong> · <?= h($r['name']) ?> · <?= h($r['ptitle'] ?? '') ?> · <span class="badge badge-<?= h($r['status']) ?>"><?= h($r['status']) ?></span></p>
    <p><?= h($r['body']) ?></p>
    <?php if ($r['photo']): ?><p><img src="<?= h($r['photo']) ?>" alt="" width="120"></p><?php endif; ?>
    <?php if ($r['reply']): ?><p class="hint">Cevap: <?= h($r['reply']) ?></p><?php endif; ?>
    <form method="post" class="toolbar">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <select name="status">
            <option value="pending"<?= $r['status']==='pending'?' selected':'' ?>>Bekle</option>
            <option value="publish"<?= $r['status']==='publish'?' selected':'' ?>>Yayınla</option>
            <option value="spam">Spam</option>
        </select>
        <input name="reply" type="text" placeholder="Cevap" value="<?= h($r['reply'] ?? '') ?>">
        <button class="btn" type="submit">Kaydet</button>
    </form>
</div>
<?php endforeach; ?>
