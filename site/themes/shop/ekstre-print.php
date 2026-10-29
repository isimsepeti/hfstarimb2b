<?php
$st = $st ?? ['from' => date('Y-m-d'), 'to' => date('Y-m-d'), 'lines' => [], 'start' => 0, 'end' => 0, 'debit' => 0, 'credit' => 0, 'snap' => []];
$u = $u ?? current_user() ?? [];
$co = $st['snap']['company'] ?? $u;
$back = $back ?? site_url('hesabim/ekstre/');
$name = trim((string) ($co['company'] ?? $co['display_name'] ?? $u['display_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Cari ekstre <?= h($st['from']) ?> – <?= h($st['to']) ?></title>
<style>
* { box-sizing: border-box; }
body { font-family: Arial, Helvetica, sans-serif; margin: 16px; color: #111; font-size: 13px; }
h1 { margin: 0 0 4px; font-size: 20px; }
.meta { color: #444; margin: 0 0 14px; }
table { width: 100%; border-collapse: collapse; }
th, td { border: 1px solid #222; padding: 6px 8px; }
th { background: #eee; text-align: left; font-size: 12px; }
td.num, th.num { text-align: right; }
.no-print { margin-bottom: 12px; }
.btn { display: inline-block; background: #1b4332; color: #fff; text-decoration: none; padding: 8px 14px; border-radius: 4px; margin-right: 8px; }
.btn-sec { background: #555; }
.sum { margin-top: 12px; }
@media print { .no-print { display: none !important; } body { margin: 8mm; } }
</style>
</head>
<body>
<p class="no-print">
    <a class="btn" href="#" onclick="window.print(); return false;">Yazdır / PDF kaydet</a>
    <a class="btn btn-sec" href="<?= h($back) ?>">Ekstreye dön</a>
</p>
<img src="<?= h(shop_logo()) ?>" alt="HFS Tarım" width="160" height="56" style="max-width:160px;height:auto;display:block;margin:0 0 10px">
<h1>Cari hesap ekstresi</h1>
<p class="meta">
    <?= h((string) setting('site_name', 'HFS Tarım')) ?> · <?= h($name) ?><br>
    Dönem: <?= h(date('d.m.Y', strtotime($st['from']) ?: time())) ?> – <?= h(date('d.m.Y', strtotime($st['to']) ?: time())) ?>
    · Portal açık bakiye <?= h(money((float) ($st['snap']['total_open'] ?? 0))) ?>
    · Bayt güncel <?= h(hfs_bayt_balance_display(is_array($baytBal ?? null) ? $baytBal : [])) ?>
</p>
<table>
    <tr><th>Tarih</th><th>Belge</th><th>Açıklama</th><th class="num">Borç</th><th class="num">Alacak</th><th class="num">Bakiye</th></tr>
    <?php foreach ($st['lines'] as $ln): ?>
        <tr>
            <td><?= h(date('d.m.Y H:i', strtotime($ln['at']) ?: time())) ?></td>
            <td><?= h($ln['code']) ?></td>
            <td><?= h($ln['note']) ?></td>
            <td class="num"><?= $ln['debit'] > 0 ? h(money($ln['debit'])) : '' ?></td>
            <td class="num"><?= $ln['credit'] > 0 ? h(money($ln['credit'])) : '' ?></td>
            <td class="num"><?= h(hfs_signed_balance_display((float) $ln['balance'])) ?></td>
        </tr>
    <?php endforeach; ?>
</table>
<p class="sum">Dönem borç <?= h(money($st['debit'])) ?> · Tahsilat <?= h(money($st['credit'])) ?> · Dönem sonu <?= h(hfs_signed_balance_display((float) $st['end'])) ?></p>
</body>
</html>
