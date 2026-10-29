(() => {
  const KEY = 'hfs_cookie_consent';
  const VER = 1;
  const root = document.getElementById('hfs-cookie');
  const bar = document.getElementById('hfs-cookie-bar');
  if (!root || !bar) return;

  const fn = document.getElementById('hfs-cookie-fn');
  const perf = document.getElementById('hfs-cookie-perf');
  const ads = document.getElementById('hfs-cookie-ads');

  const read = () => {
    try {
      const raw = document.cookie.split(';').map((s) => s.trim()).find((s) => s.startsWith(KEY + '='));
      if (!raw) return null;
      const val = decodeURIComponent(raw.slice(KEY.length + 1));
      const j = JSON.parse(val);
      if (Number(j.v) !== VER) return null;
      return j;
    } catch (_) {
      return null;
    }
  };

  const write = (prefs) => {
    const payload = {
      v: VER,
      fn: prefs.fn ? 1 : 0,
      perf: prefs.perf ? 1 : 0,
      ads: prefs.ads ? 1 : 0,
      at: Math.floor(Date.now() / 1000),
    };
    const maxAge = 60 * 60 * 24 * 365;
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie =
      KEY +
      '=' +
      encodeURIComponent(JSON.stringify(payload)) +
      '; Path=/; Max-Age=' +
      maxAge +
      '; SameSite=Lax' +
      secure;
    window.dispatchEvent(new CustomEvent('hfs:cookie-consent', { detail: payload }));
  };

  const syncBodyClass = () => {
    const hasBar = !bar.hidden;
    const hasModal = !root.hidden;
    document.body.classList.toggle('hfs-cookie-bar-visible', hasBar);
    document.body.classList.toggle('hfs-cookie-open', hasModal);
  };

  const showBar = () => {
    bar.hidden = false;
    syncBodyClass();
  };

  const hideBar = () => {
    bar.hidden = true;
    syncBodyClass();
  };

  const openModal = () => {
    const cur = read();
    if (fn) fn.checked = cur ? !!cur.fn : false;
    if (perf) perf.checked = cur ? !!cur.perf : false;
    if (ads) ads.checked = cur ? !!cur.ads : false;
    root.hidden = false;
    root.setAttribute('aria-hidden', 'false');
    syncBodyClass();
    root.querySelector('.hfs-cookie__dialog')?.focus();
  };

  const closeModal = () => {
    root.hidden = true;
    root.setAttribute('aria-hidden', 'true');
    syncBodyClass();
    if (!read()) showBar();
  };

  const acceptAll = () => {
    if (fn) fn.checked = true;
    if (perf) perf.checked = true;
    if (ads) ads.checked = true;
    write({ fn: true, perf: true, ads: true });
    hideBar();
    closeModal();
  };

  const saveFromForm = () => {
    write({
      fn: !!fn?.checked,
      perf: !!perf?.checked,
      ads: !!ads?.checked,
    });
    hideBar();
    closeModal();
  };

  root.querySelectorAll('[data-cookie-close]').forEach((el) => {
    el.addEventListener('click', closeModal);
  });

  root.querySelector('[data-cookie-save]')?.addEventListener('click', saveFromForm);

  root.querySelector('[data-cookie-accept-all]')?.addEventListener('click', acceptAll);

  bar.querySelector('[data-cookie-bar-accept]')?.addEventListener('click', acceptAll);

  document.querySelectorAll('[data-cookie-settings]').forEach((el) => {
    el.addEventListener('click', (e) => {
      e.preventDefault();
      openModal();
    });
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !root.hidden) closeModal();
  });

  if (!read()) {
    showBar();
  }
})();
