(() => {
  const body = document.body;
  const toggle = document.querySelector('.nav-toggle');
  const menu = document.getElementById('ana-menu');
  const cartBtn = document.querySelector('[data-cart-open]');
  const drawer = document.getElementById('mini-cart');
  const closeBtn = document.querySelector('.cart-drawer-close');

  const closeNav = () => {
    body.classList.remove('nav-open');
    toggle?.setAttribute('aria-expanded', 'false');
  };

  const openCart = () => {
    closeNav();
    body.classList.add('cart-open');
    cartBtn?.setAttribute('aria-expanded', 'true');
    drawer?.removeAttribute('inert');
    drawer?.setAttribute('aria-hidden', 'false');
    try { closeBtn?.focus({ preventScroll: true }); } catch (_) { closeBtn?.focus(); }
  };

  const closeCart = () => {
    const wasOpen = body.classList.contains('cart-open');
    body.classList.remove('cart-open');
    cartBtn?.setAttribute('aria-expanded', 'false');
    drawer?.setAttribute('aria-hidden', 'true');
    if (wasOpen) cartBtn?.focus();
  };

  const isMobileNav = () => window.matchMedia('(max-width: 980px)').matches;

  if (toggle && menu) {
    toggle.addEventListener('click', () => {
      const on = toggle.getAttribute('aria-expanded') === 'true';
      closeCart();
      toggle.setAttribute('aria-expanded', on ? 'false' : 'true');
      body.classList.toggle('nav-open', !on);
      if (on) {
        document.querySelectorAll('.nav-item.is-open').forEach((el) => {
          el.classList.remove('is-open');
          el.querySelector('.nav-top')?.setAttribute('aria-expanded', 'false');
        });
      }
    });
  }

  document.querySelectorAll('.nav-item > .nav-top').forEach((link) => {
    const item = link.parentElement;
    if (!item) return;
    const setOpen = (on) => {
      link.setAttribute('aria-expanded', on ? 'true' : 'false');
      item.classList.toggle('is-open', on);
    };
    item.addEventListener('mouseenter', () => {
      if (!isMobileNav()) setOpen(true);
    });
    item.addEventListener('mouseleave', () => {
      if (!isMobileNav()) setOpen(false);
    });
    item.addEventListener('focusin', () => {
      if (!isMobileNav()) setOpen(true);
    });
    item.addEventListener('focusout', (e) => {
      if (!isMobileNav() && !item.contains(e.relatedTarget)) setOpen(false);
    });
    link.addEventListener('click', (e) => {
      if (!isMobileNav() || !item.querySelector('.nav-mega')) return;
      e.preventDefault();
      const open = item.classList.contains('is-open');
      document.querySelectorAll('.nav-item.is-open').forEach((el) => {
        if (el !== item) {
          el.classList.remove('is-open');
          el.querySelector('.nav-top')?.setAttribute('aria-expanded', 'false');
        }
      });
      setOpen(!open);
    });
  });

  cartBtn?.addEventListener('click', (e) => {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) {
      return;
    }
    e.preventDefault();
    if (body.classList.contains('cart-open')) {
      closeCart();
    } else {
      openCart();
    }
  });

  document.addEventListener('click', (e) => {
    const t = e.target;
    if (!(t instanceof Element)) return;
    if (t.closest('[data-cart-line]')) return;
    if (t.closest('[data-cart-close]')) {
      if (t.closest('a[href]')) return;
      e.preventDefault();
      closeCart();
    }
  });

  // Safari / inert: sepet satır formları her zaman gönderilebilsin
  drawer?.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-cart-line')) return;
    drawer.removeAttribute('inert');
    drawer.setAttribute('aria-hidden', 'false');
  }, true);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeCart();
      closeNav();
    }
  });

  const liveForm = document.querySelector('.header-search[data-live-url]');
  if (liveForm) {
    const liveInp = liveForm.querySelector('input[name="s"]');
    const liveBox = document.getElementById('live-search-panel');
    const liveUrl = liveForm.getAttribute('data-live-url') || '';
    let liveTimer = 0;
    let liveCtrl = null;
    let liveIx = -1;
    let liveLinks = [];

    const liveClose = () => {
      if (!liveBox) return;
      liveBox.hidden = true;
      liveBox.innerHTML = '';
      liveIx = -1;
      liveLinks = [];
      liveInp?.setAttribute('aria-expanded', 'false');
    };

    const liveMark = () => {
      liveLinks.forEach((a, i) => a.classList.toggle('is-on', i === liveIx));
      const on = liveLinks[liveIx];
      if (on) on.scrollIntoView({ block: 'nearest' });
    };

    const el = (tag, cls, text) => {
      const n = document.createElement(tag);
      if (cls) n.className = cls;
      if (text) n.textContent = text;
      return n;
    };

    const liveDraw = (data) => {
      if (!liveBox || !liveInp) return;
      const q = (data && data.q) || liveInp.value.trim();
      const cats = (data && data.cats) || [];
      const items = (data && data.items) || [];
      liveBox.innerHTML = '';
      liveIx = -1;
      if (!cats.length && !items.length) {
        liveBox.appendChild(el('p', 'ls-empty', 'Sonuç bulunamadı'));
      } else {
        if (cats.length) {
          liveBox.appendChild(el('p', 'ls-sec', 'Kategoriler'));
          cats.forEach((c) => {
            const a = el('a', 'ls-item');
            a.href = c.url;
            a.setAttribute('role', 'option');
            a.appendChild(el('span', 'ls-ico', 'Kat'));
            const meta = el('span', 'ls-meta');
            meta.appendChild(el('span', 'ls-title', c.name || ''));
            a.appendChild(meta);
            liveBox.appendChild(a);
          });
        }
        if (items.length) {
          liveBox.appendChild(el('p', 'ls-sec', 'Ürünler'));
          items.forEach((p) => {
            const a = el('a', 'ls-item');
            a.href = p.url;
            a.setAttribute('role', 'option');
            if (p.img) {
              const img = document.createElement('img');
              img.src = p.img;
              img.alt = '';
              img.width = 40;
              img.height = 40;
              a.appendChild(img);
            } else {
              a.appendChild(el('span', 'ls-ico', 'Ürün'));
            }
            const meta = el('span', 'ls-meta');
            meta.appendChild(el('span', 'ls-title', p.title || ''));
            if (p.sku) meta.appendChild(el('span', 'ls-sku', p.sku));
            a.appendChild(meta);
            if (p.price) a.appendChild(el('span', 'ls-price', p.price));
            liveBox.appendChild(a);
          });
        }
        const more = el('a', 'ls-more', 'Tüm sonuçları gör');
        more.href = liveForm.action + (liveForm.action.indexOf('?') >= 0 ? '&' : '?') + 's=' + encodeURIComponent(q);
        liveBox.appendChild(more);
      }
      liveLinks = [...liveBox.querySelectorAll('a')];
      liveBox.hidden = false;
      liveInp.setAttribute('aria-expanded', 'true');
    };

    const liveGo = () => {
      const q = (liveInp?.value || '').trim();
      if (q.length < 2 || !liveUrl) {
        liveClose();
        return;
      }
      if (liveCtrl) liveCtrl.abort();
      liveCtrl = new AbortController();
      const url = liveUrl + (liveUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: liveCtrl.signal })
        .then((r) => (r.ok ? r.json() : Promise.reject()))
        .then((data) => {
          if ((liveInp?.value || '').trim() !== q) return;
          liveDraw(data);
        })
        .catch((err) => {
          if (err && err.name === 'AbortError') return;
        });
    };

    liveInp?.addEventListener('input', () => {
      clearTimeout(liveTimer);
      liveTimer = window.setTimeout(liveGo, 180);
    });
    liveInp?.addEventListener('focus', () => {
      if ((liveInp.value || '').trim().length >= 2) liveGo();
    });
    liveInp?.addEventListener('keydown', (e) => {
      if (liveBox?.hidden || !liveLinks.length) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        liveIx = (liveIx + 1) % liveLinks.length;
        liveMark();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        liveIx = liveIx <= 0 ? liveLinks.length - 1 : liveIx - 1;
        liveMark();
      } else if (e.key === 'Enter' && liveIx >= 0 && liveLinks[liveIx]) {
        e.preventDefault();
        liveLinks[liveIx].click();
      } else if (e.key === 'Escape') {
        liveClose();
      }
    });
    document.addEventListener('click', (e) => {
      const t = e.target;
      if (!(t instanceof Element) || !liveForm.contains(t)) liveClose();
    });
  }

  const params = new URLSearchParams(location.search);
  if (params.has('sepet') && !/\/sepet\/?$/.test(location.pathname)) {
    openCart();
    params.delete('sepet');
    const q = params.toString();
    history.replaceState(null, '', location.pathname + (q ? '?' + q : '') + location.hash);
  }

  const form = document.querySelector('[data-opt-form]');
  if (form) {
    let combos = [];
    try {
      combos = JSON.parse(form.getAttribute('data-combos') || '[]');
    } catch (e) {
      combos = [];
    }
    const hid = form.querySelector('[data-variant-id]');
    const priceEl = document.querySelector('[data-price-now]');
    const addBtn = form.querySelector('[data-add-btn]');
    const buyBtn = form.querySelector('[data-buy-btn]');
    const money = (n) => new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 0 }).format(n) + ' ₺';
    const selected = () => {
      const o = {};
      form.querySelectorAll('[data-opt-group]').forEach((fs) => {
        const checked = fs.querySelector('input:checked');
        if (checked) o[fs.getAttribute('data-opt-group')] = checked.value;
      });
      return o;
    };
    const match = () => {
      const sel = selected();
      const groups = [...form.querySelectorAll('[data-opt-group]')].map((fs) => fs.getAttribute('data-opt-group'));
      if (!groups.length || groups.some((g) => !sel[g])) return null;
      return combos.find((c) => Object.entries(sel).every(([g, v]) => String(c.attrs[g]) === String(v))) || null;
    };
    const motorG = form.getAttribute('data-motor-group');
    const motorOther = form.getAttribute('data-motor-other');
    const motorWrap = form.querySelector('[data-opt-motor-other-wrap]');
    const motorInput = form.querySelector('[name="opt_motor_other"]');
    const sync = () => {
      const sel = selected();
      if (motorWrap && motorG && motorOther) {
        const onOther = String(sel[motorG] || '') === String(motorOther);
        motorWrap.hidden = !onOther;
        if (motorInput) {
          motorInput.required = onOther;
          if (!onOther) motorInput.value = '';
        }
      }
      form.querySelectorAll('[data-opt-group]').forEach((fs) => {
        const gid = fs.getAttribute('data-opt-group');
        fs.querySelectorAll('input').forEach((inp) => {
          const probe = { ...sel, [gid]: inp.value };
          const ok = combos.some((c) =>
            Object.entries(probe).every(([g, v]) => String(c.attrs[g]) === String(v))
          );
          inp.disabled = !ok;
        });
      });
      const hit = match();
      if (hid) hid.value = hit ? String(hit.id) : '0';
      if (priceEl && hit) priceEl.textContent = money(hit.price);
      const baseSku = document.querySelector('[data-pdp-sku]')?.getAttribute('data-base-sku') || '';
      const skuText = hit && hit.sku ? hit.sku : baseSku;
      document.querySelectorAll('[data-pdp-sku], [data-pdp-sku-fact]').forEach((el) => {
        el.textContent = skuText || el.getAttribute('data-base-sku') || '';
      });
      const on = !!hit;
      if (addBtn) {
        addBtn.disabled = !on;
        addBtn.textContent = on ? 'Sepete ekle' : 'Varyant seçin';
      }
      if (buyBtn) {
        buyBtn.disabled = !on;
        if (buyBtn.dataset.labelBuy == null) buyBtn.dataset.labelBuy = buyBtn.textContent;
        buyBtn.textContent = on ? (buyBtn.dataset.labelBuy || 'Hemen al') : 'Varyant seçin';
      }
    };
    form.addEventListener('change', sync);
    sync();
  }

  document.querySelectorAll('[data-variant-required]').forEach((form) => {
    const addBtn = form.querySelector('[data-add-btn]');
    const buyBtn = form.querySelector('[data-buy-btn]');
    const sync = () => {
      const checked = form.querySelector('input[name="variant_id"]:checked');
      const on = !!checked;
      const sku = checked?.getAttribute('data-sku') || '';
      const base = document.querySelector('[data-pdp-sku]')?.getAttribute('data-base-sku') || '';
      document.querySelectorAll('[data-pdp-sku], [data-pdp-sku-fact]').forEach((el) => {
        el.textContent = sku || el.getAttribute('data-base-sku') || base;
      });
      if (addBtn) {
        addBtn.disabled = !on;
        addBtn.textContent = on ? 'Sepete ekle' : 'Varyant seçin';
      }
      if (buyBtn) {
        buyBtn.disabled = !on;
        if (buyBtn.dataset.labelBuy == null) buyBtn.dataset.labelBuy = 'Hemen al';
        buyBtn.textContent = on ? buyBtn.dataset.labelBuy : 'Varyant seçin';
      }
    };
    form.addEventListener('change', sync);
    sync();
  });

  document.querySelectorAll('.product-action--variants').forEach((form) => {
    const sel = form.querySelector('select[name="variant_id"]');
    const other = form.querySelector('[data-diger-other]');
    const digerId = form.getAttribute('data-diger-variant') || '';
    const card = form.closest('.product');
    const skuEl = card?.querySelector('[data-card-sku]');
    const baseSku = skuEl?.getAttribute('data-base-sku') || form.getAttribute('data-base-sku') || '';
    if (!sel) return;
    const sync = () => {
      const opt = sel.options[sel.selectedIndex];
      const sku = opt?.getAttribute('data-sku') || '';
      if (skuEl) skuEl.textContent = sku || baseSku;
      if (!other) return;
      const on = digerId !== '' && sel.value === digerId;
      other.hidden = !on;
      other.required = on;
      if (!on) other.value = '';
    };
    sel.addEventListener('change', sync);
    sync();
  });
})();
