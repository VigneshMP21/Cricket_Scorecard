<?php

function cpt_live_commentary_storage_dir()
{
    $dir = __DIR__ . '/../storage/live_commentary';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Unable to create live commentary storage directory.');
    }

    if (!is_writable($dir)) {
        throw new RuntimeException('Live commentary storage directory is not writable.');
    }

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess) && file_put_contents($htaccess, "Require all denied\nDeny from all\n", LOCK_EX) === false) {
        throw new RuntimeException('Unable to protect live commentary storage directory.');
    }

    return $dir;
}

function cpt_live_commentary_secret()
{
    $envSecret = getenv('CPT_COMMENTARY_SECRET');
    if (is_string($envSecret) && strlen($envSecret) >= 32) {
        return $envSecret;
    }

    $file = cpt_live_commentary_storage_dir() . '/.secret';
    if (file_exists($file)) {
        $secret = trim((string) file_get_contents($file));
        if (strlen($secret) >= 32) {
            return $secret;
        }
    }

    $secret = bin2hex(random_bytes(32));
    if (file_put_contents($file, $secret, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write live commentary secret.');
    }
    return $secret;
}

function cpt_live_commentary_token($matchId)
{
    return hash_hmac('sha256', 'commentary-token:' . (int) $matchId, cpt_live_commentary_secret());
}

function cpt_live_commentary_verify_token($matchId, $token)
{
    if (!is_string($token) || $token === '') {
        return false;
    }

    return hash_equals(cpt_live_commentary_token($matchId), $token);
}

function cpt_live_commentary_state_file($matchId)
{
    return cpt_live_commentary_storage_dir() . '/match_' . (int) $matchId . '.json';
}

function cpt_live_commentary_default_state($matchId)
{
    return [
        'match_id' => (int) $matchId,
        'room_id' => null,
        'active' => false,
        'revision' => 0,
        'started_at' => null,
        'stopped_at' => null,
        'updated_at' => time(),
        'viewers' => [],
    ];
}

function cpt_live_commentary_read_state($matchId)
{
    $file = cpt_live_commentary_state_file($matchId);
    if (!file_exists($file)) {
        return cpt_live_commentary_default_state($matchId);
    }

    $json = file_get_contents($file);
    $state = json_decode($json, true);
    if (!is_array($state)) {
        return cpt_live_commentary_default_state($matchId);
    }

    return array_merge(cpt_live_commentary_default_state($matchId), $state);
}

function cpt_live_commentary_write_state($matchId, array $state)
{
    $state['match_id'] = (int) $matchId;
    $state['updated_at'] = time();
    file_put_contents(
        cpt_live_commentary_state_file($matchId),
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function cpt_live_commentary_update_state($matchId, callable $callback)
{
    $file = cpt_live_commentary_state_file($matchId);
    $handle = fopen($file, 'c+');
    if (!$handle) {
        throw new RuntimeException('Unable to open commentary state file.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock commentary state file.');
        }

        rewind($handle);
        $json = stream_get_contents($handle);
        $state = json_decode($json ?: '', true);
        if (!is_array($state)) {
            $state = cpt_live_commentary_default_state($matchId);
        } else {
            $state = array_merge(cpt_live_commentary_default_state($matchId), $state);
        }

        $state = $callback($state);
        $state['match_id'] = (int) $matchId;
        $state['updated_at'] = time();

        rewind($handle);
        ftruncate($handle, 0);
        if (fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            throw new RuntimeException('Unable to write commentary state file.');
        }
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    return $state;
}

function cpt_live_commentary_prune_viewers(array $state, $ttlSeconds = 45)
{
    $now = time();
    $viewers = [];

    foreach (($state['viewers'] ?? []) as $peerId => $viewer) {
        $lastSeen = isset($viewer['last_seen']) ? (int) $viewer['last_seen'] : 0;
        if ($lastSeen > 0 && ($now - $lastSeen) <= $ttlSeconds) {
            $viewers[$peerId] = $viewer;
        }
    }

    $state['viewers'] = $viewers;
    return $state;
}

function cpt_live_commentary_public_state(array $state)
{
    return [
        'active' => !empty($state['active']),
        'room_id' => $state['room_id'],
        'revision' => (int) ($state['revision'] ?? 0),
        'viewer_count' => count($state['viewers'] ?? []),
        'updated_at' => (int) ($state['updated_at'] ?? time()),
    ];
}

function cpt_live_commentary_can_broadcast()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'commentator'], true);
}
