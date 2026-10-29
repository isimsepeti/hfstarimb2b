<?php $orders = $orders ?? [];
$accTitle = 'Kargo takibi';
$accLead = 'Sipariş bazlı Yurtiçi Kargo takip numaraları. Numaraya tıklayınca Yurtiçi sitesinde sorgularsınız.';
require __DIR__ . '/partials/account-tabs.php';
?>
<div class="hfs-ekstre wrap">
    <div class="hfs-ekstre__card">
        <?php if (!$orders): ?>
            <p class="empty">Gösterilecek sipariş yok.</p>
        <?php else: ?>
            <div class="hfs-ekstre-table hfs-track-table">
                <table>
                    <thead>
                        <tr>
                            <th>Sipariş</th>
                            <th>Tarih</th>
                            <th>Durum</th>
                            <th>Takip no</th>
                            <th>Sorgula</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o):
                            $no = trim((string) ($o['tracking'] ?? ''));
                            ?>
                            <tr>
                                <td class="hfs-ekstre-table__code">
                                    <a href="<?= h(site_url('siparis/' . $o['code'] . '/')) ?>"><strong><?= h($o['code']) ?></strong></a>
                                    <div class="hfs-track__sum"><?= h(money($o['total'])) ?></div>
                                </td>
                                <td class="hfs-ekstre-table__date"><?= h(date('d.m.Y', strtotime((string) $o['created_at']) ?: time())) ?></td>
                                <td><?= h(hfs_order_status_for($o)) ?></td>
                                <td>
                                    <?php if ($no !== ''): ?>
                                        <strong class="hfs-track__no"><?= h($no) ?></strong>
                                    <?php else: ?>
                                        <span class="hint"><?= h(hfs_track_note($o)) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($no !== ''): ?>
                                        <div class="hfs-track__links">
                                            <?php foreach (hfs_track_carriers($no) as $c): ?>
                                                <a class="btn ghost" href="<?= h($c['href']) ?>" target="_blank" rel="noopener"><?= h($c['name']) ?></a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <a href="<?= h(site_url('siparis/' . $o['code'] . '/')) ?>">Sipariş</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <p class="hint">Takip numarası depo sevkiyatında işlenir. Hâlâ görünmüyorsa 0224 216 16 01.</p>
</div>
