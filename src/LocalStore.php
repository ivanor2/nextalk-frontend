<?php
/**
 * NexTalk Messenger — Local Storage & Offline Cache
 * 
 * Обеспечивает локальное сохранение чатов и сообщений на стороне фронтенда
 * в лёгкую встроенную базу SQLite. Позволяет просматривать всю историю переписки,
 * профили собеседников и список чатов, даже если узел (nextalk-node) или сеть недоступны.
 */

class LocalStore {
    private static ?PDO $db = null;
    private static bool $initialized = false;

    /**
     * Получить экземпляр PDO SQLite с авто-инициализацией таблиц.
     */
    private static function getDb(): ?PDO {
        if (self::$db !== null) {
            return self::$db;
        }

        try {
            $dataDir = dirname(__DIR__) . '/data';
            if (!is_dir($dataDir)) {
                @mkdir($dataDir, 0777, true);
            }

            $dbFile = $dataDir . '/cache.db';
            self::$db = new PDO("sqlite:$dbFile");
            self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$db->exec('PRAGMA journal_mode = WAL;');
            self::$db->exec('PRAGMA synchronous = NORMAL;');

            self::initSchema();
            return self::$db;
        } catch (Throwable $e) {
            error_log('LocalStore connection error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Создание структуры таблиц SQLite, если они не существуют.
     */
    private static function initSchema(): void {
        if (self::$initialized || self::$db === null) return;

        self::$db->exec("
            CREATE TABLE IF NOT EXISTS cached_users (
                user_id INTEGER PRIMARY KEY,
                username TEXT UNIQUE NOT NULL,
                display_name TEXT,
                avatar_path TEXT,
                jwt_token TEXT,
                password_hash TEXT,
                public_key TEXT,
                status_text TEXT,
                updated_at TEXT
            );

            CREATE TABLE IF NOT EXISTS cached_chats (
                user_id INTEGER NOT NULL,
                chat_id INTEGER NOT NULL,
                type TEXT DEFAULT 'private',
                name TEXT,
                companion_id INTEGER,
                companion_name TEXT,
                companion_username TEXT,
                companion_avatar_url TEXT,
                companion_bio TEXT,
                companion_public_key TEXT,
                members_json TEXT,
                last_message TEXT,
                last_message_time TEXT,
                unread_count INTEGER DEFAULT 0,
                updated_at TEXT,
                PRIMARY KEY (user_id, chat_id)
            );

            CREATE TABLE IF NOT EXISTS cached_messages (
                chat_id INTEGER NOT NULL,
                id INTEGER NOT NULL,
                sender_id INTEGER,
                username TEXT,
                content TEXT,
                created_at TEXT,
                PRIMARY KEY (chat_id, id)
            );

            CREATE INDEX IF NOT EXISTS idx_cached_msg_chat ON cached_messages(chat_id, id);
            CREATE INDEX IF NOT EXISTS idx_cached_msg_created ON cached_messages(chat_id, created_at);
            CREATE INDEX IF NOT EXISTS idx_cached_chats_user ON cached_chats(user_id, last_message_time);
        ");

        self::$initialized = true;
    }

    // ── Управление пользователями и профилями ─────────────────

    /**
     * Сохранить профиль пользователя для автономного входа.
     */
    public static function saveUser(array $user, string $password = ''): bool {
        $db = self::getDb();
        if (!$db) return false;

        try {
            $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
            $username = trim($user['username'] ?? '');
            if ($userId <= 0 || empty($username)) return false;

            $passHash = null;
            if (!empty($password)) {
                $passHash = password_hash($password, PASSWORD_DEFAULT);
            }

            $stmt = $db->prepare("
                INSERT INTO cached_users (user_id, username, display_name, avatar_path, jwt_token, password_hash, public_key, status_text, updated_at)
                VALUES (:uid, :uname, :disp, :avatar, :token, :pwd, :pub, :status, datetime('now'))
                ON CONFLICT(user_id) DO UPDATE SET
                    username = excluded.username,
                    display_name = COALESCE(NULLIF(excluded.display_name, ''), cached_users.display_name),
                    avatar_path = COALESCE(NULLIF(excluded.avatar_path, ''), cached_users.avatar_path),
                    jwt_token = COALESCE(NULLIF(excluded.jwt_token, ''), cached_users.jwt_token),
                    password_hash = COALESCE(excluded.password_hash, cached_users.password_hash),
                    public_key = COALESCE(NULLIF(excluded.public_key, ''), cached_users.public_key),
                    status_text = COALESCE(excluded.status_text, cached_users.status_text),
                    updated_at = datetime('now')
            ");

            return $stmt->execute([
                ':uid' => $userId,
                ':uname' => $username,
                ':disp' => $user['display_name'] ?? $username,
                ':avatar' => $user['avatar_path'] ?? null,
                ':token' => $user['token'] ?? $user['jwt_token'] ?? null,
                ':pwd' => $passHash,
                ':pub' => $user['public_key'] ?? null,
                ':status' => $user['status_text'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('LocalStore saveUser error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Проверка пароля и вход в автономном режиме, если сеть/узел оффлайн.
     */
    public static function verifyUserOffline(string $username, string $password): ?array {
        $db = self::getDb();
        if (!$db) return null;

        try {
            $stmt = $db->prepare("SELECT * FROM cached_users WHERE username = :uname LIMIT 1");
            $stmt->execute([':uname' => $username]);
            $u = $stmt->fetch();
            if ($u && !empty($u['password_hash']) && password_verify($password, $u['password_hash'])) {
                return [
                    'ok' => true,
                    'user_id' => (int)$u['user_id'],
                    'username' => $u['username'],
                    'display_name' => $u['display_name'] ?: $u['username'],
                    'avatar_path' => $u['avatar_path'],
                    'token' => $u['jwt_token'],
                    'public_key' => $u['public_key'],
                    'offline_mode' => true,
                ];
            }
        } catch (Throwable $e) {
            error_log('LocalStore verifyUserOffline error: ' . $e->getMessage());
        }
        return null;
    }

    // ── Управление чатами ──────────────────────────────────────

    /**
     * Сохранение списка чатов пользователя.
     */
    public static function saveChats(int $userId, array $chats): bool {
        $db = self::getDb();
        if (!$db || $userId <= 0 || empty($chats)) return false;

        try {
            $db->beginTransaction();
            $stmt = $db->prepare("
                INSERT INTO cached_chats (
                    user_id, chat_id, type, name,
                    companion_id, companion_name, companion_username,
                    companion_avatar_url, companion_bio, companion_public_key,
                    members_json, last_message, last_message_time, unread_count, updated_at
                ) VALUES (
                    :uid, :cid, :type, :name,
                    :comp_id, :comp_name, :comp_user,
                    :comp_avatar, :comp_bio, :comp_pub,
                    :members, :last_msg, :last_time, :unread, datetime('now')
                )
                ON CONFLICT(user_id, chat_id) DO UPDATE SET
                    type = excluded.type,
                    name = COALESCE(excluded.name, cached_chats.name),
                    companion_id = COALESCE(excluded.companion_id, cached_chats.companion_id),
                    companion_name = COALESCE(NULLIF(excluded.companion_name, ''), cached_chats.companion_name),
                    companion_username = COALESCE(NULLIF(excluded.companion_username, ''), cached_chats.companion_username),
                    companion_avatar_url = COALESCE(NULLIF(excluded.companion_avatar_url, ''), cached_chats.companion_avatar_url),
                    companion_bio = COALESCE(NULLIF(excluded.companion_bio, ''), cached_chats.companion_bio),
                    companion_public_key = COALESCE(NULLIF(excluded.companion_public_key, ''), cached_chats.companion_public_key),
                    members_json = COALESCE(NULLIF(excluded.members_json, '[]'), cached_chats.members_json),
                    last_message = COALESCE(excluded.last_message, cached_chats.last_message),
                    last_message_time = COALESCE(excluded.last_message_time, cached_chats.last_message_time),
                    unread_count = excluded.unread_count,
                    updated_at = datetime('now')
            ");

            foreach ($chats as $c) {
                $cid = (int)($c['id'] ?? $c['chat_id'] ?? 0);
                if ($cid <= 0) continue;

                $membersJson = isset($c['members']) ? json_encode($c['members'], JSON_UNESCAPED_UNICODE) : '[]';

                $stmt->execute([
                    ':uid' => $userId,
                    ':cid' => $cid,
                    ':type' => $c['type'] ?? 'private',
                    ':name' => $c['name'] ?? null,
                    ':comp_id' => $c['companion_id'] ?? null,
                    ':comp_name' => $c['companion_name'] ?? null,
                    ':comp_user' => $c['companion_username'] ?? ($c['companion_name'] ?? null),
                    ':comp_avatar' => $c['companion_avatar_url'] ?? null,
                    ':comp_bio' => $c['companion_bio'] ?? null,
                    ':comp_pub' => $c['companion_public_key'] ?? null,
                    ':members' => $membersJson,
                    ':last_msg' => $c['last_message'] ?? null,
                    ':last_time' => $c['last_message_time'] ?? null,
                    ':unread' => (int)($c['unread_count'] ?? 0),
                ]);
            }
            $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('LocalStore saveChats error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Сохранение одного чата с его деталями.
     */
    public static function saveChat(int $userId, array $chat): bool {
        return self::saveChats($userId, [$chat]);
    }

    /**
     * Получить список сохранённых чатов пользователя для автономного режима.
     */
    public static function getChats(int $userId): array {
        $db = self::getDb();
        if (!$db || $userId <= 0) return [];

        try {
            $stmt = $db->prepare("
                SELECT * FROM cached_chats
                WHERE user_id = :uid
                ORDER BY last_message_time DESC, updated_at DESC
            ");
            $stmt->execute([':uid' => $userId]);
            $rows = $stmt->fetchAll();

            $result = [];
            foreach ($rows as $r) {
                $result[] = [
                    'id' => (int)$r['chat_id'],
                    'chat_id' => (int)$r['chat_id'],
                    'type' => $r['type'],
                    'name' => $r['name'],
                    'companion_id' => $r['companion_id'] ? (int)$r['companion_id'] : null,
                    'companion_name' => $r['companion_name'] ?: ($r['name'] ?: 'Чат'),
                    'companion_username' => $r['companion_username'] ?: ($r['companion_name'] ?: ''),
                    'companion_avatar_url' => $r['companion_avatar_url'],
                    'companion_bio' => $r['companion_bio'],
                    'companion_public_key' => $r['companion_public_key'],
                    'members' => json_decode($r['members_json'] ?: '[]', true) ?: [],
                    'last_message' => $r['last_message'],
                    'last_message_time' => $r['last_message_time'],
                    'unread_count' => (int)$r['unread_count'],
                    'offline_cached' => true,
                ];
            }
            return $result;
        } catch (Throwable $e) {
            error_log('LocalStore getChats error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Получить информацию о конкретном чате из локальной БД.
     */
    public static function getChat(int $chatId, int $userId): ?array {
        $db = self::getDb();
        if (!$db || $chatId <= 0) return null;

        try {
            $stmt = $db->prepare("
                SELECT * FROM cached_chats
                WHERE chat_id = :cid AND user_id = :uid
                LIMIT 1
            ");
            $stmt->execute([':cid' => $chatId, ':uid' => $userId]);
            $r = $stmt->fetch();
            if ($r) {
                return [
                    'id' => (int)$r['chat_id'],
                    'chat_id' => (int)$r['chat_id'],
                    'type' => $r['type'],
                    'name' => $r['name'],
                    'companion_id' => $r['companion_id'] ? (int)$r['companion_id'] : null,
                    'companion_name' => $r['companion_name'] ?: ($r['name'] ?: 'Чат'),
                    'companion_username' => $r['companion_username'] ?: ($r['companion_name'] ?: ''),
                    'companion_avatar_url' => $r['companion_avatar_url'],
                    'companion_bio' => $r['companion_bio'],
                    'companion_public_key' => $r['companion_public_key'],
                    'members' => json_decode($r['members_json'] ?: '[]', true) ?: [],
                    'last_message' => $r['last_message'],
                    'last_message_time' => $r['last_message_time'],
                    'unread_count' => (int)$r['unread_count'],
                    'offline_cached' => true,
                ];
            }
        } catch (Throwable $e) {
            error_log('LocalStore getChat error: ' . $e->getMessage());
        }
        return null;
    }

    // ── Управление сообщениями ─────────────────────────────────

    /**
     * Сохранение пачки сообщений в локальное хранилище.
     */
    public static function saveMessages(int $chatId, array $messages): bool {
        $db = self::getDb();
        if (!$db || $chatId <= 0 || empty($messages)) return false;

        try {
            $db->beginTransaction();
            $stmt = $db->prepare("
                INSERT OR REPLACE INTO cached_messages (chat_id, id, sender_id, username, content, created_at)
                VALUES (:cid, :id, :sender_id, :username, :content, :created_at)
            ");

            $maxId = 0;
            $lastContent = null;
            $lastTime = null;

            foreach ($messages as $m) {
                $id = (int)($m['id'] ?? 0);
                if ($id <= 0) continue;

                $senderId = (int)($m['sender_id'] ?? $m['user_id'] ?? 0);
                $username = $m['username'] ?? $m['sender'] ?? '';
                $content = $m['content'] ?? '';
                $createdAt = $m['created_at'] ?? $m['timestamp'] ?? date('Y-m-d H:i:s');

                $stmt->execute([
                    ':cid' => $chatId,
                    ':id' => $id,
                    ':sender_id' => $senderId,
                    ':username' => $username,
                    ':content' => $content,
                    ':created_at' => $createdAt,
                ]);

                if ($id > $maxId) {
                    $maxId = $id;
                    $lastContent = $content;
                    $lastTime = $createdAt;
                }
            }

            // Обновляем последнее сообщение чата в cached_chats если есть
            if ($lastContent !== null) {
                $updateChatStmt = $db->prepare("
                    UPDATE cached_chats
                    SET last_message = :content, last_message_time = :time
                    WHERE chat_id = :cid
                ");
                $updateChatStmt->execute([
                    ':content' => $lastContent,
                    ':time' => $lastTime,
                    ':cid' => $chatId,
                ]);
            }

            $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('LocalStore saveMessages error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Сохранить одно сообщение.
     */
    public static function saveMessage(int $chatId, array $msg): bool {
        return self::saveMessages($chatId, [$msg]);
    }

    /**
     * Получить сохранённые сообщения для чата (сортировка по возрастанию времени/ID).
     */
    public static function getMessages(int $chatId, int $limit = 200): array {
        $db = self::getDb();
        if (!$db || $chatId <= 0) return [];

        try {
            $stmt = $db->prepare("
                SELECT id, sender_id, username, content, created_at
                FROM cached_messages
                WHERE chat_id = :cid
                ORDER BY id ASC
                LIMIT :lim
            ");
            $stmt->bindValue(':cid', $chatId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            error_log('LocalStore getMessages error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Получить новые сообщения после указанного ID (для fallback-polling).
     */
    public static function getMessagesAfter(int $chatId, int $lastId = 0, int $limit = 100): array {
        $db = self::getDb();
        if (!$db || $chatId <= 0) return [];

        try {
            $stmt = $db->prepare("
                SELECT id, sender_id, username, content, created_at
                FROM cached_messages
                WHERE chat_id = :cid AND id > :last_id
                ORDER BY id ASC
                LIMIT :lim
            ");
            $stmt->bindValue(':cid', $chatId, PDO::PARAM_INT);
            $stmt->bindValue(':last_id', $lastId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            error_log('LocalStore getMessagesAfter error: ' . $e->getMessage());
            return [];
        }
    }
}

