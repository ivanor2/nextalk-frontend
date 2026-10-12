<?php
require_once __DIR__ . '/../src/Bootstrap.php';
Auth::requireLogin();

$userId = Auth::userId();
$username = Auth::username();
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $membersInput = $_POST['members'] ?? [];

    if (empty($title)) {
        if ($isAjax) {
            Helpers::json(['ok' => false, 'error' => 'Укажите название группы'], 400);
        }
        header('Location: ../index.php?error=empty_group_name');
        exit;
    }

    $memberUsernames = $_POST['member_usernames'] ?? [];
    $extraUsernamesRaw = trim($_POST['extra_usernames'] ?? '');

    $membersMap = []; // userId => ['id' => int, 'username' => string]

    // 1. Process selected checkbox contacts
    if (is_array($membersInput)) {
        foreach ($membersInput as $m) {
            $mid = (int)$m;
            if ($mid > 0 && $mid !== $userId) {
                $mUname = is_array($memberUsernames) && !empty($memberUsernames[$mid]) ? trim($memberUsernames[$mid]) : '';
                $membersMap[$mid] = [
                    'id' => $mid,
                    'username' => $mUname
                ];
            }
        }
    }

    // 2. Process extra usernames typed by user
    if (!empty($extraUsernamesRaw)) {
        $extraNames = preg_split('/[\s,]+/', $extraUsernamesRaw, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($extraNames as $uname) {
            $cleanUname = ltrim(trim($uname), '@');
            if (empty($cleanUname) || strtolower($cleanUname) === strtolower($username)) {
                continue;
            }
            // Search user via Auth API
            $searchResp = callAuthApi('GET', '/users/search?q=' . urlencode($cleanUname), null, true);
            if (!empty($searchResp['ok']) && !empty($searchResp['users'])) {
                foreach ($searchResp['users'] as $found) {
                    if (strcasecmp($found['username'], $cleanUname) === 0) {
                        $foundId = (int)$found['id'];
                        if ($foundId !== $userId) {
                            $membersMap[$foundId] = [
                                'id' => $foundId,
                                'username' => $found['username']
                            ];
                        }
                        break;
                    }
                }
            }
        }
    }

    // 3. If any member has missing username, try to enrich it
    foreach ($membersMap as $mid => &$mInfo) {
        if (empty($mInfo['username'])) {
            $searchResp = callAuthApi('GET', '/users/search?q=' . urlencode((string)$mid), null, true);
            if (!empty($searchResp['ok']) && !empty($searchResp['users'])) {
                foreach ($searchResp['users'] as $found) {
                    if ((int)$found['id'] === $mid) {
                        $mInfo['username'] = $found['username'];
                        break;
                    }
                }
            }
        }
    }
    unset($mInfo);

    $members = array_values($membersMap);

    $response = ApiClient::callNode('POST', '/chats', [
        'type' => 'group',
        'name' => $title,
        'members' => $members
    ]);

    if (isset($response['ok']) && $response['ok']) {
        $chatId = $response['chat_id'];
        if ($isAjax) {
            Helpers::json(['ok' => true, 'chat_id' => $chatId]);
        }
        header("Location: ../chat.php?id=$chatId");
        exit;
    } else {
        $err = $response['error'] ?? 'Не удалось создать группу';
        if ($isAjax) {
            Helpers::json(['ok' => false, 'error' => $err], 400);
        }
        header('Location: ../index.php?error=' . urlencode($err));
        exit;
    }
}

header('Location: ../index.php');
exit;
