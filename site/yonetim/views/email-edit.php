<?php
$key = (string) ($key ?? '');
$meta = $meta ?? [];
$tpl = $tpl ?? ['subject' => '', 'body' => ''];
?>
<div class="page-head">
    <div>
        <h1><?= h((string) ($meta['title'] ?? $key)) ?></h1>
        <p><?= h((string) ($meta['help'] ?? '')) ?></p>
    </div>
    <p><a class="btn ghost" href="<?= h(site_url('yonetim/epostalar/')) ?>">Listeye dön</a></p>
</div>
<?php if (!empty($ok)): ?><p class="ok">Kaydedildi.</p><?php endif; ?>
<?php if (!empty($test)): ?>
    <p class="<?= !empty($test['ok']) ? 'ok' : 'err' ?>">
        <?= !empty($test['ok']) ? 'Test gönderildi: ' . h((string) ($testTo ?? '')) : 'Test gönderilemedi: ' . h((string) ($test['error'] ?? '')) ?>
    </p>
<?php endif; ?>
<form class="stack dealer-form" method="post">
    <?= csrf_field() ?>
    <div class="card">
        <label class="cat"><input type="checkbox" name="on" value="1"<?= !empty($on) ? ' checked' : '' ?>> Bu şablonu gönder</label>
        <label><span>Konu</span><input name="subject" type="text" value="<?= h((string) $tpl['subject']) ?>" required></label>
        <label>
            <span>Gövde (HTML)</span>
            <span class="field-hint">Tam HTML yazmazsanız HFS yeşil çerçeve eklenir. <code>{{alan}}</code> yer tutucuları doldurulur. <code>_html</code> ile bitenler kaçışsızdır.</span>
            <textarea name="body" rows="18"><?= h((string) $tpl['body']) ?></textarea>
        </label>
        <div class="toolbar">
            <button class="btn" type="submit" name="mail_act" value="save">Kaydet</button>
            <button class="btn ghost" type="submit" name="mail_act" value="restore" onclick="return confirm('Varsayılan metne dönülsün mü?');">Varsayılana dön</button>
        </div>
    </div>
    <div class="card">
        <h2>Bu şablonu dene</h2>
        <p class="hint">Kayıtlı metin örnek verilerle gönderilir. SMTP kapalıysa PHP mail() denenir.</p>
        <label><span>Alıcı</span><input name="test_to" type="email" value="<?= h((string) ($testTo ?: setting('email'))) ?>"></label>
        <button class="btn ghost" type="submit" name="mail_act" value="test">Test gönder</button>
    </div>
</form>
<div class="card">
    <h2>Yer tutucular</h2>
    <div class="table-wrap">
        <table>
            <tr><th>Alan</th><th>Anlam</th></tr>
            <?php foreach ($placeholders ?? [] as $tag => $lab): ?>
                <tr><td><code><?= h($tag) ?></code></td><td><?= h($lab) ?></td></tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
