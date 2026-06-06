<?php
// save_player_id.php - Store OneSignal Player ID
require_once 'includes/db.php';

// Get JSON input
$data = json_decode(file_get_contents("php://input"), true);
$player_id = $data['player_id'] ?? null;
$device_id = $data['device_id'] ?? null;

if (!$player_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing player_id']);
    exit();
}

// Check if user is logged in
$user_id = $_SESSION['user_id'] ?? null;

try {
    // Insert or Update the device
    // If player_id exists, update user_id and updated_at
    // If it doesn't exist, insert new row
    $stmt = $pdo->prepare("
        INSERT INTO user_devices (user_id, onesignal_player_id, device_id)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            user_id = IF(? IS NOT NULL, ?, user_id),
            device_id = IF(? IS NOT NULL, ?, device_id),
            updated_at = NOW()
    ");
    
    $stmt->execute([$user_id, $player_id, $device_id, $user_id, $user_id, $device_id, $device_id]);

    // Store player_id in session for cleanup on logout
    $_SESSION['player_id'] = $player_id;

    echo json_encode(['status' => 'success', 'message' => 'Player ID saved']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
