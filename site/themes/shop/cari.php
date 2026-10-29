<?php $snap = $snap ?? HfsFinance::snapshot();
$accTitle = 'Cari ödeme';
$accLead = 'Sipariş olmasa da cari hesaba kart ile ödeme yatırabilirsiniz. Tahsilat BulutTahsilat güvenli sayfasında alınır.';
require __DIR__ . '/partials/account-tabs.php';
?>
<?php
$err = (string) ($err ?? '');
$detail = HfsPay::consumeError();
$errMap = [
    'tutar' => 'Geçerli bir tutar girin (1 – 10.000.000 TL).',
    'kapali' => 'Kart ile cari tahsilat kapalı. Yönetim → Ayarlar.',
    'odeme' => 'Ödeme başlatılamadı. Ayarları veya bağlantıyı kontrol edin.',
    'iptal' => 'Ödeme tamamlanmadı veya iptal edildi.',
    'donus' => 'Ödeme sonucu alınamadı. Tutarı tekrar deneyin veya ekstreden bakiyeyi kontrol edin.',
    'imza' => 'Ödeme imzası doğrulanamadı. Tekrar deneyin.',
    'onay' => 'Kart ile ödeme için KVKK ve bayi koşulları onay kutularını işaretleyin.',
];
?>
<?php if (!empty($_GET['ok'])): ?>
    <p class="wrap ok">Ödemeniz alındı ve cari hesabınıza işlendi.</p>
    <?php if (!empty($lastCariPay) && HfsPay::hasDekont($lastCariPay)): ?>
        <p class="wrap"><a class="btn ghost" href="<?= h(site_url('siparis/' . $lastCariPay['code'] . '/dekont/')) ?>" target="_blank" rel="noopener">Tahsilat dekontu indir (PDF)</a></p>
    <?php endif; ?>
<?php endif; ?>
<?php if ($err !== ''): ?><p class="wrap err"><?= h($errMap[$err] ?? 'Ödeme başlatılamadı.') ?><?php if ($detail !== ''): ?><br><small><?= h($detail) ?></small><?php endif; ?></p><?php endif; ?>
<section class="hfs-cari-summary wrap">
    <?php require __DIR__ . '/partials/bayt-balance-card.php'; ?>
    <article class="hfs-cari-card"><span>Açık bakiye (portal)</span><strong><?= h(money($snap['total_open'])) ?></strong></article>
    <article class="hfs-cari-card"><span>Kredi limiti</span><strong><?= $snap['credit_limit'] > 0 ? h(money($snap['credit_limit'])) : 'Tanımsız' ?></strong></article>
    <article class="hfs-cari-card"><span>Kalan limit</span><strong><?= h(money($snap['remaining_limit'])) ?></strong></article>
    <article class="hfs-cari-card"><span>Cari tahsilat</span><strong><?= h(money($snap['cari_paid'])) ?></strong></article>
</section>
<?php $openBal = round((float) ($payAmt ?? $snap['total_open']), 2);
$open = $openBal >= 1 ? $openBal : 0.0;
if (isset($_GET['tutar'])) {
    $req = round((float) str_replace(['.', ','], ['', '.'], (string) $_GET['tutar']), 2);
    if ($req >= 1 && $req <= 10000000) {
        $open = $req;
    }
}
?>
<form class="stack wrap" method="post" style="max-width:420px">
    <?= csrf_field() ?>
    <label>Ödenecek tutar (TL)
        <input name="amount" type="number" step="0.01" min="1" max="10000000" required value="<?= $open >= 1 ? h((string) $open) : '' ?>" placeholder="Örn. 1000">
    </label>
    <p class="hint">
        <?php if ($openBal >= 1): ?>
            <button type="button" class="textlink" onclick="this.form.amount.value='<?= h((string) $openBal) ?>'">Açık bakiyeyi doldur</button>
            — bakiyeden fazla yatırırsanız cariye alacak yazılır.
        <?php else: ?>
            Açık sipariş olmasa da tutar girip ödeyebilirsiniz; tutar cariye alacak yazılır.
        <?php endif; ?>
    </p>
    <fieldset class="hfs-checkout__consents hfs-cari-consents">
        <legend class="hp">Yasal onay</legend>
        <label class="hfs-checkout__consent">
            <input type="checkbox" name="consent_kvkk" value="1" required>
            <span><a href="<?= h(site_url('privacy/')) ?>" data-hfs-legal="privacy">KVKK aydınlatma metnini</a> okudum; tahsilat ve cari işlemler kapsamında kişisel verilerimin işlenmesini kabul ediyorum.</span>
        </label>
        <label class="hfs-checkout__consent">
            <input type="checkbox" name="consent_terms" value="1" required>
            <span><a href="<?= h(site_url('terms/')) ?>" data-hfs-legal="terms">Bayi sipariş ve ödeme koşullarını</a> (cari tahsilat dahil) kabul ediyorum.</span>
        </label>
    </fieldset>
    <button class="btn" type="submit"<?= HfsPay::cariEnabled() ? '' : ' disabled' ?>>Kart ile öde</button>
    <?php if (!HfsPay::cariEnabled()): ?><p class="hint">BulutTahsilat cari tahsilatı kapalı veya tanımlı değil.</p><?php endif; ?>
</form>
<?php if (!empty($snap['payments'])): ?>
<section class="wrap">
    <h2>Son cari tahsilatlar</h2>
    <ul class="hfs-order-list">
        <?php foreach (array_slice($snap['payments'], 0, 5) as $p): ?>
            <li><?= h(money($p['amount'])) ?> · <?= h($p['note'] ?? '') ?> <small><?= h($p['created_at']) ?></small></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
