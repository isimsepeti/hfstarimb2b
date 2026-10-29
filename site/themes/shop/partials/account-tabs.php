<?php
$tabPath = request_path();
$tabNow = match (true) {
    $tabPath === '/hesabim/' || $tabPath === '/cari-hesap/' => 'hesabim',
    $tabPath === '/cari-odeme/' => 'odeme',
    $tabPath === '/hesabim/ekstre/' || $tabPath === '/cari-ekstre/' => 'ekstre',
    $tabPath === '/hesabim/kargo/' || $tabPath === '/kargo-takip/' => 'kargo',
    $tabPath === '/hizli-siparis/' => 'hizli',
    $tabPath === '/siparis-onaylari/' => 'onay',
    default => '',
};
$tabUser = current_user();
$tabCanApprove = HfsApproval::canApprove($tabUser);
$tabPending = $tabCanApprove ? count(HfsApproval::pendingFor($tabUser)) : 0;
$tabs = [
    ['id' => 'hesabim', 'href' => site_url('hesabim/'), 'label' => 'Hesabım'],
    ['id' => 'odeme', 'href' => site_url('cari-odeme/'), 'label' => 'Cari ödeme'],
    ['id' => 'ekstre', 'href' => site_url('hesabim/ekstre/'), 'label' => 'Cari ekstre'],
    ['id' => 'kargo', 'href' => site_url('hesabim/kargo/'), 'label' => 'Kargo takibi'],
    ['id' => 'fiyat', 'href' => site_url('hesabim/fiyat-listesi/'), 'label' => 'Fiyat listesi (Excel)'],
    ['id' => 'hizli', 'href' => site_url('hizli-siparis/'), 'label' => 'Hızlı sipariş'],
];
if ($tabCanApprove) {
    $tabs[] = [
        'id' => 'onay',
        'href' => site_url('siparis-onaylari/'),
        'label' => 'Sipariş onayları' . ($tabPending > 0 ? ' (' . $tabPending . ')' : ''),
    ];
}
$accTitle = $accTitle ?? '';
$accLead = $accLead ?? '';
?>
<div class="hfs-acc wrap">
    <nav class="hfs-acc-tabs" aria-label="Hesap sekmeleri">
        <?php foreach ($tabs as $t):
            $on = $t['id'] === $tabNow;
            ?>
            <a href="<?= h($t['href']) ?>" class="<?= $on ? 'is-on' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= h($t['label']) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($accTitle !== ''): ?>
        <header class="hfs-acc__head">
            <h1><?= h($accTitle) ?></h1>
            <?php if ($accLead !== ''): ?><p><?= h($accLead) ?></p><?php endif; ?>
        </header>
    <?php endif; ?>
</div>
