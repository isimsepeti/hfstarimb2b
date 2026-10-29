<?php
declare(strict_types=1);

final class Review
{
    public static function published(int $productId): array
    {
        $st = db()->prepare("SELECT * FROM reviews WHERE product_id=? AND status='publish' ORDER BY id DESC LIMIT 40");
        $st->execute([$productId]);
        return $st->fetchAll();
    }

    public static function stats(int $productId): array
    {
        $st = db()->prepare("SELECT COUNT(*) n, AVG(rating) a FROM reviews WHERE product_id=? AND status='publish'");
        $st->execute([$productId]);
        $row = $st->fetch() ?: ['n' => 0, 'a' => 0];
        return ['count' => (int) $row['n'], 'avg' => round((float) $row['a'], 1)];
    }

    public static function inviteForOrder(array $order): void
    {
        $st = db()->prepare('SELECT id FROM review_invites WHERE order_id=?');
        $st->execute([(int) $order['id']]);
        if ($st->fetch()) {
            return;
        }
        $token = bin2hex(random_bytes(16));
        db()->prepare('INSERT INTO review_invites (order_id,token,sent_at) VALUES (?,?,NOW())')
            ->execute([(int) $order['id'], $token]);
        HfsMail::reviewInvite($order, $token);
    }

    public static function byToken(string $token): ?array
    {
        $st = db()->prepare('SELECT i.*, o.code, o.email, o.name, o.id oid FROM review_invites i INNER JOIN orders o ON o.id=i.order_id WHERE i.token=? LIMIT 1');
        $st->execute([$token]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function add(int $productId, ?int $orderId, string $name, string $email, int $rating, string $body, ?string $photo): void
    {
        $rating = max(1, min(5, $rating));
        db()->prepare('INSERT INTO reviews (product_id,order_id,name,email,rating,body,photo,status,created_at) VALUES (?,?,?,?,?,?,?,\'pending\',NOW())')
            ->execute([$productId, $orderId, $name, $email, $rating, $body, $photo]);
    }

    public static function schemaNode(array $p, array $stats, array $reviews): ?array
    {
        if ($stats['count'] < 1) {
            return null;
        }
        $node = [
            'aggregateRating' => [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format($stats['avg'], 1, '.', ''),
                'reviewCount' => (string) $stats['count'],
                'bestRating' => '5',
                'worstRating' => '1',
            ],
            'review' => [],
        ];
        foreach (array_slice($reviews, 0, 8) as $r) {
            $rev = [
                '@type' => 'Review',
                'author' => ['@type' => 'Person', 'name' => $r['name']],
                'datePublished' => date('c', strtotime((string) $r['created_at'])),
                'reviewRating' => [
                    '@type' => 'Rating',
                    'ratingValue' => (string) $r['rating'],
                    'bestRating' => '5',
                ],
                'reviewBody' => $r['body'],
            ];
            if (!empty($r['photo'])) {
                $rev['image'] = Seo::absolute($r['photo']);
            }
            $node['review'][] = $rev;
        }
        return $node;
    }
}
