<?php $templates = $templates ?? []; $csrf = csrf_token();
$accTitle = 'Hızlı sipariş';
$accLead = 'Stok kodu (SKU) ve adet girin. CSV veya kayıtlı şablonla da sepete ekleyebilirsiniz.';
require __DIR__ . '/partials/account-tabs.php';
?>
<section class="wrap hfs-quick" id="hfs-b2b-quick-order" data-csrf="<?= h($csrf) ?>">
    <div class="hfs-quick__card">
        <h2>SKU ile ekle</h2>
        <table class="hfs-quick__table">
            <thead>
                <tr><th>Stok kodu (SKU)</th><th>Adet</th></tr>
            </thead>
            <tbody>
                <?php for ($i = 0; $i < 10; $i++): ?>
                    <tr>
                        <td><input type="text" class="hfs-sku" name="sku[]" placeholder="SKU" autocomplete="off"></td>
                        <td><input type="number" class="hfs-qty" name="qty[]" min="1" value="1"></td>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>
        <p class="hfs-quick__actions">
            <button type="button" class="btn hfs-b2b-add-all">Sepete ekle</button>
            <a class="btn ghost" href="<?= h(site_url('sepet/')) ?>">Sepete git</a>
        </p>
        <p class="hfs-quick__msg" id="hfs-quick-add-msg" hidden></p>
    </div>

    <div class="hfs-quick__card">
        <h2>CSV ile toplu ekleme</h2>
        <p class="hint">İlk sütun SKU, ikinci sütun adet. İlk satır başlık olabilir.</p>
        <p class="hfs-quick__actions">
            <input type="file" id="hfs-b2b-csv-file" accept=".csv,.txt">
            <button type="button" class="btn ghost" id="hfs-b2b-csv-upload">Yükle ve sepete ekle</button>
        </p>
        <div id="hfs-b2b-csv-result"></div>
    </div>

    <div class="hfs-quick__card">
        <h2>Tekrarlayan sipariş şablonları</h2>
        <p class="hint">Mevcut sepeti şablon olarak kaydedin; sonra tek tıkla sepete yükleyin.</p>
        <p class="hfs-quick__actions">
            <input type="text" id="hfs-b2b-template-name" placeholder="Şablon adı">
            <button type="button" class="btn ghost" id="hfs-b2b-save-template">Sepeti şablon olarak kaydet</button>
        </p>
        <div id="hfs-b2b-template-msg"></div>
        <ul id="hfs-b2b-template-list" class="hfs-quick__tpl">
            <?php if (!$templates): ?>
                <li class="empty">Henüz şablon yok.</li>
            <?php else: ?>
                <?php foreach ($templates as $tid => $t): ?>
                    <li data-id="<?= h((string) $tid) ?>">
                        <?= h((string) ($t['name'] ?? 'Şablon')) ?>
                        <a href="#" class="hfs-b2b-load-template">Sepete yükle</a>
                        <a href="#" class="hfs-b2b-delete-template">Sil</a>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>
