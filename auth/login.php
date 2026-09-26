<?php
require_once __DIR__ . '/../includes/auth.php';

if (!empty($_SESSION['user_id'])) redirect('/scms/index.php');

$error = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid username or password.';
        } elseif ($user['status'] === 'pending') {
            $error = 'Your account is awaiting admin approval.';
        } elseif ($user['status'] === 'rejected') {
            $error = 'Your registration was rejected. Contact an admin.';
        } elseif ($user['status'] === 'suspended') {
            $error = 'Your account is suspended. Contact an admin.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);

            // Route by role
            if ($user['role'] === 'member') {
            redirect('/scms/portal/index.php');
            }else {
            redirect('/scms/dashboard/index.php');}
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in · SCMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/scms/assets/css/style.css">
</head>
<body class="auth-page">
<main class="auth-card">
    <a href="/scms/index.php" class="auth-brand">
        <span class="brand-mark">SC</span>
        <span>SCMS</span>
    </a>
    <h1>Welcome back</h1>
    <p class="subtitle">Sign in to continue to your dashboard.</p>

    <?php if ($msg = flash('success')): ?>
        <div class="alert alert-success"><?= e($msg) ?></div>
    <?php endif; ?>
    <?php if ($msg = flash('error')): ?>
        <div class="alert alert-error"><?= e($msg) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label>Username
            <input type="text" name="username" value="<?= e($username) ?>" autofocus required>
        </label>
        <label>Password
            <input type="password" name="password" required>
        </label>

        <button type="submit" class="btn btn-primary btn-block btn-lg">Sign in</button>
    </form>

    <p class="auth-foot">Don't have an account? <a href="register.php">Create one</a></p>
</main>
</body>
</html>