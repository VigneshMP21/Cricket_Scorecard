<?php
require_once '../includes/db.php';
if (session_status() === PHP_SESSION_NONE)
    session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$player_id = isset($_POST['player_id']) ? (int) $_POST['player_id'] : 0;

if (!$player_id) {
    echo json_encode(['success' => false, 'message' => 'Player ID required']);
    exit();
}

try {
    // 1. First fetch the image to delete it from server
    $stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
    $stmt->execute([$player_id]);
    $img = $stmt->fetchColumn();

    // 2. Delete from players table (doesn't have cascade in schema for user_id)
    $stmt = $pdo->prepare("DELETE FROM players WHERE user_id = ?");
    $stmt->execute([$player_id]);

    // 3. Delete user (triggers cascade delete for team_players, match_stats, etc.)
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    if ($stmt->execute([$player_id])) {
        // 4. Delete image file if exists
        if ($img && file_exists('../uploads/users/' . $img)) {
            unlink('../uploads/users/' . $img);
        }
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete user']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
