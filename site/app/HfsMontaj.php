<?php
declare(strict_types=1);

final class HfsMontaj
{
    /** @var array<string,string> */
    private static array $map = [
        'sirt-sistem-v2-model' => 'sirt-model-v2-montaj',
        'sirt-sistem-v3-model' => 'sirt-model-v3-montaj',
        'volt-elektrikli-v1-model' => 'volt-model-12v-montaj',
        'volt-elektrikli-v3-model' => 'volt-model-33v-montaj',
        'yan-sistem-v1-model' => 'yan-model-v1-montaj',
        'yan-sistem-v2-model' => 'yan-model-v2-montaj',
    ];

    public static function isHidden(array $cat): bool
    {
        if ((int) ($cat['hide_nav'] ?? 0) === 1) {
            return true;
        }
        $slug = (string) ($cat['slug'] ?? '');
        $path = (string) ($cat['path'] ?? '');
        if ($slug === 'uncategorized' || $slug === 'uncategorised') {
            return true;
        }
        if ($slug === 'sistem-montajlari' || str_ends_with($slug, '-montaj')) {
            return true;
        }
        return str_contains($path, 'sistem-montajlari') || str_ends_with($path, '-montaj');
    }

    public static function filterNav(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!self::isHidden($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public static function montajSlugFor(array $term): string
    {
        $cur = $term;
        $guard = 0;
        while ($cur && $guard++ < 12) {
            $slug = (string) ($cur['slug'] ?? '');
            if (isset(self::$map[$slug])) {
                return self::$map[$slug];
            }
            foreach (self::$map as $spare => $montaj) {
                if ($slug !== '' && str_contains($slug, $spare)) {
                    return $montaj;
                }
            }
            $pid = (int) ($cur['parent_id'] ?? 0);
            $cur = $pid > 0 ? Catalog::categoryById($pid) : null;
        }
        return '';
    }

    public static function featured(array $term, int $limit = 12): array
    {
        $spare = self::spareSlugFor($term);
        $items = [];
        if ($spare !== '') {
            $st = db()->prepare("SELECT * FROM products WHERE status='publish' AND slug LIKE ? ORDER BY title ASC LIMIT " . (int) $limit);
            $st->execute([$spare . '-%']);
            $items = HfsPricing::decorateMany($st->fetchAll());
        }
        if ($items === []) {
            $ids = Catalog::categoryIdsWithChildren((int) $term['id']);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = db()->prepare("SELECT p.* FROM products p
                INNER JOIN product_categories pc ON pc.product_id=p.id
                WHERE p.status='publish' AND p.has_diagram=1 AND pc.category_id IN ($ph)
                GROUP BY p.id
                ORDER BY p.featured DESC, p.title ASC
                LIMIT " . (int) $limit);
            $st->execute($ids);
            $items = HfsPricing::decorateMany($st->fetchAll());
        }
        if ($items === []) {
            return [];
        }
        $items = self::sortParts($items);
        $montajSlug = $spare !== '' ? (self::$map[$spare] ?? '') : self::montajSlugFor($term);
        return [
            'term' => $term,
            'items' => $items,
            'kicker' => 'Sistem Montajları',
            'title' => self::montajTitle($montajSlug) ?: ($term['name'] . ' montaj'),
        ];
    }

    public static function spareSlugFor(array $term): string
    {
        $cur = $term;
        $guard = 0;
        while ($cur && $guard++ < 12) {
            $slug = (string) ($cur['slug'] ?? '');
            if (isset(self::$map[$slug])) {
                return $slug;
            }
            $pid = (int) ($cur['parent_id'] ?? 0);
            $cur = $pid > 0 ? Catalog::categoryById($pid) : null;
        }
        return '';
    }

    /**
     * Mega menü: montaj parçaları (kategori altında alt kategori yoksa).
     *
     * @return list<array{cat:array<string,mixed>,label:string,href:string,on:bool,children:list<array<string,mixed>>}>
     */
    public static function navLinks(array $term, string $pathNow = ''): array
    {
        if (self::spareSlugFor($term) === '') {
            return [];
        }
        $block = self::featured($term, 12);
        $items = $block['items'] ?? [];
        if ($items === []) {
            return [];
        }
        $out = [];
        foreach ($items as $p) {
            $slug = (string) ($p['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $on = str_starts_with($pathNow, '/urun/' . $slug . '/');
            $out[] = [
                'cat' => $p,
                'label' => (string) ($p['title'] ?? ''),
                'href' => permalink_product($p),
                'on' => $on,
                'children' => [],
            ];
        }
        return $out;
    }

    public static function montajTitle(string $slug): string
    {
        $names = [
            'sirt-model-v2-montaj' => 'Sırt Model V2 Montaj',
            'sirt-model-v3-montaj' => 'Sırt Model V3 Montaj',
            'volt-model-12v-montaj' => 'Volt Model 12V Montaj',
            'volt-model-33v-montaj' => 'Volt Model 33V Montaj',
            'yan-model-v1-montaj' => 'Yan Model V1 Montaj',
            'yan-model-v2-montaj' => 'Yan Model V2 Montaj',
        ];
        return $names[$slug] ?? '';
    }

    /**
     * Parça diyagram sırası: şanzıman → tetik → tahrik → kelepçe → motor → (elcik) → kanca.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function sortParts(array $items): array
    {
        usort($items, static function (array $a, array $b): int {
            $ra = self::partRank((string) ($a['slug'] ?? ''), (string) ($a['title'] ?? ''));
            $rb = self::partRank((string) ($b['slug'] ?? ''), (string) ($b['title'] ?? ''));
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return strnatcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        });
        return array_values($items);
    }

    public static function partRank(string $slug, string $title = ''): int
    {
        $hay = strtolower($slug . ' ' . strtr(mb_strtolower($title, 'UTF-8'), [
            'ı' => 'i', 'İ' => 'i', 'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c',
        ]));
        $order = [
            'sanziman' => 10,
            'tetik' => 20,
            'tahrik' => 30,
            'kelepce' => 40,
            'motor' => 50,
            'elcik' => 60,
            'govde' => 10,
            'kol-grubu' => 20,
            'kanca' => 90,
        ];
        foreach ($order as $needle => $rank) {
            if (str_contains($hay, $needle)) {
                return $rank;
            }
        }
        if (preg_match('/grup-(\d+)/', $hay, $m) || preg_match('/grup\s+(\d+)/', $hay, $m)) {
            return 10 * (int) $m[1];
        }
        return 80;
    }
}