</section>
<script>
(function () {
  var root = document.getElementById('hfs-b2b-quick-order');
  if (!root) return;
  var csrf = root.getAttribute('data-csrf') || '';
  var url = <?= json_encode(site_url('hizli-siparis/')) ?>;

  function postForm(data, cb) {
    data.append('_csrf', csrf);
    fetch(url, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(cb)
      .catch(function () { cb({ ok: false, message: 'İstek başarısız.' }); });
  }

  root.querySelector('.hfs-b2b-add-all').addEventListener('click', function () {
    var btn = this;
    var fd = new FormData();
    fd.append('act', 'quick_add');
    root.querySelectorAll('.hfs-sku').forEach(function (el) { fd.append('sku[]', el.value); });
    root.querySelectorAll('.hfs-qty').forEach(function (el) { fd.append('qty[]', el.value || '1'); });
    btn.disabled = true;
    postForm(fd, function (res) {
      btn.disabled = false;
      var msg = (res.added || 0) + ' ürün sepete eklendi.';
      if (res.errors && res.errors.length) msg += ' ' + res.errors.join(' ');
      var box = document.getElementById('hfs-quick-add-msg');
      box.hidden = false;
      box.textContent = res.message || msg;
      if (res.added > 0) window.location.href = <?= json_encode(site_url('sepet/')) ?>;
    });
  });

  document.getElementById('hfs-b2b-csv-upload').addEventListener('click', function () {
    var input = document.getElementById('hfs-b2b-csv-file');
    var out = document.getElementById('hfs-b2b-csv-result');
    if (!input.files || !input.files[0]) {
      out.innerHTML = '<p class="err">Dosya seçin.</p>';
      return;
    }
    var fd = new FormData();
    fd.append('act', 'quick_csv');
    fd.append('file', input.files[0]);
    this.disabled = true;
    out.innerHTML = '<p>İşleniyor…</p>';
    var btn = this;
    postForm(fd, function (res) {
      btn.disabled = false;
      if (res.ok) {
        out.innerHTML = '<p class="ok">CSV işlendi. Sepete eklenen: ' + (res.added || 0) + ', atlanan: ' + (res.skipped || 0) + '.</p>';
        if (res.added > 0) window.location.href = <?= json_encode(site_url('sepet/')) ?>;
      } else {
        out.innerHTML = '<p class="err">' + (res.message || 'Hata') + '</p>';
      }
    });
  });

  document.getElementById('hfs-b2b-save-template').addEventListener('click', function () {
    var name = (document.getElementById('hfs-b2b-template-name').value || '').trim();
    var out = document.getElementById('hfs-b2b-template-msg');
    if (!name) { out.innerHTML = '<p class="err">Şablon adı girin.</p>'; return; }
    var fd = new FormData();
    fd.append('act', 'quick_save_tpl');
    fd.append('name', name);
    this.disabled = true;
    var btn = this;
    postForm(fd, function (res) {
      btn.disabled = false;
      out.innerHTML = '<p class="' + (res.ok ? 'ok' : 'err') + '">' + (res.message || '') + '</p>';
      if (res.ok && res.id) {
        var list = document.getElementById('hfs-b2b-template-list');
        var empty = list.querySelector('.empty');
        if (empty) empty.remove();
        var li = document.createElement('li');
        li.setAttribute('data-id', res.id);
        li.innerHTML = name + ' <a href="#" class="hfs-b2b-load-template">Sepete yükle</a> <a href="#" class="hfs-b2b-delete-template">Sil</a>';
        list.appendChild(li);
      }
    });
  });

  document.getElementById('hfs-b2b-template-list').addEventListener('click', function (e) {
    var a = e.target.closest('a');
    if (!a) return;
    e.preventDefault();
    var li = a.closest('li');
    var id = li ? li.getAttribute('data-id') : '';
    if (!id) return;
    if (a.classList.contains('hfs-b2b-delete-template')) {
      if (!confirm('Bu şablonu silmek istediğinize emin misiniz?')) return;
      var fd = new FormData();
      fd.append('act', 'quick_del_tpl');
      fd.append('template_id', id);
      postForm(fd, function (res) {
        if (res.ok) {
          li.remove();
          var list = document.getElementById('hfs-b2b-template-list');
          if (!list.querySelector('li')) list.innerHTML = '<li class="empty">Henüz şablon yok.</li>';
        }
      });
      return;
    }
    if (a.classList.contains('hfs-b2b-load-template')) {
      var fd = new FormData();
      fd.append('act', 'quick_load_tpl');
      fd.append('template_id', id);
      postForm(fd, function (res) {
        alert(res.message || (res.ok ? 'Sepete eklendi.' : 'Hata'));
        if (res.ok && res.added > 0) window.location.href = <?= json_encode(site_url('sepet/')) ?>;
      });
    }
  });
})();
</script>
