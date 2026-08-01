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
</script>
</body>
</html>
