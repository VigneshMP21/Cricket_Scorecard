<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

ini_set('display_errors', 0);
set_exception_handler(function($e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    exit();
});
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo json_encode(['success' => false, 'error' => $errstr, 'file' => $errfile, 'line' => $errline]);
    exit();
});
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => $error['message'], 'file' => $error['file'], 'line' => $error['line']]);
        exit();
    }
});

if (!isset($_GET['player_id']) || !is_numeric($_GET['player_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid player ID']);
    exit();
}

$player_id = (int) $_GET['player_id'];

try {
    // Fetch player basic info and team info
    $stmt = $pdo->prepare("
        SELECT u.name, u.profile_image, u.playing_role, t.team_name
        FROM users u
        LEFT JOIN team_players tp ON u.id = tp.player_id
        LEFT JOIN teams t ON tp.team_id = t.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$player_id]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($player) {
        // 1. Correct Match Count (only completed matches where player was in playing 11)
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT ms.match_id) 
            FROM match_squads ms
            JOIN matches m ON ms.match_id = m.id
            WHERE ms.player_id = ? AND m.status = 'completed'
        ");
        $stmt->execute([$player_id]);
        $matches = $stmt->fetchColumn();

        // 2. Fetch Aggregated Stats from match_statistics
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(ms.runs_scored), 0) as runs_scored,
                COALESCE(SUM(ms.wickets_taken), 0) as wickets_taken
            FROM match_statistics ms
            JOIN matches m ON ms.match_id = m.id
            WHERE ms.player_id = ? AND m.status = 'completed'
        ");
        $stmt->execute([$player_id]);
        $agg_stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $profile_image = $player['profile_image'] ? 'uploads/users/' . $player['profile_image'] :
            'assets/images/default-player.png';

        echo json_encode([
            'success' => true,
            'name' => htmlspecialchars($player['name']),
            'profile_image' => $profile_image,
            'playing_role' => htmlspecialchars($player['playing_role'] ?: 'Not specified'),
            'team_name' => $player['team_name'] ? htmlspecialchars($player['team_name']) : null,
            'matches' => (int) $matches,
            'runs' => (int) $agg_stats['runs_scored'],
            'wickets' => (int) $agg_stats['wickets_taken']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Player not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>