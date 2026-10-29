<?php
declare(strict_types=1);

final class HfsNav
{
    private const SETTING_KEY = 'nav_mega_json';

    /** @return list<array{cat:array<string,mixed>,label:string,href:string,on:bool,children:list<array<string,mixed>>}> */
    public static function items(string $pathNow = ''): array
    {
        $pathNow = $pathNow !== '' ? $pathNow : request_path();
        $out = [];
        foreach (self::config() as $row) {
            if (empty($row['enabled'])) {
                continue;
            }
            $node = self::buildItem($row, $pathNow);
            if ($node !== null) {
                $out[] = $node;
            }
        }
        return $out;
    }

    /**
     * Panel kaydı: üst menü öğeleri.
     *
     * @return list<array{id:string,label:string,category_id:int,extra_ids:list<int>,child_ids:list<int>,enabled:bool}>
     */
    public static function config(): array
    {
        $raw = (string) setting(self::SETTING_KEY, '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && $decoded !== []) {
                return self::normalizeList($decoded);
            }
        }
        return self::defaults();
    }

    /** @param list<array<string,mixed>> $rows */
    public static function save(array $rows): void
    {
        setting_set(self::SETTING_KEY, json_encode(self::normalizeList($rows), JSON_UNESCAPED_UNICODE));
    }

    public static function ensureSeeded(): void
    {
        $raw = (string) setting(self::SETTING_KEY, '');
        if ($raw === '') {
            self::save(self::defaults());
        }
    }

    /**
     * @return list<array{id:string,label:string,category_id:int,extra_ids:list<int>,child_ids:list<int>,enabled:bool}>
     */
    public static function defaults(): array
    {
        $tam = Catalog::categoryByPath('tam-sistemler');
        $yedek = Catalog::categoryByPath('yedek-parcalar');
        $aski = Catalog::categoryByPath('aski-aparati');
        $out = [];
        if ($tam) {
            $extra = $aski ? [(int) $aski['id']] : [];
            $out[] = [
                'id' => 'hasat',
                'label' => 'Hasat Makinaları',
                'category_id' => (int) $tam['id'],
                'extra_ids' => $extra,
                'child_ids' => [],
                'enabled' => true,
            ];
        }
        if ($yedek) {
            $kids = self::orderYedek(Catalog::categories((int) $yedek['id']));
            $out[] = [
                'id' => 'yedek',
                'label' => '',
                'category_id' => (int) $yedek['id'],
                'extra_ids' => [],
                'child_ids' => array_map(static fn (array $c): int => (int) $c['id'], $kids),
                'enabled' => true,
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $post */
    public static function saveFromPost(array $post): string
    {
        $act = (string) ($post['act'] ?? '');
        $rows = self::config();

        if ($act === 'add') {
            $catId = (int) ($post['category_id'] ?? 0);
            $cat = $catId > 0 ? Catalog::categoryById($catId) : null;
            if (!$cat) {
                return 'Kategori seçin.';
            }
            $label = trim((string) ($post['label'] ?? ''));
            $rows[] = [
                'id' => self::newId(),
                'label' => $label,
                'category_id' => $catId,
                'extra_ids' => self::idsFromPost($post['extra_ids'] ?? []),
                'child_ids' => [],
                'enabled' => true,
            ];
            self::save($rows);
            return '';
        }

        if ($act === 'save') {
            $id = (string) ($post['id'] ?? '');
            $idx = self::indexOf($rows, $id);
            if ($idx < 0) {
                return 'Menü bulunamadı.';
            }
            $catId = (int) ($post['category_id'] ?? 0);
            $cat = $catId > 0 ? Catalog::categoryById($catId) : null;
            if (!$cat) {
                return 'Kategori seçin.';
            }
            $rows[$idx]['label'] = trim((string) ($post['label'] ?? ''));
            $rows[$idx]['category_id'] = $catId;
            $rows[$idx]['extra_ids'] = self::idsFromPost($post['extra_ids'] ?? []);
            $rows[$idx]['child_ids'] = self::idsFromPost($post['child_ids'] ?? []);
            $rows[$idx]['enabled'] = !empty($post['enabled']);
            self::save($rows);
            return '';
        }

        if ($act === 'delete') {
            $id = (string) ($post['id'] ?? '');
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ($r['id'] ?? '') !== $id));
            self::save($rows);
            return '';
        }

        if ($act === 'reorder') {
            $map = [];
            foreach ($rows as $r) {
                $map[(string) $r['id']] = $r;
            }
            $rawOrder = $post['order_ids'] ?? [];
            if (!is_array($rawOrder)) {
                $rawOrder = [];
            }
            $next = [];
            foreach ($rawOrder as $oid) {
                $key = (string) $oid;
                if (isset($map[$key])) {
                    $next[] = $map[$key];
                    unset($map[$key]);
                }
            }
            foreach ($map as $left) {
                $next[] = $left;
            }
            self::save($next);
            return '';
        }

        if ($act === 'toggle') {
            $id = (string) ($post['id'] ?? '');
            $idx = self::indexOf($rows, $id);
            if ($idx < 0) {
                return 'Menü bulunamadı.';
            }
            $rows[$idx]['enabled'] = empty($rows[$idx]['enabled']);
            self::save($rows);
            return '';
        }

        if ($act === 'reset') {
            self::save(self::defaults());
            return '';
        }

        return 'Geçersiz işlem.';
    }

    /**
     * Menü için seçilebilir alt kategori adayları (üst + ekler).
     *
     * @param array{category_id?:int,extra_ids?:list<int>} $row
     * @return list<array<string,mixed>>
     */
    public static function childCandidates(array $row): array
    {
        $catId = (int) ($row['category_id'] ?? 0);
        $kids = $catId > 0 ? Catalog::categories($catId) : [];
        foreach ($row['extra_ids'] ?? [] as $eid) {
            $extra = Catalog::categoryById((int) $eid);
            if ($extra) {
                $kids[] = $extra;
            }
        }
        $seen = [];
        $out = [];
        foreach ($kids as $c) {
            $id = (int) ($c['id'] ?? 0);
            if ($id < 1 || isset($seen[$id]) || HfsMontaj::isHidden($c)) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $c;
        }
        return $out;
    }

    public static function label(array $cat): string
    {
        return match ((string) ($cat['slug'] ?? '')) {
            'tam-sistemler' => 'Hasat Makinaları',
            'sirttan-askili-zeytin-hasat-makinalari' => 'Sırttan Askılı Hasat Makinaları',
            'yandan-askili-zeytin-hasat-makinalari' => 'Yandan Askılı Hasat Makinaları',
            'akulu-zeytin-silkeleme-makinalari' => 'Akülü Zeytin Silkeleme Makinesi',
            'motorlar' => 'Motorlar',
            default => (string) ($cat['name'] ?? ''),
        };
    }

    /**
     * @param array{id:string,label:string,category_id:int,extra_ids:list<int>,child_ids:list<int>,enabled:bool} $row
     * @return array{cat:array<string,mixed>,label:string,href:string,on:bool,children:list<array<string,mixed>>}|null
     */
    private static function buildItem(array $row, string $pathNow): ?array
    {
        $cat = Catalog::categoryById((int) $row['category_id']);
        if (!$cat || ($cat['status'] ?? '') !== 'publish') {
            return null;
        }
        $kids = self::resolveChildren($row);
        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') {
            $label = self::label($cat);
        }
        return self::node($cat, $kids, $pathNow, $label);
    }

    /**
     * @param array{category_id:int,extra_ids:list<int>,child_ids:list<int>} $row
     * @return list<array<string,mixed>>
     */
    private static function resolveChildren(array $row): array
    {
        $candidates = self::childCandidates($row);
        $byId = [];
        foreach ($candidates as $c) {
            $byId[(int) $c['id']] = $c;
        }
        $childIds = $row['child_ids'] ?? [];
        if ($childIds === []) {
            return array_values($byId);
        }
        $out = [];
        foreach ($childIds as $cid) {
            $id = (int) $cid;
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
                unset($byId[$id]);
            }
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $kids */
    private static function orderYedek(array $kids): array
    {
        $order = [
            'hfs-motor-yedek-parcalari' => 0,
            'sirt-sistem-v2-model' => 1,
            'sirt-sistem-v3-model' => 2,
            'volt-elektrikli-v1-model' => 3,
            'volt-elektrikli-v3-model' => 4,
            'yan-sistem-v1-model' => 5,
            'yan-sistem-v2-model' => 6,
        ];
        usort($kids, static function (array $a, array $b) use ($order): int {
            $ia = $order[(string) ($a['slug'] ?? '')] ?? 50;
            $ib = $order[(string) ($b['slug'] ?? '')] ?? 50;
            return $ia <=> $ib;
        });
        return $kids;
    }

    /**
     * @param list<array<string,mixed>> $kids
     * @return array{cat:array<string,mixed>,label:string,href:string,on:bool,children:list<array<string,mixed>>}
     */
    private static function node(array $cat, array $kids, string $pathNow, ?string $label = null): array
    {
        $href = permalink_category($cat);
        $on = str_starts_with($pathNow, '/kategori/' . ($cat['path'] ?? ''));
        $children = [];
        foreach ($kids as $ch) {
            if (HfsMontaj::isHidden($ch)) {
                continue;
            }
            $grand = Catalog::categories((int) $ch['id']);
            $grandOut = [];
            foreach ($grand as $g) {
                if (HfsMontaj::isHidden($g)) {
                    continue;
                }
                $gOn = str_starts_with($pathNow, '/kategori/' . ($g['path'] ?? ''));
                $grandOut[] = [
                    'cat' => $g,
                    'label' => self::label($g),
                    'href' => permalink_category($g),
                    'on' => $gOn,
                    'children' => [],
                ];
                if ($gOn) {
                    $on = true;
                }
            }
            if ($grandOut === []) {
                foreach (HfsMontaj::navLinks($ch, $pathNow) as $link) {
                    $grandOut[] = $link;
                    if (!empty($link['on'])) {
                        $on = true;
                    }
                }
            }
            $chOn = str_starts_with($pathNow, '/kategori/' . ($ch['path'] ?? ''));
            if (!$chOn) {
                foreach ($grandOut as $g) {
                    if (!empty($g['on'])) {
                        $chOn = true;
                        break;
                    }
                }
            }
            if ($chOn) {
                $on = true;
            }
            $children[] = [
                'cat' => $ch,
                'label' => self::label($ch),
                'href' => permalink_category($ch),
                'on' => $chOn,
                'children' => $grandOut,
            ];
        }
        return [
            'cat' => $cat,
            'label' => $label ?? self::label($cat),
            'href' => $href,
            'on' => $on,
            'children' => $children,
        ];
    }

    /** @param list<mixed> $decoded */
    private static function normalizeList(array $decoded): array
    {
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $catId = (int) ($row['category_id'] ?? 0);
            if ($catId < 1) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                $id = self::newId();
            }
            $out[] = [
                'id' => $id,
                'label' => trim((string) ($row['label'] ?? '')),
                'category_id' => $catId,
                'extra_ids' => self::idsFromPost($row['extra_ids'] ?? []),
                'child_ids' => self::idsFromPost($row['child_ids'] ?? []),
                'enabled' => !isset($row['enabled']) || !empty($row['enabled']),
            ];
        }
        return $out;
    }

    /** @param mixed $raw @return list<int> */
    private static function idsFromPost(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $v) {
            $id = (int) $v;
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $id;
        }
        return $out;
    }

    /** @param list<array{id:string}> $rows */
    private static function indexOf(array $rows, string $id): int
    {
        foreach ($rows as $i => $r) {
            if (($r['id'] ?? '') === $id) {
                return (int) $i;
            }
        }
        return -1;
    }

    private static function newId(): string
    {
        try {
            return bin2hex(random_bytes(6));
        } catch (Throwable) {
            return uniqid('m', true);
        }
    }
}
