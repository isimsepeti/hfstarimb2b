<?php
$rows = $rows ?? [];
$source = (string) ($source ?? '');
$sources = ['' => 'Tümü', 'b2b' => 'B2B', 'bulut' => 'Bulut', 'bayt' => 'Bayt', 'sync' => 'Senkron', 'sistem' => 'Sistem'];
?>
<div class="page-head">
    <h1>Entegrasyon log</h1>
    <p>B2B, Bulut Tahsilat ve Bayt olayları. Kayıtlar <strong>7 gün</strong> tutulur; cron (<code>/hfs-sync/</code>) ile haftalık otomatik silinir. Dosya yedeği: <code>storage/logs/bulut.log</code>.</p>
</div>
<form method="get" class="toolbar">
    <label>
        <span>Kaynak</span>
        <select name="kaynak" onchange="this.form.submit()">
            <?php foreach ($sources as $k => $label): ?>
                <option value="<?= h($k) ?>"<?= $source === $k ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <a class="btn ghost" href="<?= h(site_url('yonetim/muhasebe/')) ?>">Muhasebe ayarları</a>
</form>
<div class="card card--flush">
    <div class="table-wrap table-wrap--fit">
        <table class="integr-log-table">
            <colgroup>
                <col class="col-date">
                <col class="col-src">
                <col class="col-event">
                <col class="col-order">
                <col class="col-ok">
                <col class="col-msg">
            </colgroup>
            <tr>
                <th>Tarih</th>
                <th>Kaynak</th>
                <th>Olay</th>
                <th>Sipariş</th>
                <th>Sonuç</th>
                <th>Mesaj</th>
            </tr>
            <?php if ($rows === []): ?>
                <tr><td colspan="6">Henüz kayıt yok veya filtre boş.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="nowrap"><?= h((string) ($row['created_at'] ?? '')) ?></td>
                    <td><?= h((string) ($row['source'] ?? '')) ?></td>
                    <td><?= h((string) ($row['event'] ?? '')) ?></td>
                    <td class="nowrap">
                        <?php
                        $oc = trim((string) ($row['order_code'] ?? ''));
                        if ($oc !== ''): ?>
                            <a href="<?= h(site_url('yonetim/siparisler/' . $oc . '/')) ?>"><?= h($oc) ?></a>
                        <?php elseif ((int) ($row['order_id'] ?? 0) > 0): ?>
                            #<?= (int) $row['order_id'] ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><?= !empty($row['ok']) ? 'OK' : 'Hata' ?></td>
                    <td class="integr-msg">
                        <?= h((string) ($row['message'] ?? '')) ?>
                        <?php if (!empty($row['context'])): ?>
                            <details class="field-hint"><summary>Detay</summary><pre class="integr-ctx"><?= h((string) $row['context']) ?></pre></details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
