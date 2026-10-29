(() => {
  const root = document.getElementById('hfs-legal');
  const dataEl = document.getElementById('hfs-legal-data');
  if (!root || !dataEl) return;

  let data = {};
  try {
    data = JSON.parse(dataEl.textContent || '{}');
  } catch (_) {
    return;
  }

  const titleEl = document.getElementById('hfs-legal-title');
  const bodyEl = document.getElementById('hfs-legal-body');
  const fullEl = document.getElementById('hfs-legal-full');
  const dialog = root.querySelector('.hfs-legal__dialog');
  let lastFocus = null;

  const close = () => {
    root.hidden = true;
    root.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('hfs-legal-open');
    if (bodyEl) bodyEl.innerHTML = '';
    if (lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus();
    }
  };

  const open = (slug) => {
    const block = data[slug];
    if (!block || !titleEl || !bodyEl) return;
    lastFocus = document.activeElement;
    titleEl.textContent = block.title || '';
    bodyEl.innerHTML = block.html || '';
    if (fullEl && block.url) {
      fullEl.href = block.url;
    }
    root.hidden = false;
    root.setAttribute('aria-hidden', 'false');
    document.body.classList.add('hfs-legal-open');
    if (dialog) dialog.focus();
  };

  document.addEventListener('click', (e) => {
    const link = e.target.closest('[data-hfs-legal]');
    if (!link) return;
    const slug = link.getAttribute('data-hfs-legal');
    if (!slug || !data[slug]) return;
    e.preventDefault();
    e.stopPropagation();
    open(slug);
  });

  root.querySelectorAll('[data-hfs-legal-close]').forEach((el) => {
    el.addEventListener('click', close);
  });

  document.addEventListener('keydown', (e) => {
    if (root.hidden) return;
    if (e.key === 'Escape') close();
  });
})();
