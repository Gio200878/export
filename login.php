<?php
require_once __DIR__ . '/lib/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$errore = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($login && $password && auth_login($login, $password)) {
        header('Location: index.php');
        exit;
    }
    $errore = 'Login o password non corretti.';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HEMI Gestionale - Accesso</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">
  <div class="login-box">
    <h1>HEMI Gestionale</h1>
    <p class="subtitle">Agenda appuntamenti educator</p>
    <?php if ($errore): ?>
      <div class="alert alert-error"><?= htmlspecialchars($errore) ?></div>
    <?php endif; ?>
    <form method="post">
      <label>Login</label>
      <input type="text" name="login" required autofocus>
      <label>Password</label>
      <input type="password" name="password" required>
      <button type="submit" class="btn btn-primary btn-block">Accedi</button>
    </form>
  </div>
</body>
</html>
