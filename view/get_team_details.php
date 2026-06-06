<?php
require_once '../includes/db.php';
// Public access - no login required

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo '<div class="alert alert-danger">Invalid team ID.</div>';
    exit();
}

$team_id = (int) $_GET['id'];

try {
    // Get team info
    $stmt = $pdo->prepare("
        SELECT t.*,
               p1.name as captain_name, p1.profile_image as captain_image,
               p2.name as vice_captain_name, p2.profile_image as vice_captain_image
        FROM teams t
        LEFT JOIN users p1 ON t.captain_id = p1.id
        LEFT JOIN users p2 ON t.vice_captain_id = p2.id
        WHERE t.id = ?
    ");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$team) {
        echo '<div class="alert alert-danger">Team not found.</div>';
        exit();
    }

    // Get team players
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.profile_image, u.playing_role
        FROM team_players tp
        JOIN users u ON tp.player_id = u.id
        WHERE tp.team_id = ?
        ORDER BY u.name
    ");
    $stmt->execute([$team_id]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ?>
    <div class="team-header mb-4 p-4 rounded-3 shadow-sm text-white"
        style="background: linear-gradient(135deg, <?= htmlspecialchars($team['team_color']) ?> 0%, #1e293b 100%);">
        <div class="row align-items-center">
            <div class="col-md-3 text-center">
                <div class="team-logo-wrapper p-2 bg-white rounded-circle shadow-lg d-inline-flex justify-content-center align-items-center"
                    style="margin-top: -40px; width: 116px; height: 116px;">
                    <img src="<?= $team['team_logo'] ? '../uploads/teams/' . $team['team_logo'] : 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><circle cx="50" cy="50" r="48" fill="#e5e7eb" stroke="#9ca3af" stroke-width="2"/><text x="50" y="60" text-anchor="middle" font-family="Arial, sans-serif" font-size="40" fill="#6b7280">T</text></svg>') ?>"
                        alt="<?= htmlspecialchars($team['team_name']) ?>" class="img-fluid rounded-circle"
                        style="width: 100px; height: 100px; object-fit: contain;">
                </div>
            </div>
            <div class="col-md-9">
                <div class="d-flex flex-wrap align-items-center mb-2">
                    <h2 class="mb-0 me-3 fw-bold text-shadow"><?= htmlspecialchars($team['team_name']) ?></h2>
                    <span
                        class="badge bg-white text-dark px-3 py-2 fs-6 fw-bold"><?= htmlspecialchars($team['team_code']) ?></span>
                </div>
                <div class="row mt-3">
                    <div class="col-sm-4">
                        <small class="opacity-75 d-block text-uppercase fw-bold letter-spacing-1">Captain</small>
                        <div class="d-flex align-items-center mt-1">
                            <?php if ($team['captain_name']): ?>
                                <img src="<?= $team['captain_image'] ? '../uploads/users/' . $team['captain_image'] : 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30"><circle cx="15" cy="15" r="14" fill="#e5e7eb" stroke="#9ca3af" stroke-width="1"/><circle cx="15" cy="12" r="4" fill="#6b7280"/><path d="M6 24c0-4.5 3.5-8 8-8s8 3.5 8 8" fill="#6b7280"/></svg>') ?>"
                                    class="rounded-circle me-2 border border-2 border-warning player-modal-trigger"
                                    data-player-id="<?= (int) $team['captain_id'] ?>"
                                    style="cursor: pointer; width: 32px; height: 32px; object-fit: cover;">
                                <span class="fw-bold player-modal-trigger" data-player-id="<?= (int) $team['captain_id'] ?>"
                                    style="cursor: pointer;">
                                    <?= htmlspecialchars($team['captain_name']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-white-50">Not assigned</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <small class="opacity-75 d-block text-uppercase fw-bold letter-spacing-1">Vice Captain</small>
                        <div class="d-flex align-items-center mt-1">
                            <?php if ($team['vice_captain_name']): ?>
                                <img src="<?= $team['vice_captain_image'] ? '../uploads/users/' . $team['vice_captain_image'] : 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30"><circle cx="15" cy="15" r="14" fill="#e5e7eb" stroke="#9ca3af" stroke-width="1"/><circle cx="15" cy="12" r="4" fill="#6b7280"/><path d="M6 24c0-4.5 3.5-8 8-8s8 3.5 8 8" fill="#6b7280"/></svg>') ?>"
                                    class="rounded-circle me-2 border border-2 border-info player-modal-trigger"
                                    data-player-id="<?= (int) $team['vice_captain_id'] ?>"
                                    style="cursor: pointer; width: 32px; height: 32px; object-fit: cover;">
                                <span class="fw-bold player-modal-trigger"
                                    data-player-id="<?= (int) $team['vice_captain_id'] ?>" style="cursor: pointer;">
                                    <?= htmlspecialchars($team['vice_captain_name']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-white-50">Not assigned</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <small class="opacity-75 d-block text-uppercase fw-bold letter-spacing-1">Total Players</small>
                        <h4 class="mb-0 fw-bold"><?= count($players) ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex align-items-center mb-3">
        <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-users-cog me-2"></i>Team Squad</h5>
        <hr class="flex-grow-1 ms-3">
    </div>

    <?php if (empty($players)): ?>
        <div class="alert alert-light border text-center py-4">
            <i class="fas fa-user-slash fa-3x text-muted mb-3 d-block"></i>
            <p class="mb-0 text-muted">No players assigned to this team.</p>
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($players as $player): ?>
                <div class="col-md-6 mb-3">
                    <div class="card player-item-card border-0 shadow-sm overflow-hidden h-100">
                        <div class="card-body p-2">
                            <div class="d-flex align-items-center">
                                <div class="player-avatar-container me-3 position-relative">
                                    <img src="<?= $player['profile_image'] ? '../uploads/users/' . $player['profile_image'] : 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 60 60"><circle cx="30" cy="30" r="28" fill="#f1f5f9" stroke="#e2e8f0" stroke-width="1"/><circle cx="30" cy="22" r="8" fill="#cbd5e1"/><path d="M12 50c0-10 8-18 18-18s18 8 18 18" fill="#cbd5e1"/></svg>') ?>"
                                        alt="<?= htmlspecialchars($player['name']) ?>"
                                        class="rounded-circle player-modal-trigger border shadow-sm"
                                        data-player-id="<?= (int) $player['id'] ?>"
                                        style="cursor: pointer; width: 60px; height: 60px; object-fit: cover;">
                                    <?php if ($player['id'] == $team['captain_id']): ?>
                                        <span
                                            class="badge bg-warning text-dark position-absolute bottom-0 start-50 translate-middle-x rounded-pill px-2 border border-white"
                                            style="font-size: 0.6rem; margin-bottom: -5px;">CAPTAIN</span>
                                    <?php elseif ($player['id'] == $team['vice_captain_id']): ?>
                                        <span
                                            class="badge bg-info text-white position-absolute bottom-0 start-50 translate-middle-x rounded-pill px-2 border border-white"
                                            style="font-size: 0.6rem; margin-bottom: -5px;">V-CAPTAIN</span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1 overflow-hidden">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <h6 class="mb-0 text-truncate fw-bold player-modal-trigger"
                                            data-player-id="<?= (int) $player['id'] ?>" style="cursor: pointer;">
                                            <?= htmlspecialchars($player['name']) ?>
                                        </h6>
                                        <span
                                            class="badge bg-light text-muted small"><?= htmlspecialchars($player['playing_role']) ?></span>
                                    </div>
                                    <small
                                        class="text-muted d-block text-truncate">@<?= strtolower(str_replace(' ', '.', $player['name'])) ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <style>
        .team-logo-wrapper {
            transition: transform 0.3s ease;
        }

        .team-header:hover .team-logo-wrapper {
            transform: rotate(5deg) scale(1.05);
        }

        .text-shadow {
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .letter-spacing-1 {
            letter-spacing: 1px;
        }

        .player-item-card {
            transition: all 0.2s ease;
            background: #ffffff;
            border-left: 4px solid transparent !important;
        }

        .player-item-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1) !important;
            border-left-color:
                <?= htmlspecialchars($team['team_color']) ?>
                !important;
        }

        .player-avatar-container img {
            transition: transform 0.2s ease;
        }

        .player-item-card:hover .player-avatar-container img {
            transform: scale(1.1);
        }
    </style>

    <?php

} catch (PDOException $e) {
    echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>