(() => {
  const bootEl = document.getElementById('hfs-diagram-boot');
  const boot = bootEl ? JSON.parse(bootEl.textContent || '{}') : {};
  const csrf = boot.csrf || document.querySelector('meta[name="csrf"]')?.content || '';
  const cartUrl = boot.cart || '';
  const back = boot.back || location.href;
  const popup = document.getElementById('hfs-b2b-diagram-popup');
  const popupContent = document.getElementById('hfs-b2b-diagram-popup-content');

  const bindQty = (root) => {
    root.querySelectorAll('.hfs-b2b-quantity-wrap').forEach((wrap) => {
      if (wrap.dataset.bound) return;
      wrap.dataset.bound = '1';
      const input = wrap.querySelector('input[name="qty"]');
      wrap.querySelector('.hfs-b2b-qty-minus')?.addEventListener('click', () => {
        input.value = String(Math.max(1, (parseInt(input.value, 10) || 1) - 1));
      });
      wrap.querySelector('.hfs-b2b-qty-plus')?.addEventListener('click', () => {
        input.value = String(Math.min(999, (parseInt(input.value, 10) || 1) + 1));
      });
    });
  };

  const openPopup = (html) => {
    if (!popup || !popupContent) return;
    popupContent.innerHTML = html;
    popup.classList.add('is-open');
    popup.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    bindQty(popupContent);
  };
  const closePopup = () => {
    if (!popup) return;
    popup.classList.remove('is-open');
    popup.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  };
  popup?.querySelector('.hfs-b2b-diagram-popup__close')?.addEventListener('click', closePopup);
  popup?.addEventListener('click', (e) => { if (e.target === popup) closePopup(); });

  document.querySelectorAll('.hfs-b2b-parts-hotspot').forEach((btn) => {
    btn.addEventListener('click', () => {
      let items = [];
      try { items = JSON.parse(btn.getAttribute('data-hotspot-products') || '[]'); } catch (e) { items = []; }
      const html = items.map((it) => `
        <div class="hfs-b2b-parts-product">
          <img src="${it.img || ''}" alt="">
          <div class="hfs-b2b-parts-product__body">
            ${it.part ? `<div class="hfs-b2b-parts-product__sku">#${it.part}</div>` : ''}
            <div class="hfs-b2b-parts-product__title"><a href="${it.url || '#'}">${it.name || ''}</a></div>
            <div class="hfs-b2b-parts-product__sku"><code>${it.sku || ''}</code></div>
            <div class="hfs-b2b-parts-product__price">${it.price_html || ''}</div>
            <form method="post" action="${cartUrl}" class="hfs-b2b-add-to-cart-form">
              <input type="hidden" name="_csrf" value="${csrf}">
              <input type="hidden" name="act" value="add">
              <input type="hidden" name="product_id" value="${it.product_id}">
              <input type="hidden" name="back" value="${back}">
              <div class="hfs-b2b-parts-product__actions">
                <span class="hfs-b2b-quantity-wrap">
                  <button type="button" class="hfs-b2b-qty-minus">−</button>
                  <input type="number" name="qty" value="1" min="1" max="999">
                  <button type="button" class="hfs-b2b-qty-plus">+</button>
                </span>
                <button class="btn" type="submit">Sepete ekle</button>
              </div>
            </form>
          </div>
        </div>`).join('');
      openPopup(`<div class="hfs-b2b-diagram-popup__products">${html}</div>`);
    });
  });

  bindQty(document);

  const hero = document.getElementById('pdp-hero');
  document.querySelectorAll('.pdp-thumbs button').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (!hero) return;
      hero.src = btn.getAttribute('data-src') || hero.src;
      document.querySelectorAll('.pdp-thumbs button').forEach((b) => b.classList.remove('is-on'));
      btn.classList.add('is-on');
    });
  });

  const year = document.querySelector('.hfs-ft__year');
  if (year) year.textContent = String(new Date().getFullYear());
  document.querySelector('.hfs-ft__top')?.addEventListener('click', (e) => {
    e.preventDefault();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
})();
