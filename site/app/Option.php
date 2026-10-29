<?php
declare(strict_types=1);

final class Option
{
    public static function groups(): array
    {
        return db()->query('SELECT * FROM option_groups ORDER BY sort, id')->fetchAll();
    }

    /** @return list<array{id:int,slug:string,name:string,sort:int,values:list<array<string,mixed>>}> */
    public static function groupsWithValues(): array
    {
        $out = [];
        foreach (self::groups() as $g) {
            $g['values'] = self::values((int) $g['id']);
            $out[] = $g;
        }
        return $out;
    }

    public static function values(int $groupId): array
    {
        $st = db()->prepare('SELECT * FROM option_values WHERE group_id=? ORDER BY sort, id');
        $st->execute([$groupId]);
        return $st->fetchAll();
    }

    public static function groupIdsForProduct(int $productId): array
    {
        $st = db()->prepare('SELECT group_id FROM product_option_groups WHERE product_id=? ORDER BY group_id');
        $st->execute([$productId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<array{id:int,slug:string,name:string,values:list<array<string,mixed>>}>
     */
    public static function axesForProduct(int $productId): array
    {
        $ids = self::groupIdsForProduct($productId);
        if ($ids === []) {
            return [];
        }
        $allowed = self::valueIdsForProduct($productId);
        $filterValues = $allowed !== [];
        $out = [];
        foreach (self::groups() as $g) {
            if (!in_array((int) $g['id'], $ids, true)) {
                continue;
            }
            $vals = self::values((int) $g['id']);
            if ($filterValues) {
                $vals = array_values(array_filter($vals, static fn (array $v): bool => in_array((int) $v['id'], $allowed, true)));
            }
            if ($vals === []) {
                continue;
            }
            $out[] = [
                'id' => (int) $g['id'],
                'slug' => (string) $g['slug'],
                'name' => (string) $g['name'],
                'values' => $vals,
            ];
        }
        return $out;
    }

    /** @return list<int> */
    public static function valueIdsForProduct(int $productId): array
    {
        $st = db()->prepare('SELECT value_id FROM product_option_values WHERE product_id=? ORDER BY value_id');
        $st->execute([$productId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param list<int> $valueIds */
    public static function setProductValues(int $productId, array $valueIds): void
    {
        db()->prepare('DELETE FROM product_option_values WHERE product_id=?')->execute([$productId]);
        $ins = db()->prepare('INSERT IGNORE INTO product_option_values (product_id, value_id) VALUES (?,?)');
        foreach ($valueIds as $vid) {
            $vid = (int) $vid;
            if ($vid > 0) {
                $ins->execute([$productId, $vid]);
            }
        }
    }

    public static function groupIdBySlug(string $slug): int
    {
        $st = db()->prepare('SELECT id FROM option_groups WHERE slug=? LIMIT 1');
        $st->execute([$slug]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    public static function valueIdBySlug(string $groupSlug, string $valueSlug): int
    {
        $gid = self::groupIdBySlug($groupSlug);
        if ($gid < 1) {
            return 0;
        }
        $st = db()->prepare('SELECT id FROM option_values WHERE group_id=? AND slug=? LIMIT 1');
        $st->execute([$gid, $valueSlug]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    /** @return list<list<array<string,mixed>>> */
    public static function combosForProduct(int $productId): array
    {
        $axes = self::axesForProduct($productId);
        if ($axes === []) {
            return [];
        }
        $sets = array_map(static fn (array $a): array => $a['values'], $axes);
        $out = [[]];
        foreach ($sets as $list) {
            $next = [];
            foreach ($out as $row) {
                foreach ($list as $item) {
                    $next[] = [...$row, $item];
                }
            }
            $out = $next;
        }
        return $out;
    }

    /**
     * Sepet satırı notu; Diğer motor seçiliyken metin zorunlu. false = eksik.
     */
    public static function motorOtherFromNote(string $cartNote): string
    {
        $cartNote = trim($cartNote);
        if ($cartNote === '') {
            return '';
        }
        if (preg_match('/Motor \(Diğer\):\s*(.+)$/u', $cartNote, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /** Sepet / sipariş satırında “Diğer” yerine yazılan motor adı. */
    public static function variantDisplayName(?array $variant, string $cartNote): string
    {
        $name = trim((string) ($variant['name'] ?? ''));
        if ($name === '') {
            return '';
        }
        $custom = self::motorOtherFromNote($cartNote);
        if ($custom === '') {
            return $name;
        }
        if (str_contains($name, ' · Diğer')) {
            return str_replace(' · Diğer', ' · ' . $custom, $name);
        }
        if (str_ends_with($name, 'Diğer')) {
            return rtrim(mb_substr($name, 0, -mb_strlen('Diğer'))) . $custom;
        }
        return $name;
    }

    /** Motor (Diğer) notu başlıkta gösterildiğinde alt satırda tekrarlanmasın. */
    public static function noteForDisplay(string $cartNote): string
    {
        $cartNote = trim($cartNote);
        if ($cartNote === '') {
            return '';
        }
        if (preg_match('/^Motor \(Diğer\):\s*.+$/u', $cartNote)) {
            return '';
        }
        $stripped = preg_replace('/\s*·\s*Motor \(Diğer\):\s*.+$/u', '', $cartNote);
        return is_string($stripped) ? trim($stripped) : $cartNote;
    }

    public static function cartLineNote(int $productId, int $variantId, string $note, string $motorOther): string|false
    {
        $note = mb_substr(trim($note), 0, 80);
        if ($variantId < 1 || $productId < 1) {
            return $note;
        }
        $st = db()->prepare('SELECT attrs FROM variants WHERE id=? AND product_id=? LIMIT 1');
        $st->execute([$variantId, $productId]);
        $attrsJson = (string) ($st->fetchColumn() ?: '');
        if ($attrsJson === '') {
            return $note;
        }
        $otherId = self::valueIdBySlug('motor-tipi', 'diger');
        $motorGid = self::groupIdBySlug('motor-tipi');
        if ($otherId < 1 || $motorGid < 1) {
            return $note;
        }
        $attrs = self::decodeAttrs($attrsJson);
        $picked = (int) ($attrs[(string) $motorGid] ?? $attrs[$motorGid] ?? 0);
        if ($picked !== $otherId) {
            return $note;
        }
        $motorOther = trim($motorOther);
        if ($motorOther === '') {
            return false;
        }
        $extra = 'Motor (Diğer): ' . mb_substr($motorOther, 0, 48);
        if ($note !== '') {
            return mb_substr($note . ' · ' . $extra, 0, 80);
        }
        return mb_substr($extra, 0, 80);
    }

    /**
     * @return int Toplam stok (ürün stoğu güncellemesi için)
     */
    public static function regenProductVariants(int $productId, string $skuBase, bool $forceCombos, array $manualRows = []): int
    {
        $groupIds = self::groupIdsForProduct($productId);
        $oldVars = Catalog::variants($productId);
        $oldByAttrs = [];
        foreach ($oldVars as $v) {
            $key = (string) ($v['attrs'] ?? '');
            if ($key !== '') {
                $oldByAttrs[$key] = $v;
            }
        }
        $hadSimple = false;
        foreach ($oldVars as $v) {
            if (($v['attrs'] ?? '') === '' && trim((string) ($v['name'] ?? '')) !== '') {
                $hadSimple = true;
                break;
            }
        }
        $rows = [];
        if ($manualRows !== []) {
            $rows = $manualRows;
        } elseif ($groupIds !== [] && ($forceCombos || ($oldByAttrs === [] && !$hadSimple))) {
            foreach (self::combosForProduct($productId) as $combo) {
                $json = self::attrsKey($combo);
                $old = $oldByAttrs[$json] ?? null;
                $slugs = array_map(static fn (array $i): string => slugify((string) $i['name']), $combo);
                $autoSku = $skuBase !== '' ? $skuBase . '-' . implode('-', $slugs) : '';
                $priceRaw = $old['price'] ?? null;
                $rows[] = [
                    'name' => self::comboName($combo),
                    'sku' => $old['sku'] ?? $autoSku,
                    'stock' => (int) ($old['stock'] ?? 0),
                    'price' => ($priceRaw !== null && $priceRaw !== '') ? (float) $priceRaw : null,
                    'attrs' => $json,
                ];
            }
        }
        if ($rows === []) {
            return 0;
        }
        db()->prepare('DELETE FROM variants WHERE product_id=?')->execute([$productId]);
        $vins = db()->prepare('INSERT INTO variants (product_id,sku,name,price,stock,sort,attrs) VALUES (?,?,?,?,?,?,?)');
        $sumStock = 0;
        foreach ($rows as $i => $row) {
            $vins->execute([$productId, $row['sku'], $row['name'], $row['price'], $row['stock'], $i, $row['attrs']]);
            $sumStock += $row['stock'];
        }
        return $sumStock;
    }

    public static function seedHfsMachines(): void
    {
        $groups = [
            ['sistem-uzunlugu', 'Sistem Uzunluğu', 10, ['1,5 Metre', '2 Metre']],
            ['kanca-araligi', 'Kanca Aralığı', 20, ['35 Milimetre', '55 Milimetre']],
            ['motor-tipi', 'Motor Tipi', 30, ['HFS', 'Kawasaki', 'Ole-Mac', 'Stihl', 'Husqvarna', 'Palmera', 'Çin', 'Diğer']],
        ];
        self::seedGroups($groups);
    }

    /** @param list<array{0:string,1:string,2:int,3:list<string>}> $groups */
    private static function seedGroups(array $groups): void
    {
        $findG = db()->prepare('SELECT id FROM option_groups WHERE slug=? LIMIT 1');
        $insG = db()->prepare('INSERT INTO option_groups (slug,name,sort) VALUES (?,?,?)');
        $updG = db()->prepare('UPDATE option_groups SET name=?, sort=? WHERE id=?');
        $findV = db()->prepare('SELECT id FROM option_values WHERE group_id=? AND slug=? LIMIT 1');
        $insV = db()->prepare('INSERT INTO option_values (group_id,slug,name,sort) VALUES (?,?,?,?)');
        $updV = db()->prepare('UPDATE option_values SET name=?, sort=? WHERE id=?');
        foreach ($groups as [$slug, $name, $sort, $values]) {
            $findG->execute([$slug]);
            $gid = (int) ($findG->fetchColumn() ?: 0);
            if ($gid) {
                $updG->execute([$name, $sort, $gid]);
            } else {
                $insG->execute([$slug, $name, $sort]);
                $gid = (int) db()->lastInsertId();
            }
            foreach ($values as $i => $label) {
                $vslug = slugify($label);
                $findV->execute([$gid, $vslug]);
                $vid = (int) ($findV->fetchColumn() ?: 0);
                if ($vid) {
                    $updV->execute([$label, $i, $vid]);
                } else {
                    $insV->execute([$gid, $vslug, $label, $i]);
                }
            }
        }
    }

    /** @param list<string> $productSlugs */
    public static function wireMachineVariants(array $productSlugs): void
    {
        $groupIds = [];
        foreach (['sistem-uzunlugu', 'kanca-araligi', 'motor-tipi'] as $gs) {
            $id = self::groupIdBySlug($gs);
            if ($id > 0) {
                $groupIds[] = $id;
            }
        }
        if ($groupIds === []) {
            return;
        }
        $allValueIds = [];
        foreach ($groupIds as $gid) {
            foreach (self::values($gid) as $v) {
                $allValueIds[] = (int) $v['id'];
            }
        }
        foreach ($productSlugs as $slug) {
            $st = db()->prepare("SELECT id, sku, stock FROM products WHERE slug=? LIMIT 1");
            $st->execute([$slug]);
            $p = $st->fetch();
            if (!$p) {
                continue;
            }
            $pid = (int) $p['id'];
            self::setProductGroups($pid, $groupIds);
            self::setProductValues($pid, $allValueIds);
            $skuBase = trim((string) ($p['sku'] ?? ''));
            $stock = max(0, (int) ($p['stock'] ?? 0));
            self::regenProductVariants($pid, $skuBase, true);
            $comboN = count(self::combosForProduct($pid));
            if ($comboN > 0 && $stock > 0) {
                db()->prepare('UPDATE variants SET stock=? WHERE product_id=?')->execute([$stock, $pid]);
                db()->prepare('UPDATE products SET stock=? WHERE id=?')->execute([$stock * $comboN, $pid]);
            }
        }
    }

    /**
     * @param list<int> $groupIds
     * @return list<list<array<string,mixed>>>
     */
    public static function cartesian(array $groupIds): array
    {
        $sets = [];
        foreach ($groupIds as $gid) {
            $vals = self::values($gid);
            if ($vals === []) {
                continue;
            }
            $sets[] = $vals;
        }
        if ($sets === []) {
            return [];
        }
        $out = [[]];
        foreach ($sets as $list) {
            $next = [];
            foreach ($out as $row) {
                foreach ($list as $item) {
                    $next[] = [...$row, $item];
                }
            }
            $out = $next;
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $combo */
    public static function attrsKey(array $combo): string
    {
        $attrs = [];
        foreach ($combo as $item) {
            $attrs[(string) $item['group_id']] = (int) $item['id'];
        }
        ksort($attrs);
        return (string) json_encode($attrs, JSON_UNESCAPED_UNICODE);
    }

    /** @param list<array<string,mixed>> $combo */
    public static function comboName(array $combo): string
    {
        return implode(' · ', array_map(static fn (array $i): string => (string) $i['name'], $combo));
    }

    public static function decodeAttrs(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    public static function setProductGroups(int $productId, array $groupIds): void
    {
        db()->prepare('DELETE FROM product_option_groups WHERE product_id=?')->execute([$productId]);
        $ins = db()->prepare('INSERT IGNORE INTO product_option_groups (product_id, group_id) VALUES (?,?)');
        foreach ($groupIds as $gid) {
            $gid = (int) $gid;
            if ($gid > 0) {
                $ins->execute([$productId, $gid]);
            }
        }
    }

    public static function seedCoffee(): void
    {
        self::seedGroups([
            ['gramaj', 'Gramaj', 10, ['250 gr', '500 gr', '1000 gr']],
            ['ogutme', 'Öğütme derecesi', 20, [
                'Çekirdek',
                'Kağıt Filtre / V60',
                'Metal Filtre / Makine',
                'French Press',
                'Aeropress',
                'Moka Pot',
            ]],
            ['kavurma', 'Kavurma derecesi', 30, ['Açık', 'Orta', 'Koyu']],
        ]);
    }

    public static function adminPost(): void
    {
        $act = (string) ($_POST['act'] ?? '');
        if ($act === 'add_group') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                return;
            }
            $slug = slugify((string) ($_POST['slug'] ?: $name));
            $st = db()->prepare('SELECT id FROM option_groups WHERE slug=?');
            $st->execute([$slug]);
            if ($st->fetchColumn()) {
                $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            }
            db()->prepare('INSERT INTO option_groups (slug,name,sort) VALUES (?,?,?)')
                ->execute([$slug, $name, (int) ($_POST['sort'] ?? 0)]);
            return;
        }
        if ($act === 'save_group') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($id < 1 || $name === '') {
                return;
            }
            db()->prepare('UPDATE option_groups SET name=?, sort=? WHERE id=?')
                ->execute([$name, (int) ($_POST['sort'] ?? 0), $id]);
            return;
        }
        if ($act === 'delete_group') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                return;
            }
            db()->prepare('DELETE pov FROM product_option_values pov INNER JOIN option_values ov ON ov.id=pov.value_id WHERE ov.group_id=?')->execute([$id]);
            db()->prepare('DELETE FROM option_values WHERE group_id=?')->execute([$id]);
            db()->prepare('DELETE FROM product_option_groups WHERE group_id=?')->execute([$id]);
            db()->prepare('DELETE FROM option_groups WHERE id=?')->execute([$id]);
            return;
        }
        if ($act === 'add_value') {
            $gid = (int) ($_POST['group_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($gid < 1 || $name === '') {
                return;
            }
            $slug = slugify((string) ($_POST['slug'] ?: $name));
            $st = db()->prepare('SELECT id FROM option_values WHERE group_id=? AND slug=?');
            $st->execute([$gid, $slug]);
            if ($st->fetchColumn()) {
                $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            }
            $mx = db()->prepare('SELECT COALESCE(MAX(sort),-1) FROM option_values WHERE group_id=?');
            $mx->execute([$gid]);
            $sort = (int) $mx->fetchColumn() + 1;
            db()->prepare('INSERT INTO option_values (group_id,slug,name,sort) VALUES (?,?,?,?)')
                ->execute([$gid, $slug, $name, $sort]);
            return;
        }
        if ($act === 'save_value') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($id < 1 || $name === '') {
                return;
            }
            db()->prepare('UPDATE option_values SET name=?, sort=? WHERE id=?')
                ->execute([$name, (int) ($_POST['sort'] ?? 0), $id]);
            return;
        }
        if ($act === 'delete_value') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                db()->prepare('DELETE FROM product_option_values WHERE value_id=?')->execute([$id]);
                db()->prepare('DELETE FROM option_values WHERE id=?')->execute([$id]);
            }
        }
    }
}
