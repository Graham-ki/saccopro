<?php
require_once __DIR__ . '/../includes/auth.php';

if (!empty($_SESSION['user_id'])) redirect('/scms/index.php');

$errors = [];
$old = ['username' => '', 'email' => '', 'full_name' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $old['username']  = trim($_POST['username']  ?? '');
    $old['email']     = trim($_POST['email']     ?? '');
    $old['full_name'] = trim($_POST['full_name'] ?? '');
    $password         = $_POST['password']         ?? '';
    $confirm          = $_POST['password_confirm'] ?? '';

    if ($old['username'] === '' || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $old['username']))
        $errors[] = 'Username must be 3–30 characters (letters, numbers, underscore).';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL))
        $errors[] = 'A valid email is required.';
    if ($old['full_name'] === '')
        $errors[] = 'Full name is required.';
    if (strlen($password) < 8)
        $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm)
        $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$old['username'], $old['email']]);
        if ($stmt->fetch()) $errors[] = 'Username or email already registered.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, email, password_hash, full_name, role, status)
             VALUES (?, ?, ?, ?, 'clerk', 'pending')"
        );
        $stmt->execute([
            $old['username'], $old['email'],
            password_hash($password, PASSWORD_DEFAULT),
            $old['full_name'],
        ]);
        flash('success', 'Registration submitted. An admin must approve your account before you can sign in.');
        redirect('/scms/auth/login.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create account · SCMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/scms/assets/css/style.css">
</head>
<body class="auth-page">
<main class="auth-card">
    <a href="/scms/landing.php" class="auth-brand">
        <span class="brand-mark">SC</span>
        <span>SCMS</span>
    </a>
    <h1>Create your account</h1>
    <p class="subtitle">An administrator will review your request before you can sign in.</p>

    <?php if ($errors): ?>
        <div class="alert alert-error">
            <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="post" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label>Full name
            <input type="text" name="full_name" value="<?= e($old['full_name']) ?>" required>
        </label>
        <label>Username
            <input type="text" name="username" value="<?= e($old['username']) ?>" required>
        </label>
        <label>Email
            <input type="email" name="email" value="<?= e($old['email']) ?>" required>
        </label>
        <label>Password
            <input type="password" name="password" minlength="8" required>
        </label>
        <label>Confirm password
            <input type="password" name="password_confirm" minlength="8" required>
        </label>

        <button type="submit" class="btn btn-primary btn-block btn-lg">Create account</button>
    </form>

    <p class="auth-foot">Already registered? <a href="login.php">Sign in</a></p>
</main>
</body>
</html> 