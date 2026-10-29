<?php
$st = $st ?? HfsFinance::statement();
$snap = $st['snap'];
$q = '?bas=' . rawurlencode($st['from']) . '&bit=' . rawurlencode($st['to']);
$payAmt = max(0, round((float) $snap['total_open'], 2));
$accTitle = 'Hesap ekstresi';
$accLead = 'Bayt muhasebe bakiyesi anlık çekilir; portal ekstresi sipariş ve tahsilat hareketlerinizdir. B = borç, A = alacak (Bayt ile aynı).';
require __DIR__ . '/partials/account-tabs.php';
?>
<div class="hfs-ekstre wrap">
    <section class="hfs-cari-summary" aria-label="Özet">
        <?php require __DIR__ . '/partials/bayt-balance-card.php'; ?>
        <article class="hfs-cari-card"><span>Açık bakiye (portal)</span><strong><?= h(money($snap['total_open'])) ?></strong></article>
        <article class="hfs-cari-card"><span>Dönem borç</span><strong><?= h(money($st['debit'])) ?></strong></article>
        <article class="hfs-cari-card"><span>Dönem tahsilat</span><strong><?= h(money($st['credit'])) ?></strong></article>
        <article class="hfs-cari-card"><span>Dönem sonu</span><strong><?= h(hfs_signed_balance_display((float) $st['end'])) ?></strong></article>
    </section>
    <div class="hfs-ekstre__card">
        <form class="hfs-ekstre-filter" method="get" action="<?= h(site_url('hesabim/ekstre/')) ?>">
            <label class="hfs-ekstre-date">
                <span>Başlangıç</span>
                <span class="hfs-ekstre-date__ctrl"><input type="date" name="bas" value="<?= h($st['from']) ?>"></span>
            </label>
            <label class="hfs-ekstre-date">
                <span>Bitiş</span>
                <span class="hfs-ekstre-date__ctrl"><input type="date" name="bit" value="<?= h($st['to']) ?>"></span>
            </label>
            <div class="hfs-ekstre-filter__acts">
                <button class="btn ghost" type="submit">Göster</button>
                <a class="btn ghost" href="<?= h(site_url('hesabim/ekstre/') . $q . '&yazdir=1') ?>">Yazdır / PDF</a>
                <?php if ($payAmt >= 1): ?>
                    <a class="btn" href="<?= h(site_url('cari-odeme/') . '?tutar=' . rawurlencode((string) $payAmt)) ?>">Bu bakiyeyi öde · <?= h(money($payAmt)) ?></a>
                <?php endif; ?>
            </div>
        </form>
        <div class="hfs-ekstre-table">
            <table>
                <thead>
                    <tr><th>Tarih</th><th>Belge</th><th>Açıklama</th><th>Borç</th><th>Alacak</th><th>Bakiye</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($st['lines'] as $ln): ?>
                        <tr class="hfs-ekstre-<?= h($ln['kind']) ?>">
                            <td class="hfs-ekstre-table__date"><?= h(date('d.m.Y H:i', strtotime($ln['at']) ?: time())) ?></td>
                            <td class="hfs-ekstre-table__code">
                                <?php if ($ln['kind'] === 'siparis' && $ln['code'] !== ''): ?>
                                    <a href="<?= h(site_url('siparis/' . $ln['code'] . '/')) ?>"><?= h($ln['code']) ?></a>
                                <?php else: ?>
                                    <?= $ln['code'] !== '' ? h($ln['code']) : '—' ?>
                                <?php endif; ?>
                            </td>
                            <td><?= h($ln['note']) ?></td>
                            <td><?= $ln['debit'] > 0 ? h(money($ln['debit'])) : '—' ?></td>
                            <td><?= $ln['credit'] > 0 ? h(money($ln['credit'])) : '—' ?></td>
                            <td><?= h(hfs_signed_balance_display((float) $ln['balance'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="hint">Açık bakiye; açılış, tahsilat ve henüz kapanmamış siparişlere göredir. Dönem sonu bakiyesi seçilen aralıktaki hareketlerin netidir.</p>
</div>
