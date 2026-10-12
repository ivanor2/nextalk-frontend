<?php
require_once __DIR__ . '/src/Bootstrap.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$errors = [];
$success = false;

// Загружаем список узлов для dropdown
$nodesResponse = callAuthApi('GET', '/nodes?status=active');
$nodes = [];
if (isset($nodesResponse['ok']) && $nodesResponse['ok']) {
    $nodes = $nodesResponse['nodes'] ?? [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $displayName = trim($_POST['display_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $homeNodeId = (int)($_POST['home_node_id'] ?? 0);
    $agree = isset($_POST['agree']);

    // Client-side validation (redundant with server but good UX)
    if (!$username) $errors['username'] = 'Логин обязателен';
    elseif (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) $errors['username'] = 'Логин: 3–32 символа, латиница, цифры, _';

    if (!$displayName) $errors['display_name'] = 'Отображаемое имя обязательно';
    elseif (mb_strlen($displayName) > 64) $errors['display_name'] = 'Максимум 64 символа';

    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Некорректный формат e-mail';

    if (!$password) $errors['password'] = 'Пароль обязателен';
    elseif (strlen($password) < 10) $errors['password'] = 'Минимум 10 символов';
    elseif (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password))
        $errors['password'] = 'Пароль должен содержать буквы и цифры';

    if ($password !== $passwordConfirm) $errors['password_confirm'] = 'Пароли не совпадают';

    if (!$agree) $errors['agree'] = 'Необходимо принять правила';

    if (empty($errors)) {
        // По ТЗ раздел 6.6: узел проксирует запрос на сервер авторизации
        $response = callNodeApi('POST', '/auth/register', [
            'username' => $username,
            'password' => $password,
            'email' => $email,
            'display_name' => $displayName,
            'home_node_id' => $homeNodeId ?: null,
        ]);

        // Если узел оффлайн или не отвечает, обращаемся напрямую к auth-серверу
        if (!isset($response['ok']) || !$response['ok']) {
            if (isset($response['error']) && ($response['error'] === 'Node offline' || str_contains($response['error'], 'Offline'))) {
                $response = callAuthApi('POST', '/auth/register', [
                    'username' => $username,
                    'password' => $password,
                    'email' => $email,
                    'display_name' => $displayName,
                    'home_node_id' => $homeNodeId ?: null,
                ]);
            }
        }

        if (isset($response['ok']) && $response['ok']) {
            $_SESSION['user_id'] = $response['user_id'];
            $_SESSION['username'] = $response['username'];
            $_SESSION['display_name'] = $response['display_name'] ?? $response['username'];
            $_SESSION['jwt_token'] = $response['token'];
            header('Location: index.php');
            exit;
        } else {
            $errors['general'] = $response['error'] ?? 'Ошибка при регистрации';
        }
    }
}
$pageTitle = 'NexTalk — Регистрация';
$metaDescription = 'Создайте аккаунт в NexTalk — децентрализованном мессенджере с E2E-шифрованием';
$bodyClass = 'auth-body';
require __DIR__ . '/templates/partials/head.php';
?>
    <div class="auth-card auth-card-wide">
        <div class="auth-logo">
            <div class="logo-icon">💬</div>
            <div class="logo-text">Nex<em>Talk</em></div>
        </div>

        <h2>Создать аккаунт</h2>
        <p class="auth-subtitle">Зарегистрируйтесь, чтобы начать общение</p>

        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-error">⚠️ <?= htmlspecialchars($errors['general']) ?></div>
        <?php endif; ?>

        <form method="POST" id="registerForm" novalidate>
            <div class="field">
                <label for="username">Логин</label>
                <input type="text" id="username" name="username" required
                       pattern="[a-zA-Z0-9_]{3,32}"
                       placeholder="my_login"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       class="<?= isset($errors['username']) ? 'error-input' : '' ?>">
                <?php if (isset($errors['username'])): ?>
                    <div class="field-error"><?= htmlspecialchars($errors['username']) ?></div>
                <?php else: ?>
                    <div class="field-hint">3–32 символа: латиница, цифры, _</div>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="display_name">Отображаемое имя</label>
                <input type="text" id="display_name" name="display_name" required
                       maxlength="64"
                       placeholder="Как вас называть в чатах"
                       value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>"
                       class="<?= isset($errors['display_name']) ? 'error-input' : '' ?>">
                <?php if (isset($errors['display_name'])): ?>
                    <div class="field-error"><?= htmlspecialchars($errors['display_name']) ?></div>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email"
                       placeholder="user@example.com"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       class="<?= isset($errors['email']) ? 'error-input' : '' ?>">
                <?php if (isset($errors['email'])): ?>
                    <div class="field-error"><?= htmlspecialchars($errors['email']) ?></div>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="password">Пароль</label>
                <div class="password-wrap">
                    <input type="password" id="password" name="password" required
                           minlength="10"
                           placeholder="Минимум 10 символов, буквы + цифры"
                           class="<?= isset($errors['password']) ? 'error-input' : '' ?>">
                    <button type="button" class="password-toggle" id="togglePass" tabindex="-1">👁</button>
                </div>
                <div class="password-strength" id="passwordStrength">
                    <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                    <span class="strength-label" id="strengthLabel"></span>
                </div>
                <?php if (isset($errors['password'])): ?>
                    <div class="field-error"><?= htmlspecialchars($errors['password']) ?></div>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="password_confirm">Повторите пароль</label>
                <input type="password" id="password_confirm" name="password_confirm" required
                       placeholder="Ещё раз тот же пароль"
                       class="<?= isset($errors['password_confirm']) ? 'error-input' : '' ?>">
                <?php if (isset($errors['password_confirm'])): ?>
                    <div class="field-error"><?= htmlspecialchars($errors['password_confirm']) ?></div>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="home_node_id">Домашний узел</label>
                <select id="home_node_id" name="home_node_id" class="select-field">
                    <?php if (empty($nodes)): ?>
                        <option value="0">Узел по умолчанию</option>
                    <?php else: ?>
                        <?php foreach ($nodes as $node): ?>
                            <option value="<?= $node['id'] ?>"
                                <?= (isset($_POST['home_node_id']) && $_POST['home_node_id'] == $node['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($node['name']) ?>
                                <?php if (!empty($node['user_count'])): ?>
                                    (<?= $node['user_count'] ?> польз.)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <div class="field-hint">Узел, через который будут передаваться ваши сообщения</div>
            </div>

            <div class="field-check">
                <input type="checkbox" id="agree" name="agree" <?= isset($_POST['agree']) ? 'checked' : '' ?>>
                <label for="agree">Я принимаю <a href="rules.php" target="_blank">правила использования</a> и <a href="rules.php#privacy" target="_blank">политику конфиденциальности</a></label>
            </div>
            <?php if (isset($errors['agree'])): ?>
                <div class="field-error field-error-agree"><?= htmlspecialchars($errors['agree']) ?></div>
            <?php endif; ?>

            <button type="submit" class="btn-submit" id="submitBtn">Зарегистрироваться</button>
        </form>

        <div class="auth-switch">
            Уже есть аккаунт? <a href="login.php">Войти</a>
        </div>
    </div>

    <script src="assets/js/app.js"></script>
    <script src="assets/js/auth.js"></script>
</body>
</html>