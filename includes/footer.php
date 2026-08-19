  </main>
</div>
<div class="toast-container position-fixed top-0 end-0 p-3" id="toastStack" style="z-index:1090" aria-live="polite" aria-atomic="true"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const T_SAVING = <?= json_encode(__('common_saving')) ?>;

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
      '<button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
    '</div>';
  toastEl.querySelector('.toast-body').textContent = message;
  stack.appendChild(toastEl);
  const toast = new bootstrap.Toast(toastEl, { delay: 4000, autohide: true });
  toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
  toast.show();
}

function toggleTheme() {
  const isLight = document.body.classList.toggle('theme-light');
  localStorage.setItem('theme', isLight ? 'light' : 'dark');
  document.getElementById('themeToggleLabel').textContent = isLight ? 'Light' : 'Dark';
  document.dispatchEvent(new CustomEvent('themechange'));
}
document.addEventListener('DOMContentLoaded', () => {
  const label = document.getElementById('themeToggleLabel');
  if (label && document.body.classList.contains('theme-light')) {
    label.textContent = 'Light';
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
