  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleTheme() {
  const isLight = document.body.classList.toggle('theme-light');
  localStorage.setItem('theme', isLight ? 'light' : 'dark');
  document.getElementById('themeToggleLabel').textContent = isLight ? 'Light' : 'Dark';
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
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' + btn.innerHTML;
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
