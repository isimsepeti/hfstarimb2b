<?php
$items = $items ?? [];
$cats = $cats ?? [];
$err = (string) ($err ?? '');
$ok = !empty($ok);
$editId = (string) ($editId ?? '');
$edit = null;
foreach ($items as $row) {
    if (($row['id'] ?? '') === $editId) {
        $edit = $row;
        break;
    }
}
$editKids = $edit ? HfsNav::childCandidates($edit) : [];
$editSelected = $edit ? array_map('intval', $edit['child_ids'] ?? []) : [];
$editSelectedMap = array_flip($editSelected);
$extraSelected = $edit ? array_flip(array_map('intval', $edit['extra_ids'] ?? [])) : [];
?>
<div class="page-head">
    <h1>Mega menü</h1>
    <p>Üst menüdeki mega menüleri ekleyin, çıkarın, sırasını ve alt kolonlarını düzenleyin.</p>
</div>
<?php if ($ok): ?>
    <p class="ok">Kaydedildi.</p>
<?php endif; ?>
<?php if ($err !== ''): ?>
    <p class="err"><?= h($err) ?></p>
<?php endif; ?>

<?php if ($edit): ?>
<div class="card dealer-form" style="margin-bottom:20px">
    <h2>Menüyü düzenle</h2>
    <form class="stack" method="post" id="menu-edit-form">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="save">
        <input type="hidden" name="id" value="<?= h($edit['id']) ?>">
        <div class="row2">
            <label>
                <span>Menü başlığı</span>
                <input name="label" type="text" value="<?= h($edit['label']) ?>" placeholder="Boşsa kategori adı">
            </label>
            <label>
                <span>Ana kategori</span>
                <select name="category_id" required>
                    <?php foreach ($cats as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === (int) $edit['category_id'] ? ' selected' : '' ?>><?= h($c['path']) ?> — <?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <fieldset class="menu-fieldset">
            <legend>Ek kolon kategorileri</legend>
            <p class="hint">Ana kategorinin çocuklarına ek kolon olarak eklenir (ör. Askı Aparatı).</p>
            <div class="check-grid">
                <?php foreach ($cats as $c):
                    if ((int) $c['id'] === (int) $edit['category_id']) {
                        continue;
                    }
                    $cid = (int) $c['id'];
                    ?>
                    <label class="remember">
                        <input type="checkbox" name="extra_ids[]" value="<?= $cid ?>"<?= isset($extraSelected[$cid]) ? ' checked' : '' ?>>
                        <?= h($c['path']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <fieldset class="menu-fieldset">
            <legend>Alt kolonlar</legend>
            <p class="hint">İşaretlenenler menüde görünür; sıra numarası küçükten büyüğe.</p>
            <?php if ($editKids === []): ?>
                <p class="hint">Bu kategori altında kolon adayı yok. Ek kategori seçip kaydedin.</p>
            <?php else:
                $byId = [];
                foreach ($editKids as $k) {
                    $byId[(int) $k['id']] = $k;
                }
                $ordered = [];
                if ($editSelected !== []) {
                    foreach ($editSelected as $cid) {
                        if (isset($byId[$cid])) {
                            $ordered[] = $byId[$cid];
                            unset($byId[$cid]);
                        }
                    }
                    foreach ($byId as $left) {
                        $ordered[] = $left;
                    }
                } else {
                    $ordered = $editKids;
                }
                ?>
                <div class="table-wrap">
                    <table>
                        <tr><th></th><th>Kategori</th><th>Sıra</th></tr>
                        <?php foreach ($ordered as $i => $k):
                            $cid = (int) $k['id'];
                            $checked = $editSelected === [] || isset($editSelectedMap[$cid]);
                            ?>
                            <tr>
                                <td><input type="checkbox" data-child value="<?= $cid ?>"<?= $checked ? ' checked' : '' ?>></td>
                                <td><?= h($k['name']) ?> <small class="hint"><?= h((string) ($k['path'] ?? '')) ?></small></td>
                                <td><input class="compact" type="number" data-child-sort="<?= $cid ?>" value="<?= ($i + 1) * 10 ?>" min="0"></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <div id="child-ids-holder"></div>
            <?php endif; ?>
        </fieldset>
        <label class="remember"><input type="checkbox" name="enabled" value="1"<?= !empty($edit['enabled']) ? ' checked' : '' ?>> Yayında</label>
        <div class="toolbar">
            <button class="btn" type="submit">Kaydet</button>
            <a class="btn ghost" href="<?= h(site_url('yonetim/menu/')) ?>">İptal</a>
        </div>
    </form>
</div>
<script>
(function () {
  var form = document.getElementById('menu-edit-form');
  if (!form) return;
  form.addEventListener('submit', function () {
    var holder = document.getElementById('child-ids-holder');
    if (!holder) return;
    holder.innerHTML = '';
    var rows = [];
    form.querySelectorAll('input[data-child]').forEach(function (cb) {
      if (!cb.checked) return;
      var id = cb.value;
      var sortInp = form.querySelector('input[data-child-sort="' + id + '"]');
      rows.push({ id: id, sort: sortInp ? (parseInt(sortInp.value, 10) || 0) : 0 });
    });
    rows.sort(function (a, b) { return a.sort - b.sort; });
    rows.forEach(function (r) {
      var inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = 'child_ids[]';
      inp.value = r.id;
      holder.appendChild(inp);
    });
  });
})();
</script>
<?php endif; ?>

<div class="card" style="margin-bottom:20px">
    <h2>Menü listesi</h2>
    <?php if ($items === []): ?>
        <p class="hint">Henüz menü yok. Aşağıdan ekleyin veya varsayılana dönün.</p>
    <?php else: ?>
        <form method="post" id="menu-reorder-form" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="reorder">
            <div class="table-wrap">
                <table>
                    <tr><th>Sıra</th><th>Başlık</th><th>Kategori</th><th>Durum</th><th></th></tr>
                    <?php foreach ($items as $i => $row):
                        $cat = Catalog::categoryById((int) $row['category_id']);
                        $title = trim((string) ($row['label'] ?? ''));
                        if ($title === '' && $cat) {
                            $title = HfsNav::label($cat);
                        }
                        ?>
                        <tr>
                            <td>
                                <input type="hidden" name="order_ids[]" value="<?= h($row['id']) ?>">
                                <input class="compact" type="number" data-sort-ui="<?= h($row['id']) ?>" value="<?= ($i + 1) * 10 ?>" min="0" title="Sıra">
                            </td>
                            <td><strong><?= h($title) ?></strong></td>
                            <td><?= h($cat['path'] ?? ('#' . (int) $row['category_id'])) ?></td>
                            <td><?= !empty($row['enabled']) ? 'Açık' : 'Kapalı' ?></td>
                            <td>
                                <div class="toolbar" style="justify-content:flex-end;margin:0">
                                    <a class="btn ghost" href="<?= h(site_url('yonetim/menu/?duzenle=' . rawurlencode($row['id']))) ?>">Düzenle</a>
                                    <button class="btn ghost" type="button" data-act="toggle" data-id="<?= h($row['id']) ?>"><?= !empty($row['enabled']) ? 'Kapat' : 'Aç' ?></button>
                                    <button class="textlink" type="button" data-act="delete" data-id="<?= h($row['id']) ?>">Sil</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <button class="btn ghost" type="submit">Sırayı kaydet</button>
        </form>
        <script>
        (function () {
          var form = document.getElementById('menu-reorder-form');
          if (!form) return;
          form.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-act]');
            if (!btn) return;
            e.preventDefault();
            var act = btn.getAttribute('data-act');
            var id = btn.getAttribute('data-id') || '';
            if (act === 'delete' && !confirm('Bu menü silinsin mi?')) return;
            var f = document.createElement('form');
            f.method = 'post';
            f.action = form.action || location.href;
            form.querySelectorAll('input[name="_csrf"], input[name="_csrf_day"]').forEach(function (el) {
              f.appendChild(el.cloneNode(true));
            });
            [['act', act], ['id', id]].forEach(function (pair) {
              var inp = document.createElement('input');
              inp.type = 'hidden';
              inp.name = pair[0];
              inp.value = pair[1];
              f.appendChild(inp);
            });
            document.body.appendChild(f);
            f.submit();
          });
          form.addEventListener('submit', function () {
            var pairs = [];
            form.querySelectorAll('[data-sort-ui]').forEach(function (inp) {
              pairs.push({ id: inp.getAttribute('data-sort-ui'), sort: parseInt(inp.value, 10) || 0 });
            });
            pairs.sort(function (a, b) { return a.sort - b.sort; });
            var hiddens = form.querySelectorAll('input[name="order_ids[]"]');
            pairs.forEach(function (p, i) {
              if (hiddens[i]) hiddens[i].value = p.id;
            });
          });
        })();
        </script>
    <?php endif; ?>
</div>

<form class="stack dealer-form" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="add">
    <div class="card">
        <h2>Yeni menü ekle</h2>
        <div class="row2">
            <label>
                <span>Menü başlığı</span>
                <input name="label" type="text" placeholder="Örn. Hasat Makinaları">
            </label>
            <label>
                <span>Ana kategori</span>
                <select name="category_id" required>
                    <option value="">— Seçin —</option>
                    <?php foreach ($cats as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= h($c['path']) ?> — <?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <fieldset class="menu-fieldset">
            <legend>Ek kolon kategorileri (isteğe bağlı)</legend>
            <div class="check-grid">
                <?php foreach ($cats as $c): ?>
                    <label class="remember">
                        <input type="checkbox" name="extra_ids[]" value="<?= (int) $c['id'] ?>">
                        <?= h($c['path']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <button class="btn" type="submit">Menü ekle</button>
    </div>
</form>

<form method="post" style="margin-top:20px" onsubmit="return confirm('Mevcut mega menü varsayılan Hasat + Yedek düzenine dönsün mü?');">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="reset">
    <button class="textlink" type="submit">Varsayılan menüye dön</button>
</form>
