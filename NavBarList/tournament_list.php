<?php
require_once '../includes/db.php';
// Public access - no login required

// Fetch tournaments with enhanced data
$tournaments = [];
try {
    $stmt = $pdo->query("
        SELECT t.id, t.tournament_name, t.tournament_code, t.description, t.start_date, t.end_date, 
               t.venue, t.overs, t.format, t.ground_type, t.prize_amount, t.entry_fee, 
               t.tournament_logo, t.tournament_banner, t.max_teams, t.status, t.created_by, t.created_at, t.updated_at,
               (SELECT COUNT(*) FROM teams tm WHERE tm.tournament_id = t.id AND tm.status = 'active') as team_count,
               (SELECT COUNT(*) FROM matches m WHERE m.tournament_id = t.id) as match_count
        FROM tournaments t
        ORDER BY t.start_date DESC
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tournaments[$row['id']] = $row;
    }

    // Calculate status and progress based on dates
    $current_date = date('Y-m-d');
    foreach ($tournaments as &$tournament) {
        $start_date = $tournament['start_date'];
        $end_date = $tournament['end_date'];

        if ($current_date < $start_date) {
            $tournament['calculated_status'] = 'upcoming';
        } elseif ($current_date >= $start_date && $current_date <= $end_date) {
            $tournament['calculated_status'] = 'ongoing';
        } else {
            $tournament['calculated_status'] = 'completed';
        }
    }
    unset($tournament);
} catch (PDOException $e) {
    // Handle error
    error_log("Database error in tournament list: " . $e->getMessage());
}

// Fetch total teams count
$total_teams = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM teams");
    $total_teams = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Database error fetching total teams: " . $e->getMessage());
}

$page_title = "Tournament List";
require_once '../includes/header.php';
?>

