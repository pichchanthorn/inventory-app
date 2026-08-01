<?php
require_once __DIR__ . '/../config/base_url.php';
require_once __DIR__ . '/../config/db.php';
session_start();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name']);
    $email = trim($_POST['email']);
    $pass  = $_POST['password'];

    if ($name === '' || $email === '' || $pass === '') {
        $error = 'Please fill in every field.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'This email is already registered.';
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
<meta charset="UTF-8">
<title>Register — Inventory</title>
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
      <h4 class="mb-4">Create account</h4>
      <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post">
        <div class="mb-3">
          <label class="form-label">Name</label>
          <input type="text" name="name" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" required minlength="6">
        </div>
        <button class="btn btn-primary w-100">Register</button>
        <p class="text-center mt-3 mb-0">Already have an account? <a href="<?= BASE_URL ?>/auth/login.php">Log in</a></p>
      </form>
    </div>
  </div>
</div>
</body>
</html>
