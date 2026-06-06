<?php
// login/logout.php
// Logout script

require_once '../includes/db.php';

// Handle Persistent Token Cleanup
if (isset($_COOKIE['remember_token'])) {
    $parts = explode(':', $_COOKIE['remember_token']);
    if (count($parts) === 2) {
        $selector = $parts[0];
        try {
            $del_stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE selector = ?");
            $del_stmt->execute([$selector]);
        } catch (PDOException $e) {
            // Error logging (non-critical for logout)
        }
    }
    // Clear persistent cookie
    setcookie('remember_token', '', time() - 3600, '/');
}

// 🔔 Unlink OneSignal Player ID (Device becomes guest again)
if (isset($_SESSION['player_id'])) {
    try {
        $stmt = $pdo->prepare("UPDATE user_devices SET user_id = NULL WHERE onesignal_player_id = ?");
        $stmt->execute([$_SESSION['player_id']]);
    } catch (PDOException $e) {
        // Non-blocking error
    }
}

// Destroy all session data
$_SESSION = array();

// Redirect to home page
header("Location: ../../index.php");
exit();
?>