<div class="container-fluid py-4"
    style="background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 50%, #cbd5e1 100%); min-height: calc(100vh - 76px);">
    <div class="d-flex justify-content-between align-items-center mb-5">
        <div>
            <h2 class="text-dark fw-bold m-0"><i class="fas fa-trophy me-2 text-primary"></i>Tournaments</h2>
            <div class="d-none d-md-block mt-2">
                <span class="badge bg-white text-dark shadow-sm rounded-pill fw-normal me-2 px-3 py-2">
                    <i class="fas fa-list me-1 text-primary"></i> <?= count($tournaments) ?> Total
                </span>
                <span class="badge bg-white text-dark shadow-sm rounded-pill fw-normal me-2 px-3 py-2">
                    <i class="fas fa-users me-1 text-success"></i> <?= $total_teams ?> Teams
                </span>
                <span class="badge bg-white text-dark shadow-sm rounded-pill fw-normal px-3 py-2">
                    <i
                        class="fas fa-baseball-ball me-1 text-danger"></i><?= array_sum(array_column($tournaments, 'match_count')) ?>
                    Matches
                </span>
            </div>
        </div>
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
            <a href="../admin/create_tournament.php" class="btn btn-primary rounded-pill px-4 shadow-sm fw-bold">
                <i class="fas fa-plus me-1"></i>New Tournament
            </a>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
        <div id="successMsg" class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            Tournament created successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <script>
        setTimeout(() => {
            const msg = document.getElementById('successMsg');
            if (msg) {
                msg.style.transition = "opacity 0.5s ease";
                msg.style.opacity = "0";
                setTimeout(() => msg.remove(), 500);
            }
        }, 3000);
        </script>
    <?php endif; ?>

    <?php if (empty($tournaments)): ?>
        <div class="card border-0 shadow-lg"
            style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 20px;">
            <div class="card-body text-center py-5">
                <div class="mb-4">
                    <i class="fas fa-trophy fa-5x opacity-75 animate-float"></i>
                </div>
                <h3 class="card-title mb-3 fw-bold">No tournaments available</h3>
                <p class="card-text mb-4 opacity-90">Check back later for upcoming tournaments!</p>
                <div class="row g-3 justify-content-center">
                    <div class="col-auto">
                        <a href="#" class="btn btn-outline-light btn-lg px-4 rounded-pill">
                            <i class="fas fa-info-circle me-2"></i>Learn More
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($tournaments as $tournament): ?>
                <div class="col-xl-3 col-lg-4 col-md-6">
                    <div class="card tournament-card border-0 shadow-sm h-100 overflow-hidden">
                        <div class="card-img-top position-relative tournament-banner-wrapper">
                            <!-- Banner Background -->
                            <?php if ($tournament['tournament_banner']): ?>
                                <div class="tournament-banner-bg"
                                    style="background-image: url('../<?= htmlspecialchars($tournament['tournament_banner']) ?>');">
                                </div>
                            <?php else: ?>
                                <div class="tournament-banner-bg bg-primary-gradient"></div>
                            <?php endif; ?>

                            <div class="status-badge-container">
                                <span
                                    class="badge rounded-pill px-3 py-2 shadow-sm
                            <?= $tournament['calculated_status'] == 'upcoming' ? 'bg-warning text-dark' :
                                ($tournament['calculated_status'] == 'ongoing' ? 'bg-success text-white' : 'bg-secondary text-white') ?>">
                                    <i class="fas fa-<?=
                                        $tournament['calculated_status'] == 'upcoming' ? 'clock' :
                                        ($tournament['calculated_status'] == 'ongoing' ? 'play-circle' : 'flag-checkered')
                                        ?> me-1"></i>
                                    <?= ucfirst($tournament['calculated_status']) ?>
                                </span>
                            </div>
                        </div>

                        <!-- Circular Logo Overlay: Moved outside wrapper to prevent clipping -->
                        <div class="tournament-logo-overlay-wrapper">
                            <div class="tournament-logo-overlay">
                                <?php if ($tournament['tournament_logo']): ?>
                                    <img src="../<?= htmlspecialchars($tournament['tournament_logo']) ?>" alt="Logo"
                                        class="img-fluid rounded-circle shadow-lg border border-4 border-white">
                                <?php else: ?>
                                    <div class="bg-white rounded-circle shadow-lg d-flex align-items-center justify-content-center border border-4 border-white"
                                        style="width: 80px; height: 80px;">
                                        <i class="fas fa-trophy fa-2x text-primary"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card-body pt-5 px-4 pb-4">
                            <h5 class="card-title text-center fw-bold text-dark mb-1 text-truncate"
                                title="<?= htmlspecialchars($tournament['tournament_name']) ?>">
                                <?= htmlspecialchars($tournament['tournament_name']) ?>
                            </h5>
                            <p class="text-center text-muted small mb-3">
                                <?= htmlspecialchars($tournament['venue']) ?>
                            </p>

                            <div class="row g-2 mb-4 bg-light rounded-3 p-2 mx-0">
                                <div class="col-4 text-center border-end">
                                    <span class="d-block fw-bold text-dark"><?= $tournament['team_count'] ?></span>
                                    <small class="text-muted" style="font-size: 0.7rem;">TEAMS</small>
                                </div>
                                <div class="col-4 text-center border-end">
                                    <span class="d-block fw-bold text-dark"><?= $tournament['match_count'] ?></span>
                                    <small class="text-muted" style="font-size: 0.7rem;">MATCHES</small>
                                </div>
                                <div class="col-4 text-center">
                                    <span class="d-block fw-bold text-dark"><?= $tournament['overs'] ?></span>
                                    <small class="text-muted" style="font-size: 0.7rem;">OVERS</small>
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="d-flex align-items-center mb-2 text-secondary small">
                                    <div class="icon-box-sm bg-primary-subtle text-primary rounded-circle me-2">
                                        <i class="far fa-calendar-alt"></i>
                                    </div>
                                    <div>
                                        <?= date('d M', strtotime($tournament['start_date'])) ?> -
                                        <?= date('d M, Y', strtotime($tournament['end_date'])) ?>
                                    </div>
                                </div>
                                <?php if ($tournament['prize_amount']): ?>
                                    <div class="d-flex align-items-center text-secondary small">
                                        <div class="icon-box-sm bg-warning-subtle text-warning rounded-circle me-2">
                                            <i class="fas fa-trophy"></i>
                                        </div>
                                        <div class="fw-bold text-dark">
                                            ₹<?= number_format($tournament['prize_amount']) ?> Prize
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-4"></div>

                            <div class="d-grid gap-2">
                                    <button class="btn btn-primary rounded-pill fw-bold shadow-sm"
                                    onclick="viewTournament(<?= $tournament['id'] ?>)">
                                    View Details
                                </button>

                                <button class="btn btn-success rounded-pill fw-bold shadow-sm mt-2"
                                    onclick="registerTeam(<?= $tournament['id'] ?>)">
                                    Register Your Team
                                </button>

                                <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                                    <div class="row g-2 mt-1">
                                        <div class="col-6">
                                            <button class="btn btn-sm btn-outline-warning w-100 rounded-pill fw-bold"
                                                onclick="editTournament(<?= $tournament['id'] ?>)">
                                                <i class="fas fa-edit me-1"></i> Edit
                                            </button>
                                        </div>
                                        <div class="col-6">
                                            <button class="btn btn-sm btn-outline-danger w-100 rounded-pill fw-bold"
                                                onclick="deleteTournament(<?= $tournament['id'] ?>, '<?= htmlspecialchars($tournament['tournament_name']) ?>')">
                                                <i class="fas fa-trash me-1"></i> Delete
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
    /* Card & Banner Styles */
    .tournament-card {
        border-radius: 20px;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        background: #fff;
    }

    .tournament-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1) !important;
    }

    .tournament-banner-wrapper {
        height: 140px;
        overflow: hidden;
        border-top-left-radius: 20px;
        border-top-right-radius: 20px;
    }

    .tournament-banner-bg {
        width: 100%;
        height: 100%;
        background-size: cover;
        background-position: center;
        /* Removed blur and brightness for full clarity as requested */
        transform: scale(1.05);
        transition: transform 0.4s ease;
    }

    .tournament-card:hover .tournament-banner-bg {
        transform: scale(1.0);
    }

    .bg-primary-gradient {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    }

    /* Logo Overlay */
    .tournament-logo-overlay-wrapper {
        position: relative;
        height: 0;
        z-index: 10;
        text-align: center;
    }

    .tournament-logo-overlay {
        position: absolute;
        top: -40px;
        left: 50%;
        transform: translateX(-50%);
        width: 80px;
        height: 80px;
    }

    .tournament-logo-overlay img {
        background: #fff;
        width: 80px;
        height: 80px;
        object-fit: cover;
    }

    /* Status Badge */
    .status-badge-container {
        position: absolute;
        top: 15px;
        right: 15px;
        z-index: 2;
    }

    /* Icons */
    .icon-box-sm {
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
    }

    /* Animations */
    .animate-float {
        animation: float 3s ease-in-out infinite;
    }

    @keyframes float {
        0% {
            transform: translateY(0px);
        }

        50% {
            transform: translateY(-10px);
        }

        100% {
            transform: translateY(0px);
        }
    }

    /* Helpers */
    .bg-primary-subtle {
        background-color: #ecf0ff;
    }

    .bg-warning-subtle {
        background-color: #fffbeb;
    }

    /* Responsive */
    @media (max-width: 576px) {
        .container-fluid {
            padding-left: 15px;
            padding-right: 15px;
        }
    }
</style>

<script>
    function viewTournament(id) {
        window.location.href = '../view/view_tournament.php?id=' + id;
    }

    function registerTeam(id) {
        <?php if (!isset($_SESSION['role'])): ?>
            alert('Login to register your team. Do login first.');
            window.location.href = '../login/login.php';
        <?php elseif ($_SESSION['role'] == 'audience'): ?>
            alert('Login to register your team. Do login first.');
        <?php else: ?>
            window.location.href = 'register_team.php?tournament_id=' + id + '&show_note=1';
        <?php endif; ?>
    }

    function editTournament(id) {
        window.location.href = '../edit/edit_tournament.php?id=' + id;
    }

    function deleteTournament(id, name) {
        if (confirm('Are you sure you want to delete the tournament "' + name + '"? This action cannot be undone.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '../admin/delete_tournament.php';
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'tournament_id';
            idInput.value = id;
            form.appendChild(idInput);
            document.body.appendChild(form);
            form.submit();
        }
    }
</script>

<?php require_once '../includes/footer.php'; ?>