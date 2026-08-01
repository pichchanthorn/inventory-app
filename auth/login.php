<?php
require_once __DIR__ . '/../config/base_url.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../config/db.php';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $pass  = $_POST['password'];

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($pass, $user['password'])) {
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['role_id']   = $user['role_id'];
        $_SESSION['must_change_password'] = (bool) $user['must_change_password'];
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    } else {
        $error = __('login_invalid');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>
  if (localStorage.getItem('theme') === 'light') {
    document.documentElement.classList.add('theme-light-pending');
  }
</script>
<meta charset="UTF-8">
<title>Log in — Inventory</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css?v=<?= ASSET_VER ?>">
</head>
<body lang="<?= $_SESSION['lang'] ?>">
<script>
  if (document.documentElement.classList.contains('theme-light-pending')) {
    document.body.classList.add('theme-light');
  }
</script>
<a href="?lang=<?= $_SESSION['lang'] === 'km' ? 'en' : 'km' ?>" class="theme-toggle-btn text-decoration-none" style="position:absolute; top:20px; right:20px; width:auto; margin-bottom:0;">
  <?= $_SESSION['lang'] === 'km' ? 'EN' : 'ខ្មែរ' ?>
</a>
<div class="auth-wrap">
  <div class="auth-left">
    <div>
      <div class="bracket-label mb-2"><?= __('auth_tagline') ?></div>
      <span class="barcode"><i style="width:2px;height:60%"></i><i style="height:100%"></i><i style="width:2px;height:40%"></i><i style="height:80%"></i><i style="width:4px;height:55%"></i><i style="height:100%"></i><i style="width:2px;height:70%"></i></span>
    </div>
    <div>
      <h1><?= __('auth_hero_title') ?></h1>
      <p class="mt-3"><?= __('auth_hero_subtitle') ?></p>
    </div>
    <div class="mono" style="color:#5C6584; font-size:.78rem;">127.0.0.1:9000</div>
  </div>

  <div class="auth-right">
    <div class="auth-form">
      <h4 class="mb-4"><?= __('login_title') ?></h4>
      <?php if (!empty($_GET['registered'])): ?>
        <div class="alert alert-success py-2"><?= __('login_registered_success') ?></div>
      <?php endif; ?>
      <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post">
        <div class="mb-3">
          <label class="form-label"><?= __('login_email') ?></label>
          <input type="email" name="email" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label"><?= __('login_password') ?></label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button class="btn btn-primary w-100"><?= __('login_button') ?></button>
        <p class="text-center mt-3 mb-0"><?= __('login_no_account') ?> <a href="<?= BASE_URL ?>/auth/register.php"><?= __('login_register_link') ?></a></p>
      </form>
    </div>
  </div>
</div>
</body>
</html>
