<?php
declare(strict_types=1);

final class HfsTrAddress
{
    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    /** @return array<string,mixed> */
    private static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }
        $file = BMN_ROOT . '/data/tr-address.json';
        if (!is_file($file)) {
            $file = BMN_ROOT . '/themes/shop/assets/tr-address.json';
        }
        $raw = is_file($file) ? file_get_contents($file) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        self::$data = is_array($decoded) ? $decoded : ['country' => 'TR', 'provinces' => []];
        return self::$data;
    }

    private static function norm(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        // OSMANGAZİ → osmangazi̇ (combining dot) olmasın; JSON'daki Osmangazi ile eşleşsin.
        $s = strtr($s, [
            'I' => 'i', 'İ' => 'i', 'ı' => 'i',
            'Ğ' => 'g', 'ğ' => 'g',
            'Ü' => 'u', 'ü' => 'u',
            'Ş' => 's', 'ş' => 's',
            'Ö' => 'o', 'ö' => 'o',
            'Ç' => 'c', 'ç' => 'c',
        ]);
        $s = preg_replace('/\p{M}+/u', '', $s) ?? $s;
        if (function_exists('mb_strtolower')) {
            $s = mb_strtolower($s, 'UTF-8');
        } else {
            $s = strtolower($s);
        }
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /** @return list<array{id:int,name:string}> */
    public static function provinces(): array
    {
        $out = [];
        foreach ((array) (self::data()['provinces'] ?? []) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = ['id' => (int) ($p['id'] ?? 0), 'name' => $name];
        }
        return $out;
    }

    /**
     * @return array{city:string,district:string,postcode:string,country:string}|null
     */
    public static function resolve(string $city, string $district): ?array
    {
        $hit = self::resolveExact($city, $district);
        if ($hit) {
            return $hit;
        }
        // İl / ilçe yer değiştirilmişse (OSMANGAZİ + BURSA) düzelt.
        if (trim($city) !== '' && trim($district) !== '') {
            return self::resolveExact($district, $city);
        }
        return null;
    }

    /** @return array{city:string,district:string,postcode:string,country:string}|null */
    private static function resolveExact(string $city, string $district): ?array
    {
        $cityN = self::norm($city);
        $distN = self::norm($district);
        if ($cityN === '' || $distN === '') {
            return null;
        }
        foreach ((array) (self::data()['provinces'] ?? []) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $pname = trim((string) ($p['name'] ?? ''));
            if (self::norm($pname) !== $cityN) {
                continue;
            }
            foreach ((array) ($p['districts'] ?? []) as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $dname = trim((string) ($d['name'] ?? ''));
                if (self::norm($dname) !== $distN) {
                    continue;
                }
                $zip = preg_replace('/\D+/', '', (string) ($d['postcode'] ?? '')) ?? '';
                if (strlen($zip) !== 5) {
                    $zip = sprintf('%02d000', (int) ($p['id'] ?? 0));
                }
                return [
                    'city' => $pname,
                    'district' => $dname,
                    'postcode' => $zip,
                    'country' => 'TR',
                ];
            }
        }
        return null;
    }

    public static function assetUrl(): string
    {
        return asset('tr-address.json');
    }
}
