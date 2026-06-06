<?php
require_once '../includes/db.php';
require_login();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['match_id'])) {
    $match_id = (int) $_POST['match_id'];

    try {
        // Start transaction
        $pdo->beginTransaction();

        // Get match details for logging
        $stmt = $pdo->prepare("SELECT match_code, team1_id, team2_id FROM matches WHERE id = ?");
        $stmt->execute([$match_id]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            throw new Exception("Match not found.");
        }

        // Decrement match counts for the squad before deleting
        $stmt = $pdo->prepare("SELECT player_id FROM match_squads WHERE match_id = ?");
        $stmt->execute([$match_id]);
        $players = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($players)) {
            $placeholders = str_repeat('?,', count($players) - 1) . '?';
            $pdo->prepare("UPDATE player_stats SET matches_played = GREATEST(0, matches_played - 1) WHERE player_id IN ($placeholders)")->execute($players);
        }

        // Delete related records in correct order (due to foreign key constraints)
        $tables_to_clean = [
            'ball_by_ball',
            'innings',
            'match_statistics',
            'match_squads'
        ];

        foreach ($tables_to_clean as $table) {
            $pdo->prepare("DELETE FROM $table WHERE match_id = ?")->execute([$match_id]);
        }

        // Delete the match
        $stmt = $pdo->prepare("DELETE FROM matches WHERE id = ?");
        $stmt->execute([$match_id]);

        // Log the deletion
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, old_value)
            VALUES (?, 'DELETE', 'matches', ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $match_id,
            json_encode(['match_code' => $match['match_code'], 'team1_id' => $match['team1_id'], 'team2_id' => $match['team2_id']])
        ]);

        $pdo->commit();

        // Redirect with success message
        header("Location: ../NavBarList/matches.php?success=2");
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Match deletion error: " . $e->getMessage());

        // Redirect with error message
        header("Location: ../NavBarList/matches.php?error=1");
        exit();
    }
}

// If not POST request, redirect
header("Location: ../NavBarList/matches.php");
exit();
?>