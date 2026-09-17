<?php
require_once __DIR__ . '/../config/base_url.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/login_throttle.php';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $pass  = $_POST['password'];

    // Phase K2-C: decide whether this address is currently refused
    // BEFORE touching the users table or any password hash.
    //
    // Doing it first is the point. A bcrypt verify costs roughly 0.23s
    // of CPU here (PASSWORD_DEFAULT is cost 12), and the stock php-fpm
    // pool runs five workers - so a throttle that still paid for the
    // hash would let an attacker exhaust the shop's own ability to log
    // in while being "protected". A refused request costs one indexed
    // COUNT instead.
    //
    // The refusal is deliberately indistinguishable from an ordinary
    // failure: same $error, same rendered page, same 200. Nothing tells
    // the caller that a throttle exists or which address tripped it.
    if (loginIsThrottled($pdo, $email)) {
        $error = __('login_invalid');
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Phase K2-C: always perform one real bcrypt verify, whether or
        // not the address exists.
        //
        // Previously this was `$user && password_verify(...)`, so a
        // submitted address with no account skipped the hash entirely
        // and answered in about a thousandth of the time a real account
        // took - measured at 0.0011s against 0.2324s, a ~210x tell that
        // enumerated every valid account in the shop at one request
        // each. The dummy hash is a fixed constant in
        // includes/login_throttle.php, never regenerated per request.
        //
        // The lookup itself is unchanged - same query, same binding,
        // same case-folding behaviour as before.
        $authenticated = $user
            ? password_verify($pass, $user['password'])
            : verifyAgainstDummyHash($pass);

        if ($authenticated) {
            // Phase K2-C: proving the password wipes this address's
            // failure history, so a member of staff who fumbles a few
            // times and then gets it right does not carry those
            // failures into the next ten minutes.
            clearFailedLogins($pdo, $email);

            // Phase K2-A: rotate the session ID the moment
            // authentication succeeds, BEFORE any authenticated value
            // is written below.
            //
            // The session is already open by this point -
            // includes/lang.php (required above) starts it to read
            // $_SESSION['lang'] - so whatever ID the browser presented
            // has already been adopted. K2-B now sets
            // session.use_strict_mode=1, but that is configuration a
            // deployment could lose; without this call a presented ID
            // would simply become an authenticated one (session
            // fixation), so the rotation stays the primary defence.
            //
            // The `true` argument deletes the old server-side session
            // file rather than leaving it behind as a second,
            // still-valid copy. $_SESSION contents are carried over to
            // the new ID by PHP, so the pre-login language choice
            // survives and the assignments below behave exactly as
            // before.
            session_regenerate_id(true);

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['role_id']   = $user['role_id'];
            $_SESSION['must_change_password'] = (bool) $user['must_change_password'];
            // Phase K2-D: the baseline includes/auth_check.php compares
            // against on every later request. Storing it here - from the
            // same row the password was just verified against - is what
            // lets a subsequent password change invalidate this session
            // and every other one for the account. No extra query and no
            // second session_regenerate_id(): K2-A's rotation above has
            // already run, and $_SESSION survives it.
            $_SESSION['password_changed_at'] = $user['password_changed_at'];
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        } else {
            // Phase K2-C: record the failure, then take out rows that
            // have already aged past the window. Pruning here rather
            // than on a schedule keeps the table proportional to recent
            // activity without this application growing a scheduler;
            // the cutoff is the window boundary itself, so it can only
            // ever remove attempts that no longer count for anyone.
            recordFailedLogin($pdo, $email);
            pruneStaleLoginAttempts($pdo);

            $error = __('login_invalid');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($_SESSION['lang']) ?>">
<head>
<script>
  if (localStorage.getItem('theme') === 'light') {
    document.documentElement.classList.add('theme-light-pending');
  }
</script>
<meta charset="UTF-8">
<title>Log in — Inventory</title>
<link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>/assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="64x64" href="<?= BASE_URL ?>/assets/favicon-64.png">
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
      <img src="<?= BASE_URL ?>/assets/logo-192.png" alt="" width="48" height="48" class="mb-3">
      <div class="bracket-label mb-2"><?= __('auth_tagline') ?></div>
      <span class="barcode"><i style="width:2px;height:60%"></i><i style="height:100%"></i><i style="width:2px;height:40%"></i><i style="height:80%"></i><i style="width:4px;height:55%"></i><i style="height:100%"></i><i style="width:2px;height:70%"></i></span>
    </div>
    <div class="auth-hero">
      <h1><?= __('auth_hero_title') ?></h1>
      <p class="mt-3"><?= __('auth_hero_subtitle') ?></p>
    </div>
    <div class="mono auth-footer-meta" style="color:#5C6584; font-size:.78rem;">127.0.0.1:9000</div>
  </div>

  <div class="auth-right">
    <div class="auth-form">
      <h4 class="mb-4"><?= __('login_title') ?></h4>
      <?php if (!empty($_GET['registered'])): ?>
        <div class="alert alert-success py-2"><?= __('login_registered_success') ?></div>
      <?php endif; ?>
      <?php if (!empty($_GET['registration_closed'])): ?>
        <div class="alert alert-info py-2"><?= __('login_registration_closed') ?></div>
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
        <?php if (filter_var(getenv('SELF_REGISTRATION_ENABLED'), FILTER_VALIDATE_BOOLEAN)): ?>
        <p class="text-center mt-3 mb-0"><?= __('login_no_account') ?> <a href="<?= BASE_URL ?>/auth/register.php"><?= __('login_register_link') ?></a></p>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>
</body>
</html>
