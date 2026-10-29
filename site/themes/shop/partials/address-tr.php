<?php
$row = $row ?? [];
$req = !empty($requireTrAddress);
$city = trim((string) ($row['city'] ?? ''));
$district = trim((string) ($row['district'] ?? ''));
$postcode = trim((string) ($row['postcode'] ?? ''));
$provs = HfsTrAddress::provinces();
$matchProv = static function (string $a, string $b): bool {
    if (function_exists('mb_strtolower')) {
        return mb_strtolower(trim($a), 'UTF-8') === mb_strtolower(trim($b), 'UTF-8');
    }
    return strtolower(trim($a)) === strtolower(trim($b));
};
?>
<div class="hfs-tr-address" data-hfs-tr-address data-src="<?= h(HfsTrAddress::assetUrl()) ?>" data-city="<?= h($city) ?>" data-district="<?= h($district) ?>" data-postcode="<?= h($postcode) ?>">
    <label>Ülke
        <select disabled aria-readonly="true">
            <option selected>Türkiye (TR)</option>
        </select>
        <input type="hidden" name="country" value="TR">
    </label>
    <label>İl
        <select name="city" autocomplete="address-level1"<?= $req ? ' required' : '' ?>>
            <option value="">Seçin</option>
            <?php foreach ($provs as $p): ?>
                <option value="<?= h($p['name']) ?>"<?= $city !== '' && $matchProv($city, $p['name']) ? ' selected' : '' ?>><?= h($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>İlçe
        <select name="district" autocomplete="address-level2"<?= $req ? ' required' : '' ?><?= $city === '' ? ' disabled' : '' ?>>
            <option value=""><?= $city === '' ? 'Önce il seçin' : 'Seçin' ?></option>
            <?php if ($district !== ''): ?>
                <option value="<?= h($district) ?>" selected><?= h($district) ?></option>
            <?php endif; ?>
        </select>
    </label>
    <label>Posta kodu
        <input type="text" name="postcode" inputmode="numeric" maxlength="5" readonly<?= $req ? ' required' : '' ?> value="<?= h($postcode) ?>" placeholder="İlçe seçince dolar">
    </label>
</div>
