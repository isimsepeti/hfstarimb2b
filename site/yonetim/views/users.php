<div class="page-head">
    <div>
        <h1>Bayiler</h1>
        <p>Yalnızca yöneticiler ve bayiler. Üyelik yok; bayi hesabını admin ekler.</p>
    </div>
    <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
        <a class="btn" href="<?= h(site_url('yonetim/kullanicilar/yeni/')) ?>">Yeni bayi</a>
    <?php endif; ?>
</div>
<?php if (!empty($pull)): ?>
    <p class="<?= !empty($pull['ok']) ? 'ok' : 'err' ?>"><?= h((string) ($pull['message'] ?? '')) ?></p>
    <?php if (!empty($pull['steps'])): ?>
        <ul class="hint"><?php foreach ($pull['steps'] as $st): ?><li><?= h((string) $st) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
<?php endif; ?>
<?php if ((current_user()['role'] ?? '') === 'admin'): ?>
<form method="post" class="toolbar" onsubmit="return confirm('Bayt ve BulutTahsilat carileri bayi listesine çekilsin mi? Mevcut hesaplar silinmez.');">
    <?= csrf_field() ?>
    <button class="btn" type="submit" name="pull_cari" value="1">API’den çek</button>
</form>
<p class="hint">Bayt / BulutTahsilat üzerinden cari ve adres bilgileri güncellenir (vergi no, adres, il/ilçe, <code>bayt_customer_id</code>). Mevcut bayiler silinmez. Giriş: kayıtlı vergi veya T.C. numarası; ilk girişte yeni şifre zorunludur.</p>
<?php endif; ?>
<form class="toolbar" method="get">
    <input type="search" name="q" value="<?= h((string) ($q ?? '')) ?>" placeholder="Firma, kullanıcı, e-posta">
    <button class="btn ghost" type="submit">Ara</button>
</form>
<div class="table-wrap">
<table>
    <tr>
        <th>Firma / kullanıcı</th>
        <th>Rol</th>
        <th>İndirim</th>
        <th>Kredi limiti</th>
        <th>Telefon</th>
    </tr>
    <?php foreach ($items as $u): ?>
        <tr>
            <td>
                <a href="<?= h(site_url('yonetim/kullanicilar/' . (int) $u['id'] . '/')) ?>"><strong><?= h($u['company'] ?: $u['display_name'] ?: $u['username']) ?></strong></a>
                <br><small><?= h($u['username']) ?> · <?= h($u['email']) ?></small>
                <?php if ((int) ($u['parent_user_id'] ?? 0) > 0): ?><br><small>Alt hesap</small><?php endif; ?>
                <?php if ((int) ($u['sub_count'] ?? 0) > 0): ?><br><small><?= (int) $u['sub_count'] ?> alt hesap</small><?php endif; ?>
            </td>
            <td><?= h(HfsDealers::roleLabel((string) $u['role'], (string) ($u['b2b_role'] ?? ''))) ?></td>
            <td><?= (float) ($u['discount_percent'] ?? 0) > 0 ? h(rtrim(rtrim(number_format((float) $u['discount_percent'], 2, ',', '.'), '0'), ',')) . ' %' : '—' ?></td>
            <td><?= (float) ($u['credit_limit'] ?? 0) > 0 ? h(money($u['credit_limit'])) : 'Sınırsız / tanımsız' ?></td>
            <td><?= h($u['phone'] ?? '') ?></td>
        </tr>
    <?php endforeach; ?>
</table>
</div>
