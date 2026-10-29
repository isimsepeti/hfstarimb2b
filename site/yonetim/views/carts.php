<div class="page-head"><h1>Terk edilen sepetler</h1></div>
<div class="table-wrap">
<table>
    <tr><th>E-posta</th><th>Özet</th><th>Hatırlatma</th><th>Durum</th><th>Güncelleme</th></tr>
    <?php foreach ($items as $c):
        $p = json_decode((string) $c['payload'], true) ?: [];
        ?>
        <tr>
            <td><?= h($c['email'] ?: '—') ?></td>
            <td><?= h(implode(', ', (array) ($p['titles'] ?? []))) ?> · <?= h(money($p['total'] ?? 0)) ?></td>
            <td><?= (int) $c['reminders'] ?></td>
            <td><?= (int) $c['recovered'] ? 'döndü' : 'açık' ?></td>
            <td><?= h($c['updated_at']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>
</div>
<p class="hint">Cron: ayarlardaki URL. 2 saat sonra hatırlatma, 24 saat sonra SEP10.</p>
