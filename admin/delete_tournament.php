<?php
require_once '../includes/db.php';
require_login();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}



use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;

// Configure Cloudinary
Configuration::instance([
    'cloud' => [
        'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'] ?? "",
        'api_key'    => $_ENV['CLOUDINARY_API_KEY'] ?? "",
        'api_secret' => $_ENV['CLOUDINARY_API_SECRET'] ?? "",
    ],
    'url' => [
        'secure' => true
    ]
]);

function deleteFromCloudinary($public_id) {
    if (!$public_id) return;

    try {
        error_log("🗑️ Attempting to delete from Cloudinary. public_id: " . $public_id);
        $uploadApi = new UploadApi();
        $response = $uploadApi->destroy($public_id);
        
        if (isset($response['result']) && $response['result'] === 'ok') {
            error_log("✅ Cloudinary Delete SUCCESS: " . $public_id);
        } else {
            error_log("⚠️ Cloudinary Delete WARNING (Image might not exist): " . json_encode($response));
        }
    } catch (Exception $e) {
        error_log("❌ Cloudinary SDK Error: " . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tournament_id'])) {
    $tournament_id = (int) $_POST['tournament_id'];

    try {
        // First, get tournament details for asset cleanup
        $stmt = $pdo->prepare("
            SELECT tournament_name, tournament_logo, tournament_logo_public_id, 
                   tournament_banner, tournament_banner_public_id 
            FROM tournaments WHERE id = ?
        ");
        $stmt->execute([$tournament_id]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tournament) {
            $_SESSION['error'] = "Tournament not found.";
            header("Location: ../NavBarList/tournament_list.php");
            exit();
        }

        // Delete tournament assets from server
        if ($tournament['tournament_logo']) {
            $logo_path = '../' . $tournament['tournament_logo'];
            if (file_exists($logo_path)) {
                unlink($logo_path);
            }
        }

        if ($tournament['tournament_banner']) {
            $banner_path = '../' . $tournament['tournament_banner'];
            if (file_exists($banner_path)) {
                unlink($banner_path);
            }
        }

        // --- CLOUDINARY CLEANUP ---
        if (!empty($tournament['tournament_logo_public_id'])) {
            deleteFromCloudinary($tournament['tournament_logo_public_id']);
        }
        if (!empty($tournament['tournament_banner_public_id'])) {
            deleteFromCloudinary($tournament['tournament_banner_public_id']);
        }

        // Delete related records first (to maintain referential integrity)

        // Delete point table entries first
        $pdo->prepare("DELETE FROM point_table_entries WHERE point_table_id IN (SELECT id FROM point_tables WHERE tournament_id = ?)")->execute([$tournament_id]);

        // Delete point tables
        $pdo->prepare("DELETE FROM point_tables WHERE tournament_id = ?")->execute([$tournament_id]);

        // Delete match-related records
        // Get match ids first
        $match_ids = $pdo->prepare("SELECT id FROM matches WHERE tournament_id = ?");
        $match_ids->execute([$tournament_id]);
        $match_ids = $match_ids->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($match_ids)) {
            $match_ids_placeholder = str_repeat('?,', count($match_ids) - 1) . '?';

            // Delete ball_by_ball
            $pdo->prepare("DELETE FROM ball_by_ball WHERE match_id IN ($match_ids_placeholder)")->execute($match_ids);

            // Delete innings
            $pdo->prepare("DELETE FROM innings WHERE match_id IN ($match_ids_placeholder)")->execute($match_ids);

            // Delete match_squads
            $pdo->prepare("DELETE FROM match_squads WHERE match_id IN ($match_ids_placeholder)")->execute($match_ids);

            // Delete match_statistics
            $pdo->prepare("DELETE FROM match_statistics WHERE match_id IN ($match_ids_placeholder)")->execute($match_ids);
        }

        // Delete matches
        $pdo->prepare("DELETE FROM matches WHERE tournament_id = ?")->execute([$tournament_id]);

        // Delete team-related records
        // Get team ids and logos first
        $teams_stmt = $pdo->prepare("SELECT id, team_logo FROM teams WHERE tournament_id = ?");
        $teams_stmt->execute([$tournament_id]);
        $teams_to_delete = $teams_stmt->fetchAll(PDO::FETCH_ASSOC);
        $team_ids = array_column($teams_to_delete, 'id');

        if (!empty($team_ids)) {
            $team_ids_placeholder = str_repeat('?,', count($team_ids) - 1) . '?';

            // Update users to remove team association
            $pdo->prepare("UPDATE users SET team_id = NULL WHERE team_id IN ($team_ids_placeholder)")->execute($team_ids);

            // Delete players
            $pdo->prepare("DELETE FROM players WHERE team_id IN ($team_ids_placeholder)")->execute($team_ids);

            // Delete team_players
            $pdo->prepare("DELETE FROM team_players WHERE team_id IN ($team_ids_placeholder)")->execute($team_ids);

            // Delete team logos
            foreach ($teams_to_delete as $team_record) {
                if ($team_record['team_logo']) {
                    $team_logo_path = '../uploads/teams/' . $team_record['team_logo'];
                    if (file_exists($team_logo_path)) {
                        unlink($team_logo_path);
                    }
                }
            }
        }

        // Delete teams
        $pdo->prepare("DELETE FROM teams WHERE tournament_id = ?")->execute([$tournament_id]);

        // Finally, delete the tournament
        $stmt = $pdo->prepare("DELETE FROM tournaments WHERE id = ?");
        $stmt->execute([$tournament_id]);

        // Log the deletion
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, old_value) VALUES (?, 'DELETE', 'tournaments', ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $tournament_id, json_encode($tournament)]);

        $_SESSION['success'] = "Tournament '" . $tournament['tournament_name'] . "' has been deleted successfully.";
        header("Location: ../NavBarList/tournament_list.php");
        exit();

    } catch (PDOException $e) {
        error_log("Database error in delete tournament: " . $e->getMessage());
        $_SESSION['error'] = "Failed to delete tournament. Please try again.";
        header("Location: ../NavBarList/tournament_list.php");
        exit();
    }
} else {
    header("Location: ../NavBarList/tournament_list.php");
    exit();
}
?>