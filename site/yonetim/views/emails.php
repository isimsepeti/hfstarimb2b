<?php
$catalog = $catalog ?? [];
$log = $log ?? [];
$groups = [];
foreach ($catalog as $key => $meta) {
    $groups[$meta['group'] ?? 'Diğer'][$key] = $meta;
}
?>
<div class="page-head">
    <h1>E-posta şablonları</h1>
    <p>Sipariş, iade ve diğer otomatik iletiler. SMTP <a href="<?= h(site_url('yonetim/ayarlar/')) ?>">Ayarlar</a> sayfasında.</p>
</div>
<?php foreach ($groups as $group => $rows): ?>
<div class="card">
    <h2><?= h($group) ?></h2>
    <div class="table-wrap">
        <table>
            <tr><th>Şablon</th><th>Konu</th><th></th></tr>
            <?php foreach ($rows as $key => $meta):
                $on = HfsMail::enabled($key);
                $tpl = HfsMail::resolved($key);
                ?>
                <tr>
                    <td>
                        <a href="<?= h(site_url('yonetim/epostalar/' . $key . '/')) ?>"><?= h($meta['title']) ?></a>
                        <div class="hint"><?= $on ? 'Açık' : 'Kapalı' ?> · <?= h((string) ($meta['help'] ?? '')) ?></div>
                    </td>
                    <td><?= h($tpl['subject']) ?></td>
                    <td><a class="btn ghost" href="<?= h(site_url('yonetim/epostalar/' . $key . '/')) ?>">Düzenle</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endforeach; ?>
<?php if ($log): ?>
<div class="card">
    <h2>Son gönderimler</h2>
    <div class="table-wrap">
        <table>
            <tr><th>Tarih</th><th>Alıcı</th><th>Konu</th><th>Durum</th></tr>
            <?php foreach ($log as $row): ?>
                <tr>
                    <td><?= h((string) $row['created_at']) ?></td>
                    <td><?= h((string) $row['to_email']) ?></td>
                    <td><?= h((string) $row['subject']) ?></td>
                    <td>
                        <?= h((string) $row['status']) ?>
                        <?php if (!empty($row['err'])): ?><div class="hint"><?= h((string) $row['err']) ?></div><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
