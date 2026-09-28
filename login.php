<?php
require_once 'db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $response = callNodeApi('POST', '/auth/login', [
            'username' => $username,
            'password' => $password
        ]);

        if (isset($response['ok']) && $response['ok']) {
            $_SESSION['user_id'] = $response['user_id'];
            $_SESSION['username'] = $response['username'];
            $_SESSION['jwt_token'] = $response['token'];
            header('Location: index.php');
            exit;
        } else {
            $error = $response['error'] ?? 'Неверный логин или пароль. Ответ: ' . json_encode($response);
        }
    } else {
        $error = 'Введите логин и пароль';
    }
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <title>NexTalk — Вход</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .auth-container { max-width: 400px; margin: 100px auto; background: var(--bg-surface); padding: 32px; border-radius: 12px; border: 1px solid var(--border); }
        .auth-container h2 { text-align: center; margin-bottom: 24px; color: var(--text-primary); }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 14px; }
        .form-group input { width: 100%; padding: 12px; background: var(--bg-secondary); border: 1px solid var(--border); border-radius: 8px; color: var(--text-primary); }
        .auth-btn { width: 100%; padding: 12px; background: var(--accent); color: white; border: none; border-radius: 8px; font-weight: 500; cursor: pointer; margin-top: 8px; }
        .auth-links { text-align: center; margin-top: 16px; font-size: 14px; }
        .auth-links a { color: var(--accent); text-decoration: none; }
        .error-msg { color: var(--danger); background: rgba(255, 68, 68, 0.1); padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; text-align: center; }
    </style>
</head>
<body>
    <div class="auth-container">
        <h2>Вход в NexTalk</h2>
        <?php if ($error): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Логин</label>
                <input type="text" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Пароль</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="auth-btn">Войти</button>
        </form>
        <div class="auth-links">
            Нет аккаунта? <a href="register.php">Зарегистрироваться</a>
        </div>
    </div>
</body>
</html>