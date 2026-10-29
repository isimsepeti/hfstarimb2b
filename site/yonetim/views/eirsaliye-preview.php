<?php
/** @var array<string,mixed> $order */
/** @var array<string,mixed> $preview */
/** @var string $back */
$p = $preview ?? HfsBayt::eirsaliyePreview($order);
$supplier = (array) ($p['supplier'] ?? []);
$buyer = (array) ($p['buyer'] ?? []);
$delivery = (array) ($p['delivery'] ?? []);
$bayt = (array) ($p['bayt'] ?? []);
$lines = (array) ($p['lines'] ?? []);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>e-İrsaliye ön izleme — <?= h((string) ($order['code'] ?? '')) ?></title>
<style>
* { box-sizing: border-box; }
body { font-family: "Segoe UI", Arial, sans-serif; margin: 16px; color: #111; font-size: 13px; max-width: 920px; }
.banner { background: #fff8e6; border: 1px solid #eab308; color: #854d0e; padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; line-height: 1.45; }
.banner strong { display: block; margin-bottom: 4px; }
.status { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
.status-ok { background: #dcfce7; color: #166534; }
.status-warn { background: #fee2e2; color: #991b1b; }
h1 { margin: 0 0 6px; font-size: 20px; }
.meta { color: #555; margin-bottom: 14px; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
.box { border: 1px solid #ccc; border-radius: 8px; padding: 12px; }
.box h2 { margin: 0 0 8px; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #666; }
.box .title { font-weight: 700; font-size: 15px; margin-bottom: 6px; }
table { width: 100%; border-collapse: collapse; margin-top: 8px; }
th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
th { background: #f3f4f6; }
.tools { margin-bottom: 14px; }
.btn { display: inline-block; background: #1b4332; color: #fff; text-decoration: none; padding: 8px 14px; border-radius: 6px; margin-right: 8px; border: 0; cursor: pointer; font-size: 13px; }
.btn-ghost { background: #555; }
@media print { .tools, .banner { display: none; } }
@media (max-width: 700px) { .grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<p class="tools">
    <a class="btn" href="#" onclick="window.print(); return false;">Yazdır</a>
    <a class="btn btn-ghost" href="<?= h($back ?? '#') ?>">Geri</a>
</p>
<div class="banner">
    <strong>Ön izleme — resmi e-İrsaliye değil</strong>
    <?= h((string) ($p['disclaimer'] ?? '')) ?>
</div>
<h1>e-İrsaliye (Despatch Advice) — ön izleme</h1>
<p class="meta">
    Sipariş: <strong><?= h((string) ($order['code'] ?? '')) ?></strong>
    · İrsaliye no (ref): <strong><?= h((string) ($p['despatch_number'] ?? '')) ?></strong>
    · Sevk tarihi: <?= h((string) ($p['despatch_date'] ?? '')) ?>
    · UUID (simülasyon): <code><?= h((string) ($p['uuid_preview'] ?? '')) ?></code>
</p>
<p>
    GİB belgesi:
    <?php if (!empty($p['gib_document_exists'])): ?>
        <span class="status status-ok">Sistemde XML/PDF var</span>
    <?php else: ?>
        <span class="status status-warn">B2B’de yok — Bayt/GİB’de kesilir</span>
    <?php endif; ?>
    &nbsp; Bayt gönderilecek irsaliye:
    <?php if (!empty($p['bayt_waybill_ready'])): ?>
        <span class="status status-ok">Oluşturuldu (#<?= (int) ($bayt['waybill_id'] ?? 0) ?>)</span>
    <?php else: ?>
        <span class="status status-warn">Henüz yok</span>
    <?php endif; ?>
</p>
<div class="grid">
    <div class="box">
        <h2>Sevk eden (satıcı)</h2>
        <div class="title"><?= h((string) ($supplier['name'] ?? '')) ?></div>
        <p><?= h((string) ($supplier['address'] ?? '')) ?></p>
        <p>VKN: <?= h((string) ($supplier['vkn'] ?? '—')) ?> · VD: <?= h((string) ($supplier['tax_office'] ?? '')) ?></p>
    </div>
    <div class="box">
        <h2>Alıcı (cari)</h2>
        <div class="title"><?= h((string) ($buyer['name'] ?? '')) ?></div>
        <p>VKN/TCKN: <?= h((string) ($buyer['vkn_tckn'] ?? '—')) ?> · VD: <?= h((string) ($buyer['tax_office'] ?? '')) ?></p>
        <p>Bayt cari: <?= h((string) ($buyer['bayt_code'] ?? '')) ?></p>
    </div>
    <div class="box">
        <h2>Teslimat adresi</h2>
        <p><?= h((string) ($delivery['address'] ?? '')) ?></p>
        <p><?= h((string) ($delivery['district'] ?? '')) ?> / <?= h((string) ($delivery['city'] ?? '')) ?> <?= h((string) ($delivery['postcode'] ?? '')) ?></p>
        <p><?= h((string) ($delivery['country'] ?? '')) ?></p>
    </div>
    <div class="box">
        <h2>Bayt muhasebe</h2>
        <p>Ref: <strong><?= h((string) ($bayt['ref'] ?? '')) ?></strong></p>
        <p>Satış siparişi ID: <?= (int) ($bayt['order_id'] ?? 0) ?> · İrsaliye ID: <?= (int) ($bayt['waybill_id'] ?? 0) ?></p>
        <p>Durum: <?= h((string) ($bayt['status'] ?? '')) ?> / onay: <?= h((string) ($bayt['approval'] ?? '')) ?></p>
        <?php if (!empty($bayt['sent_at'])): ?><p>Gönderim: <?= h((string) $bayt['sent_at']) ?></p><?php endif; ?>
    </div>
</div>
<table>
    <thead><tr><th>SKU</th><th>Mal / hizmet</th><th>Miktar</th><th>Birim</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $ln): ?>
        <tr>
            <td><code><?= h((string) ($ln['sku'] ?? '')) ?></code></td>
            <td><?= h((string) ($ln['name'] ?? '')) ?></td>
            <td><?= h((string) ($ln['qty'] ?? '')) ?></td>
            <td><?= h((string) ($ln['unit'] ?? 'Adet')) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($lines === []): ?><tr><td colspan="4">Satır yok</td></tr><?php endif; ?>
    </tbody>
</table>
</body>
</html>
