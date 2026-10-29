<div class="page-head">
    <h1>Fiyat Excel</h1>
    <p>Katalog fiyatlarını .xlsx olarak indirin veya güncelleyip yükleyin. Bayi iskontosu ayrıca uygulanır.</p>
</div>
<?php if (!empty($import)): ?>
    <?php
    $nf = $import['not_found'] ?? [];
    $sk = $import['skipped_rows'] ?? [];
    $up = $import['updated_rows'] ?? [];
    $pw = $import['price_warnings'] ?? [];
    $hasFail = !empty($import['errors']) && (int) $import['updated'] < 1 && (int) $import['unchanged'] < 1;
    ?>
    <div class="card <?= $hasFail ? 'err' : 'ok' ?>">
        <h2>Yükleme özeti</h2>
        <ul class="stack tight">
            <li><strong>Güncellenen:</strong> <?= (int) $import['updated'] ?> ürün</li>
            <li><strong>Değişmeyen (aynı fiyat):</strong> <?= (int) $import['unchanged'] ?></li>
            <li><strong>Atlanan satır:</strong> <?= (int) $import['skipped'] ?>
                <?php if ($sk !== []): ?>
                    <span class="hint">(boş kod, geçersiz fiyat vb.)</span>
                <?php endif; ?>
            </li>
            <li><strong>Sistemde olmayan SKU:</strong> <?= count($nf) ?></li>
            <li><strong>Şüpheli fiyat uyarısı:</strong> <?= count($pw) ?></li>
        </ul>
    </div>
    <?php if ($pw !== []): ?>
        <details class="card err" open>
            <summary>Şüpheli fiyatlar — kontrol edin (<?= count($pw) ?>)</summary>
            <p class="hint">Yükleme yine de uygulanır; bu satırlar mantık kontrolünden geçemedi. Excel veya katalog fiyatını gözden geçirin.</p>
            <table class="data">
                <thead><tr><th>Satır</th><th>SKU</th><th>Katalog</th><th>Excel</th><th>Uyarı</th></tr></thead>
                <tbody>
                <?php foreach ($pw as $r): ?>
                    <tr>
                        <td><?= (int) $r['line'] ?></td>
                        <td><?= h((string) $r['sku']) ?></td>
                        <td><?= h(number_format((float) $r['old'], 2, ',', '.')) ?></td>
                        <td><?= h(number_format((float) $r['new'], 2, ',', '.')) ?></td>
                        <td><?= h((string) $r['message']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    <?php endif; ?>
    <?php if ($up !== []): ?>
        <details class="card" open>
            <summary>Güncellenen ürünler (<?= count($up) ?>)</summary>
            <table class="data">
                <thead><tr><th>SKU</th><th>Eski</th><th>Yeni</th></tr></thead>
                <tbody>
                <?php foreach ($up as $r): ?>
                    <tr>
                        <td><?= h((string) $r['sku']) ?></td>
                        <td><?= h(number_format((float) $r['old'], 2, ',', '.')) ?></td>
                        <td><?= h(number_format((float) $r['new'], 2, ',', '.')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    <?php endif; ?>
    <?php if ($nf !== []): ?>
        <details class="card" open>
            <summary>Olmayan ürünler — SKU bulunamadı (<?= count($nf) ?>)</summary>
            <table class="data">
                <thead><tr><th>Satır</th><th>SKU</th></tr></thead>
                <tbody>
                <?php foreach ($nf as $r): ?>
                    <tr><td><?= (int) $r['line'] ?></td><td><?= h((string) $r['sku']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    <?php endif; ?>
    <?php if ($sk !== []): ?>
        <details class="card">
            <summary>Atlanan satırlar (<?= count($sk) ?>)</summary>
            <table class="data">
                <thead><tr><th>Satır</th><th>SKU</th><th>Neden</th></tr></thead>
                <tbody>
                <?php foreach ($sk as $r): ?>
                    <tr>
                        <td><?= (int) $r['line'] ?></td>
                        <td><?= h((string) $r['sku']) ?></td>
                        <td><?= h((string) $r['reason']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    <?php endif; ?>
    <?php if (!empty($import['errors']) && $hasFail): ?>
        <ul class="hint err"><?php foreach ($import['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
<?php endif; ?>
<div class="card">
    <p><a class="btn" href="<?= h(site_url('yonetim/fiyatlar/') . '?indir=1') ?>">Excel indir</a></p>
    <form class="stack" method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <label><span>xlsx yükle</span><input type="file" name="xlsx" accept=".xlsx" required></label>
        <button class="btn" type="submit">Fiyatları güncelle</button>
    </form>
    <p class="hint">Yalnızca fiyat sütununu değiştirin (Fiyat, Liste fiyatı veya Bayi fiyatı). Ürün kodunu / SKU’yu değiştirmeyin. CSV değil, .xlsx kaydedin.</p>
</div>
