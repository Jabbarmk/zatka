// Theme toggle and mobile sidebar
(function () {
  const root = document.documentElement;
  const btn = document.getElementById('theme-toggle');
  function paintIcon() {
    if (!btn) return;
    const dark = root.getAttribute('data-bs-theme') === 'dark';
    btn.innerHTML = '<i class="bi ' + (dark ? 'bi-sun' : 'bi-moon-stars') + '"></i>';
    btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
  }
  if (btn) {
    btn.addEventListener('click', function () {
      const next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-bs-theme', next);
      try { localStorage.setItem('theme', next); } catch (e) {}
      paintIcon();
      document.dispatchEvent(new CustomEvent('themechange'));
    });
    paintIcon();
  }

  const body = document.body;
  document.querySelectorAll('[data-sidebar-open]').forEach(el => el.addEventListener('click', () => body.classList.add('sidebar-open')));
  document.querySelectorAll('[data-sidebar-close]').forEach(el => el.addEventListener('click', () => body.classList.remove('sidebar-open')));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') body.classList.remove('sidebar-open'); });
})();
