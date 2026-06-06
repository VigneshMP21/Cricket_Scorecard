<?php
require_once '../includes/db.php';
// Public access - no login required

// Get tournament ID from query parameter
$tournament_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$tournament_id) {
    header("Location: ../NavBarList/tournament_list.php");
    exit();
}

// Fetch tournament details
$tournament = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$tournament_id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tournament) {
        header("Location: ../NavBarList/tournament_list.php");
        exit();
    }
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    header("Location: ../NavBarList/tournament_list.php");
    exit();
}

// Calculate status
$current_date = date('Y-m-d');
$start_date = $tournament['start_date'];
$end_date = $tournament['end_date'];

if ($current_date < $start_date) {
    $status = 'upcoming';
    $status_badge = 'warning';
    $status_text = 'Upcoming';
    $status_icon = 'clock';
} elseif ($current_date >= $start_date && $current_date <= $end_date) {
    $status = 'ongoing';
    $status_badge = 'danger';
    $status_text = 'Live / Ongoing';
    $status_icon = 'play-circle';
} else {
    $status = 'completed';
    $status_badge = 'success';
    $status_text = 'Completed';
    $status_icon = 'flag-checkered';
}

$page_title = htmlspecialchars($tournament['tournament_name']) . " - Tournament Details";
require_once '../includes/header.php';
?>

