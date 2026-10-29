<?php
$snap = $snap ?? HfsFinance::snapshot();
$u = $u ?? current_user();
$accTitle = 'Hesabım';
$accLead = trim((string) ($u['display_name'] ?? '')) . ' · ' . (string) ($u['email'] ?? '');
$isMainAccount = (int) ($u['parent_user_id'] ?? 0) < 1;
$profileErr = trim((string) ($_GET['hata'] ?? ''));
?>
<div class="wrap hfs-account-page">
<?php require __DIR__ . '/partials/account-tabs.php'; ?>
<section class="hfs-profile">
    <?php if (isset($_GET['ok'])): ?><p class="ok hfs-profile-flash">Profil güncellendi.</p><?php endif; ?>
    <?php if ($profileErr !== ''): ?><p class="err hfs-profile-flash"><?= h($profileErr) ?></p><?php endif; ?>
    <div class="hfs-profile-card">
        <h2 class="hfs-profile-title">Profil bilgileri</h2>
        <form class="hfs-profile-form" method="post" action="<?= h(site_url('hesabim/')) ?>">
            <?= csrf_field() ?>
            <p class="hfs-profile-user">Kullanıcı adı: <strong><?= h((string) ($u['username'] ?? '')) ?></strong> · değiştirilemez</p>
            <div class="row2">
                <label>Ad soyad / unvan
                    <input type="text" name="display_name" value="<?= h((string) ($u['display_name'] ?? '')) ?>" autocomplete="name">
                </label>
                <label>E-posta
                    <input type="email" name="email" required value="<?= h((string) ($u['email'] ?? '')) ?>" autocomplete="email">
                </label>
            </div>
            <label>Telefon
                <input type="tel" name="phone" value="<?= h((string) ($u['phone'] ?? '')) ?>" autocomplete="tel">
            </label>
            <?php
            $row = $u;
            $requireTrAddress = $isMainAccount;
            require __DIR__ . '/partials/address-tr.php';
            ?>
            <label>Adres
                <textarea name="address" rows="2" autocomplete="street-address"><?= h((string) ($u['address'] ?? '')) ?></textarea>
            </label>
            <?php if ($isMainAccount): ?>
                <h3>Firma / fatura</h3>
                <label>Firma unvanı
                    <input type="text" name="company" required value="<?= h((string) ($u['company'] ?? '')) ?>">
                </label>
                <div class="row2">
                    <label>Vergi dairesi
                        <input type="text" name="tax_office" required value="<?= h((string) ($u['tax_office'] ?? '')) ?>">
                    </label>
                    <label>Vergi / T.C. no
                        <input type="text" name="tax_number" inputmode="numeric" maxlength="11" required value="<?= h((string) ($u['tax_number'] ?? '')) ?>">
                    </label>
                </div>
            <?php else:
                $co = HfsPricing::company($u);
                ?>
                <p class="hint">Firma ve vergi bilgileri ana cari hesaptan yönetilir<?= trim((string) ($co['company'] ?? '')) !== '' ? ': ' . h((string) $co['company']) : '' ?>.</p>
            <?php endif; ?>
            <h3>Şifre</h3>
            <p class="hint">Değiştirmeyecekseniz boş bırakın.</p>
            <label>Mevcut şifre
                <input type="password" name="password_current" autocomplete="current-password">
            </label>
            <div class="row2">
                <label>Yeni şifre
                    <input type="password" name="password_new" minlength="8" autocomplete="new-password">
                </label>
                <label>Yeni şifre (tekrar)
                    <input type="password" name="password_confirm" minlength="8" autocomplete="new-password">
                </label>
            </div>
            <div class="hfs-profile-actions">
                <button class="btn" type="submit">Kaydet</button>
            </div>
        </form>
    </div>
</section>
<section class="hfs-cari-summary">
    <?php require __DIR__ . '/partials/bayt-balance-card.php'; ?>
    <article class="hfs-cari-card"><span>Açık bakiye (portal)</span><strong><?= h(money($snap['total_open'])) ?></strong></article>
    <article class="hfs-cari-card"><span>Kredi limiti</span><strong><?= $snap['credit_limit'] > 0 ? h(money($snap['credit_limit'])) : 'Tanımsız' ?></strong></article>
    <article class="hfs-cari-card"><span>Kalan limit</span><strong><?= h(money($snap['remaining_limit'])) ?></strong></article>
    <article class="hfs-cari-card"><span>Cari tahsilat</span><strong><?= h(money($snap['cari_paid'])) ?></strong></article>
</section>
<?php if (!empty($snap['payments'])): ?>
<section class="hfs-account-block">
    <h2>Cari tahsilat geçmişi</h2>
    <ul class="hfs-order-list">
        <?php foreach (array_slice($snap['payments'], 0, 8) as $p): ?>
            <li><?= h(money($p['amount'])) ?> · <?= h($p['note'] ?? '') ?> <small><?= h($p['created_at']) ?></small></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<section class="hfs-account-block">
    <h2 id="siparisler">Son siparişler</h2>
    <?php if (empty($snap['orders'])): ?>
        <p class="empty">Henüz sipariş yok.</p>
    <?php else: ?>
        <ul class="hfs-order-list">
            <?php foreach ($snap['orders'] as $o): ?>
                <li>
                    <a href="<?= h(site_url('siparis/' . $o['code'] . '/')) ?>"><strong><?= h($o['code']) ?></strong></a>
                    <?= h(hfs_order_status_for($o)) ?> · <?= h(money($o['total'])) ?>
                    <?php $tr = trim((string) ($o['tracking'] ?? '')); if ($tr !== ''): ?>
                        · <a href="<?= h(site_url('hesabim/kargo/')) ?>">Takip <?= h($tr) ?></a>
                    <?php elseif ((int) ($o['is_cari'] ?? 0) !== 1): ?>
                        · <a href="<?= h(site_url('siparis/' . $o['code'] . '/')) ?>">Kargo</a>
                    <?php endif; ?>
                    <small><?= h($o['created_at']) ?></small>
                    <?php if (($o['status'] ?? '') !== 'pending_b2b' && (int) ($o['is_cari'] ?? 0) !== 1): ?>
                        <a href="<?= h(site_url('hesabim/') . '?tekrar=' . (int) $o['id']) ?>">Tekrar sipariş</a>
                    <?php endif; ?>
                    <?php if (!in_array((string) ($o['status'] ?? ''), ['iptal', 'odeme'], true) && (int) ($o['is_cari'] ?? 0) !== 1): ?>
                        <a href="<?= h(site_url('siparis/' . $o['code'] . '/irsaliye/')) ?>">İrsaliye</a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
</div>
