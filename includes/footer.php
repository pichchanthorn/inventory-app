  </main>
  </div>
</div>
<div class="toast-container position-fixed top-0 end-0 p-3" id="toastStack" style="z-index:1090" aria-live="polite" aria-atomic="true"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const T_SAVING = <?= json_encode(__('common_saving')) ?>;
const T_CLOSE = <?= json_encode(__('common_close')) ?>;
const T_THEME_DARK = <?= json_encode(__('theme_toggle_dark')) ?>;
const T_THEME_LIGHT = <?= json_encode(__('theme_toggle_light')) ?>;

// Shared toast helper — every page that used to render a top-of-page
// success/error <div class="alert"> now calls this instead, so messages
// show as a dismissing top-right toast rather than pushing content down.
function showToast(message, type) {
  if (!message) return;
  const stack = document.getElementById('toastStack');
  if (!stack) return;
  const toastEl = document.createElement('div');
  toastEl.className = 'toast align-items-center border-0 ' + (type === 'error' ? 'toast-danger' : 'toast-success');
  toastEl.setAttribute('role', 'alert');
  toastEl.setAttribute('aria-live', 'assertive');
  toastEl.setAttribute('aria-atomic', 'true');
  toastEl.innerHTML =
    '<div class="d-flex">' +
      '<div class="toast-body"></div>' +
      '<button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="' + T_CLOSE + '"></button>' +
    '</div>';
  toastEl.querySelector('.toast-body').textContent = message;
  stack.appendChild(toastEl);
  const toast = new bootstrap.Toast(toastEl, { delay: 4000, autohide: true });
  toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
  toast.show();
}

// Debounced "live" search for list pages (Products, Categories, Units,
// Suppliers). Refetches the current URL with an updated ?q= after typing
// pauses for 300ms, and swaps in just the results container — no full page
// reload. The plain GET <form> around the search box is left untouched, so
// pressing Enter still works as a normal fallback (JS disabled, slow
// network, etc).
function initLiveSearch(inputId, resultsId) {
  const input = document.getElementById(inputId);
  if (!input || !document.getElementById(resultsId)) return;
  let timer = null;
  let requestSeq = 0;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      const seq = ++requestSeq;
      const url = new URL(window.location.href);
      url.searchParams.set('q', input.value);
      document.getElementById(resultsId)?.classList.add('live-search-loading');
      fetch(url)
        .then(r => r.text())
        .then(html => {
          if (seq !== requestSeq) return; // a newer keystroke already superseded this request
          const fresh = new DOMParser().parseFromString(html, 'text/html').getElementById(resultsId);
          const current = document.getElementById(resultsId);
          if (fresh && current) {
            current.replaceWith(fresh);
            history.replaceState(null, '', url);
          }
        })
        .catch(() => {}) // silent — Enter-to-submit fallback still works
        .finally(() => {
          if (seq === requestSeq) document.getElementById(resultsId)?.classList.remove('live-search-loading');
        });
    }, 300);
  });
}

function toggleTheme() {
  const isLight = document.body.classList.toggle('theme-light');
  localStorage.setItem('theme', isLight ? 'light' : 'dark');
  document.getElementById('themeToggleLabel').textContent = isLight ? T_THEME_LIGHT : T_THEME_DARK;
  document.dispatchEvent(new CustomEvent('themechange'));
}
document.addEventListener('DOMContentLoaded', () => {
  const label = document.getElementById('themeToggleLabel');
  if (label && document.body.classList.contains('theme-light')) {
    label.textContent = T_THEME_LIGHT;
  }
});

// Disable the submit button and show a spinner on POST form submission,
// so a slow request (or an accidental double-click) can't double-submit.
document.addEventListener('submit', function (e) {
  const form = e.target;
  if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') return;
  const btn = form.querySelector('button[type="submit"], button:not([type])');
  if (!btn || btn.disabled) return;
  btn.dataset.originalHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' + T_SAVING;
});

// Restore buttons if the page is restored from bfcache (e.g. browser back button)
window.addEventListener('pageshow', function () {
  document.querySelectorAll('button[disabled][data-original-html]').forEach(function (btn) {
    btn.disabled = false;
    btn.innerHTML = btn.dataset.originalHtml;
  });
});
</script>
</body>
</html>
