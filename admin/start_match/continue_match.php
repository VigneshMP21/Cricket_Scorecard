<?php
require_once '../../includes/db.php';
if (session_status() === PHP_SESSION_NONE)
    session_start();

// Admin only check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../NavBarList/matches.php?error=3");
    exit();
}

$match_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$match_id) {
    header("Location: ../../NavBarList/matches.php");
    exit();
}

try {
    // 1. Check if session exists (user was in the middle of setup)
    if (isset($_SESSION['match_setup'][$match_id])) {
        $setup = $_SESSION['match_setup'][$match_id];

        if (!isset($setup['toss_winner'])) {
            header("Location: toss.php?id=$match_id");
            exit();
        }

        if (!isset($setup['team1'])) {
            header("Location: team_selection.php?id=$match_id&winner=" . $setup['toss_winner'] . "&choice=" . $setup['toss_choice'] . "&step=1");
            exit();
        }

        if (!isset($setup['team2'])) {
            header("Location: team_selection.php?id=$match_id&winner=" . $setup['toss_winner'] . "&choice=" . $setup['toss_choice'] . "&step=2");
            exit();
        }

        header("Location: confirmation.php?id=$match_id");
        exit();
    }

    // 2. No session, check database state
    $stmt = $pdo->prepare("SELECT status, toss_winner FROM matches WHERE id = ?");
    $stmt->execute([$match_id]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        header("Location: ../../NavBarList/matches.php?error=3");
        exit();
    }

    if ($match['status'] !== 'ongoing') {
        // If it's upcoming, initialize it
        header("Location: initialize_match.php?id=$match_id");
        exit();
    }

    if ($match['toss_winner']) {
        // Toss is done and match finalized (since finalize_match.php sets toss_winner)
        header("Location: score_controller.php?id=$match_id");
        exit();
    } else {
        // Ongoing but no toss winner -> back to toss
        header("Location: toss.php?id=$match_id");
        exit();
    }

} catch (PDOException $e) {
    die("Error continuing match: " . $e->getMessage());
}
