<?php
// Consider restricting or removing public self-registration once Admin-created
// accounts are in use, so only approved staff can access the system.
require_once __DIR__ . '/../config/base_url.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../config/db.php';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name']);
    $email = trim($_POST['email']);
    $pass  = $_POST['password'];

    if ($name === '' || $email === '' || $pass === '') {
        $error = __('register_err_fill_fields');
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = __('common_err_email_taken');
        } else {
            $hashed = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?, ?, ?, 2)');
            $stmt->execute([$name, $email, $hashed]);
            header('Location: ' . BASE_URL . '/auth/login.php?registered=1');
            exit;
        }
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
<title>Register — Inventory</title>
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
      <h4 class="mb-4"><?= __('register_title') ?></h4>
      <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post">
        <div class="mb-3">
          <label class="form-label"><?= __('register_name') ?></label>
          <input type="text" name="name" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label"><?= __('register_email') ?></label>
          <input type="email" name="email" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label"><?= __('register_password') ?></label>
          <input type="password" name="password" class="form-control" required minlength="6">
        </div>
        <button class="btn btn-primary w-100"><?= __('register_button') ?></button>
        <p class="text-center mt-3 mb-0"><?= __('register_have_account') ?> <a href="<?= BASE_URL ?>/auth/login.php"><?= __('register_login_link') ?></a></p>
      </form>
    </div>
  </div>
</div>
</body>
</html>
