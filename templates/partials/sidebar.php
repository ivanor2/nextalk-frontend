<?php
$activeTab = $activeTab ?? 'chats';
$curUsername = $_SESSION['username'] ?? 'User';
$curDisplayName = $_SESSION['display_name'] ?? $curUsername;

// Получаем число непрочитанных для бейджа "Чаты"
$sidebarTotalUnread = 0;
if (function_exists('callNodeApi')) {
    $sidebarResp = callNodeApi('GET', '/chats');
    $sidebarTotalUnread = (int)($sidebarResp['total_unread'] ?? 0);
}

$curAvatarPath = $_SESSION['avatar_path'] ?? null;
if (!$curAvatarPath && function_exists('callAuthApi')) {
    $curProfile = callAuthApi('GET', '/profile', null, true);
    if (!empty($curProfile['user']['avatar_path'])) {
        $curAvatarPath = $curProfile['user']['avatar_path'];
        $_SESSION['avatar_path'] = $curAvatarPath;
    }
}
$curAvatarUrl = Helpers::avatarUrl($curAvatarPath);
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-top">
        <div class="sidebar-user">
            <?php if ($activeTab !== 'chats'): ?>
                <a href="index.php" class="icon-btn fs-20 text-decoration-none" title="К чатам">←</a>
            <?php else: ?>
                <div class="user-avatar-btn" id="profileBtn" title="Мой профиль">
                    <div class="avatar avatar-md">
                        <?php if ($curAvatarUrl): ?>
                            <img src="<?= htmlspecialchars($curAvatarUrl) ?>" alt="Мой профиль" class="avatar-img">
                        <?php else: ?>
                            <?= mb_strtoupper(mb_substr($curDisplayName, 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="sidebar-user-info sidebar-user-pointer" onclick="location.href='profile.php'">
                <div class="sidebar-username"><?= htmlspecialchars($curDisplayName) ?></div>
                <div class="sidebar-tag">@<?= htmlspecialchars($curUsername) ?></div>
            </div>
            <button class="icon-btn" id="themeToggleApp" title="Переключить тему">🌙</button>
        </div>
    </div>

    <div class="chat-list chat-list-flat">
        <div class="chat-list-label">Разделы</div>
        <a href="index.php" class="chat-item <?= $activeTab === 'chats' ? 'active' : '' ?>">
            <span class="sidebar-icon">💬</span>
            <div class="chat-item-body">
                <div class="chat-item-name flex-between">
                    <span>Чаты</span>
                    <span class="unread-badge sidebar-chats-badge <?= $sidebarTotalUnread > 0 ? '' : 'd-none' ?>" id="sidebarChatsBadge"><?= $sidebarTotalUnread ?></span>
                </div>
            </div>
        </a>
        <a href="contacts.php" class="chat-item <?= $activeTab === 'contacts' ? 'active' : '' ?>">
            <span class="sidebar-icon">👥</span>
            <div class="chat-item-body">
                <div class="chat-item-name">Контакты</div>
            </div>
        </a>
        <a href="profile.php" class="chat-item <?= $activeTab === 'profile' ? 'active' : '' ?>">
            <span class="sidebar-icon">👤</span>
            <div class="chat-item-body">
                <div class="chat-item-name">Профиль</div>
            </div>
        </a>
        <a href="settings.php" class="chat-item <?= $activeTab === 'settings' ? 'active' : '' ?>">
            <span class="sidebar-icon">⚙️</span>
            <div class="chat-item-body">
                <div class="chat-item-name">Настройки</div>
            </div>
        </a>
        <a href="sessions.php" class="chat-item <?= $activeTab === 'sessions' ? 'active' : '' ?>">
            <span class="sidebar-icon">📱</span>
            <div class="chat-item-body">
                <div class="chat-item-name">Сеансы</div>
            </div>
        </a>
        <a href="nodes.php" class="chat-item <?= $activeTab === 'nodes' ? 'active' : '' ?>">
            <span class="sidebar-icon">🌐</span>
            <div class="chat-item-body">
                <div class="chat-item-name">Узлы сети</div>
            </div>
        </a>
        <a href="rules.php" class="chat-item <?= $activeTab === 'rules' ? 'active' : '' ?>">
            <span class="sidebar-icon">📜</span>
            <div class="chat-item-body">
                <div class="chat-item-name">Правила</div>
            </div>
        </a>
    </div>

    <div class="sidebar-bottom">
        <a href="logout.php" class="sidebar-action-btn text-decoration-none">🚪 Выйти</a>
    </div>
</aside>

