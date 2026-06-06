<?php
require_once '../includes/db.php';
require_once '../includes/onesignal_utils.php';
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

        // Get match details for logging and notification
        $stmt = $pdo->prepare("
            SELECT m.match_code, m.status, t1.team_name as t1n, t2.team_name as t2n 
            FROM matches m 
            JOIN teams t1 ON m.team1_id = t1.id 
            JOIN teams t2 ON m.team2_id = t2.id 
            WHERE m.id = ?
        ");
        $stmt->execute([$match_id]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            throw new Exception("Match not found.");
        }

        if ($match['status'] !== 'ongoing') {
            throw new Exception("Match is not currently live.");
        }

        // Update match status to upcoming
        $stmt = $pdo->prepare("UPDATE matches SET status = 'upcoming' WHERE id = ?");
        $stmt->execute([$match_id]);

        // Log the status change
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, old_value, new_value)
            VALUES (?, 'UPDATE', 'matches', ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $match_id,
            json_encode(['status' => $match['status']]),
            json_encode(['status' => 'upcoming'])
        ]);

        // Commit transaction
        $pdo->commit();

        // Send Push Notification
        $all_ids = array_unique(array_merge(
            $pdo->query("SELECT onesignal_player_id FROM user_devices WHERE onesignal_player_id IS NOT NULL AND user_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN),
            getGuestPlayerIds($pdo)
        ));

        if (!empty($all_ids)) {
            $title = "🛑 Match Stopped";
            $message = "The live match between {$match['t1n']} and {$match['t2n']} has been stopped by the admin.";
            sendOneSignalNotification(
                $all_ids,
                $title,
                $message,
                ['type' => 'match_stopped', 'match_id' => $match_id]
            );
        }

        // Redirect back with success message
        header("Location: ../NavBarList/matches.php?success=4");
        exit();

    } catch (Exception $e) {
        // Rollback transaction
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Redirect back with error message
        header("Location: ../NavBarList/matches.php?error=4");
        exit();
    }
} else {
    // Invalid request
    header("Location: ../NavBarList/matches.php");
    exit();
}
?>