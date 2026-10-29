(function () {
  var form = document.querySelector('form.product-editor');
  if (!form) return;

  var listUrl = form.getAttribute('data-media-list') || '';
  var warmUrl = form.getAttribute('data-media-warm') || '';
  var resetUrl = form.getAttribute('data-media-reset') || '';
  var uploadUrl = form.getAttribute('data-media-upload') || '';
  var csrf = form.querySelector('input[name="_csrf"]');
  var csrfVal = csrf ? csrf.value : '';

  var modal = document.getElementById('media-picker');
  if (!modal || !listUrl) return;

  var grid = modal.querySelector('[data-media-grid]');
  var search = modal.querySelector('[data-media-search]');
  var uploadInput = modal.querySelector('[data-media-file]');
  var uploadBtn = modal.querySelector('[data-media-upload-btn]');
  var statusEl = modal.querySelector('[data-media-status]');
  var titleEl = modal.querySelector('[data-media-title]');

  var mode = 'featured';
  var multi = false;
  var searchTimer;
  var listOffset = 0;
  var listHasMore = false;
  var listLoading = false;
  var listTotal = 0;
  var loadMoreBtn = modal.querySelector('[data-media-more]');
  var warmRunning = false;
  var warmReloadPending = false;
  var currentSearch = '';

  function h(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function setStatus(msg, err) {
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.className = 'media-picker__status' + (err ? ' is-err' : '');
  }

  function galleryLines() {
    var ta = form.querySelector('textarea[name="gallery"]');
    if (!ta) return [];
    return ta.value.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
  }

  function setGalleryLines(lines) {
    var ta = form.querySelector('textarea[name="gallery"]');
    if (ta) ta.value = lines.join('\n');
    renderGalleryUi();
  }

  var urlCache = {};

  function mediaUrl(rel, cb) {
    if (!rel) return cb('');
    if (/^https?:\/\//i.test(rel)) return cb(rel);
    if (urlCache[rel]) return cb(urlCache[rel]);
    var u = form.getAttribute('data-media-url') || '';
    fetch(u + '?rel=' + encodeURIComponent(rel), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        urlCache[rel] = d.url || rel;
        cb(urlCache[rel]);
      })
      .catch(function () { cb(rel); });
  }

  function renderFeaturedPreview() {
    var inp = form.querySelector('input[name="featured_media"]');
    var box = form.querySelector('[data-featured-preview]');
    if (!inp || !box) return;
    var rel = inp.value.trim();
    if (!rel) {
      box.innerHTML = '<span class="media-slot media-slot--empty">Ana görsel seçilmedi</span>';
      return;
    }
    mediaUrl(rel, function (url) {
      box.innerHTML = '<figure class="media-slot"><img src="' + h(url) + '" alt=""><figcaption>' + h(rel) + '</figcaption></figure>';
    });
  }

  function renderGalleryUi() {
    var list = form.querySelector('[data-gallery-list]');
    if (!list) return;
    var lines = galleryLines();
    list.innerHTML = '';
    lines.forEach(function (rel, i) {
      var row = document.createElement('div');
      row.className = 'gallery-row';
      row.innerHTML =
        '<img src="" alt="" width="56" height="56" data-gallery-thumb="' + i + '">' +
        '<span class="gallery-row__path">' + h(rel) + '</span>' +
        '<button type="button" class="btn-ghost btn-ghost--sm" data-gallery-up="' + i + '" title="Yukarı">↑</button>' +
        '<button type="button" class="btn-ghost btn-ghost--sm" data-gallery-remove="' + i + '" title="Kaldır">×</button>';
      list.appendChild(row);
      mediaUrl(rel, function (url) {
        var img = row.querySelector('[data-gallery-thumb]');
        if (img) img.src = url;
      });
    });
    list.querySelectorAll('[data-gallery-remove]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var idx = parseInt(btn.getAttribute('data-gallery-remove'), 10);
        var L = galleryLines();
        L.splice(idx, 1);
        setGalleryLines(L);
      });
    });
    list.querySelectorAll('[data-gallery-up]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var idx = parseInt(btn.getAttribute('data-gallery-up'), 10);
        if (idx <= 0) return;
        var L = galleryLines();
        var t = L[idx];
        L[idx] = L[idx - 1];
        L[idx - 1] = t;
        setGalleryLines(L);
      });
    });
  }

  function openPicker(m, isMulti) {
    mode = m;
    multi = !!isMulti;
    titleEl.textContent = multi ? 'Galeriye görsel ekle' : (mode === 'diagram' ? 'Diyagram seç' : 'Ana görsel seç');
    modal.hidden = false;
    document.body.classList.add('media-modal-open');
    loadItems(search ? search.value : '', false);
  }

  function closePicker() {
    modal.hidden = true;
    document.body.classList.remove('media-modal-open');
    warmRunning = false;
    setStatus('');
  }

  function applySelection(rel, url) {
    if (!rel) return;
    if (url) urlCache[rel] = url;
    if (mode === 'featured') {
      var inp = form.querySelector('input[name="featured_media"]');
      if (inp) inp.value = rel;
      renderFeaturedPreview();
      if (!multi) closePicker();
      return;
    }
    if (mode === 'diagram') {
      var d = form.querySelector('input[name="diagram_image"]');
      if (d) d.value = rel;
      if (!multi) closePicker();
      return;
    }
    if (mode === 'gallery') {
      var L = galleryLines();
      if (L.indexOf(rel) === -1) L.push(rel);
      setGalleryLines(L);
      if (!multi) closePicker();
    }
  }

  function appendItems(items) {
    items.forEach(function (it) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'media-picker__item';
      btn.innerHTML = '<img src="' + h(it.url) + '" alt="" loading="lazy"><span>' + h(it.name) + '</span>';
      btn.addEventListener('click', function () { applySelection(it.rel, it.url); });
      grid.appendChild(btn);
    });
  }

  function updateListStatus(q, remotePending, remoteTotal) {
    var shown = grid ? grid.querySelectorAll('.media-picker__item').length : 0;
    if (listTotal > 0) {
      var extra = '';
      if (remotePending) {
        extra = ' · bayi kütüphanesi arka planda (' + (remoteTotal || 0) + '+)';
      }
      setStatus(shown + ' / ' + listTotal + ' görsel' + (q ? ' (arama)' : '') + extra);
    } else {
      setStatus(remotePending ? 'Ürün görselleri yüklendi, bayi kütüphanesi arka planda…' : 'Görsel bulunamadı. Arayın veya yeni yükleyin.');
    }
    if (loadMoreBtn) {
      loadMoreBtn.hidden = !listHasMore;
      loadMoreBtn.disabled = listLoading;
      loadMoreBtn.textContent = listLoading ? 'Yükleniyor…' : 'Daha fazla göster';
    }
  }

  function warmRemoteLoop() {
    if (!warmUrl || modal.hidden) {
      warmRunning = false;
      return;
    }
    warmRunning = true;
    fetch(warmUrl + '?pages=3', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.added > 0) warmReloadPending = true;
        if (d && !d.done && !modal.hidden) {
          warmRemoteLoop();
          return;
        }
        warmRunning = false;
        if (warmReloadPending && !modal.hidden) {
          warmReloadPending = false;
          loadItems(currentSearch, false);
        }
      })
      .catch(function () { warmRunning = false; });
  }

  function startRemoteWarm() {
    if (warmRunning) return;
    warmRemoteLoop();
  }

  function loadItems(q, append) {
    if (listLoading) return;
    currentSearch = q || '';
    listLoading = true;
    if (!append) {
      listOffset = 0;
      if (grid) grid.innerHTML = '';
    }
    setStatus(append ? 'Daha fazla yükleniyor…' : 'Yükleniyor…');
    var params = new URLSearchParams();
    if (q) params.set('q', q);
    params.set('offset', String(listOffset));
    params.set('limit', '120');
    fetch(listUrl + '?' + params.toString(), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var items = data.items || [];
        listTotal = data.total || items.length;
        listHasMore = !!data.has_more;
        listOffset = (data.offset || 0) + items.length;
        var remotePending = !!data.remote_pending;
        var remoteTotal = data.remote_total || 0;
        if (!append && !items.length) {
          listTotal = 0;
          listHasMore = false;
          updateListStatus(q, remotePending, remoteTotal);
        } else {
          appendItems(items);
          updateListStatus(q, remotePending, remoteTotal);
        }
        if (remotePending) startRemoteWarm();
      })
      .catch(function () { setStatus('Liste alınamadı.', true); })
      .finally(function () { listLoading = false; if (loadMoreBtn) loadMoreBtn.disabled = false; });
  }

  modal.querySelectorAll('[data-media-close]').forEach(function (el) {
    el.addEventListener('click', closePicker);
  });

  form.querySelectorAll('[data-pick-media]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openPicker(btn.getAttribute('data-pick-media') || 'featured', btn.getAttribute('data-pick-multi') === '1');
    });
  });

  form.querySelectorAll('[data-clear-media]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var t = btn.getAttribute('data-clear-media');
      if (t === 'featured') {
        var inp = form.querySelector('input[name="featured_media"]');
        if (inp) inp.value = '';
        renderFeaturedPreview();
      } else if (t === 'gallery') {
        setGalleryLines([]);
      } else if (t === 'diagram') {
        var d = form.querySelector('input[name="diagram_image"]');
        if (d) d.value = '';
      }
    });
  });

  if (search) {
    search.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () { loadItems(search.value.trim(), false); }, 280);
    });
  }

  if (loadMoreBtn) {
    loadMoreBtn.addEventListener('click', function () {
      loadItems(search ? search.value.trim() : '', true);
    });
  }

  var refreshBtn = modal.querySelector('[data-media-refresh]');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', function () {
      var q = search ? search.value.trim() : '';
      warmReloadPending = false;
      warmRunning = false;
      var reset = resetUrl
        ? fetch(resetUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrfVal },
            body: new URLSearchParams({ _csrf: csrfVal }),
          })
        : Promise.resolve();
      reset.then(function () { loadItems(q, false); }).catch(function () { loadItems(q, false); });
    });
  }

  if (uploadBtn && uploadInput) {
    uploadBtn.addEventListener('click', function () {
      var file = uploadInput.files && uploadInput.files[0];
      if (!file) {
        setStatus('Dosya seçin.', true);
        return;
      }
      var fd = new FormData();
      fd.append('file', file);
      fd.append('_csrf', csrfVal);
      setStatus('Yükleniyor…');
      fetch(uploadUrl, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrfVal },
      })
        .then(function (r) {
          return r.text().then(function (t) {
            var res = null;
            try { res = t ? JSON.parse(t) : null; } catch (e) { res = null; }
            return { http: r.status, res: res, raw: t };
          });
        })
        .then(function (pack) {
          var res = pack.res;
          if (!res || typeof res !== 'object') {
            setStatus('Yükleme hatası (HTTP ' + pack.http + ').', true);
            return;
          }
          if (!res.ok) {
            setStatus(res.message || 'Yükleme başarısız.', true);
            return;
          }
          uploadInput.value = '';
          applySelection(res.rel, res.url);
          loadItems(search ? search.value.trim() : '', false);
          setStatus('Yüklendi.');
        })
        .catch(function () { setStatus('Yükleme hatası (bağlantı).', true); });
    });
  }

  renderFeaturedPreview();
  renderGalleryUi();
})();
