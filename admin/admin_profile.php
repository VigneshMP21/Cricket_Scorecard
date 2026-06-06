<?php
require_once '../includes/db.php';
require_login();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Get user data
$user_id = $_SESSION['user_id'];
$user = null;
$errors = [];
$success = '';

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header("Location: ../login/login.php");
        exit();
    }
} catch (PDOException $e) {
    $errors[] = "Database error occurred.";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $update_data = [];

        // Personal Details Updates (Admin doesn't need cricket fields)
        if (isset($_POST['full_name'])) {
            $update_data['full_name'] = trim($_POST['full_name']);
        }

        if (isset($_POST['phone'])) {
            $update_data['phone'] = trim($_POST['phone']);
        }

        if (isset($_POST['email'])) {
            $update_data['email'] = trim($_POST['email']);
        }

        if (isset($_POST['dob'])) {
            $update_data['dob'] = $_POST['dob'] ?: null;
        }

        if (isset($_POST['address'])) {
            $update_data['address'] = trim($_POST['address']);
        }

        if (isset($_POST['city'])) {
            $update_data['city'] = trim($_POST['city']);
        }

        if (isset($_POST['bio'])) {
            $update_data['bio'] = trim($_POST['bio']);
        }

        // Handle profile image upload
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['profile_image']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (in_array($ext, $allowed)) {
                $newname = uniqid() . '.' . $ext;
                $destination = '../uploads/users/' . $newname;

                if (!is_dir('../uploads/users/')) {
                    mkdir('../uploads/users/', 0755, true);
                }

                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $destination)) {
                    // Delete old image if exists
                    if ($user['profile_image'] && file_exists('../uploads/users/' . $user['profile_image'])) {
                        unlink('../uploads/users/' . $user['profile_image']);
                    }
                    $update_data['profile_image'] = $newname;
                    $_SESSION['profile_image'] = $newname;
                } else {
                    $errors[] = "Failed to upload profile image.";
                }
            } else {
                $errors[] = "Invalid image format. Only JPG, PNG, and GIF are allowed.";
            }
        }

        // Update database if no errors
        if (empty($errors) && !empty($update_data)) {
            $set_parts = [];
            $params = [];

            foreach ($update_data as $column => $value) {
                $set_parts[] = "$column = ?";
                $params[] = $value;
            }

            $params[] = $user_id;

            $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $set_parts) . " WHERE id = ?");
            $stmt->execute($params);

            $success = "Profile updated successfully!";

            // Refresh user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }

    } catch (PDOException $e) {
        $errors[] = "Database error occurred while updating profile.";
    }
}

