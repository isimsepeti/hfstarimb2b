<?php
$baytBal = $baytBal ?? ['ok' => false];
?>
<article class="hfs-cari-card hfs-cari-card--bayt" title="BYTE / Bayt muhasebe cari bakiyesi">
    <span>Bayt güncel bakiye</span>
    <strong><?= h(hfs_bayt_balance_display(is_array($baytBal) ? $baytBal : [])) ?></strong>
    <?php if (!empty($baytBal['ok'])): ?>
        <small class="hint"><?= h((string) ($baytBal['code'] ?? '')) ?><?= !empty($baytBal['fetched_at']) ? ' · ' . date('H:i', (int) $baytBal['fetched_at']) : '' ?></small>
    <?php elseif (!empty($baytBal['error'])): ?>
        <small class="hint"><?= h((string) $baytBal['error']) ?></small>
    <?php endif; ?>
</article>
