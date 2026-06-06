<?php
require_once __DIR__ . '/../includes/live_commentary.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function commentary_json($payload, $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function commentary_require_https()
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false)
        || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
        || (isset($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_CF_VISITOR']) && stripos($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"') !== false);

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isLocalhost = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host);

    if (!$isHttps && !$isLocalhost) {
        commentary_json([
            'success' => false,
            'message' => 'Live commentary requires HTTPS.',
        ], 403);
    }
}

function commentary_match_id()
{
    $matchId = isset($_REQUEST['match_id']) ? (int) $_REQUEST['match_id'] : 0;
    if ($matchId <= 0) {
        commentary_json(['success' => false, 'message' => 'Invalid match id.'], 400);
    }

    return $matchId;
}

function commentary_token()
{
    return $_POST['token'] ?? $_GET['token'] ?? '';
}

function commentary_validate_peer_id($peerId)
{
    return is_string($peerId) && preg_match('/^[A-Za-z0-9_-]{8,90}$/', $peerId);
}

commentary_require_https();

$action = $_REQUEST['action'] ?? 'status';
$matchId = commentary_match_id();

try {
    if ($action === 'start') {
        if (!cpt_live_commentary_can_broadcast()) {
            commentary_json(['success' => false, 'message' => 'Commentary broadcaster access required.'], 403);
        }

        $hostPeerId = trim((string) ($_POST['host_peer_id'] ?? ''));
        if ($hostPeerId !== '' && !commentary_validate_peer_id($hostPeerId)) {
            commentary_json(['success' => false, 'message' => 'Invalid commentator PeerJS id.'], 400);
        }

        $state = cpt_live_commentary_update_state($matchId, function ($state) use ($hostPeerId) {
            $state = cpt_live_commentary_prune_viewers($state);
            $state['active'] = true;
            $state['room_id'] = 'cpt-' . bin2hex(random_bytes(18));
            $state['host_peer_id'] = $hostPeerId ?: null;
            $state['revision'] = (int) ($state['revision'] ?? 0) + 1;
            $state['started_at'] = time();
            $state['stopped_at'] = null;
            return $state;
        });

        commentary_json(['success' => true] + cpt_live_commentary_public_state($state));
    }

    if ($action === 'stop') {
        if (!cpt_live_commentary_can_broadcast()) {
            commentary_json(['success' => false, 'message' => 'Commentary broadcaster access required.'], 403);
        }

        $state = cpt_live_commentary_update_state($matchId, function ($state) {
            $state = cpt_live_commentary_prune_viewers($state);
            $state['active'] = false;
            $state['host_peer_id'] = null;
            $state['revision'] = (int) ($state['revision'] ?? 0) + 1;
            $state['stopped_at'] = time();
            return $state;
        });

        commentary_json(['success' => true] + cpt_live_commentary_public_state($state));
    }

    if ($action === 'viewers') {
        if (!cpt_live_commentary_can_broadcast()) {
            commentary_json(['success' => false, 'message' => 'Commentary broadcaster access required.'], 403);
        }

        $state = cpt_live_commentary_update_state($matchId, function ($state) {
            return cpt_live_commentary_prune_viewers($state);
        });

        $viewers = array_values(array_map(function ($viewer) {
            return [
                'peer_id' => $viewer['peer_id'],
                'last_seen' => (int) $viewer['last_seen'],
                'joined_at' => (int) ($viewer['joined_at'] ?? $viewer['last_seen']),
            ];
        }, $state['viewers'] ?? []));

        commentary_json([
            'success' => true,
            'viewers' => $viewers,
        ] + cpt_live_commentary_public_state($state));
    }

    if (!cpt_live_commentary_verify_token($matchId, commentary_token())) {
        commentary_json(['success' => false, 'message' => 'Invalid commentary token.'], 403);
    }

    if ($action === 'join' || $action === 'heartbeat') {
        $peerId = trim((string) ($_POST['peer_id'] ?? ''));
        if (!commentary_validate_peer_id($peerId)) {
            commentary_json(['success' => false, 'message' => 'Invalid PeerJS id.'], 400);
        }

        $viewerCookie = $_COOKIE['cpt_viewer_id'] ?? '';
        $viewerHash = is_string($viewerCookie) && $viewerCookie !== ''
            ? hash_hmac('sha256', $viewerCookie, cpt_live_commentary_secret())
            : null;

        $state = cpt_live_commentary_update_state($matchId, function ($state) use ($peerId, $viewerHash) {
            $state = cpt_live_commentary_prune_viewers($state);
            $now = time();
            $state['viewers'][$peerId] = [
                'peer_id' => $peerId,
                'viewer_hash' => $viewerHash,
                'joined_at' => $state['viewers'][$peerId]['joined_at'] ?? $now,
                'last_seen' => $now,
            ];
            return $state;
        });

        commentary_json([
            'success' => true,
            'peer_id' => $peerId,
        ] + cpt_live_commentary_public_state($state));
    }

    if ($action === 'leave') {
        $peerId = trim((string) ($_POST['peer_id'] ?? ''));
        if ($peerId !== '') {
            cpt_live_commentary_update_state($matchId, function ($state) use ($peerId) {
                unset($state['viewers'][$peerId]);
                return $state;
            });
        }

        commentary_json(['success' => true]);
    }

    if ($action === 'status') {
        $state = cpt_live_commentary_update_state($matchId, function ($state) {
            return cpt_live_commentary_prune_viewers($state);
        });

        commentary_json(['success' => true] + cpt_live_commentary_public_state($state));
    }

    commentary_json(['success' => false, 'message' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    error_log('Live commentary API error: ' . $e->getMessage());
    commentary_json([
        'success' => false,
        'message' => 'Commentary service error.',
    ], 500);
}
