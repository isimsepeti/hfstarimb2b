<?php
$store = (string) setting('site_name', 'HFS Tarım');
$phone = (string) setting('phone', '0224 216 16 01');
$po = trim((string) ($order['po_number'] ?? ''));
$date = !empty($order['created_at']) ? date('d.m.Y H:i', strtotime((string) $order['created_at'])) : '';
$items = [];
$totalQty = 0;
foreach ($order['items'] as $it) {
    if ((string) ($it['sku'] ?? '') === 'CARI') {
        continue;
    }
    $qty = (int) $it['qty'];
    $totalQty += max(0, $qty);
    $p = Catalog::productById((int) $it['product_id']);
    $img = $p ? hfs_media((string) ($p['featured_media'] ?? '')) : '';
    $items[] = [
        'name' => $it['title'],
        'sku' => $it['sku'],
        'qty' => $qty,
        'img' => $img,
    ];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>İrsaliye <?= h($order['code']) ?></title>
<style>
* { box-sizing: border-box; }
body { font-family: Arial, Helvetica, sans-serif; margin: 16px; color: #111; font-size: 13px; }
h1 { margin: 0 0 4px; font-size: 22px; letter-spacing: .04em; }
.meta { color: #444; margin: 0 0 12px; }
.top { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 14px; }
.order-no { font-size: 28px; font-weight: 700; text-align: right; line-height: 1.1; }
.boxes { display: flex; gap: 16px; margin-bottom: 14px; }
.box { flex: 1; border: 1px solid #222; padding: 10px 12px; min-height: 110px; }
.box h2 { margin: 0 0 6px; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #333; }
.box .name { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
table { width: 100%; border-collapse: collapse; }
th, td { border: 1px solid #222; padding: 6px 8px; vertical-align: middle; }
th { background: #eee; text-align: left; font-size: 12px; }
td.img { width: 72px; text-align: center; }
td.img img { max-width: 64px; max-height: 64px; display: block; margin: 0 auto; }
td.qty { width: 70px; text-align: center; font-size: 18px; font-weight: 700; }
td.sku { width: 160px; font-family: Consolas, Monaco, monospace; font-size: 13px; font-weight: 700; }
.totals { margin-top: 8px; font-size: 14px; }
.sign { display: flex; gap: 16px; margin-top: 28px; }
.sign div { flex: 1; border-top: 1px solid #111; padding-top: 6px; min-height: 70px; }
.no-print { margin-bottom: 12px; }
.btn { display: inline-block; background: #1b4332; color: #fff; text-decoration: none; padding: 8px 14px; border-radius: 4px; margin-right: 8px; }
.btn-sec { background: #555; }
@media print {
  .no-print { display: none !important; }
  body { margin: 8mm; }
}
</style>
</head>
<body>
<p class="no-print">
    <a class="btn" href="#" onclick="window.print(); return false;">Yazdır / PDF kaydet</a>
    <a class="btn btn-sec" href="<?= h($back ?? site_url('yonetim/siparisler/' . $order['code'] . '/')) ?>">Siparişe dön</a>
    <span>Yazdır penceresinden “PDF olarak kaydet” seçebilirsiniz. Koliye yapıştırmak için A4 çıktı alın.</span>
</p>
<div class="top">
    <div>
        <img src="<?= h(shop_logo()) ?>" alt="HFS Tarım" width="180" height="64" style="max-width:180px;height:auto;display:block;margin:0 0 8px">
        <h1>İRSALİYE / SEVK BELGESİ</h1>
        <p class="meta"><?= h($store) ?> · Altınova Mah. Askı Sok. No: 10 Osmangazi / Bursa · <?= h($phone) ?></p>
    </div>
    <div class="order-no">
        <?= h($order['code']) ?><br>
        <span style="font-size:14px; font-weight:600;"><?= h($date) ?></span>
    </div>
</div>
<div class="boxes">
    <div class="box">
        <h2>Gönderen</h2>
        <div class="name"><?= h($store) ?></div>
        <p>Altınova Mah. Askı Sok. No: 10<br>Osmangazi / Bursa</p>
    </div>
    <div class="box">
        <h2>Alıcı / teslim</h2>
        <div class="name"><?= h($order['name']) ?></div>
        <p><?= h($order['address']) ?><br><?= h($order['district']) ?> <?= h($order['city']) ?><br><?= h($order['phone']) ?></p>
        <?php if ($po !== ''): ?><p>PO: <strong><?= h($po) ?></strong></p><?php endif; ?>
        <?php $trk = trim((string) ($order['tracking'] ?? '')); if ($trk !== ''): ?>
            <p>Kargo takip: <strong><?= h($trk) ?></strong></p>
        <?php endif; ?>
    </div>
</div>
<table>
    <tr><th></th><th>SKU</th><th>Ürün</th><th>Adet</th></tr>
    <?php foreach ($items as $it): ?>
        <tr>
            <td class="img"><?php if ($it['img']): ?><img src="<?= h($it['img']) ?>" alt=""><?php endif; ?></td>
            <td class="sku"><?= h($it['sku']) ?></td>
            <td><?= h($it['name']) ?></td>
            <td class="qty"><?= (int) $it['qty'] ?></td>
        </tr>
    <?php endforeach; ?>
</table>
<p class="totals"><strong>Toplam adet:</strong> <?= (int) $totalQty ?></p>
<div class="sign">
    <div>Sevk eden</div>
    <div>Teslim alan</div>
</div>
</body>
</html>
