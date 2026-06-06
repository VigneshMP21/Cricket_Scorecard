<?php
require_once '../../includes/db.php';
if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../NavBarList/matches.php?error=3");
    exit();
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../../NavBarList/matches.php");
    exit();
}

$match_id = (int) $_POST['match_id'];
$team_id = (int) $_POST['team_id'];
$step = (int) $_POST['step'];
$toss_winner_id = (int) $_POST['toss_winner_id'];
$toss_choice = $_POST['toss_choice'];
$players = isset($_POST['players']) ? $_POST['players'] : [];
$captain_id = (int) $_POST['captain_id'];
$vice_captain_id = (int) $_POST['vice_captain_id'];

if (!$match_id || empty($players) || !$captain_id || !$vice_captain_id) {
    die("Invalid data provided.");
}

// Store in session
if (!isset($_SESSION['match_setup'])) {
    $_SESSION['match_setup'] = [];
}
if (!isset($_SESSION['match_setup'][$match_id])) {
    $_SESSION['match_setup'][$match_id] = [];
}

$_SESSION['match_setup'][$match_id]['team' . $step] = [
    'team_id' => $team_id,
    'players' => $players,
    'captain_id' => $captain_id,
    'vice_captain_id' => $vice_captain_id
];

// Redirect to next step
if ($step == 1) {
    header("Location: team_selection.php?id=$match_id&winner=$toss_winner_id&choice=$toss_choice&step=2");
} else {
    header("Location: confirmation.php?id=$match_id");
}
exit();
