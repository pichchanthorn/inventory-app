<?php
require_once __DIR__ . '/../config/base_url.php';
require_once __DIR__ . '/../config/db.php';
session_start();
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
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    } else {
        $error = 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Log in — Inventory</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-left">
    <div>
      <div class="bracket-label mb-2">ADVANCED_PHP_MYSQL</div>
      <span class="barcode"><i style="width:2px;height:60%"></i><i style="height:100%"></i><i style="width:2px;height:40%"></i><i style="height:80%"></i><i style="width:4px;height:55%"></i><i style="height:100%"></i><i style="width:2px;height:70%"></i></span>
    </div>
    <div>
      <h1>Inventory that tracks every unit, in and out.</h1>
      <p class="mt-3">Categories, suppliers, products and stock movements — one system, one source of truth.</p>
    </div>
    <div class="mono" style="color:#5C6584; font-size:.78rem;">127.0.0.1:9000</div>
  </div>

  <div class="auth-right">
    <div class="auth-form">
      <h4 class="mb-4">Log in</h4>
      <?php if (!empty($_GET['registered'])): ?>
        <div class="alert alert-success py-2">Account created — please log in.</div>
      <?php endif; ?>
      <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post">
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button class="btn btn-primary w-100">Log in</button>
        <p class="text-center mt-3 mb-0">Don't have an account? <a href="<?= BASE_URL ?>/auth/register.php">Register</a></p>
      </form>
    </div>
  </div>
</div>
</body>
</html>
