<?php
declare(strict_types=1);

final class Shipping
{
    public static function cities(): array
    {
        $raw = trim((string) setting('ship_cities', ''));
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/\r\n|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$city, $fee] = array_map('trim', explode('=', $line, 2));
            if ($city !== '') {
                $out[$city] = (float) $fee;
            }
        }
        return $out;
    }

    public static function slots(): array
    {
        $raw = trim((string) setting('ship_slots', ''));
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\n/', $raw) ?: [])));
    }

    public static function fee(float $subtotal, string $country = 'TR', string $city = '', bool $digital = false): float
    {
        if ($digital || $subtotal <= 0) {
            return 0;
        }
        $country = strtoupper($country ?: 'TR');
        if ($country !== 'TR') {
            $intl = (float) setting('ship_intl', '189');
            return $subtotal >= free_ship_min() ? 0.0 : $intl;
        }
        $map = self::cities();
        if ($city !== '' && isset($map[$city])) {
            $fee = $map[$city];
            return $subtotal >= free_ship_min() ? 0.0 : $fee;
        }
        return shipping_for($subtotal);
    }

    public static function countries(): array
    {
        return [
            'TR' => 'Türkiye',
            'DE' => 'Almanya',
            'NL' => 'Hollanda',
            'GB' => 'Birleşik Krallık',
            'US' => 'ABD',
            'CY' => 'KKTC / Kıbrıs',
        ];
    }
}