<style>
    /* 
 * Tournament Details - Modern UI
 * Design System:
 * Primary: #2563eb
 * Background: #f8fafc
 * Text: #0f172a
 * Accent: #f59e0b
 */

    :root {
        --td-primary: #2563eb;
        --td-primary-dark: #1d4ed8;
        --td-primary-light: #dbeafe;
        --td-bg: #f8fafc;
        --td-text: #0f172a;
        --td-text-muted: #64748b;
        --td-accent: #f59e0b;
        --td-white: #ffffff;
        --td-border: #e2e8f0;
        --td-shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        --td-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        --td-shadow-md: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        --td-radius: 12px;
    }

    body {
        background-color: var(--td-bg);
        color: var(--td-text);
    }

    /* Hero Section */
    .hero-stats-wrapper {
        background: var(--td-white);
        border-bottom: 1px solid var(--td-border);
    }

    .tournament-hero {
        background: linear-gradient(135deg, var(--td-primary) 0%, #1e40af 100%);
        padding: 60px 0 100px;
        color: var(--td-white);
        position: relative;
        overflow: hidden;
    }

    .tournament-hero.has-banner {
        background-size: cover !important;
        background-position: center !important;
        background-repeat: no-repeat !important;
        border-bottom: 2px solid var(--td-accent);
    }

    .tournament-hero .container {
        position: relative;
        z-index: 2;
    }

    .hero-content h1 {
        font-size: 2.5rem;
        font-weight: 800;
        margin-bottom: 0.5rem;
        letter-spacing: -0.025em;
        color: rgba(255, 255, 255, 0.95);
        text-shadow: 0 2px 6px rgba(0, 0, 0, 0.6);
    }

    .hero-content .venue {
        font-size: 1.1rem;
        opacity: 1;
        color: rgba(255, 255, 255, 0.95);
        text-shadow: 0 2px 6px rgba(0, 0, 0, 0.6);
        margin-bottom: 1.5rem;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 6px 16px;
        border-radius: 9999px;
        font-weight: 600;
        font-size: 0.875rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 1.5rem;
    }

    .status-badge.upcoming {
        background-color: var(--td-accent);
        color: var(--td-white);
    }

    .status-badge.ongoing {
        background-color: #ef4444;
        color: var(--td-white);
    }

    .status-badge.completed {
        background-color: #10b981;
        color: var(--td-white);
    }

    .hero-cta .btn-cta {
        background-color: var(--td-white);
        color: var(--td-primary);
        font-weight: 700;
        padding: 12px 32px;
        border-radius: var(--td-radius);
        text-decoration: none;
        transition: all 0.2s;
        box-shadow: var(--td-shadow);
    }

    .hero-cta .btn-cta:hover {
        transform: translateY(-2px);
        box-shadow: var(--td-shadow-md);
        background-color: var(--td-bg);
    }

    /* Info Bar */
    .info-bar {
        position: relative;
        z-index: 10;
        margin-top: -50px;
        margin-bottom: 40px;
    }

    .info-card {
        background: var(--td-white);
        border-radius: var(--td-radius);
        padding: 20px;
        box-shadow: var(--td-shadow);
        border: 1px solid var(--td-border);
        display: flex;
        align-items: center;
        gap: 16px;
        height: 100%;
    }

    .info-icon {
        width: 48px;
        height: 48px;
        background: var(--td-primary-light);
        color: var(--td-primary);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
    }

    .info-label {
        font-size: 0.75rem;
        text-transform: uppercase;
        font-weight: 700;
        color: var(--td-text-muted);
        letter-spacing: 0.05em;
        margin-bottom: 2px;
    }

    .info-value {
        font-weight: 700;
        font-size: 1.1rem;
        color: var(--td-text);
    }

    /* Main Layout */
    .td-card {
        background: var(--td-white);
        border-radius: var(--td-radius);
        padding: 24px;
        box-shadow: var(--td-shadow-sm);
        border: 1px solid var(--td-border);
        margin-bottom: 24px;
    }

    .td-section-title {
        font-size: 1.25rem;
        font-weight: 700;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .td-section-title i {
        color: var(--td-primary);
    }

    /* Overview Grid */
    .overview-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 16px;
    }

    .overview-item {
        background: var(--td-bg);
        padding: 16px;
        border-radius: 8px;
        text-align: center;
    }

    .overview-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        font-weight: 600;
        color: var(--td-text-muted);
    }

    .overview-value {
        font-weight: 700;
        font-size: 1rem;
    }

    /* Description */
    .description-block {
        line-height: 1.7;
        color: var(--td-text);
    }

    /* Sidebar Cards */
    .prize-card {
        background: linear-gradient(135deg, var(--td-accent) 0%, #d97706 100%);
        color: var(--td-white);
        border: none;
    }

    .prize-card .info-label {
        color: rgba(255, 255, 255, 0.8);
    }

    .prize-card .info-value {
        color: var(--td-white);
        font-size: 1.5rem;
    }

    .btn-admin {
        width: 100%;
        margin-bottom: 12px;
        padding: 10px;
        font-weight: 600;
        border-radius: 8px;
        transition: all 0.2s;
    }

    .btn-admin-edit {
        background-color: var(--td-primary);
        color: var(--td-white);
        border: none;
    }

    .btn-admin-delete {
        background-color: #fee2e2;
        color: #ef4444;
        border: 1px solid #fecaca;
    }

    .btn-admin-delete:hover {
        background-color: #ef4444;
        color: var(--td-white);
    }

    /* Responsive */
    @media (max-width: 768px) {
        /* Mobile Fixes */
        .hero-cta {
            justify-content: center;
        }

        .tournament-logo-wrapper {
            width: 140px !important;
            height: 140px !important;
            margin-top: 1.5rem !important;
        }

        .tournament-hero {
            padding: 40px 0 60px;
            text-align: center;
        }

        .hero-content h1 {
            font-size: 1.75rem;
        }

        .info-bar {
            margin-top: -30px;
        }

        .info-card {
            padding: 12px;
        }
    }
</style>


<div class="tournament-details-page">
    <!-- Hero Section -->
    <div class="tournament-hero <?= $tournament['tournament_banner'] ? 'has-banner' : '' ?>" 
         style="<?= $tournament['tournament_banner'] ? 'background: url(../' . htmlspecialchars($tournament['tournament_banner']) . ') center/cover no-repeat;' : '' ?>">
        <div class="container py-3">
            <!-- Back Button: Aligned to Top Left for both Mobile & Desktop -->
            <div class="mb-4 text-start">
                <a href="../NavBarList/tournament_list.php" class="text-white text-decoration-none fw-bold" style="text-shadow: 0 2px 4px rgba(0,0,0,0.5);">
                    <i class="fas fa-arrow-left me-2"></i>Back
                </a>
            </div>

            <div class="row align-items-center g-4">
                <!-- Logo Column: Appears First on Mobile -->
                <div class="col-lg-4 order-0 order-lg-1 text-center">
                    <?php if ($tournament['tournament_logo']): ?>
                        <div class="tournament-logo-wrapper"
                            style="width: 180px; height: 180px; background: rgba(255,255,255,0.2); backdrop-filter: blur(8px); padding: 10px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.3); margin: 0 auto;">
                            <img src="../<?= htmlspecialchars($tournament['tournament_logo']) ?>" alt="Logo"
                                class="img-fluid rounded-3 h-100 w-100 object-fit-cover shadow">
                        </div>
                    <?php else: ?>
                        <div class="d-flex align-items-center justify-content-center h-100 bg-white bg-opacity-25 rounded-4 text-white"
                            style="width: 180px; height: 180px; margin: 0 auto; backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.3);">
                            <i class="fas fa-trophy fa-5x"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Info Column: Appears Second on Mobile, with Left Margin on Desktop -->
                <div class="col-lg-8 order-1 order-lg-0 ps-lg-5">
                    <div class="hero-content">
                        <h1><?= htmlspecialchars($tournament['tournament_name']) ?></h1>
                        <p class="venue mb-3"><i
                                class="fas fa-map-marker-alt me-2 text-warning"></i><?= htmlspecialchars($tournament['venue']) ?>
                        </p>
                        
                        <div class="mb-4">
                            <span class="status-badge <?= $status ?> mb-0">
                                <i class="fas fa-<?= $status_icon ?> me-2"></i><?= $status_text ?>
                            </span>
                        </div>

                        <div class="hero-cta d-flex flex-wrap gap-2 gap-md-3">
                            <a href="../NavBarList/register_team.php?tournament_id=<?= $tournament_id ?>" class="btn-cta">
                                <i class="fas fa-user-plus me-2"></i>Join Tournament
                            </a>
                            <a href="../NavBarList/teams.php?tournament_id=<?= $tournament_id ?>" class="btn-cta" style="background-color: var(--td-accent); color: white;">
                                <i class="fas fa-users me-2"></i>View Teams
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Info Bar -->
    <div class="container info-bar">
        <div class="row g-3">
            <div class="col-6 col-md-3">
                <div class="info-card">
                    <div class="info-icon"><i class="fas fa-calendar-plus"></i></div>
                    <div>
                        <div class="info-label">Start Date</div>
                        <div class="info-value"><?= date('d M Y', strtotime($tournament['start_date'])) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="info-card">
                    <div class="info-icon"><i class="fas fa-calendar-check"></i></div>
                    <div>
                        <div class="info-label">End Date</div>
                        <div class="info-value"><?= date('d M Y', strtotime($tournament['end_date'])) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="info-card">
                    <div class="info-icon"><i class="fas fa-trophy"></i></div>
                    <div>
                        <div class="info-label">Format</div>
                        <div class="info-value"><?= htmlspecialchars($tournament['format']) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="info-card">
                    <div class="info-icon"><i class="fas fa-users"></i></div>
                    <div>
                        <div class="info-label">Teams</div>
                        <div class="info-value">Max <?= (int) $tournament['max_teams'] ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Section -->
    <div class="container pb-5">
        <div class="row g-4">
            <!-- Left Column: Main Content -->
            <div class="col-lg-8">
                <!-- Overview Grid -->
                <div class="td-card">
                    <h3 class="td-section-title"><i class="fas fa-info-circle"></i>Tournament Overview</h3>
                    <div class="overview-grid">
                        <div class="overview-item">
                            <div class="overview-label">Overs</div>
                            <div class="overview-value"><?= $tournament['overs'] ?> Overs</div>
                        </div>
                        <div class="overview-item">
                            <div class="overview-label">Pitch Type</div>
                            <div class="overview-value"><?= htmlspecialchars($tournament['ground_type']) ?></div>
                        </div>
                        <div class="overview-item">
                            <div class="overview-label">Code</div>
                            <div class="overview-value">#<?= htmlspecialchars($tournament['tournament_code']) ?></div>
                        </div>
                        <div class="overview-item">
                            <div class="overview-label">Balls</div>
                            <div class="overview-value"><?= htmlspecialchars($tournament['ball_type'] ?? 'Leather') ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Description Section -->
                <?php if ($tournament['description']): ?>
                    <div class="td-card">
                        <h3 class="td-section-title"><i class="fas fa-align-left"></i>About the Tournament</h3>
                        <div class="description-block">
                            <?= nl2br(htmlspecialchars($tournament['description'])) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Column: Sidebar -->
            <div class="col-lg-4">
                <!-- Prize and Fees Card -->
                <div class="td-card prize-card shadow-lg">
                    <div class="mb-4">
                        <div class="info-label">Prize Pool</div>
                        <div class="info-value"><i
                                class="fas fa-award me-2"></i><?= $tournament['prize_amount'] ? '₹' . number_format($tournament['prize_amount']) : 'TBD' ?>
                        </div>
                    </div>
                    <div class="pt-3 border-top border-white border-opacity-25">
                        <div class="info-label">Entry Fee</div>
                        <div class="info-value"><i
                                class="fas fa-ticket-alt me-2"></i><?= $tournament['entry_fee'] ? '₹' . number_format($tournament['entry_fee']) : 'Free' ?>
                        </div>
                    </div>
                </div>

                <!-- Admin Controls -->
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                    <div class="td-card">
                        <h3 class="td-section-title"><i class="fas fa-user-shield"></i>Admin Panel</h3>
                        <div class="admin-actions">
                            <button class="btn btn-admin btn-admin-edit" onclick="editTournament(<?= $tournament['id'] ?>)">
                                <i class="fas fa-edit me-2"></i>Edit Tournament
                            </button>
                            <button class="btn btn-admin btn-admin-delete"
                                onclick="deleteTournament(<?= $tournament['id'] ?>, '<?= htmlspecialchars($tournament['tournament_name']) ?>')">
                                <i class="fas fa-trash-alt me-2"></i>Delete Season
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Quick Information -->
                <div class="td-card">
                    <h3 class="td-section-title"><i class="fas fa-history"></i>Quick Info</h3>
                    <ul class="list-unstyled mb-0">
                        <li class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Created</span>
                            <span class="fw-bold"><?= date('d M Y', strtotime($tournament['created_at'])) ?></span>
                        </li>
                        <li class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Visibility</span>
                            <span class="fw-bold">Publicly Listed</span>
                        </li>
                        <li class="d-flex justify-content-between">
                            <span class="text-muted">Location</span>
                            <span class="fw-bold text-end ms-2"><?= htmlspecialchars($tournament['venue']) ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
    function editTournament(id) {
        window.location.href = '../edit/edit_tournament.php?id=' + id;
    }

    function deleteTournament(id, name) {
        if (confirm('⚠️ Are you sure you want to delete the tournament "' + name + '"?\n\nThis action cannot be undone and will remove all related data.')) {
            window.location.href = '../admin/delete_tournament.php?id=' + id;
        }
    }
</script>

<?php require_once '../includes/footer.php'; ?>