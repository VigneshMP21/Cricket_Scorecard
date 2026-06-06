<?php
// includes/header.php - Common header
ob_start();
if (session_status() === PHP_SESSION_NONE)
    session_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CPT CRICKET LEAGUE</title>
    <link rel="shortcut icon" href="/CPT_LEAGUE/assets/images/logo.jpg" type="image/x-icon">

    <!-- Bootstrap 5 CSS -->
    <link href="/CPT_LEAGUE/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="/CPT_LEAGUE/assets/vendor/fontawesome/css/all.min.css">

    <!-- Cropper.js CSS -->
    <link rel="stylesheet" href="/CPT_LEAGUE/assets/vendor/cropperjs/cropper.min.css">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="/CPT_LEAGUE/assets/css/style.css">
    <link rel="stylesheet" href="/CPT_LEAGUE/assets/css/footer.css">

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/CPT_LEAGUE/assets/images/logo.jpg">

    <!-- Custom CSS for Surgical Sidebar -->
    <style>
        @media (max-width: 991.98px) {
            .offcanvas-lg {
                width: 300px !important;
                background-color: #1a1a2e !important;
                /* Dark theme for sidebar */
                color: white !important;
                z-index: 1055 !important;
                /* Ensure sidebar is above sticky header (1020) */
            }

            .offcanvas-header {
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
                background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            }

            /* Force single column layout for sidebar items */
            .offcanvas-body .nav {
                flex-direction: column !important;
            }

            .offcanvas-body .nav-item {
                width: 100% !important;
            }

            .offcanvas-body .nav-link {
                color: rgba(255, 255, 255, 0.8) !important;
                padding: 12px 20px !important;
                border-radius: 0 !important;
                text-align: left !important;
                border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            }

            .offcanvas-body .nav-link.active {
                background: rgba(255, 255, 255, 0.1) !important;
                color: white !important;
            }

            .offcanvas-body .nav-link i {
                width: 25px;
            }
        }

        @media (min-width: 992px) {
            .offcanvas-body {
                display: flex !important;
                padding: 0 !important;
            }
        }

        .header-logo {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 50%;
            vertical-align: middle;
            transition: all 0.3s ease;
            background: white;
            padding: 2px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }

        .header-logo:hover {
            transform: scale(1.05);
            border-color: rgba(255, 255, 255, 0.8);
            box-shadow: 0 0 20px rgba(255, 255, 255, 0.3);
        }

        .brand-text, .offcanvas-title {
            display: inline-flex !important;
            align-items: center !important;
            gap: 15px !important;
        }

        @media (max-width: 480px) {
            .header-logo {
                width: 45px;
                height: 45px;
                border-radius: 15px;
            }
            .brand-text, .offcanvas-title {
                gap: 6px !important;
            }
        }
    </style>

    <!-- Global Loader CSS -->
    <style>
        #page-loader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            z-index: 99999;
            display: flex;
            justify-content: center;
            align-items: center;
            transition: opacity 0.5s ease-out, visibility 0.5s;
        }

        .spinner-container {
            position: relative;
            width: 100px;
            height: 100px;
        }

        .spinner-ring {
            width: 100%;
            height: 100%;
            border: 3px solid rgba(0, 0, 0, 0.1);
            border-top: 5px solid #f80505ff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            box-shadow: 0 0 10px rgba(0, 0, 0, 1);
        }

        .spinner-icon {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 100px;
            height: 100px;
            object-fit: contain;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 0.7;
                transform: translate(-50%, -50%) scale(0.9);
            }

            50% {
                opacity: 1;
                transform: translate(-50%, -50%) scale(1);
            }
        }

        .loader-hidden {
            opacity: 0;
            visibility: hidden;
        }
    </style>

    <!-- Professional Profile Dropdown Styles -->
    <style>
        .profile-trigger {
            padding: 4px 12px 4px 4px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 50px;
            transition: all 0.3s ease;
        }

        .profile-trigger:hover,
        .profile-trigger[aria-expanded="true"] {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.4);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .profile-img-ring {
            padding: 2px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
        }

        .profile-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.8);
        }

        .profile-avatar-placeholder {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            border: 2px solid rgba(255, 255, 255, 0.8);
        }

        .profile-dropdown-menu {
            width: 260px;
            padding: 10px;
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            margin-top: 15px;
            animation: dropdownSlide 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes dropdownSlide {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .dropdown-header {
            padding: 8px 12px;
            background: white;
            border-radius: 10px;
            margin-bottom: 8px;
        }

        .dropdown-item {
            padding: 10px 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #475569;
            font-weight: 500;
            border-radius: 10px;
            transition: all 0.2s;
            margin-bottom: 2px;
        }

        .dropdown-item:hover,
        .dropdown-item:active {
            background: #f1f5f9;
            color: #4f46e5;
            transform: translateX(4px);
        }

        .dropdown-item.text-danger:hover {
            background: #FEF2F2;
            color: #DC2626;
        }

        .icon-box {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e0e7ff;
            color: #4f46e5;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .dropdown-item:hover .icon-box {
            background: #4f46e5;
            color: white;
        }

        .icon-box.text-danger {
            background: #fee2e2;
            color: #ef4444;
        }

        .dropdown-item.text-danger:hover .icon-box.text-danger {
            background: #ef4444;
            color: white;
        }
    </style>
</head>

<body>
    <!-- Global Loading Animation -->
    <div id="page-loader">
        <div class="spinner-container">
            <div class="spinner-ring"></div>
            <img src="/CPT_LEAGUE/assets/images/loading.png" class="spinner-icon" alt="Loading...">
        </div>
    </div>

    <script>
        (function () {
            // Check if the current page load is a reload
            const navEntries = performance.getEntriesByType("navigation");
            const isReload = navEntries.length > 0 && navEntries[0].type === "reload";

            if (isReload) {
                const loader = document.getElementById('page-loader');
                if (loader) loader.style.display = 'none';
            }
        })();

        window.addEventListener('load', function () {
            const loader = document.getElementById('page-loader');
            const navEntries = performance.getEntriesByType("navigation");
            const isReload = navEntries.length > 0 && navEntries[0].type === "reload";

            // If it's a reload, nothing more to do
            if (isReload) {
                return;
            }

            // Normal navigation: hide with delay
            setTimeout(() => {
                if (loader) {
                    loader.classList.add('loader-hidden');
                }
            }, 500);
        });

        // Safety fallback: Hide loader after 5 seconds max if window.load hangs
        setTimeout(() => {
            const loader = document.getElementById('page-loader');
            if (loader && !loader.classList.contains('loader-hidden')) {
                loader.classList.add('loader-hidden');
            }
        }, 5000);
    </script>
    <!-- Navigation Bar -->
    <?php if (isset($_SESSION['user_id']) && $_SESSION['role'] == 'admin'): ?>
        <!-- Admin Header -->
        <header class="admin-header sticky-top">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center py-3">
                    <div class="brand-section d-flex align-items-center">
                        <button class="btn btn-link text-white d-lg-none me-2 p-0" type="button" data-bs-toggle="offcanvas"
                            data-bs-target="#adminSidebar" aria-controls="adminSidebar" aria-label="Toggle navigation">
                            <i class="fas fa-bars fa-lg"></i>
                        </button>
                        <h1 class="brand-text mb-0">
                            <img src="/CPT_LEAGUE/assets/images/logo.jpg" class="header-logo" alt="Logo">
                            <span class="fw-bold">CPT LEAGUE</span>
                        </h1>
                    </div>
                    <div class="d-flex align-items-center">
                        <div class="profile-section ms-2">
                            <div class="dropdown">
                                <a class="profile-trigger d-flex align-items-center text-decoration-none" href="#"
                                    role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <div class="profile-img-ring">
                                        <?php if (isset($_SESSION['profile_image']) && $_SESSION['profile_image']): ?>
                                            <img src="/CPT_LEAGUE/uploads/users/<?= htmlspecialchars($_SESSION['profile_image']) ?>"
                                                alt="Profile" class="profile-avatar">
                                        <?php else: ?>
                                            <div class="profile-avatar-placeholder">
                                                <span>
                                                    <?= substr(strtoupper($_SESSION['user_name'] ?? 'U'), 0, 1) ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-none d-md-block ms-2 text-start">
                                        <small class="d-block text-white opacity-75"
                                            style="font-size: 0.7rem; line-height: 1;">Welcome,</small>
                                        <span class="text-white fw-medium" style="font-size: 0.9rem;">
                                            <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?>
                                        </span>
                                    </div>
                                    <i class="fas fa-chevron-down ms-2 text-white small"></i>
                                </a>

                                <ul class="dropdown-menu dropdown-menu-end profile-dropdown-menu">
                                    <li class="dropdown-header">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <?php if (isset($_SESSION['profile_image']) && $_SESSION['profile_image']): ?>
                                                    <img src="/CPT_LEAGUE/uploads/users/<?= htmlspecialchars($_SESSION['profile_image']) ?>"
                                                        class="rounded-circle" width="40" height="40"
                                                        style="object-fit: cover;">
                                                <?php else: ?>
                                                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center"
                                                        style="width: 40px; height: 40px; font-weight: bold;">
                                                        <?= substr(strtoupper($_SESSION['user_name'] ?? 'U'), 0, 1) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h6 class="mb-0 fw-bold text-dark">
                                                    <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?>
                                                </h6>
                                                <small class="text-muted">
                                                    <?= ucfirst($_SESSION['role'] ?? 'Administrator') ?>
                                                </small>
                                            </div>
                                        </div>
                                    </li>
                                    <li>
                                        <hr class="dropdown-divider my-2">
                                    </li>
                                    <li><a class="dropdown-item" href="/CPT_LEAGUE/admin/admin_dashboard.php">
                                            <div class="icon-box"><i class="fas fa-tachometer-alt"></i></div>
                                            <span>Dashboard</span>
                                        </a></li>
                                    <li><a class="dropdown-item" href="/CPT_LEAGUE/admin/admin_profile.php">
                                            <div class="icon-box"><i class="fas fa-user"></i></div>
                                            <span>Profile</span>
                                        </a></li>
                                    <li><a class="dropdown-item" href="/CPT_LEAGUE/NavBarList/notification_manager.php">
                                            <div class="icon-box"><i class="fas fa-bell"></i></div>
                                            <span>Notification Manager</span>
                                        </a></li>
                                    <li><a class="dropdown-item" href="/CPT_LEAGUE/admin/change_password.php">
                                            <div class="icon-box"><i class="fas fa-key"></i></div>
                                            <span>Change Password</span>
                                        </a></li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                    <li><a class="dropdown-item text-danger" href="/CPT_LEAGUE/login/logout.php">
                                            <div class="icon-box text-danger bg-danger-subtle"><i
                                                    class="fas fa-sign-out-alt"></i></div>
                                            <span>Logout</span>
                                        </a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Admin Navigation Bar -->
        <nav class="admin-nav sticky-top">
            <div class="container-fluid">
                <div class="offcanvas-lg offcanvas-start" id="adminSidebar">
                    <div class="offcanvas-header">
                        <h5 class="offcanvas-title text-white"><img src="/CPT_LEAGUE/assets/images/logo.jpg" class="header-logo" alt="Logo" style="width: 45px; height: 45px;">CPT LEAGUE</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"
                            data-bs-target="#adminSidebar"></button>
                    </div>
                    <div class="offcanvas-body">
                        <ul class="nav nav-pills nav-fill w-100">
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/admin/admin_dashboard.php">
                                    <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'tournament_list.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/tournament_list.php">
                                    <i class="fas fa-trophy me-1"></i>Tournament
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'matches.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/matches.php">
                                    <i class="fas fa-baseball-ball me-1"></i>Matches
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'point_tables.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/point_tables.php">
                                    <i class="fas fa-table me-1"></i>Point Table
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'teams.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/teams.php">
                                    <i class="fas fa-users me-1"></i>Teams
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'best_batter_ranking.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/best_batter_ranking.php">
                                    <i class="fas fa-baseball-bat-ball me-1"></i>Best Batter Ranking
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'best_bowler_ranking.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/best_bowler_ranking.php">
                                    <i class="fas fa-bowling-ball me-1"></i>Best Bowler Ranking
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'pos_ranking.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/pos_ranking.php">
                                    <i class="fas fa-trophy me-1"></i>POS Ranking
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'players_list.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/players_list.php">
                                    <i class="fas fa-user-friends me-1"></i>Players
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </nav>
    <?php elseif (isset($_SESSION['user_id']) && $_SESSION['role'] == 'player'): ?>
        <!-- Player Header -->
        <header class="admin-header sticky-top">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center py-3">
                    <div class="brand-section d-flex align-items-center">
                        <button class="btn btn-link text-white d-lg-none me-2 p-0" type="button" data-bs-toggle="offcanvas"
                            data-bs-target="#playerSidebar" aria-controls="playerSidebar" aria-label="Toggle navigation">
                            <i class="fas fa-bars fa-lg"></i>
                        </button>
                        <h1 class="brand-text mb-0">
                            <img src="/CPT_LEAGUE/assets/images/logo.jpg" class="header-logo" alt="Logo">
                            <span class="fw-bold">CPT LEAGUE</span>
                        </h1>
                    </div>
                    <div class="d-flex align-items-center">
                        <div class="profile-section ms-2">
                            <div class="dropdown">
                                <a class="profile-trigger d-flex align-items-center text-decoration-none" href="#"
                                    role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <div class="profile-img-ring">
                                        <?php if (isset($_SESSION['profile_image']) && $_SESSION['profile_image']): ?>
                                            <img src="/CPT_LEAGUE/uploads/users/<?= htmlspecialchars($_SESSION['profile_image']) ?>"
                                                alt="Profile" class="profile-avatar">
                                        <?php else: ?>
                                            <div class="profile-avatar-placeholder">
                                                <span><?= substr(strtoupper($_SESSION['user_name'] ?? 'U'), 0, 1) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-none d-md-block ms-2 text-start">
                                        <small class="d-block text-white opacity-75"
                                            style="font-size: 0.7rem; line-height: 1;">Welcome,</small>
                                        <span class="text-white fw-medium"
                                            style="font-size: 0.9rem;"><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></span>
                                    </div>
                                    <i class="fas fa-chevron-down ms-2 text-white small"></i>
                                </a>

                                <ul class="dropdown-menu dropdown-menu-end profile-dropdown-menu">
                                    <li class="dropdown-header">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <?php if (isset($_SESSION['profile_image']) && $_SESSION['profile_image']): ?>
                                                    <img src="/CPT_LEAGUE/uploads/users/<?= htmlspecialchars($_SESSION['profile_image']) ?>"
                                                        class="rounded-circle" width="40" height="40"
                                                        style="object-fit: cover;">
                                                <?php else: ?>
                                                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center"
                                                        style="width: 40px; height: 40px; font-weight: bold;">
                                                        <?= substr(strtoupper($_SESSION['user_name'] ?? 'U'), 0, 1) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h6 class="mb-0 fw-bold text-dark">
                                                    <?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?>
                                                </h6>
                                                <small
                                                    class="text-muted"><?= ucfirst($_SESSION['role'] ?? 'Player') ?></small>
                                            </div>
                                        </div>
                                    </li>
                                    <li>
                                        <hr class="dropdown-divider my-2">
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="/CPT_LEAGUE/player/player_dashboard.php?page=home">
                                            <div class="icon-box"><i class="fas fa-home"></i></div>
                                            <span>Dashboard</span>
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="/CPT_LEAGUE/player/player_profile.php">
                                            <div class="icon-box"><i class="fas fa-user"></i></div>
                                            <span>My Profile</span>
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item"
                                            href="/CPT_LEAGUE/view/view_player_profile.php?player_id=<?= $_SESSION['user_id'] ?>">
                                            <div class="icon-box"><i class="fas fa-chart-line"></i></div>
                                            <span>My Stats</span>
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="/CPT_LEAGUE/player/change_password.php">
                                            <div class="icon-box"><i class="fas fa-key"></i></div>
                                            <span>Change Password</span>
                                        </a>
                                    </li>
                                    <li>
                                        <hr class="dropdown-divider my-2">
                                    </li>
                                    <li>
                                        <a class="dropdown-item text-danger fw-medium" href="/CPT_LEAGUE/login/logout.php">
                                            <div class="icon-box text-danger bg-danger-subtle"><i
                                                    class="fas fa-sign-out-alt"></i></div>
                                            <span>Logout</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </header>

        <!-- Player Navigation Bar -->
        <nav class="admin-nav sticky-top">
            <div class="container-fluid">
                <div class="offcanvas-lg offcanvas-start" id="playerSidebar">
                    <div class="offcanvas-header">
                        <h5 class="offcanvas-title text-white"><img src="/CPT_LEAGUE/assets/images/logo.jpg" class="header-logo" alt="Logo" style="width: 45px; height: 45px;">CPT LEAGUE</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"
                            data-bs-target="#playerSidebar"></button>
                    </div>
                    <div class="offcanvas-body">
                        <ul class="nav nav-pills nav-fill w-100">
                            <li class="nav-item">
                                <a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'player_dashboard.php' && (!isset($_GET['page']) || $_GET['page'] == 'home')) ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/player/player_dashboard.php?page=home">
                                    <i class="fas fa-home me-1"></i>Dashboard
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'tournament_list.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/tournament_list.php">
                                    <i class="fas fa-trophy me-1"></i>Tournament
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'my_matches.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/my_matches.php">
                                    <i class="fas fa-calendar-check me-1"></i>My Matches
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'matches.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/matches.php">
                                    <i class="fas fa-baseball-ball me-1"></i>Matches
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'point_tables.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/point_tables.php">
                                    <i class="fas fa-table me-1"></i>Point Table
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'teams.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/teams.php">
                                    <i class="fas fa-users me-1"></i>Teams
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'best_batter_ranking.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/best_batter_ranking.php">
                                    <i class="fas fa-baseball-bat-ball me-1"></i>Best Batter Ranking
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'best_bowler_ranking.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/best_bowler_ranking.php">
                                    <i class="fas fa-bowling-ball me-1"></i>Best Bowler Ranking
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'pos_ranking.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/pos_ranking.php">
                                    <i class="fas fa-trophy me-1"></i>POS Ranking
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'players_list.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/players_list.php">
                                    <i class="fas fa-user-friends me-1"></i>Players
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </nav>
    <?php elseif ((isset($_SESSION['user_id']) && $_SESSION['role'] == 'audience') || basename($_SERVER['PHP_SELF']) == 'audience_dashboard.php' || strpos($_SERVER['PHP_SELF'], '/NavBarList/') !== false || strpos($_SERVER['PHP_SELF'], '/view/') !== false || strpos($_SERVER['PHP_SELF'], '/live_stream/') !== false): ?>
        <!-- Audience Header -->
        <header class="admin-header sticky-top">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center py-3">
                    <div class="brand-section d-flex align-items-center">
                        <button class="btn btn-link text-white d-lg-none me-2 aud-toggle-btn" type="button" data-bs-toggle="offcanvas"
                            data-bs-target="#audienceSidebar" aria-controls="audienceSidebar"
                            aria-label="Toggle navigation">
                            <i class="fas fa-bars"></i>
                        </button>
                        <h1 class="brand-text aud-brand-text mb-0">
                            <img src="/CPT_LEAGUE/assets/images/logo.jpg" class="header-logo" alt="Logo">
                            <span class="fw-bold">CPT LEAGUE</span>
                        </h1>
                    </div>
                    <div class="d-flex align-items-center">
                        <a href="../../index.php" class="btn btn-outline-light btn-sm me-2 aud-home-btn">
                            <i class="fas fa-home"></i>
                            <span>Home</span>
                        </a>
                        <?php if (isset($_SESSION['user_id']) && $_SESSION['role'] == 'audience'): ?>
                            <a href="/CPT_LEAGUE/login/logout.php" class="btn btn-outline-light btn-sm">
                                <i class="fas fa-sign-out-alt me-1"></i>Logout
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </header>

        <!-- Audience Navigation Bar -->
        <nav class="admin-nav sticky-top">
            <div class="container-fluid">
                <div class="offcanvas-lg offcanvas-start" id="audienceSidebar">
                    <div class="offcanvas-header">
                        <h5 class="offcanvas-title text-white">
                            <img src="/CPT_LEAGUE/assets/images/logo.jpg" class="header-logo" alt="Logo" style="width: 45px; height: 45px;">
                            CPT_LEAGUE
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"
                            data-bs-target="#audienceSidebar"></button>
                    </div>
                    <div class="offcanvas-body">
                        <ul class="nav nav-pills nav-fill w-100">
                            <li class="nav-item">
                                <a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'live_match.php' || basename($_SERVER['PHP_SELF']) == 'audience_dashboard.php') ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/audience/audience_dashboard.php">
                                    <i class="fas fa-play-circle me-1"></i>Live Matches
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'tournament_list.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/tournament_list.php">
                                    <i class="fas fa-trophy me-1"></i>Tournament
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'matches.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/matches.php">
                                    <i class="fas fa-calendar me-1"></i>Matches
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'point_tables.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/point_tables.php">
                                    <i class="fas fa-table me-1"></i>Point Table
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'teams.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/teams.php">
                                    <i class="fas fa-users me-1"></i>Teams
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'best_batter_ranking.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/best_batter_ranking.php">
                                    <i class="fas fa-baseball-bat-ball me-1"></i>Best Batter Ranking
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'best_bowler_ranking.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/best_bowler_ranking.php">
                                    <i class="fas fa-bowling-ball me-1"></i>Best Bowler Ranking
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'pos_ranking.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/pos_ranking.php">
                                    <i class="fas fa-trophy me-1"></i>POS Ranking
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'players_list.php' ? 'active' : '' ?>"
                                    href="/CPT_LEAGUE/NavBarList/players_list.php">
                                    <i class="fas fa-user-friends me-1"></i>Players
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </nav>
    <?php else: ?>
        <!-- Public Header -->
        <header class="admin-header sticky-top">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center py-3">
                    <div class="brand-section">
                        <h1 class="brand-text mb-0">
                            <img src="/CPT_LEAGUE/assets/images/logo.jpg" class="header-logo" alt="Logo">
                            <span class="fw-bold">CPT LEAGUE</span>
                        </h1>
                    </div>
                    <div class="d-flex align-items-center">
                        <a href="/CPT_LEAGUE/login/login.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-sign-in-alt me-1"></i>Login
                        </a>
                    </div>
                </div>
            </div>
        </header>
    <?php endif; ?>


    <!-- Main Content Container -->
    <main class="container-fluid p-0">