// Calculate age from DOB
$age = null;
if ($user['dob']) {
    $birthDate = new DateTime($user['dob']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
}

$page_title = "Admin Presence";
require_once '../includes/header.php';
?>

<div class="container-fluid py-5 player-profile-page">
    <!-- Custom CSS and Google Fonts -->
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap');

        :root {
            --glass-bg: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.2);
            --primary-gradient: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            --accent-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --body-bg: #f8fafc;
        }

        .player-profile-page {
            font-family: 'Outfit', sans-serif;
            background-color: var(--body-bg);
            min-height: 100vh;
        }

        /* Hero Section */
        .profile-hero {
            background: var(--primary-gradient);
            height: 240px;
            border-radius: 24px;
            position: relative;
            margin-bottom: -80px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(79, 70, 229, 0.2);
            animation: heroReveal 1.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .profile-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('https://www.transparenttextures.com/patterns/carbon-fibre.png');
            opacity: 0.1;
        }

        .hero-pattern {
            position: absolute;
            top: -20%;
            right: -10%;
            width: 500px;
            height: 500px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            filter: blur(80px);
        }

        /* Glass Cards */
        .glass-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            overflow: hidden;
            animation: cardSlideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
        }

        .glass-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            border-color: rgba(79, 70, 229, 0.3);
        }

        /* Profile Image */
        .profile-pic-wrapper {
            position: relative;
            z-index: 10;
            margin-top: -80px;
            animation: profileZoom 1s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .profile-pic-container {
            width: 180px;
            height: 180px;
            margin: 0 auto;
            border-radius: 50%;
            padding: 8px;
            background: white;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }

        .profile-pic {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #f1f5f9;
        }

        /* Buttons and Badges */
        .btn-modern {
            padding: 10px 24px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-edit {
            background: white;
            color: #4f46e5;
            border: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .btn-edit:hover {
            background: #f8fafc;
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
        }

        .badge-modern {
            padding: 6px 16px;
            border-radius: 50px;
            background: var(--primary-gradient);
            color: white;
            font-size: 0.85rem;
            font-weight: 500;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2);
        }

        /* Icons and Labels */
        .detail-item {
            padding: 12px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.5);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .detail-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #4f46e5;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
        }

        .detail-label {
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 0;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 1rem;
            color: #1e293b;
            font-weight: 700;
            margin-bottom: 0;
        }

        /* Animations */
        @keyframes heroReveal {
            from {
                height: 0;
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                height: 240px;
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes cardSlideUp {
            from {
                transform: translateY(40px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes profileZoom {
            from {
                transform: scale(0.5);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .delay-1 {
            animation-delay: 0.2s;
        }

        .delay-2 {
            animation-delay: 0.4s;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>

    <div class="row justify-content-center">
        <div class="col-12 col-xl-10">
            <!-- Hero Header -->
            <div class="profile-hero">
                <div class="hero-pattern"></div>
                <div class="p-4 d-flex justify-content-between align-items-start position-relative">
                    <div>
                        <h1 class="text-white fw-bold mb-0">System Custodian</h1>
                        <p class="text-white opacity-75">Administrative Overview & Security Profile</p>
                    </div>
                    <button type="button" class="btn btn-modern btn-edit" id="editBtn">
                        <i class="fas fa-pen-nib"></i>Edit Profile
                    </button>
                </div>
            </div>

            <!-- Profile Overview -->
            <div class="profile-pic-wrapper text-center">
                <div class="profile-pic-container">
                    <img src="<?= $user['profile_image'] ? '../uploads/users/' . $user['profile_image'] . '?t=' . time() : '../assets/images/default-admin.png' ?>"
                        alt="Profile" class="profile-pic" onerror="this.src='../assets/images/default-admin.png'">
                </div>
                <h2 class="mt-3 fw-bold text-dark">
                    <?= htmlspecialchars($user['full_name'] ?: ($user['email'] ?? 'Administrator')) ?>
                </h2>
                <div class="d-flex justify-content-center gap-2 mt-2">
                    <span class="badge-modern"><i class="fas fa-shield-alt me-1"></i>Root Administrator</span>
                    <span class="badge-modern bg-success"><i class="fas fa-check-circle me-1"></i>Verified
                        Identity</span>
                </div>
            </div>

            <!-- Feedback Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success mt-4 border-0 shadow-sm rounded-4 text-center">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger mt-4 border-0 shadow-sm rounded-4">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Content Section -->
            <div class="row mt-5">
                <!-- System Identity Section -->
                <div class="col-lg-6 mb-4">
                    <div class="glass-card shadow-sm h-100 delay-1">
                        <div class="card-header border-0 bg-transparent pt-4 px-4">
                            <h5 class="mb-0 fw-bold"><i class="fas fa-fingerprint me-2 text-primary"></i>System Identity
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row">
                                <div class="col-12">
                                    <div class="detail-item">
                                        <div class="detail-icon"><i class="fas fa-id-badge"></i></div>
                                        <div>
                                            <p class="detail-label">Access Level</p>
                                            <p class="detail-value text-danger">Tier 1: Root Access</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 mt-2">
                                    <div class="detail-item">
                                        <div class="detail-icon"><i class="fas fa-calendar-alt"></i></div>
                                        <div>
                                            <p class="detail-label">Service Since</p>
                                            <p class="detail-value"><?= date('F Y', strtotime($user['created_at'])) ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 mt-2">
                                    <div class="detail-item">
                                        <div class="detail-icon"><i class="fas fa-history"></i></div>
                                        <div>
                                            <p class="detail-label">Last Synchronization</p>
                                            <p class="detail-value">
                                                <?= $user['last_login'] ? date('d M Y, H:i', strtotime($user['last_login'])) : 'First Entry' ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Personal Dossier Section -->
                <div class="col-lg-6 mb-4">
                    <div class="glass-card shadow-sm h-100 delay-2">
                        <div class="card-header border-0 bg-transparent pt-4 px-4">
                            <h5 class="mb-0 fw-bold"><i class="fas fa-user-tie me-2 text-success"></i>Personal Dossier
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row">
                                <div class="col-sm-6">
                                    <div class="detail-item">
                                        <div class="detail-icon"><i class="fas fa-envelope"></i></div>
                                        <div style="overflow: hidden;">
                                            <p class="detail-label">Email</p>
                                            <p class="detail-value text-truncate">
                                                <?= htmlspecialchars($user['email']) ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="detail-item">
                                        <div class="detail-icon"><i class="fas fa-phone-alt"></i></div>
                                        <div>
                                            <p class="detail-label">Phone</p>
                                            <p class="detail-value"><?= $user['phone'] ?: 'Redacted' ?></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="detail-item">
                                        <div class="detail-icon"><i class="fas fa-cake-candles"></i></div>
                                        <div>
                                            <p class="detail-label">Birth Date</p>
                                            <p class="detail-value">
                                                <?= $user['dob'] ? date('d M Y', strtotime($user['dob'])) : 'Redacted' ?>
                                                <?= $age ? "($age yrs)" : "" ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="detail-item">
                                        <div class="detail-icon"><i class="fas fa-location-dot"></i></div>
                                        <div>
                                            <p class="detail-label">Location</p>
                                            <p class="detail-value"><?= $user['city'] ?: 'Undisclosed' ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="detail-item mt-2">
                                <div class="detail-icon"><i class="fas fa-quote-left"></i></div>
                                <div class="w-100">
                                    <p class="detail-label">Official Bio</p>
                                    <p class="detail-value mt-1"
                                        style="font-weight: 500; font-style: italic; color: #475569;">
                                        <?= $user['bio'] ? nl2br(htmlspecialchars($user['bio'])) : 'Critical system administrator. No further bio provided.' ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel">Edit Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="profileForm">
                    <div class="modal-body">
                        <!-- Profile Image Section -->
                        <div class="text-center mb-4">
                            <div class="profile-image-container">
                                <img id="currentImage"
                                    src="<?= $user['profile_image'] ? '../uploads/users/' . $user['profile_image'] . '?t=' . time() : '../images/default-admin.png' ?>"
                                    alt="Current Profile" class="img-fluid responsive-profile-img"
                                    style="max-width: 200px; height: auto; border: 2px solid #007bff; cursor:pointer;">
                            </div>
                            <div class="mt-2">
                                <label for="profile_image" class="form-label text-muted small">Click to change
                                    profile picture</label>
                                <input type="file" class="form-control d-none" id="profile_image" name="profile_image"
                                    accept="image/*">
                            </div>
                        </div>

                        <!-- Personal Details Form -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="full_name" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="full_name" name="full_name"
                                    value="<?= htmlspecialchars($user['full_name'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Mail ID *</label>
                                <input type="email" class="form-control" id="email" name="email"
                                    value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">Mobile Number *</label>
                                <input type="tel" class="form-control" id="phone" name="phone"
                                    value="<?= htmlspecialchars($user['phone'] ?? '') ?>" required>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="dob" class="form-label">Date of Birth *</label>
                                    <input type="date" class="form-control" id="dob" name="dob"
                                        value="<?= $user['dob'] ?? '' ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="city" class="form-label">City *</label>
                                    <input type="text" class="form-control" id="city" name="city"
                                        value="<?= htmlspecialchars($user['city'] ?? '') ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="address" class="form-label">Address *</label>
                                <textarea class="form-control" id="address" name="address"
                                    rows="3"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="bio" class="form-label">Bio / About</label>
                                <textarea class="form-control" id="bio" name="bio" rows="4"
                                    placeholder="Tell us about yourself..."><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    /* Glass Modal Styles */
    .modal-content {
        background: rgba(255, 255, 255, 0.98);
        backdrop-filter: blur(16px);
        border: 1px solid var(--glass-border);
        border-radius: 24px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    }

    .modal-header {
        background: var(--primary-gradient);
        border-radius: 24px 24px 0 0;
        padding: 1.5rem;
        color: white;
        border: none;
    }

    .modal-title {
        font-weight: 700;
        letter-spacing: -0.5px;
    }

    .form-control,
    .form-select {
        border-radius: 12px;
        padding: 12px 16px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        transition: all 0.3s;
    }

    .form-control:focus,
    .form-select:focus {
        background: white;
        border-color: #4f46e5;
        box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
    }

    .responsive-profile-img {
        border-radius: 16px;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    }

    .profile-image-container {
        position: relative;
        overflow: hidden;
        border-radius: 16px;
        display: inline-block;
    }

    #currentImage {
        cursor: pointer;
        transition: all 0.3s;
    }

    #currentImage:hover {
        opacity: 0.8;
        transform: scale(1.02);
    }
</style>

<!-- Crop Modal Structure -->
<div class="modal fade" id="cropModal" tabindex="-1" aria-labelledby="cropModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cropModalLabel">Perfect Your Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div id="cropperContainer" style="max-height: 400px; overflow: hidden; border-radius: 12px;">
                    <img id="imageToCrop" src="" style="max-width: 100%;">
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary rounded-pill px-4"
                    data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary rounded-pill px-4" id="cropBtn">Apply Changes</button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Auto-hide alert after 5 seconds
        setTimeout(function () {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        const imageInput = document.getElementById('profile_image');
        const currentImage = document.getElementById('currentImage');
        const imageToCrop = document.getElementById('imageToCrop');
        const cropBtn = document.getElementById('cropBtn');
        let cropper;
        let cropModal;

        // Initialize Modal
        cropModal = new bootstrap.Modal(document.getElementById('cropModal'));

        // Open file selector when clicking current image
        currentImage.addEventListener('click', function () {
            imageInput.click();
        });

        imageInput.addEventListener('change', function (e) {
            const files = e.target.files;
            if (files && files.length > 0) {
                const file = files[0];

                // Prevent recursive loop
                if (file.cropped) return;

                const reader = new FileReader();
                reader.onload = function (event) {
                    imageToCrop.src = event.target.result;
                    cropModal.show();

                    // Wait for modal to be somewhat visible or just destroy prev immediately
                    if (cropper) cropper.destroy();
                };
                reader.readAsDataURL(file);

                // Clear input so same file can be selected again if cancelled
                imageInput.value = '';
            }
        });

        // Initialize cropper when modal is shown
        document.getElementById('cropModal').addEventListener('shown.bs.modal', function () {
            cropper = new Cropper(imageToCrop, {
                aspectRatio: 1,
                viewMode: 1,
                autoCropArea: 1,
            });
        });

        cropBtn.addEventListener('click', function () {
            if (!cropper) return;

            // Get Cropped Canvas
            const canvas = cropper.getCroppedCanvas({
                width: 500,
                height: 500,
            });

            // Convert to Blob
            canvas.toBlob(function (blob) {
                const croppedFile = new File([blob], "profile_cropped.png", { type: "image/png" });
                croppedFile.cropped = true; // Flag to prevent loop

                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(croppedFile);
                imageInput.files = dataTransfer.files;

                // Show Preview
                currentImage.src = URL.createObjectURL(blob);

                cropModal.hide();
            }, 'image/png');
        });

        document.getElementById('cropModal').addEventListener('hidden.bs.modal', function () {
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
        });
    });

    document.getElementById('editBtn').addEventListener('click', function () {
        const modal = new bootstrap.Modal(document.getElementById('editModal'));
        modal.show();
    });

    // Enhanced Save Button Feedback
    document.getElementById('profileForm').addEventListener('submit', function (e) {
        const requiredFields = ['phone', 'dob', 'address', 'city'];
        let isValid = true;

        requiredFields.forEach(field => {
            const element = document.getElementById(field);
            if (!element.value.trim()) {
                element.classList.add('is-invalid');
                isValid = false;
            } else {
                element.classList.remove('is-invalid');
            }
        });

        if (!isValid) {
            e.preventDefault();
            return;
        }

        const btn = this.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Syncing Profile...`;
    });
</script>

<?php require_once '../includes/footer.php'; ?>