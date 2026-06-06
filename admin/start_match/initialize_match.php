<?php
require_once '../../includes/db.php';

if (session_status() === PHP_SESSION_NONE)
    session_start();

// Admin only check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../NavBarList/matches.php?error=3");
    exit();
}

$match_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$match_id) {
    header("Location: ../../NavBarList/matches.php");
    exit();
}

try {
    // Check if match exists and is upcoming
    $stmt = $pdo->prepare("SELECT status FROM matches WHERE id = ?");
    $stmt->execute([$match_id]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        header("Location: ../../NavBarList/matches.php?error=3");
        exit();
    }

    // Only update to ongoing if it's currently upcoming
    if ($match['status'] === 'upcoming') {
        $stmt = $pdo->prepare("UPDATE matches SET status = 'ongoing', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$match_id]);
    }


    // Redirect to toss stage
    header("Location: toss.php?id=$match_id");
    exit();

}
catch (PDOException $e) {
    die("Error initializing match: " . $e->getMessage());
}
