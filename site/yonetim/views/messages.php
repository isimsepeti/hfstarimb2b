<div class="page-head"><h1>Mesajlar</h1></div>
<?php foreach ($items as $m): ?>
    <div class="card">
        <strong><?= h($m['name']) ?></strong> · <?= h($m['email']) ?> · <?= h($m['created_at']) ?>
        <p><?= h($m['subject']) ?></p>
        <p><?= nl2br(h($m['message'])) ?></p>
    </div>
<?php endforeach; ?>
<?php if (!$items): ?><p class="hint">Mesaj yok.</p><?php endif; ?>
