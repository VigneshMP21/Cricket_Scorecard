<?php
require_once '../includes/db.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("
        SELECT m.id, m.match_date, m.match_time, m.venue, m.match_type,
               t1.team_name as team1_name, t1.team_logo as team1_logo, t1.team_code as team1_code,
               t2.team_name as team2_name, t2.team_logo as team2_logo, t2.team_code as team2_code
        FROM matches m
        JOIN teams t1 ON m.team1_id = t1.id
        JOIN teams t2 ON m.team2_id = t2.id
        WHERE m.status = 'upcoming'
        ORDER BY m.match_date ASC, m.match_time ASC
        LIMIT 1
    ");
    $stmt->execute();
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'match' => $match]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
