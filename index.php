<?php
// index.php
// Home page of CPT League

require_once 'CPT_LEAGUE/includes/db.php';

// Redirect if already logged in (via session or auto-login)
if (isset($_SESSION['user_id'])) {
    $base_url = "http://" . $_SERVER['HTTP_HOST'] . "/CPT_LEAGUE/";
    $role = $_SESSION['role'];
    if ($role == 'admin') {
        header("Location: " . $base_url . "admin/admin_dashboard.php");
    } elseif ($role == 'player') {
        header("Location: " . $base_url . "player/player_dashboard.php");
    } else {
        header("Location: " . $base_url . "audience/audience_dashboard.php");
    }
    exit();
}

require_once 'CPT_LEAGUE/includes/header.php';
?>

<!-- Hero Section -->
<section class="hero-section">
    <div class="hero-overlay">
        <div class="container">
            <div class="hero-content text-center">
                <h1 class="display-4 fw-bold mb-4">Welcome to CPT League</h1>
                <p class="lead mb-5">Track live scores, player statistics, and match details of your CPT League matches
                    in real-time!</p>

                <!-- Action Buttons -->
                <div class="hero-buttons d-none d-md-block">
                    <div class="row g-4 justify-content-center">
                        <!-- Login -->
                        <div class="col-12 col-md-4">
                            <div class="hero-card card-hover">
                                <div class="hero-icon">
                                    <i class="fas fa-sign-in-alt"></i>
                                </div>
                                <h3>Login</h3>
                                <p>Access your dashboard to manage matches, teams, and statistics</p>
                                <a href="CPT_LEAGUE/login/login.php" class="btn btn-primary btn-lg w-100">
                                    <i class="fas fa-sign-in-alt me-2"></i>Login
                                </a>
                            </div>
                        </div>

                        <!-- Player Register -->
                        <div class="col-12 col-md-4">
                            <div class="hero-card card-hover">
                                <div class="hero-icon">
                                    <i class="fas fa-user-plus"></i>
                                </div>
                                <h3>Player Register</h3>
                                <p>Create a new player account to start tracking your CPT League matches</p>
                                <a href="CPT_LEAGUE/login/register.php" class="btn btn-success btn-lg w-100">
                                    <i class="fas fa-user-plus me-2"></i>Player Register
                                </a>
                            </div>
                        </div>

                        <!-- Audience Login -->
                        <div class="col-12 col-md-4">
                            <div class="hero-card card-hover">
                                <div class="hero-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <h3>Audience Login</h3>
                                <p>Watch live matches and follow scores without creating an account</p>
                                <a href="CPT_LEAGUE/audience/audience_dashboard.php" class="btn btn-info btn-lg w-100">
                                    <i class="fas fa-eye me-2"></i>Watch Live
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons (Mobile) -->
                <div class="mobile-actions d-flex flex-column align-items-center gap-3 d-md-none mt-4 w-100">
                    <a href="CPT_LEAGUE/login/login.php" class="btn btn-primary btn-lg py-3 shadow-sm w-75">
                        <i class="fas fa-sign-in-alt me-2"></i>Login
                    </a>
                    <a href="CPT_LEAGUE/login/register.php" class="btn btn-success btn-lg py-3 shadow-sm w-75">
                        <i class="fas fa-user-plus me-2"></i>Player Registration
                    </a>
                    <a href="CPT_LEAGUE/audience/audience_dashboard.php"
                        class="btn btn-info btn-lg py-3 shadow-sm text-white w-75">
                        <i class="fas fa-eye me-2"></i>Watch Live
                    </a>
                </div>

                <!-- Quick Stats -->
                <div class="hero-stats mt-5">
                    <div class="row g-4 justify-content-center">
                        <?php
                        // Fetch counts
                        $stats = [
                            'matches' => $pdo->query("SELECT COUNT(*) FROM matches")->fetchColumn(),
                            'teams' => $pdo->query("SELECT COUNT(*) FROM teams")->fetchColumn(),
                            'players' => $pdo->query("SELECT COUNT(*) FROM users WHERE role='player'")->fetchColumn(),
                            'tournaments' => $pdo->query("SELECT COUNT(*) FROM tournaments")->fetchColumn()
                        ];

                        $statItems = [
                            ['icon' => 'fa-trophy', 'count' => $stats['tournaments'], 'label' => 'Tournaments'],
                            ['icon' => 'fa-users', 'count' => $stats['teams'], 'label' => 'Teams'],
                            ['icon' => 'fa-user-friends', 'count' => $stats['players'], 'label' => 'Players'],
                            ['icon' => 'fa-baseball-ball', 'count' => $stats['matches'], 'label' => 'Matches']
                        ];

                        foreach ($statItems as $item): ?>
                            <div class="col-6 col-md-3">
                                <div class="stat-card">
                                    <i class="fas <?= $item['icon'] ?>"></i>
                                    <h3><?= $item['count'] ?></h3>
                                    <p><?= $item['label'] ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once 'CPT_LEAGUE/includes/footer.php'; ?>