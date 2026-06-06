<?php
require_once '../includes/db.php';

use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Configuration\Configuration;

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv') && file_exists(__DIR__ . '/../.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
        try {
            $dotenv->load();
        } catch (Exception $e) {
        }
    }
}

if (!isset($_GET['team_id']) || !is_numeric($_GET['team_id'])) {
    header('Location: teams.php');
    exit();
}

$team_id = (int) $_GET['team_id'];
$cloudName = $_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw';

$templates = [
    1 => ['name' => 'Template 1', 'bg' => 'https://res.cloudinary.com/dffnuolqw/image/upload/v1778947936/5_uwfdhs.avif'],
    2 => ['name' => 'Template 2', 'bg' => 'https://res.cloudinary.com/dffnuolqw/image/upload/v1778947969/Gemini_Generated_Image_yilyjuyilyjuyily_rr26yz.png'],
    3 => ['name' => 'Template 3', 'bg' => 'https://res.cloudinary.com/dffnuolqw/image/upload/v1778947854/3_h6sxds.jpg'],
    4 => ['name' => 'Template 4', 'bg' => 'https://res.cloudinary.com/dffnuolqw/image/upload/v1778947857/2_xa5j1c.png'],
    5 => ['name' => 'Template 5', 'bg' => 'https://res.cloudinary.com/dffnuolqw/image/upload/v1778947853/4_lq0yxq.jpg'],
    6 => ['name' => 'Template 6', 'bg' => 'https://res.cloudinary.com/dffnuolqw/image/upload/v1779122063/8_l1b2qy.png'],
    7 => ['name' => 'Template 7', 'bg' => 'https://res.cloudinary.com/dffnuolqw/image/upload/v1779122063/6_krhnxn.png'],
    8 => ['name' => 'Template 8', 'bg' => 'https://res.cloudinary.com/dffnuolqw/image/upload/v1779122063/7_mtilbl.png'],
    9 => ['name' => 'Template 9', 'bg' => 'https://res.cloudinary.com/dffnuolqw/image/upload/v1779122064/10_po6ddp.png'],
    10 => ['name' => 'Template 10', 'bg' => 'https://res.cloudinary.com/dffnuolqw/image/upload/v1779122069/9_kin3wc.png'],
];

const BANNER_WIDTH = 1080;
const BANNER_HEIGHT = 1920;
const CUSTOM_BANNER_MAX_FILE_SIZE = 8 * 1024 * 1024;
const CUSTOM_BANNER_LIFETIME_SECONDS = 300;

if (class_exists('Cloudinary\Configuration\Configuration')) {
    Configuration::instance([
        'cloud' => [
            'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'] ?? '',
            'api_key' => $_ENV['CLOUDINARY_API_KEY'] ?? '',
            'api_secret' => $_ENV['CLOUDINARY_API_SECRET'] ?? '',
        ],
        'url' => [
            'secure' => true,
        ],
    ]);
}

function isCloudinaryReady(): bool
{
    return class_exists('Cloudinary\Api\Upload\UploadApi')
        && !empty($_ENV['CLOUDINARY_CLOUD_NAME'])
        && !empty($_ENV['CLOUDINARY_API_KEY'])
        && !empty($_ENV['CLOUDINARY_API_SECRET']);
}

function ensureDirectory(string $directory): bool
{
    return is_dir($directory) || mkdir($directory, 0755, true);
}

function getCustomBannerDirectory(): string
{
    return __DIR__ . '/../assets/images/banners/custom/';
}

function getCustomBannerUrlBase(): string
{
    return '../assets/images/banners/custom/';
}

function buildCustomBannerPaths(string $token): array
{
    $safeToken = preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
    $fileBase = 'custom_banner_' . $safeToken;
    $directory = getCustomBannerDirectory();
    $urlBase = getCustomBannerUrlBase();

    return [
        'token' => $safeToken,
        'file_base' => $fileBase,
        'state_path' => $directory . $fileBase . '.json',
        'file_path' => $directory . $fileBase . '.png',
        'file_url' => $urlBase . $fileBase . '.png',
    ];
}

function readJsonFile(string $filePath): ?array
{
    if (!file_exists($filePath)) {
        return null;
    }

    $contents = file_get_contents($filePath);
    if ($contents === false) {
        return null;
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : null;
}

function writeJsonFile(string $filePath, array $payload): bool
{
    return file_put_contents($filePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

function buildCustomBannerToken(): string
{
    return time() . '_' . substr(bin2hex(random_bytes(5)), 0, 10);
}

function getCustomBannerExpiresAt(?int $baseTimestamp = null): string
{
    $timestamp = $baseTimestamp ?? time();
    return date(DATE_ATOM, $timestamp + CUSTOM_BANNER_LIFETIME_SECONDS);
}

function isCustomBannerExpired(array $state, ?int $now = null): bool
{
    $currentTime = $now ?? time();
    $expiresAt = isset($state['expires_at']) ? strtotime((string) $state['expires_at']) : false;
    if ($expiresAt !== false) {
        return $expiresAt <= $currentTime;
    }

    $fallbackBase = strtotime((string) ($state['generated_at'] ?? $state['created_at'] ?? ''));
    if ($fallbackBase === false) {
        return false;
    }

    return ($fallbackBase + CUSTOM_BANNER_LIFETIME_SECONDS) <= $currentTime;
}

function cleanupCustomBannerAssets(array $paths, ?array $state = null): array
{
    $state = $state ?? readJsonFile($paths['state_path']);
    if (!$state) {
        return ['success' => true, 'cleaned' => true];
    }

    $cleanupErrors = [];

    $cloudinaryPublicIds = array_values(array_unique(array_filter([
        $state['background_public_id'] ?? '',
        $state['generated_public_id'] ?? '',
    ])));

    foreach ($cloudinaryPublicIds as $publicId) {
        $cloudinaryCleanup = destroyCloudinaryImage($publicId);
        if (!$cloudinaryCleanup['success']) {
            $cleanupErrors[] = 'Cloudinary cleanup failed: ' . $cloudinaryCleanup['error'];
        }
    }

    $localFiles = array_values(array_unique(array_filter([
        $state['local_file'] ?? '',
        $paths['file_path'],
    ])));

    foreach ($localFiles as $localFile) {
        if ($localFile && file_exists($localFile) && !unlink($localFile)) {
            $cleanupErrors[] = 'Generated banner file could not be deleted.';
        }
    }

    if (empty($cleanupErrors) && file_exists($paths['state_path']) && !unlink($paths['state_path'])) {
        $cleanupErrors[] = 'Temporary custom banner state file could not be deleted.';
    }

    return [
        'success' => empty($cleanupErrors),
        'cleaned' => empty($cleanupErrors),
        'error' => implode(' ', $cleanupErrors),
        'errors' => $cleanupErrors,
    ];
}

function purgeExpiredCustomBannerStates(): void
{
    $directory = getCustomBannerDirectory();
    if (!is_dir($directory)) {
        return;
    }

    $stateFiles = glob($directory . 'custom_banner_*.json');
    if ($stateFiles === false) {
        return;
    }

    foreach ($stateFiles as $statePath) {
        $state = readJsonFile($statePath);
        if (!$state || !isCustomBannerExpired($state)) {
            continue;
        }

        $token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($state['token'] ?? ''));
        if ($token === '') {
            $token = preg_replace('/^custom_banner_/', '', pathinfo($statePath, PATHINFO_FILENAME));
        }

        if ($token === '') {
            continue;
        }

        $paths = buildCustomBannerPaths($token);
        $cleanupResult = cleanupCustomBannerAssets($paths, $state);
        if (!$cleanupResult['success']) {
            error_log('Expired custom banner cleanup failed for token ' . $token . ': ' . $cleanupResult['error']);
        }
    }
}

function isPngBinary(string $contents): bool
{
    return substr($contents, 0, 8) === "\x89PNG\r\n\x1a\n";
}

function isPngFile(string $filePath): bool
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return false;
    }

    $handle = fopen($filePath, 'rb');
    if (!$handle) {
        return false;
    }

    $signature = fread($handle, 8);
    fclose($handle);

    return $signature === "\x89PNG\r\n\x1a\n";
}

function sendDownloadError(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo $message;
    exit();
}

function servePngDownload(string $filePath, string $downloadName, string $notFoundMessage): void
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        sendDownloadError(404, $notFoundMessage);
    }

    if (!isPngFile($filePath)) {
        sendDownloadError(415, 'The generated banner is not a valid PNG image. Please regenerate it.');
    }

    $safeDownloadName = preg_replace('/[^a-zA-Z0-9_.-]/', '', $downloadName);
    if ($safeDownloadName === '' || substr(strtolower($safeDownloadName), -4) !== '.png') {
        $safeDownloadName = 'team-banner.png';
    }

    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) {
            break;
        }
    }

    header('Content-Type: image/png');
    header('Content-Disposition: attachment; filename="' . $safeDownloadName . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Content-Transfer-Encoding: binary');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    readfile($filePath);
    exit();
}

function uploadImageToCloudinary(string $filePath, string $folder): ?array
{
    if (!isCloudinaryReady() || !file_exists($filePath)) {
        return null;
    }

    try {
        $uploadOptions = ['folder' => $folder];
        $uploadPreset = $_ENV['CLOUDINARY_UPLOAD_PRESET'] ?? '';
        if ($uploadPreset !== '') {
            $uploadOptions['upload_preset'] = $uploadPreset;
        }

        $uploadApi = new UploadApi();
        $result = $uploadApi->upload($filePath, $uploadOptions);

        if (!empty($result['secure_url']) && !empty($result['public_id'])) {
            return [
                'url' => $result['secure_url'],
                'public_id' => $result['public_id'],
            ];
        }
    } catch (Exception $e) {
        error_log('Custom banner Cloudinary upload error: ' . $e->getMessage());
    }

    return null;
}

function destroyCloudinaryImage(?string $publicId): array
{
    if (empty($publicId)) {
        return ['success' => true];
    }

    if (!isCloudinaryReady()) {
        return ['success' => false, 'error' => 'Cloudinary is not configured for cleanup.'];
    }

    try {
        $uploadApi = new UploadApi();
        $response = $uploadApi->destroy($publicId);
        $result = $response['result'] ?? '';

        if (in_array($result, ['ok', 'not found'], true)) {
            return ['success' => true];
        }

        return ['success' => false, 'error' => 'Cloudinary returned: ' . ($result ?: 'unknown response')];
    } catch (Exception $e) {
        error_log('Custom banner Cloudinary cleanup error: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function validateCustomBannerUpload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Please choose a valid JPG, PNG, or WEBP image.'];
    }

    if (($file['size'] ?? 0) > CUSTOM_BANNER_MAX_FILE_SIZE) {
        $maxMb = number_format(CUSTOM_BANNER_MAX_FILE_SIZE / (1024 * 1024), 0);
        return ['success' => false, 'error' => "Image size must be {$maxMb} MB or less."];
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    if (!isset($allowedMimeTypes[$mimeType])) {
        return ['success' => false, 'error' => 'Only JPG, PNG, and WEBP images are allowed.'];
    }

    return ['success' => true, 'mime_type' => $mimeType];
}

function getPlayerImageUrl(array $player, string $cloudName): string
{
    if (!empty($player['profile_image_url'])) {
        return $player['profile_image_url'];
    }

    if (!empty($player['profile_image'])) {
        return 'https://res.cloudinary.com/' . $cloudName . '/image/upload/' . $player['profile_image'];
    }

    return 'https://res.cloudinary.com/' . $cloudName . '/image/upload/v1745678901/default_user_ovz6zt.png';
}

function getBaseAppUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/CPT_LEAGUE';
}

function normalizeSelectedPlayers(array $submittedOrder, array $players, array $team): array
{
    if (empty($players)) {
        return ['success' => false, 'error' => 'No squad players are available for this banner.'];
    }

    $playerLookup = [];
    foreach ($players as $player) {
        $playerLookup[(string) $player['id']] = $player;
    }

    $submittedOrderIds = [];
    foreach ($submittedOrder as $rawId) {
        $playerId = (string) (int) $rawId;
        if ($playerId === '0' || !isset($playerLookup[$playerId]) || in_array($playerId, $submittedOrderIds, true)) {
            continue;
        }
        $submittedOrderIds[] = $playerId;
    }

    $requiredLeadIds = [];
    foreach ([(int) ($team['captain_id'] ?? 0), (int) ($team['vice_captain_id'] ?? 0)] as $leadId) {
        $leadKey = (string) $leadId;
        if ($leadId > 0 && isset($playerLookup[$leadKey]) && !in_array($leadId, $requiredLeadIds, true)) {
            $requiredLeadIds[] = $leadId;
        }
    }

    foreach ($requiredLeadIds as $leadId) {
        if (!in_array((string) $leadId, $submittedOrderIds, true)) {
            return ['success' => false, 'error' => 'Captain and vice-captain must remain selected.'];
        }
    }

    $orderedIds = [];
    foreach ($requiredLeadIds as $leadId) {
        $orderedIds[] = (string) $leadId;
    }

    foreach ($submittedOrderIds as $playerId) {
        if (!in_array($playerId, $orderedIds, true)) {
            $orderedIds[] = $playerId;
        }
    }

    $availableCount = count($playerLookup);
    $minPlayers = min(11, $availableCount);
    $maxPlayers = 15;

    if (count($orderedIds) > $maxPlayers) {
        $orderedIds = array_slice($orderedIds, 0, $maxPlayers);
    }

    $selectedCount = count($orderedIds);

    if ($selectedCount < $minPlayers) {
        return ['success' => false, 'error' => "Select at least {$minPlayers} players to generate this banner."];
    }

    $selectedPlayers = [];
    foreach ($orderedIds as $index => $playerId) {
        $player = $playerLookup[$playerId];
        $player['banner_order'] = $index + 1;
        $selectedPlayers[] = $player;
    }

    return ['success' => true, 'players' => $selectedPlayers];
}

function buildPlayerCardHtml(array $player, array $team): string
{
    $playerName = htmlspecialchars($player['name']);
    $playerAlt = htmlspecialchars($player['name'], ENT_QUOTES);
    if ((int) $player['id'] === (int) ($team['captain_id'] ?? 0)) {
        $playerName .= ' (C)';
    } elseif ((int) $player['id'] === (int) ($team['vice_captain_id'] ?? 0)) {
        $playerName .= ' (VC)';
    }

    $playerRole = htmlspecialchars($player['playing_role'] ?? 'Player');
    $playerImage = htmlspecialchars($player['image_url'], ENT_QUOTES);

    return "
        <div class='player-card'>
            <div class='player-img-wrap'>
                <img src='{$playerImage}' class='player-img' alt='{$playerAlt}'>
            </div>
            <div class='player-name-box'>{$playerName}</div>
            <div class='player-role-box'>{$playerRole}</div>
        </div>
    ";
}

function buildSubstituteCardHtml(array $substitutePlayers): string
{
    $substituteItems = '';
    foreach ($substitutePlayers as $player) {
        $substituteItems .= "<div class='substitute-item'>" . htmlspecialchars($player['name']) . "</div>";
    }

    return "
        <div class='player-card substitute-card'>
            <div class='substitute-card-body'>
                <div class='substitute-card-title'>Substitute Players</div>
                <div class='substitute-card-list'>{$substituteItems}</div>
            </div>
        </div>
    ";
}

function buildBannerHtml(array $team, array $players, string $bgUrl, string $cloudName): string
{
    $baseAppUrl = getBaseAppUrl();
    $teamLogoUrl = !empty($team['team_logo_public_id'])
        ? 'https://res.cloudinary.com/' . $cloudName . '/image/upload/' . $team['team_logo_public_id']
        : (!empty($team['team_logo']) ? $baseAppUrl . '/uploads/teams/' . $team['team_logo'] : '');
    $tournamentName = htmlspecialchars($team['tournament_name'] ?? 'CPT LEAGUE');
    $tournamentLogoUrl = '';
    if (!empty($team['tournament_logo_public_id'])) {
        $tournamentLogoUrl = 'https://res.cloudinary.com/' . $cloudName . '/image/upload/' . $team['tournament_logo_public_id'];
    } elseif (!empty($team['tournament_logo'])) {
        $tournamentLogoPath = ltrim((string) $team['tournament_logo'], '/');
        $tournamentLogoUrl = preg_match('/^https?:\/\//i', $tournamentLogoPath)
            ? $tournamentLogoPath
            : $baseAppUrl . '/' . $tournamentLogoPath;
    } else {
        $tournamentLogoUrl = $baseAppUrl . '/assets/images/logo.jpg';
    }

    $teamName = htmlspecialchars($team['team_name']);
    $teamCode = htmlspecialchars($team['team_code'] ?? '');
    $teamColor = !empty($team['team_color']) ? $team['team_color'] : '#ffffff';
    $logoHtml = $teamLogoUrl
        ? "<img src='" . htmlspecialchars($teamLogoUrl, ENT_QUOTES) . "' class='team-logo' alt='Team logo'>"
        : "<div class='team-logo-placeholder'>TEAM</div>";
    $tournamentBadgeHtml = "
        <div class='tournament-badge'>
            <div class='tournament-logo-shell'>
                <img src='" . htmlspecialchars($tournamentLogoUrl, ENT_QUOTES) . "' class='tournament-logo' alt='Tournament logo'>
            </div>
            <div class='tournament-copy'>
                <div class='tournament-label'>Tournament</div>
                <div class='tournament-name'>{$tournamentName}</div>
            </div>
        </div>
    ";

    $mainPlayers = array_slice($players, 0, 11);
    $substitutePlayers = array_slice($players, 11, 4);
    $rows = [
        array_slice($mainPlayers, 0, 3),
        array_slice($mainPlayers, 3, 3),
        array_slice($mainPlayers, 6, 3),
        array_slice($mainPlayers, 9, 2),
    ];

    $rowsHtml = '';
    foreach ($rows as $rowIndex => $rowPlayers) {
        $shouldRenderSubstituteCard = ($rowIndex === 3 && !empty($substitutePlayers));
        if (empty($rowPlayers) && !$shouldRenderSubstituteCard) {
            continue;
        }

        $rowsHtml .= "<div class='player-row'>";
        foreach ($rowPlayers as $player) {
            $rowsHtml .= buildPlayerCardHtml($player, $team);
        }
        if ($shouldRenderSubstituteCard) {
            $rowsHtml .= buildSubstituteCardHtml($substitutePlayers);
        }
        $rowsHtml .= '</div>';
    }

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            width: 1080px;
            height: 1920px;
            overflow: hidden;
            font-family: 'Outfit', sans-serif;
            background: #0f172a;
        }
        .banner {
            width: 1080px;
            height: 1920px;
            position: relative;
            overflow: hidden;
            background: url('{$bgUrl}') center/cover no-repeat;
        }
        .banner::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                linear-gradient(180deg, rgba(7, 10, 25, 0.30) 0%, rgba(7, 10, 25, 0.52) 35%, rgba(7, 10, 25, 0.76) 100%),
                radial-gradient(circle at top left, rgba(255, 255, 255, 0.22), transparent 36%);
        }
        .content {
            position: relative;
            z-index: 1;
            width: 100%;
            height: 100%;
            padding: 68px 30px 32px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .team-header {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 34px;
            margin-bottom: 34px;
            padding: 0;
            border-radius: 0;
            background: transparent;
            border: 0;
            backdrop-filter: none;
        }
        .team-logo,
        .team-logo-placeholder {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.96);
            padding: 10px;
            object-fit: contain;
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.35);
        }
        .team-logo-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1e293b;
            font-size: 30px;
            font-weight: 800;
            letter-spacing: 4px;
        }
        .team-info {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            max-width: 760px;
        }
        .team-name {
            font-size: 64px;
            line-height: 1.04;
            font-weight: 800;
            color: {$teamColor};
            text-shadow: 0 8px 24px rgba(0, 0, 0, 0.45);
            word-break: break-word;
        }
        .team-code {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 7px;
            color: rgba(255, 255, 255, 0.86);
        }
        .squad-title {
            margin-bottom: 28px;
            padding: 12px 28px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
            font-size: 26px;
            font-weight: 700;
            letter-spacing: 7px;
            text-transform: uppercase;
        }
        .player-row {
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: stretch;
            gap: 16px;
            margin-bottom: 16px;
        }
        .player-card {
            width: 232px;
            display: flex;
            flex-direction: column;
            align-items: stretch;
        }
        .player-img-wrap {
            width: 232px;
            height: 232px;
            overflow: hidden;
            border: 4px solid rgba(15, 23, 42, 0.92);
            border-radius: 28px 28px 0 0;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(226, 232, 240, 0.96));
            box-shadow: 0 20px 32px rgba(0, 0, 0, 0.18);
        }
        .player-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: top;
        }
        .player-name-box {
            width: 232px;
            min-height: 58px;
            padding: 11px 10px 9px;
            text-align: center;
            background: rgba(255, 255, 255, 0.96);
            border-left: 4px solid rgba(15, 23, 42, 0.92);
            border-right: 4px solid rgba(15, 23, 42, 0.92);
            color: #0f172a;
            font-size: 17px;
            font-weight: 800;
            line-height: 1.25;
        }
        .player-role-box {
            width: 232px;
            min-height: 40px;
            padding: 8px 10px 10px;
            text-align: center;
            background: rgba(248, 250, 252, 0.96);
            border: 4px solid rgba(15, 23, 42, 0.92);
            border-top: 0;
            border-radius: 0 0 28px 28px;
            color: #334155;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .substitute-card {
            justify-content: stretch;
        }
        .substitute-card-body {
            width: 232px;
            min-height: 330px;
            height: 100%;
            padding: 18px 14px;
            border-radius: 28px;
            border: 4px solid rgba(245, 158, 11, 0.9);
            background:
                linear-gradient(180deg, rgba(255, 247, 237, 0.98) 0%, rgba(254, 243, 199, 0.98) 100%);
            color: #7c2d12;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            box-shadow: 0 20px 32px rgba(0, 0, 0, 0.18);
        }
        .substitute-card-title {
            margin-bottom: 14px;
            text-align: center;
            font-size: 18px;
            line-height: 1.25;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .substitute-card-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .substitute-item {
            padding: 8px 10px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.78);
            border: 1px solid rgba(245, 158, 11, 0.36);
            font-size: 15px;
            font-weight: 700;
            line-height: 1.25;
            text-align: left;
            word-break: break-word;
        }
        .tournament-badge {
            position: absolute;
            right: 30px;
            bottom: 32px;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 14px;
            max-width: 360px;
            padding: 14px 16px;
            border-radius: 26px;
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.82), rgba(30, 41, 59, 0.62));
            border: 1px solid rgba(255, 255, 255, 0.18);
            box-shadow: 0 18px 34px rgba(0, 0, 0, 0.22);
            backdrop-filter: blur(10px);
        }
        .tournament-logo-shell {
            width: 88px;
            height: 88px;
            flex: 0 0 88px;
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.96);
            padding: 8px;
            box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.18);
        }
        .tournament-logo {
            width: 100%;
            height: 100%;
            border-radius: 14px;
            object-fit: cover;
            object-position: center;
        }
        .tournament-copy {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .tournament-label {
            color: rgba(191, 219, 254, 0.88);
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .tournament-name {
            color: #ffffff;
            font-size: 24px;
            line-height: 1.15;
            font-weight: 800;
            word-break: break-word;
        }
    </style>
</head>
<body>
    <div class="banner">
        <div class="content">
            <div class="team-header">
                {$logoHtml}
                <div class="team-info">
                    <div class="team-name">{$teamName}</div>
                    <div class="team-code">{$teamCode}</div>
                </div>
            </div>
            <div class="squad-title">Squad Players</div>
            {$rowsHtml}
        </div>
        {$tournamentBadgeHtml}
    </div>
</body>
</html>
HTML;
}

function generateBannerViaApi(string $html, string $apiKey): array
{
    $ch = curl_init('https://www.html2image.net/api/api.php?key=' . urlencode($apiKey) . '&type=png&width=' . BANNER_WIDTH . '&height=' . BANNER_HEIGHT . '&delay=2000&transparent=false');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'source=' . urlencode($html),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'error' => 'cURL error: ' . $curlErr];
    }

    $result = json_decode($response, true);
    if (!$result) {
        return ['success' => false, 'error' => 'Invalid API response: ' . substr((string) $response, 0, 200)];
    }

    if (($result['Status'] ?? '') === 'OK' && !empty($result['Link'])) {
        return ['success' => true, 'link' => $result['Link']];
    }

    return ['success' => false, 'error' => $result['Message'] ?? json_encode($result)];
}

function buildSelectionMetadata(array $selectedPlayers): array
{
    $playerOrder = [];
    foreach ($selectedPlayers as $player) {
        $playerOrder[] = [
            'player_id' => (int) $player['id'],
            'order_number' => (int) $player['banner_order'],
            'name' => $player['name'],
            'playing_role' => $player['playing_role'] ?? 'Player',
            'image_url' => $player['image_url'],
        ];
    }

    return $playerOrder;
}

try {
    $stmt = $pdo->prepare("
        SELECT t.*, tr.tournament_name, tr.tournament_logo, tr.tournament_logo_public_id
        FROM teams t
        LEFT JOIN tournaments tr ON t.tournament_id = tr.id
        WHERE t.id = ?
    ");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$team) {
        header('Location: teams.php');
        exit();
    }
} catch (PDOException $e) {
    die('DB error: ' . $e->getMessage());
}

$captain_id = !empty($team['captain_id']) ? (int) $team['captain_id'] : null;
$vice_captain_id = !empty($team['vice_captain_id']) ? (int) $team['vice_captain_id'] : null;

try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.playing_role, u.profile_image, u.profile_image_url
        FROM team_players tp
        JOIN users u ON tp.player_id = u.id
        WHERE tp.team_id = ?
        ORDER BY tp.id ASC
        LIMIT 15
    ");
    $stmt->execute([$team_id]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $roleOrder = [
        'Batsman' => 1,
        'Wicket Keeper' => 1,
        'All-rounder' => 2,
        'All Rounder' => 2,
        'Bowler' => 3,
    ];

    usort($players, function (array $a, array $b) use ($captain_id, $vice_captain_id, $roleOrder): int {
        $aIsCaptain = ((int) $a['id'] === (int) $captain_id);
        $bIsCaptain = ((int) $b['id'] === (int) $captain_id);
        $aIsViceCaptain = ((int) $a['id'] === (int) $vice_captain_id);
        $bIsViceCaptain = ((int) $b['id'] === (int) $vice_captain_id);

        if ($aIsCaptain && !$bIsCaptain) {
            return -1;
        }
        if (!$aIsCaptain && $bIsCaptain) {
            return 1;
        }
        if ($aIsViceCaptain && !$bIsViceCaptain) {
            return -1;
        }
        if (!$aIsViceCaptain && $bIsViceCaptain) {
            return 1;
        }

        $roleA = $roleOrder[$a['playing_role']] ?? 4;
        $roleB = $roleOrder[$b['playing_role']] ?? 4;
        if ($roleA !== $roleB) {
            return $roleA <=> $roleB;
        }

        return strcmp($a['name'], $b['name']);
    });
} catch (PDOException $e) {
    $players = [];
}

foreach ($players as &$player) {
    $player['id'] = (int) $player['id'];
    $player['image_url'] = getPlayerImageUrl($player, $cloudName);
    $player['is_captain'] = ($captain_id !== null && $player['id'] === $captain_id);
    $player['is_vice_captain'] = ($vice_captain_id !== null && $player['id'] === $vice_captain_id);
}
unset($player);

$teamFolder = str_replace(' ', '_', preg_replace('/[^a-zA-Z0-9 ]/', '', $team['team_name']));
$bannerDir = __DIR__ . '/../assets/images/banners/' . $teamFolder . '/';
$bannerUrl = '../assets/images/banners/' . $teamFolder . '/';
$customBannerDir = getCustomBannerDirectory();
purgeExpiredCustomBannerStates();

$postAction = $_POST['action'] ?? '';
$getAction = $_GET['action'] ?? '';

if ($getAction === 'download_template') {
    $templateId = (int) ($_GET['template_id'] ?? 0);
    if (!isset($templates[$templateId])) {
        sendDownloadError(400, 'Invalid template.');
    }

    $filePath = $bannerDir . 'template_' . $templateId . '.png';
    servePngDownload(
        $filePath,
        'squad_banner_template_' . $templateId . '.png',
        'Banner not found. Please generate it again.'
    );
}

if ($getAction === 'download_custom') {
    $token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['custom_token'] ?? ''));
    if ($token === '') {
        sendDownloadError(400, 'Invalid custom banner token.');
    }

    $paths = buildCustomBannerPaths($token);
    $state = readJsonFile($paths['state_path']);
    if (!$state || empty($state['local_file']) || !file_exists($state['local_file'])) {
        sendDownloadError(404, 'Custom banner not found. Please generate it again.');
    }

    if ((int) ($state['team_id'] ?? 0) !== $team_id) {
        sendDownloadError(403, 'This custom banner does not belong to the selected team.');
    }

    if (isCustomBannerExpired($state)) {
        cleanupCustomBannerAssets($paths, $state);
        sendDownloadError(410, 'The custom banner expired. Please upload the image again.');
    }

    $downloadName = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) ($state['file_base'] ?? $paths['file_base'])) . '.png';
    if ($downloadName === '.png') {
        $downloadName = $paths['file_base'] . '.png';
    }

    servePngDownload(
        $state['local_file'],
        $downloadName,
        'Custom banner not found. Please generate it again.'
    );
}

if ($postAction === 'upload_custom_background') {
    header('Content-Type: application/json');

    if (!isset($_FILES['background_image']) || !is_array($_FILES['background_image'])) {
        echo json_encode(['success' => false, 'error' => 'Please select an image to upload.']);
        exit();
    }

    $validation = validateCustomBannerUpload($_FILES['background_image']);
    if (!$validation['success']) {
        echo json_encode($validation);
        exit();
    }

    if (!isCloudinaryReady()) {
        echo json_encode(['success' => false, 'error' => 'Cloudinary is not configured for custom banner uploads.']);
        exit();
    }

    if (!ensureDirectory($customBannerDir)) {
        echo json_encode(['success' => false, 'error' => 'Unable to prepare the custom banner storage directory.']);
        exit();
    }

    $token = buildCustomBannerToken();
    $paths = buildCustomBannerPaths($token);
    $uploadResult = uploadImageToCloudinary($_FILES['background_image']['tmp_name'], 'team_banners/custom_backgrounds');

    if (!$uploadResult) {
        echo json_encode(['success' => false, 'error' => 'Cloudinary upload failed. Please try another image.']);
        exit();
    }

    $state = [
        'kind' => 'custom',
        'team_id' => $team_id,
        'token' => $paths['token'],
        'file_base' => $paths['file_base'],
        'created_at' => date(DATE_ATOM),
        'expires_at' => getCustomBannerExpiresAt(),
        'background_url' => $uploadResult['url'],
        'background_public_id' => $uploadResult['public_id'],
        'background_original_name' => $_FILES['background_image']['name'] ?? 'Custom Background',
        'background_mime_type' => $validation['mime_type'] ?? '',
        'generated_at' => null,
        'player_order' => [],
        'local_file' => null,
        'local_url' => null,
    ];

    if (!writeJsonFile($paths['state_path'], $state)) {
        destroyCloudinaryImage($uploadResult['public_id']);
        echo json_encode(['success' => false, 'error' => 'The background uploaded, but its temporary state could not be saved.']);
        exit();
    }

    echo json_encode([
        'success' => true,
        'token' => $paths['token'],
        'background_url' => $uploadResult['url'],
        'original_name' => $state['background_original_name'],
        'expires_at' => $state['expires_at'],
    ]);
    exit();
}

if ($postAction === 'cleanup_custom') {
    header('Content-Type: application/json');

    $token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['custom_token'] ?? ''));
    if ($token === '') {
        echo json_encode(['success' => false, 'error' => 'Invalid custom banner token.']);
        exit();
    }

    $paths = buildCustomBannerPaths($token);
    $state = readJsonFile($paths['state_path']);

    if (!$state) {
        echo json_encode(['success' => true, 'cleaned' => true]);
        exit();
    }

    $cleanupResult = cleanupCustomBannerAssets($paths, $state);
    if (!$cleanupResult['success']) {
        echo json_encode(['success' => false, 'error' => $cleanupResult['error']]);
        exit();
    }

    echo json_encode(['success' => true, 'cleaned' => true]);
    exit();
}

if ($postAction === 'generate_custom') {
    header('Content-Type: application/json');

    $token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['custom_token'] ?? ''));
    $apiKey = $_ENV['HTML2IMAGE_API_KEY'] ?? '';

    if ($token === '') {
        echo json_encode(['success' => false, 'error' => 'Invalid custom banner token.']);
        exit();
    }

    if (empty($apiKey) || $apiKey === 'your_html2image_api_key_here') {
        echo json_encode(['success' => false, 'error' => 'HTML2IMAGE_API_KEY is not configured in .env']);
        exit();
    }

    if (!ensureDirectory($customBannerDir)) {
        echo json_encode(['success' => false, 'error' => 'Unable to prepare the custom banner directory.']);
        exit();
    }

    $paths = buildCustomBannerPaths($token);
    $state = readJsonFile($paths['state_path']);
    if (!$state || empty($state['background_url'])) {
        echo json_encode(['success' => false, 'error' => 'Custom background not found. Please upload the image again.']);
        exit();
    }

    if (isCustomBannerExpired($state)) {
        cleanupCustomBannerAssets($paths, $state);
        echo json_encode([
            'success' => false,
            'expired' => true,
            'error' => 'The custom banner expired after 5 minutes. Please upload the image again.',
        ]);
        exit();
    }

    if (file_exists($paths['file_path'])) {
        echo json_encode([
            'success' => true,
            'url' => $paths['file_url'],
            'cached' => true,
            'custom' => true,
            'custom_token' => $paths['token'],
            'file_name' => $paths['file_base'] . '.png',
            'expires_at' => $state['expires_at'] ?? getCustomBannerExpiresAt(),
        ]);
        exit();
    }

    $submittedOrder = json_decode($_POST['player_order'] ?? '[]', true);
    if (!is_array($submittedOrder)) {
        echo json_encode(['success' => false, 'error' => 'Invalid player order payload.']);
        exit();
    }

    $selectionResult = normalizeSelectedPlayers($submittedOrder, $players, $team);
    if (!$selectionResult['success']) {
        echo json_encode($selectionResult);
        exit();
    }

    $selectedPlayers = $selectionResult['players'];
    $html = buildBannerHtml($team, $selectedPlayers, $state['background_url'], $cloudName);
    $apiResult = generateBannerViaApi($html, $apiKey);

    if (!$apiResult['success']) {
        echo json_encode(['success' => false, 'error' => $apiResult['error']]);
        exit();
    }

    $imageData = file_get_contents($apiResult['link']);
    if ($imageData === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to download the generated custom banner image.']);
        exit();
    }

    if (!isPngBinary($imageData)) {
        echo json_encode(['success' => false, 'error' => 'The generated custom banner response was not a PNG image.']);
        exit();
    }

    if (file_put_contents($paths['file_path'], $imageData) === false) {
        echo json_encode(['success' => false, 'error' => 'Unable to save the generated custom banner image.']);
        exit();
    }

    $generatedUploadResult = uploadImageToCloudinary($paths['file_path'], 'team_banners/custom_generated');
    if (!$generatedUploadResult) {
        error_log('Custom generated banner Cloudinary upload failed for token ' . $paths['token']);
    }

    $state['generated_at'] = date(DATE_ATOM);
    $state['expires_at'] = getCustomBannerExpiresAt();
    $state['player_order'] = buildSelectionMetadata($selectedPlayers);
    $state['local_file'] = $paths['file_path'];
    $state['local_url'] = $paths['file_url'];
    $state['generated_url'] = $generatedUploadResult['url'] ?? null;
    $state['generated_public_id'] = $generatedUploadResult['public_id'] ?? null;

    if (!writeJsonFile($paths['state_path'], $state)) {
        if (!empty($generatedUploadResult['public_id'])) {
            destroyCloudinaryImage($generatedUploadResult['public_id']);
        }
        @unlink($paths['file_path']);
        echo json_encode(['success' => false, 'error' => 'Custom banner image was generated, but its state could not be saved.']);
        exit();
    }

    echo json_encode([
        'success' => true,
        'url' => $paths['file_url'],
        'cached' => false,
        'custom' => true,
        'custom_token' => $paths['token'],
        'file_name' => $paths['file_base'] . '.png',
        'expires_at' => $state['expires_at'],
    ]);
    exit();
}

if (
    $postAction === 'generate' &&
    isset($_POST['template_id'])
) {
    header('Content-Type: application/json');

    $templateId = (int) $_POST['template_id'];
    $apiKey = $_ENV['HTML2IMAGE_API_KEY'] ?? '';

    if (!isset($templates[$templateId])) {
        echo json_encode(['success' => false, 'error' => 'Invalid template.']);
        exit();
    }

    if (empty($apiKey) || $apiKey === 'your_html2image_api_key_here') {
        echo json_encode(['success' => false, 'error' => 'HTML2IMAGE_API_KEY is not configured in .env']);
        exit();
    }

    if (!is_dir($bannerDir) && !mkdir($bannerDir, 0755, true) && !is_dir($bannerDir)) {
        echo json_encode(['success' => false, 'error' => 'Unable to create the banner cache directory.']);
        exit();
    }

    $fileBase = 'template_' . $templateId;
    $filePath = $bannerDir . $fileBase . '.png';
    $fileUrl = $bannerUrl . $fileBase . '.png';
    $metaPath = $bannerDir . $fileBase . '_selection.json';

    if (file_exists($filePath)) {
        echo json_encode(['success' => true, 'url' => $fileUrl, 'cached' => true]);
        exit();
    }

    $submittedOrder = json_decode($_POST['player_order'] ?? '[]', true);
    if (!is_array($submittedOrder)) {
        echo json_encode(['success' => false, 'error' => 'Invalid player order payload.']);
        exit();
    }

    $selectionResult = normalizeSelectedPlayers($submittedOrder, $players, $team);
    if (!$selectionResult['success']) {
        echo json_encode($selectionResult);
        exit();
    }

    $selectedPlayers = $selectionResult['players'];
    $html = buildBannerHtml($team, $selectedPlayers, $templates[$templateId]['bg'], $cloudName);
    $apiResult = generateBannerViaApi($html, $apiKey);

    if (!$apiResult['success']) {
        echo json_encode(['success' => false, 'error' => $apiResult['error']]);
        exit();
    }

    $imageData = file_get_contents($apiResult['link']);
    if ($imageData === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to download the generated banner image.']);
        exit();
    }

    if (!isPngBinary($imageData)) {
        echo json_encode(['success' => false, 'error' => 'The generated banner response was not a PNG image.']);
        exit();
    }

    if (file_put_contents($filePath, $imageData) === false) {
        echo json_encode(['success' => false, 'error' => 'Unable to save the generated banner image.']);
        exit();
    }

    $metadata = [
        'team_id' => $team_id,
        'template_id' => $templateId,
        'generated_at' => date(DATE_ATOM),
        'player_order' => buildSelectionMetadata($selectedPlayers),
    ];

    if (file_put_contents($metaPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        @unlink($filePath);
        echo json_encode(['success' => false, 'error' => 'Banner image was generated, but the player order could not be saved.']);
        exit();
    }

    echo json_encode(['success' => true, 'url' => $fileUrl, 'cached' => false]);
    exit();
}

$playerSelectionData = array_map(static function (array $player): array {
    return [
        'id' => (int) $player['id'],
        'name' => $player['name'],
        'playing_role' => $player['playing_role'] ?? 'Player',
        'image_url' => $player['image_url'],
        'is_captain' => !empty($player['is_captain']),
        'is_vice_captain' => !empty($player['is_vice_captain']),
    ];
}, $players);

$page_title = 'Team Squad Banners - ' . htmlspecialchars($team['team_name']);
require_once '../includes/header.php';

$teamLogoDisplay = !empty($team['team_logo_public_id'])
    ? 'https://res.cloudinary.com/' . $cloudName . '/image/upload/' . $team['team_logo_public_id']
    : (!empty($team['team_logo']) ? '../uploads/teams/' . htmlspecialchars($team['team_logo']) : '../assets/images/default-player.png');
?>

<div class="banner-page pb-5">
    <div class="container">
        <div class="banner-page-nav fade-in">
            <a href="teams.php" class="btn rounded-pill team-back-link">
                <i class="fas fa-arrow-left me-2"></i>Back to Teams
            </a>
        </div>

        <div class="text-center mb-5 fade-in">
            <h1 class="display-4 fw-bold text-dark mb-2">Team Squad Banner</h1>
            <p class="lead text-muted mx-auto" style="max-width:650px;">
                Select a template to generate your team's official squad banner. Generated banners are cached
                automatically.
            </p>
            <div class="accent-line mx-auto mt-3"></div>
        </div>

        <div
            class="team-info-bar bg-white rounded-4 shadow-sm p-4 mb-5 d-flex align-items-center justify-content-between fade-in-up">
            <div class="d-flex align-items-center gap-3">
                <img src="<?= $teamLogoDisplay ?>" class="rounded-circle shadow" width="60" height="60"
                    style="object-fit:contain;" alt="Team logo">
                <div>
                    <h4 class="mb-0 fw-bold"><?= htmlspecialchars($team['team_name']) ?></h4>
                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($team['team_code'] ?? '') ?></span>
                    <span class="ms-2 text-muted small"><?= count($players) ?> players in squad</span>
                </div>
            </div>
        </div>

        <div id="alertArea" class="mb-4"></div>

        <div class="row g-4">
            <?php foreach ($templates as $tplId => $tpl):
                $cachedFile = $bannerDir . 'template_' . $tplId . '.png';
                $isCached = file_exists($cachedFile);
                $cachedBannerUrl = $bannerUrl . 'template_' . $tplId . '.png';
                $previewImageUrl = $isCached ? $cachedBannerUrl : $tpl['bg'];
                ?>
                <div class="col-6 col-md-6 col-xl-4 fade-in template-grid-col"
                    style="animation-delay:<?= ($tplId - 1) * 0.08 ?>s;">
                    <div class="card tpl-card h-100 border-0 shadow-lg rounded-4 overflow-hidden"
                        data-tpl-id="<?= $tplId ?>" data-cached="<?= $isCached ? '1' : '0' ?>"
                        data-banner-url="<?= $isCached ? htmlspecialchars($cachedBannerUrl, ENT_QUOTES) : '' ?>"
                        data-bg-url="<?= htmlspecialchars($tpl['bg'], ENT_QUOTES) ?>"
                        data-preview-url="<?= htmlspecialchars($previewImageUrl, ENT_QUOTES) ?>">
                        <div class="tpl-img-wrap">
                            <img src="<?= htmlspecialchars($previewImageUrl) ?>" class="tpl-bg-img"
                                alt="<?= htmlspecialchars($tpl['name']) ?>">
                            <div class="tpl-overlay">
                                <button class="btn btn-light rounded-pill px-4 fw-semibold quick-preview-btn"
                                    data-tpl-id="<?= $tplId ?>" type="button">
                                    <i class="fas fa-eye me-2"></i>Quick Preview
                                </button>
                            </div>
                            <?php if ($isCached): ?>
                                <div class="cached-badge"><i class="fas fa-check-circle me-1"></i>Generated</div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-4 text-center">
                            <h5 class="fw-bold mb-1"><?= htmlspecialchars($tpl['name']) ?></h5>
                            <p class="text-muted small mb-3">Template <?= $tplId ?></p>
                            <div class="d-flex gap-2">
                                <button class="btn btn-primary rounded-pill flex-fill fw-semibold action-btn"
                                    data-action="preview" data-tpl-id="<?= $tplId ?>" type="button">
                                    <i class="fas fa-search-plus me-2"></i>Preview
                                </button>
                                <button class="btn btn-outline-primary rounded-pill flex-fill fw-semibold action-btn"
                                    data-action="download" data-tpl-id="<?= $tplId ?>" type="button">
                                    <i class="fas fa-download me-2"></i>Download
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="col-6 col-md-6 col-xl-4 fade-in template-grid-col" style="animation-delay:0.88s;">
                <div class="card tpl-card custom-banner-card h-100 border-0 shadow-lg rounded-4 overflow-hidden"
                    id="customBannerCard" data-custom-ready="0">
                    <div class="tpl-img-wrap custom-banner-surface" id="customBannerSurface">
                        <div class="custom-banner-empty" id="customBannerEmpty">
                            <div class="custom-surface-badge">
                                <i class="fas fa-cloud-upload-alt me-2"></i>Custom Upload
                            </div>
                            <div class="custom-plus-orb">
                                <i class="fas fa-plus"></i>
                            </div>
                            <div class="custom-surface-caption">Tap to upload your background</div>
                        </div>
                        <div class="custom-banner-preview d-none" id="customBannerPreviewPane">
                            <img src="" alt="Custom background preview" class="tpl-bg-img" id="customBannerPreviewImg">
                            <div class="custom-banner-overlay-panel">
                                <div>
                                    <div class="custom-banner-overlay-title" id="customBannerOverlayTitle">Custom Background Ready</div>
                                    <div class="custom-banner-overlay-subtitle" id="customBannerOverlaySubtitle">Preview or download your temporary banner</div>
                                </div>
                                <button class="btn btn-light btn-sm rounded-pill px-3" id="customReplaceBtn" type="button">
                                    <i class="fas fa-image me-2"></i>Change Image
                                </button>
                            </div>
                        </div>
                        <div class="custom-upload-loader d-none" id="customUploadLoader">
                            <div class="spinner-border text-primary mb-3" role="status"></div>
                            <div class="fw-semibold" id="customUploadLoaderText">Uploading background...</div>
                        </div>
                    </div>
                    <div class="card-body p-4 text-center">
                        <h5 class="fw-bold mb-1" id="customBannerCardTitle">Create Custom Banner</h5>
                        <p class="text-muted small mb-3" id="customBannerCardSubtitle">Upload your own background image</p>
                        <div class="d-flex gap-2 d-none" id="customBannerActionGroup">
                            <button class="btn btn-primary rounded-pill flex-fill fw-semibold" id="customPreviewBtn" type="button">
                                <i class="fas fa-search-plus me-2"></i>Preview
                            </button>
                            <button class="btn btn-outline-primary rounded-pill flex-fill fw-semibold" id="customDownloadBtn"
                                type="button">
                                <i class="fas fa-download me-2"></i>Download
                            </button>
                        </div>
                    </div>
                </div>
                <input type="file" id="customBannerInput" class="d-none" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="selectionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable selection-modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden selection-modal-content">
            <div class="modal-header border-0 pb-0 px-4 pt-4">
                <div>
                    <h5 class="modal-title fw-bold mb-1">Select Playing XI for Team Banner</h5>
                    <p class="text-muted small mb-0" id="selectionHelpText"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-4 pb-3 pt-3">
                <div class="selection-summary">
                    <div class="summary-pill">
                        <span id="selectedCount">0</span> selected
                    </div>
                    <div class="summary-pill summary-pill-subtle">
                        <span id="substituteCount">0</span> substitutes
                    </div>
                </div>
                <div id="selectionValidation" class="alert alert-warning d-none rounded-4 border-0 shadow-sm mt-3 mb-0">
                </div>
                <div id="playerSelectionList" class="player-selection-list mt-3"></div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-0">
                <button class="btn btn-outline-secondary rounded-pill px-4" type="button"
                    data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary rounded-pill px-4" id="confirmSelectionBtn" type="button">
                    Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold" id="previewModalTitle">Banner Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3 text-center">
                <div class="d-flex align-items-center justify-content-center py-5" id="previewLoader">
                    <div class="spinner-border text-primary me-3"></div>
                    <span class="fw-semibold">Generating banner, please wait...</span>
                </div>
                <img id="previewModalImg" src="" alt="Banner preview" class="img-fluid rounded-3 shadow d-none"
                    style="max-height:80vh;">
            </div>
            <div class="modal-footer border-0">
                <button class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal" type="button">Close</button>
                <button id="modalDownloadBtn" type="button" class="btn btn-primary rounded-pill px-4 d-none">
                    <i class="fas fa-download me-2"></i>Download
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="quickPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold" id="quickPreviewTitle">Template Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3 text-center">
                <img id="quickPreviewImg" src="" class="img-fluid rounded-3 shadow" style="max-height:75vh;"
                    alt="Template preview">
            </div>
        </div>
    </div>
</div>

<style>
    body {
        background: #f1f5f9;
    }

    .banner-page {
        padding-top: .6rem;
    }

    .accent-line {
        width: 60px;
        height: 4px;
        background: #3b82f6;
        border-radius: 2px;
    }

    .team-info-bar {
        border-left: 5px solid #3b82f6;
    }

    .banner-page-nav {
        margin-bottom: .9rem;
    }

    .team-back-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: .62rem 1rem;
        border: 1px solid rgba(148, 163, 184, 0.32);
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        color: #475569;
        font-size: .84rem;
        font-weight: 600;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
        transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease, color .25s ease;
    }

    .team-back-link:hover {
        color: #1d4ed8;
        border-color: rgba(59, 130, 246, 0.35);
        box-shadow: 0 14px 28px rgba(37, 99, 235, 0.12);
        transform: translateY(-1px);
    }

    .tpl-card {
        transition: transform 0.35s cubic-bezier(.4, 0, .2, 1);
    }

    .tpl-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 40px -10px rgba(0, 0, 0, .18) !important;
    }

    .tpl-img-wrap {
        position: relative;
        height: 220px;
        overflow: hidden;
        background: #e2e8f0;
    }

    .tpl-bg-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform .6s ease;
    }

    .tpl-card:hover .tpl-bg-img {
        transform: scale(1.08);
    }

    .tpl-overlay {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, .42);
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity .3s;
    }

    .tpl-card:hover .tpl-overlay {
        opacity: 1;
    }

    .action-btn,
    #customPreviewBtn,
    #customDownloadBtn {
        min-height: 40px;
        padding: .55rem .7rem;
        font-size: .8rem;
        line-height: 1.15;
    }

    .action-btn i,
    #customPreviewBtn i,
    #customDownloadBtn i {
        font-size: .74rem;
    }

    .cached-badge {
        position: absolute;
        top: 12px;
        right: 12px;
        background: #10b981;
        color: #fff;
        padding: 4px 12px;
        border-radius: 50px;
        font-size: .75rem;
        font-weight: 700;
    }

    .custom-banner-card {
        border: 2px dashed rgba(59, 130, 246, 0.32) !important;
        background:
            radial-gradient(circle at top left, rgba(191, 219, 254, 0.36), transparent 34%),
            linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    }

    .custom-banner-card:hover {
        border-color: rgba(37, 99, 235, 0.55) !important;
    }

    .custom-banner-surface {
        position: relative;
        isolation: isolate;
        display: flex;
        align-items: center;
        justify-content: center;
        background:
            radial-gradient(circle at 20% 20%, rgba(96, 165, 250, 0.28), transparent 32%),
            radial-gradient(circle at 78% 18%, rgba(191, 219, 254, 0.6), transparent 24%),
            linear-gradient(135deg, rgba(219, 234, 254, 0.98), rgba(239, 246, 255, 0.98) 48%, rgba(248, 250, 252, 0.98) 100%);
    }

    .custom-banner-surface::before,
    .custom-banner-surface::after {
        content: "";
        position: absolute;
        inset: auto;
        pointer-events: none;
        z-index: 0;
    }

    .custom-banner-surface::before {
        width: 118px;
        height: 118px;
        top: 18px;
        right: -26px;
        border-radius: 28px;
        background: linear-gradient(135deg, rgba(37, 99, 235, 0.16), rgba(96, 165, 250, 0.04));
        transform: rotate(18deg);
    }

    .custom-banner-surface::after {
        width: 140px;
        height: 2px;
        left: -18px;
        bottom: 34px;
        background: linear-gradient(90deg, rgba(59, 130, 246, 0), rgba(59, 130, 246, 0.3), rgba(59, 130, 246, 0));
        box-shadow: 0 18px 0 rgba(96, 165, 250, 0.12);
    }

    .custom-banner-empty,
    .custom-upload-loader {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 24px;
    }

    .custom-banner-empty {
        z-index: 1;
        transition: transform .3s ease, opacity .3s ease;
    }

    .custom-banner-card:hover .custom-banner-empty {
        transform: translateY(-4px);
    }

    .custom-surface-badge {
        display: inline-flex;
        align-items: center;
        padding: 6px 12px;
        border-radius: 999px;
        margin-bottom: 14px;
        background: rgba(255, 255, 255, 0.82);
        color: #2563eb;
        font-size: .74rem;
        font-weight: 700;
        letter-spacing: .02em;
        box-shadow: 0 8px 18px rgba(37, 99, 235, 0.08);
    }

    .custom-plus-orb {
        width: 74px;
        height: 74px;
        border-radius: 22px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 12px;
        background: linear-gradient(135deg, #2563eb, #60a5fa);
        color: #ffffff;
        font-size: 1.9rem;
        box-shadow: 0 18px 38px rgba(37, 99, 235, 0.24);
        transition: transform .35s ease, box-shadow .35s ease;
    }

    .custom-banner-card:hover .custom-plus-orb {
        transform: scale(1.08) rotate(90deg);
        box-shadow: 0 24px 44px rgba(37, 99, 235, 0.32);
    }

    .custom-surface-caption {
        color: #475569;
        font-size: .78rem;
        font-weight: 700;
        line-height: 1.35;
    }

    #customBannerCardTitle {
        font-size: 1rem;
        line-height: 1.25;
    }

    #customBannerCardSubtitle {
        font-size: .8rem;
        line-height: 1.45;
        max-width: 190px;
        margin-left: auto;
        margin-right: auto;
    }

    .custom-banner-preview {
        position: absolute;
        inset: 0;
    }

    .custom-banner-overlay-panel {
        position: absolute;
        inset: auto 14px 14px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 14px 16px;
        border-radius: 22px;
        background: linear-gradient(135deg, rgba(15, 23, 42, 0.82), rgba(30, 41, 59, 0.62));
        color: #ffffff;
        backdrop-filter: blur(10px);
    }

    .custom-banner-overlay-title {
        font-size: .9rem;
        font-weight: 800;
    }

    .custom-banner-overlay-subtitle {
        font-size: .78rem;
        color: rgba(255, 255, 255, 0.78);
    }

    .custom-upload-loader {
        background: rgba(255, 255, 255, 0.9);
        z-index: 2;
    }

    .selection-modal-content {
        background:
            radial-gradient(circle at top left, rgba(59, 130, 246, 0.12), transparent 34%),
            linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    }

    #selectionModal.fade .modal-dialog {
        transform: translateY(18px) scale(.98);
        transition: transform .25s ease, opacity .25s ease;
    }

    #selectionModal.show .modal-dialog {
        transform: translateY(0) scale(1);
    }

    .selection-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
    }

    .summary-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 16px;
        border-radius: 999px;
        background: #dbeafe;
        color: #1d4ed8;
        font-weight: 700;
    }

    .summary-pill-subtle {
        background: #ecfeff;
        color: #0f766e;
    }

    .player-selection-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .player-select-row {
        display: grid;
        grid-template-columns: auto 72px minmax(0, 1fr) 62px;
        gap: 16px;
        align-items: center;
        padding: 14px 18px;
        border-radius: 22px;
        border: 1px solid rgba(148, 163, 184, 0.25);
        background: rgba(255, 255, 255, 0.92);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease, background .2s ease;
        cursor: pointer;
    }

    .player-select-row:hover {
        transform: translateY(-2px);
        box-shadow: 0 16px 28px rgba(15, 23, 42, 0.08);
        border-color: rgba(59, 130, 246, 0.26);
    }

    .player-select-row.selected {
        border-color: rgba(37, 99, 235, 0.42);
        background: linear-gradient(135deg, rgba(239, 246, 255, 0.96), rgba(255, 255, 255, 0.98));
    }

    .player-select-row.locked {
        cursor: default;
    }

    .player-select-checkbox {
        width: 1.2rem;
        height: 1.2rem;
        margin: 0;
        cursor: pointer;
    }

    .player-select-checkbox:disabled {
        cursor: not-allowed;
    }

    .player-select-avatar {
        width: 72px;
        height: 72px;
        border-radius: 20px;
        overflow: hidden;
        background: #e2e8f0;
        box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.2);
    }

    .player-select-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        object-position: top;
    }

    .player-select-info {
        min-width: 0;
    }

    .player-select-name {
        font-size: 1rem;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 4px;
        word-break: break-word;
    }

    .player-select-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        color: #64748b;
        font-size: .875rem;
    }

    .player-role-chip,
    .player-lead-chip {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        border-radius: 999px;
        font-weight: 700;
        font-size: .75rem;
        letter-spacing: .02em;
    }

    .player-role-chip {
        background: #f1f5f9;
        color: #334155;
    }

    .player-lead-chip.captain {
        background: #fef3c7;
        color: #92400e;
    }

    .player-lead-chip.vice-captain {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .player-order-badge {
        width: 48px;
        height: 48px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #eff6ff;
        color: #1d4ed8;
        font-size: 1rem;
        font-weight: 800;
        box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.16);
    }

    .empty-selection-state {
        padding: 32px 20px;
        text-align: center;
        border-radius: 24px;
        background: #ffffff;
        color: #64748b;
        border: 1px dashed rgba(148, 163, 184, 0.45);
        font-weight: 600;
    }

    .fade-in {
        animation: fadeIn .7s ease-out forwards;
        opacity: 0;
    }

    .fade-in-up {
        animation: fadeInUp .7s ease-out forwards;
        opacity: 0;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(18px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @media (max-width: 767.98px) {
        .selection-summary {
            flex-direction: column;
        }

        .player-select-row {
            grid-template-columns: auto 58px minmax(0, 1fr);
            gap: 12px;
        }

        .player-order-badge {
            grid-column: 2 / 4;
            justify-self: end;
            width: 44px;
            height: 44px;
            border-radius: 14px;
        }

        .player-select-avatar {
            width: 58px;
            height: 58px;
            border-radius: 16px;
        }
    }

    @media (max-width: 479.98px) {
        .banner-page {
            padding-top: .15rem;
        }

        .display-4 {
            font-size: 2rem;
        }

        .lead {
            font-size: 1rem;
        }

        .team-info-bar {
            padding: 1rem !important;
            margin-bottom: 1.5rem !important;
            flex-direction: column;
            align-items: flex-start !important;
            gap: 14px;
        }

        .team-info-bar>div {
            width: 100%;
            align-items: flex-start !important;
            gap: 12px !important;
        }

        .team-info-bar img {
            width: 52px;
            height: 52px;
        }

        .team-info-bar h4 {
            font-size: 1.1rem;
            line-height: 1.3;
        }

        .team-info-bar .badge {
            font-size: .72rem;
        }

        .team-info-bar .text-muted.small {
            display: block;
            margin: 6px 0 0 !important;
            font-size: .82rem;
        }

        .banner-page-nav {
            margin-bottom: .65rem;
        }

        .team-back-link {
            padding: .48rem .82rem;
            font-size: .76rem;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
        }

        .row.g-4 {
            --bs-gutter-x: .85rem;
            --bs-gutter-y: .85rem;
        }

        .template-grid-col {
            width: 50%;
            flex: 0 0 auto;
        }

        .tpl-img-wrap {
            height: 124px;
        }

        .card-body.p-4 {
            padding: 1rem !important;
        }

        .tpl-card h5 {
            font-size: 1rem;
        }

        .tpl-card p.text-muted.small,
        #customBannerCardSubtitle {
            font-size: .78rem;
            line-height: 1.45;
        }

        .tpl-card .d-flex.gap-2,
        #customBannerActionGroup {
            gap: .45rem !important;
        }

        .action-btn,
        #customPreviewBtn,
        #customDownloadBtn {
            min-height: 31px;
            padding: .42rem .3rem;
            font-size: .66rem;
            border-radius: 999px !important;
            font-weight: 700 !important;
        }

        .action-btn i,
        #customPreviewBtn i,
        #customDownloadBtn i {
            margin-right: .2rem !important;
            font-size: .62rem;
        }

        .quick-preview-btn {
            padding: .55rem .9rem;
            font-size: .8rem;
        }

        .cached-badge {
            top: 10px;
            right: 10px;
            padding: 4px 10px;
            font-size: .65rem;
        }

        .custom-banner-empty,
        .custom-upload-loader {
            padding: 14px;
        }

        .custom-surface-badge {
            padding: 5px 10px;
            margin-bottom: 10px;
            font-size: .64rem;
        }

        .custom-plus-orb {
            width: 58px;
            height: 58px;
            border-radius: 18px;
            margin-bottom: 8px;
            font-size: 1.4rem;
        }

        .custom-surface-caption {
            font-size: .67rem;
        }

        #customBannerCardTitle {
            font-size: .88rem;
        }

        #customBannerCardSubtitle {
            max-width: 140px;
            font-size: .68rem;
        }

        .custom-banner-overlay-panel {
            inset: auto 8px 8px 8px;
            gap: 8px;
            padding: 10px 12px;
            border-radius: 16px;
        }

        .custom-banner-overlay-title {
            font-size: .76rem;
        }

        .custom-banner-overlay-subtitle {
            font-size: .68rem;
        }

        #customReplaceBtn {
            padding: .42rem .72rem;
            font-size: .74rem;
        }
    }
</style>

<script>
    function buildCacheBustedImageUrl(url) {
        const downloadUrl = new URL(url, window.location.href);
        downloadUrl.searchParams.set('download_ts', Date.now().toString());
        return downloadUrl.href;
    }

    async function forceDownloadImage(url, filename) {
        try {
            if (!url) {
                throw new Error("Download failed");
            }

            const response = await fetch(buildCacheBustedImageUrl(url), {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'image/png'
                }
            });

            if (!response.ok || response.redirected) {
                throw new Error("Download failed");
            }

            const contentType = (response.headers.get('content-type') || '').toLowerCase();
            if (!contentType.startsWith('image/png')) {
                throw new Error("Download failed");
            }

            const blob = await response.blob();
            const pngBlob = blob.type === 'image/png' ? blob : new Blob([blob], { type: 'image/png' });

            const blobUrl = window.URL.createObjectURL(pngBlob);

            const a = document.createElement("a");
            a.href = blobUrl;
            a.download = filename || "team-banner.png";
            a.rel = 'noopener';
            a.style.display = 'none';

            document.body.appendChild(a);
            a.click();

            document.body.removeChild(a);

            window.setTimeout(() => {
                window.URL.revokeObjectURL(blobUrl);
            }, 1000);

            return true;
        } catch (err) {
            console.error(err);
            alert("Image download failed");
            return false;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const TEAM_ID = <?= $team_id ?>;
        const PLAYER_LIST = <?= json_encode($playerSelectionData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const tplBgs = <?= json_encode(array_map(static fn($template) => $template['bg'], $templates), JSON_UNESCAPED_SLASHES) ?>;
        const tplNames = <?= json_encode(array_map(static fn($template) => $template['name'], $templates), JSON_UNESCAPED_UNICODE) ?>;
        const CUSTOM_MAX_UPLOAD_SIZE = <?= CUSTOM_BANNER_MAX_FILE_SIZE ?>;
        const CUSTOM_ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
        const CUSTOM_ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

        const MIN_SELECTION = PLAYER_LIST.length >= 11 ? 11 : PLAYER_LIST.length;
        const MAX_SELECTION = 15;

        const alertArea = document.getElementById('alertArea');
        const selectionModalEl = document.getElementById('selectionModal');
        const previewModalEl = document.getElementById('previewModal');
        const quickPreviewModalEl = document.getElementById('quickPreviewModal');

        if (!window.bootstrap || !selectionModalEl || !previewModalEl || !quickPreviewModalEl) {
            return;
        }

        const selectionModal = new bootstrap.Modal(selectionModalEl);
        const previewModal = new bootstrap.Modal(previewModalEl);
        const quickPreviewModal = new bootstrap.Modal(quickPreviewModalEl);

        const selectionHelpText = document.getElementById('selectionHelpText');
        const selectionValidation = document.getElementById('selectionValidation');
        const playerSelectionList = document.getElementById('playerSelectionList');
        const selectedCountEl = document.getElementById('selectedCount');
        const substituteCountEl = document.getElementById('substituteCount');
        const confirmSelectionBtn = document.getElementById('confirmSelectionBtn');

        const previewLoader = document.getElementById('previewLoader');
        const previewImg = document.getElementById('previewModalImg');
        const previewTitle = document.getElementById('previewModalTitle');
        const modalDownloadBtn = document.getElementById('modalDownloadBtn');
        const quickPreviewImg = document.getElementById('quickPreviewImg');
        const quickPreviewTitle = document.getElementById('quickPreviewTitle');
        const customBannerCard = document.getElementById('customBannerCard');
        const customBannerSurface = document.getElementById('customBannerSurface');
        const customBannerInput = document.getElementById('customBannerInput');
        const customBannerEmpty = document.getElementById('customBannerEmpty');
        const customBannerPreviewPane = document.getElementById('customBannerPreviewPane');
        const customBannerPreviewImg = document.getElementById('customBannerPreviewImg');
        const customBannerCardTitle = document.getElementById('customBannerCardTitle');
        const customBannerCardSubtitle = document.getElementById('customBannerCardSubtitle');
        const customBannerActionGroup = document.getElementById('customBannerActionGroup');
        const customPreviewBtn = document.getElementById('customPreviewBtn');
        const customDownloadBtn = document.getElementById('customDownloadBtn');
        const customReplaceBtn = document.getElementById('customReplaceBtn');
        const customUploadLoader = document.getElementById('customUploadLoader');
        const customUploadLoaderText = document.getElementById('customUploadLoaderText');
        const customBannerOverlayTitle = document.getElementById('customBannerOverlayTitle');
        const customBannerOverlaySubtitle = document.getElementById('customBannerOverlaySubtitle');
        const loadedPreviewUrls = new Map();

        let selectedPlayerIds = [];
        let pendingRequest = null;
        let customBannerState = createEmptyCustomBannerState();
        let customBannerExpiryTimerId = null;
        let activeDownloadButton = null;

        function createEmptyCustomBannerState() {
            return {
                token: '',
                backgroundUrl: '',
                originalName: '',
                bannerUrl: '',
                cached: false,
                generated: false,
                fileName: '',
                expiresAt: '',
                isUploading: false
            };
        }

        function getTemplatePreviewKey(templateId) {
            return `template:${templateId}`;
        }

        function getTemplateCard(templateId) {
            return document.querySelector(`.tpl-card[data-tpl-id="${templateId}"]`);
        }

        function updateTemplatePreviewSource(templateId, previewUrl) {
            const tplCard = getTemplateCard(templateId);
            if (!tplCard || !previewUrl) {
                return;
            }

            tplCard.dataset.previewUrl = previewUrl;
            const templatePreviewImg = tplCard.querySelector('.tpl-bg-img');
            if (templatePreviewImg) {
                templatePreviewImg.src = previewUrl;
            }
        }

        function getTemplateQuickPreviewData(templateId) {
            const tplCard = getTemplateCard(templateId);
            const hasGeneratedBanner = tplCard && tplCard.dataset.cached === '1' && tplCard.dataset.bannerUrl;
            const imageUrl = tplCard
                ? (tplCard.dataset.previewUrl || tplCard.dataset.bannerUrl || tplCard.dataset.bgUrl || tplBgs[templateId])
                : tplBgs[templateId];
            const title = hasGeneratedBanner
                ? `${tplNames[templateId] || 'Template'} Generated Banner`
                : `${tplNames[templateId] || 'Template'} Background`;

            return { imageUrl, title };
        }

        function getCustomPreviewKey(token) {
            return `custom:${token}`;
        }

        function getLeadRank(player) {
            if (player.is_captain) {
                return 1;
            }
            if (player.is_vice_captain) {
                return 2;
            }
            return 3;
        }

        function getDefaultSelectedPlayerIds() {
            return PLAYER_LIST
                .filter(player => player.is_captain || player.is_vice_captain)
                .sort((a, b) => getLeadRank(a) - getLeadRank(b))
                .map(player => String(player.id));
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function isAllowedCustomImage(file) {
            if (!file) {
                return false;
            }

            if (file.type && CUSTOM_ALLOWED_TYPES.includes(file.type)) {
                return true;
            }

            const fileName = file.name || '';
            const extension = fileName.includes('.') ? fileName.split('.').pop().toLowerCase() : '';
            return CUSTOM_ALLOWED_EXTENSIONS.includes(extension);
        }

        function showAlert(type, message) {
            const iconClass = type === 'danger'
                ? 'fa-exclamation-circle'
                : (type === 'warning' ? 'fa-exclamation-triangle' : 'fa-check-circle');
            alertArea.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show rounded-3 shadow-sm border-0">
                <i class="fas ${iconClass} me-2"></i>${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>`;
        }

        function clearSelectionValidation() {
            selectionValidation.classList.add('d-none');
            selectionValidation.textContent = '';
        }

        function showSelectionValidation(message) {
            selectionValidation.textContent = message;
            selectionValidation.classList.remove('d-none');
        }

        function buildSelectionHelpText() {
            if (PLAYER_LIST.length === 0) {
                return 'No squad players are currently available for this team.';
            }

            if (MIN_SELECTION === MAX_SELECTION) {
                return `Select ${MIN_SELECTION} players. Captain and vice-captain stay locked at orders 1 and 2.`;
            }

            return `Select ${MIN_SELECTION} to ${MAX_SELECTION} players. Captain and vice-captain stay locked at orders 1 and 2.`;
        }

        function renderPlayerSelectionList() {
            if (PLAYER_LIST.length === 0) {
                playerSelectionList.innerHTML = `<div class="empty-selection-state">No squad players are available for this team.</div>`;
                updateSelectionSummary();
                return;
            }

            playerSelectionList.innerHTML = PLAYER_LIST.map(player => {
                const playerId = String(player.id);
                const isLocked = player.is_captain || player.is_vice_captain;
                const leadBadge = player.is_captain
                    ? `<span class="player-lead-chip captain">Captain</span>`
                    : (player.is_vice_captain ? `<span class="player-lead-chip vice-captain">Vice Captain</span>` : '');
                const playerName = escapeHtml(player.name);
                const playerRole = escapeHtml(player.playing_role);
                const playerImage = escapeHtml(player.image_url);

                return `
                    <div class="player-select-row ${isLocked ? 'locked' : ''}" data-player-id="${playerId}">
                        <input class="form-check-input player-select-checkbox" type="checkbox" data-player-id="${playerId}"
                            ${isLocked ? 'disabled' : ''}>
                        <div class="player-select-avatar">
                            <img src="${playerImage}" alt="${playerName}">
                        </div>
                        <div class="player-select-info">
                            <div class="player-select-name">${playerName}</div>
                            <div class="player-select-meta">
                                <span class="player-role-chip">${playerRole}</span>
                                ${leadBadge}
                            </div>
                        </div>
                        <div class="player-order-badge" data-order-for="${playerId}">-</div>
                    </div>
                `;
            }).join('');

            updateSelectionSummary();
        }

        function updateSelectionSummary() {
            const selectedCount = selectedPlayerIds.length;
            selectedCountEl.textContent = selectedCount;
            substituteCountEl.textContent = Math.max(0, selectedCount - 11);
            confirmSelectionBtn.disabled = PLAYER_LIST.length === 0 || selectedCount < MIN_SELECTION;

            playerSelectionList.querySelectorAll('.player-select-row').forEach(row => {
                const playerId = row.dataset.playerId;
                const checkbox = row.querySelector('.player-select-checkbox');
                const orderBadge = row.querySelector('.player-order-badge');
                const orderIndex = selectedPlayerIds.indexOf(playerId);
                const isSelected = orderIndex !== -1;

                row.classList.toggle('selected', isSelected);
                if (checkbox) {
                    checkbox.checked = isSelected;
                }
                if (orderBadge) {
                    orderBadge.textContent = isSelected ? String(orderIndex + 1) : '-';
                }
            });
        }

        function resetSelectionState() {
            selectedPlayerIds = getDefaultSelectedPlayerIds();
            selectionHelpText.textContent = buildSelectionHelpText();
            clearSelectionValidation();
            renderPlayerSelectionList();
        }

        function markTemplateAsCached(templateId) {
            const tplCard = getTemplateCard(templateId);
            if (!tplCard) {
                return;
            }

            tplCard.dataset.cached = '1';
            const imgWrap = tplCard.querySelector('.tpl-img-wrap');
            if (!imgWrap.querySelector('.cached-badge')) {
                const badge = document.createElement('div');
                badge.className = 'cached-badge';
                badge.innerHTML = '<i class="fas fa-check-circle me-1"></i>Generated';
                imgWrap.appendChild(badge);
            }
        }

        function setTemplateBannerUrl(templateId, bannerUrl, previewUrl = bannerUrl) {
            const tplCard = getTemplateCard(templateId);
            if (!tplCard) {
                return;
            }

            tplCard.dataset.bannerUrl = bannerUrl;
            updateTemplatePreviewSource(templateId, previewUrl);
        }

        function getTemplateDownloadUrl(templateId) {
            return new URL(`team_banner.php?team_id=${TEAM_ID}&action=download_template&template_id=${encodeURIComponent(templateId)}`, window.location.href).href;
        }

        function setModalDownloadState(options) {
            const {
                url = '#',
                downloadUrl = url,
                fileName = 'banner.png',
                isCustom = false,
                customToken = ''
            } = options || {};

            modalDownloadBtn.dataset.isCustom = isCustom ? '1' : '0';
            modalDownloadBtn.dataset.customToken = customToken;
            modalDownloadBtn.dataset.bannerUrl = url;
            modalDownloadBtn.dataset.downloadUrl = downloadUrl;
            modalDownloadBtn.dataset.fileName = fileName;
            modalDownloadBtn.classList.remove('d-none');
        }

        function resetModalDownloadState() {
            modalDownloadBtn.dataset.isCustom = '0';
            modalDownloadBtn.dataset.customToken = '';
            modalDownloadBtn.dataset.bannerUrl = '';
            modalDownloadBtn.dataset.downloadUrl = '';
            modalDownloadBtn.dataset.fileName = '';
            modalDownloadBtn.classList.add('d-none');
        }

        function setDownloadButtonLoading(button, isLoading) {
            if (!button) {
                return;
            }

            if (isLoading) {
                if (!button.dataset.originalHtml) {
                    button.dataset.originalHtml = button.innerHTML;
                }
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Downloading...';
                return;
            }

            if (button.dataset.originalHtml) {
                button.innerHTML = button.dataset.originalHtml;
                delete button.dataset.originalHtml;
            }
            button.disabled = false;
        }

        function markDownloadStarted(button = activeDownloadButton) {
            setDownloadButtonLoading(button, true);
            window.setTimeout(() => {
                setDownloadButtonLoading(button, false);
                if (activeDownloadButton === button) {
                    activeDownloadButton = null;
                }
            }, 1400);
        }

        async function triggerDownload(url, templateId) {
            const fileName = `squad_banner_template_${templateId}.png`;
            const downloadUrl = getTemplateDownloadUrl(templateId);
            const downloaded = await forceDownloadImage(downloadUrl || url, fileName);

            if (downloaded) {
                markDownloadStarted();
                return true;
            }

            setDownloadButtonLoading(activeDownloadButton, false);
            activeDownloadButton = null;
            return false;
        }

        function clearCustomBannerExpiryTimer() {
            if (customBannerExpiryTimerId !== null) {
                window.clearTimeout(customBannerExpiryTimerId);
                customBannerExpiryTimerId = null;
            }
        }

        function getCustomBannerExpiryTimeMs() {
            if (!customBannerState.expiresAt) {
                return 0;
            }

            const parsedTime = Date.parse(customBannerState.expiresAt);
            return Number.isFinite(parsedTime) ? parsedTime : 0;
        }

        async function handleExpiredCustomBanner(message = 'Temporary custom banner files are removed after 5 minutes. Upload the image again.') {
            if (!customBannerState.token) {
                return false;
            }

            const expiredToken = customBannerState.token;
            clearCustomBannerExpiryTimer();

            try {
                await cleanupCustomBannerState({ keepFileInputValue: true });
            } catch (cleanupError) {
                console.error('Custom banner expiry cleanup failed:', cleanupError);
                loadedPreviewUrls.delete(getCustomPreviewKey(expiredToken));
                customBannerState = createEmptyCustomBannerState();
                renderCustomBannerCard();
            }

            if (pendingRequest && pendingRequest.kind === 'custom') {
                pendingRequest = null;
            }

            resetModalDownloadState();
            previewModal.hide();
            selectionModal.hide();
            showAlert('warning', `<strong>Custom banner expired.</strong> ${message}`);
            return true;
        }

        function scheduleCustomBannerExpiryCleanup() {
            clearCustomBannerExpiryTimer();

            if (!customBannerState.token) {
                return;
            }

            const expiryTimeMs = getCustomBannerExpiryTimeMs();
            if (!expiryTimeMs) {
                return;
            }

            const delayMs = expiryTimeMs - Date.now();
            if (delayMs <= 0) {
                customBannerExpiryTimerId = window.setTimeout(() => {
                    void handleExpiredCustomBanner();
                }, 0);
                return;
            }

            customBannerExpiryTimerId = window.setTimeout(() => {
                void handleExpiredCustomBanner();
            }, delayMs);
        }

        async function ensureCustomBannerIsActive() {
            if (!customBannerState.token) {
                return true;
            }

            const expiryTimeMs = getCustomBannerExpiryTimeMs();
            if (!expiryTimeMs || expiryTimeMs > Date.now()) {
                return true;
            }

            await handleExpiredCustomBanner();
            return false;
        }

        function setCustomUploadLoading(isLoading, message) {
            if (!customUploadLoader || !customUploadLoaderText) {
                return;
            }

            customBannerState.isUploading = isLoading;
            customUploadLoaderText.textContent = message || 'Uploading background...';
            customUploadLoader.classList.toggle('d-none', !isLoading);
            if (customBannerSurface) {
                customBannerSurface.classList.toggle('pe-none', isLoading);
            }
            if (customPreviewBtn) {
                customPreviewBtn.disabled = isLoading;
            }
            if (customDownloadBtn) {
                customDownloadBtn.disabled = isLoading;
            }
        }

        function renderCustomBannerCard() {
            const hasBackground = customBannerState.backgroundUrl !== '';
            const hasGeneratedBanner = customBannerState.bannerUrl !== '';

            if (customBannerCard) {
                customBannerCard.dataset.customReady = hasBackground ? '1' : '0';
            }

            if (customBannerEmpty) {
                customBannerEmpty.classList.toggle('d-none', hasBackground);
            }
            if (customBannerPreviewPane) {
                customBannerPreviewPane.classList.toggle('d-none', !hasBackground);
            }
            if (customBannerPreviewImg) {
                customBannerPreviewImg.src = hasBackground ? customBannerState.backgroundUrl : '';
            }
            if (customBannerActionGroup) {
                customBannerActionGroup.classList.toggle('d-none', !hasBackground);
            }
            if (customBannerCardTitle) {
                customBannerCardTitle.textContent = hasBackground ? 'Custom Banner Ready' : 'Create Custom Banner';
            }
            if (customBannerCardSubtitle) {
                customBannerCardSubtitle.textContent = hasBackground
                    ? (customBannerState.originalName || 'Background uploaded')
                    : 'Upload your own background image';
            }
            if (customBannerOverlayTitle) {
                customBannerOverlayTitle.textContent = hasGeneratedBanner ? 'Temporary Banner Generated' : 'Custom Background Ready';
            }
            if (customBannerOverlaySubtitle) {
                customBannerOverlaySubtitle.textContent = hasGeneratedBanner
                    ? 'Preview again or download before temporary files expire'
                    : 'Select players to generate your temporary banner';
            }
            if (customPreviewBtn) {
                customPreviewBtn.disabled = !hasBackground || customBannerState.isUploading;
            }
            if (customDownloadBtn) {
                customDownloadBtn.disabled = !hasBackground || customBannerState.isUploading;
            }
        }

        async function cleanupCustomBannerState(options = {}) {
            if (!customBannerState.token) {
                return { success: true };
            }

            const formData = new FormData();
            formData.append('action', 'cleanup_custom');
            formData.append('custom_token', customBannerState.token);

            const response = await fetch(`team_banner.php?team_id=${TEAM_ID}`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Temporary custom banner cleanup failed.');
            }

            const currentToken = customBannerState.token;
            clearCustomBannerExpiryTimer();
            loadedPreviewUrls.delete(getCustomPreviewKey(currentToken));
            customBannerState = createEmptyCustomBannerState();
            if (customBannerInput && !options.keepFileInputValue) {
                customBannerInput.value = '';
            }
            renderCustomBannerCard();

            return data;
        }

        function getCustomDownloadUrl() {
            const token = encodeURIComponent(customBannerState.token);
            return new URL(`team_banner.php?team_id=${TEAM_ID}&action=download_custom&custom_token=${token}`, window.location.href).href;
        }

        function scheduleCustomBannerCleanupAfterDownload(token, delayMs = 15000) {
            if (!token) {
                return;
            }

            window.setTimeout(() => {
                const payload = new URLSearchParams();
                payload.append('action', 'cleanup_custom');
                payload.append('custom_token', token);

                fetch(`team_banner.php?team_id=${TEAM_ID}`, {
                    method: 'POST',
                    body: payload,
                    keepalive: true,
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                    }
                }).catch((cleanupError) => {
                    console.error('Downloaded custom banner cleanup failed:', cleanupError);
                });
            }, delayMs);
        }

        async function triggerCustomBannerHttpDownload() {
            if (!customBannerState.bannerUrl || !customBannerState.token) {
                throw new Error('Generate the custom banner before downloading it.');
            }

            const downloadedToken = customBannerState.token;
            const downloaded = await forceDownloadImage(
                getCustomDownloadUrl(),
                customBannerState.fileName || `custom_banner_${customBannerState.token}.png`
            );

            if (!downloaded) {
                return false;
            }

            markDownloadStarted();
            scheduleCustomBannerCleanupAfterDownload(downloadedToken);
            return true;
        }

        function forgetDownloadedCustomBanner() {
            const downloadedToken = customBannerState.token;
            clearCustomBannerExpiryTimer();
            if (downloadedToken) {
                loadedPreviewUrls.delete(getCustomPreviewKey(downloadedToken));
            }
            customBannerState = createEmptyCustomBannerState();
            if (customBannerInput) {
                customBannerInput.value = '';
            }
            renderCustomBannerCard();
        }

        async function handleCustomBannerDownload() {
            if (!(await ensureCustomBannerIsActive())) {
                setDownloadButtonLoading(activeDownloadButton, false);
                activeDownloadButton = null;
                return;
            }

            try {
                setCustomUploadLoading(true, 'Preparing custom banner download...');
                const downloaded = await triggerCustomBannerHttpDownload();
                if (!downloaded) {
                    setDownloadButtonLoading(activeDownloadButton, false);
                    activeDownloadButton = null;
                    return;
                }

                forgetDownloadedCustomBanner();
                previewModal.hide();
                resetModalDownloadState();
                showAlert('success', '<strong>Custom banner download started.</strong> Temporary files will be deleted shortly.');
            } catch (error) {
                setDownloadButtonLoading(activeDownloadButton, false);
                activeDownloadButton = null;
                previewModal.hide();
                resetModalDownloadState();
                showAlert('danger', `<strong>Custom Download Failed:</strong> ${error.message}`);
            } finally {
                setCustomUploadLoading(false);
            }
        }

        function openLoadedPreview(cacheKey, titleText, downloadOptions) {
            const loadedUrl = loadedPreviewUrls.get(cacheKey);
            if (!loadedUrl) {
                return false;
            }

            previewLoader.classList.add('d-none');
            previewImg.classList.remove('d-none');
            previewImg.src = loadedUrl;
            previewTitle.textContent = titleText;
            setModalDownloadState(downloadOptions);
            previewModal.show();
            return true;
        }

        function loadPreviewImage(cacheKey, bannerUrl, displayUrl, titleText, downloadOptions) {
            previewLoader.classList.remove('d-none');
            previewImg.classList.add('d-none');
            previewTitle.textContent = titleText;
            setModalDownloadState(downloadOptions);
            previewModal.show();

            previewImg.onload = function () {
                loadedPreviewUrls.set(cacheKey, displayUrl);
                previewLoader.classList.add('d-none');
                previewImg.classList.remove('d-none');
                previewImg.onload = null;
                previewImg.onerror = null;
            };

            previewImg.onerror = function () {
                previewImg.onload = null;
                previewImg.onerror = null;
                previewModal.hide();
                showAlert('danger', '<strong>Preview Failed:</strong> Unable to load the banner image.');
            };

            if (previewImg.src !== displayUrl) {
                previewImg.src = displayUrl;
            } else if (previewImg.complete) {
                loadedPreviewUrls.set(cacheKey, displayUrl);
                previewLoader.classList.add('d-none');
                previewImg.classList.remove('d-none');
                previewImg.onload = null;
                previewImg.onerror = null;
            }
        }

        async function requestBanner(action, templateId, orderedPlayerIds = []) {
            previewLoader.classList.remove('d-none');
            previewImg.classList.add('d-none');
            resetModalDownloadState();
            previewTitle.textContent = `Generating ${tplNames[templateId] || 'Banner'}...`;
            previewModal.show();

            try {
                const formData = new FormData();
                formData.append('action', 'generate');
                formData.append('template_id', templateId);
                if (orderedPlayerIds.length > 0) {
                    formData.append('player_order', JSON.stringify(orderedPlayerIds));
                }

                const response = await fetch(`team_banner.php?team_id=${TEAM_ID}`, {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.error || 'Unable to generate the team banner.');
                }

                const bannerUrl = data.url;
                const displayUrl = data.cached ? bannerUrl : `${bannerUrl}?t=${Date.now()}`;
                const titleText = data.cached
                    ? `${tplNames[templateId] || 'Banner'} Preview (Cached)`
                    : `${tplNames[templateId] || 'Banner'} Preview`;
                const downloadOptions = {
                    url: bannerUrl,
                    downloadUrl: getTemplateDownloadUrl(templateId),
                    fileName: `squad_banner_template_${templateId}.png`,
                    isCustom: false,
                    customToken: ''
                };

                markTemplateAsCached(templateId);
                setTemplateBannerUrl(templateId, bannerUrl, displayUrl);
                loadPreviewImage(getTemplatePreviewKey(templateId), bannerUrl, displayUrl, titleText, downloadOptions);

                if (action === 'download') {
                    await triggerDownload(bannerUrl, templateId);
                }
            } catch (error) {
                if (action === 'download') {
                    setDownloadButtonLoading(activeDownloadButton, false);
                    activeDownloadButton = null;
                }
                previewModal.hide();
                showAlert('danger', `<strong>Generation Failed:</strong> ${error.message}`);
            }
        }

        async function uploadCustomBackground(file) {
            if (!isAllowedCustomImage(file)) {
                showAlert('danger', 'Only JPG, PNG, and WEBP images are allowed for custom banners.');
                return;
            }

            if (file.size > CUSTOM_MAX_UPLOAD_SIZE) {
                const maxMb = Math.round(CUSTOM_MAX_UPLOAD_SIZE / (1024 * 1024));
                showAlert('danger', `Custom background size must be ${maxMb} MB or less.`);
                return;
            }

            try {
                if (customBannerState.token) {
                    try {
                        await cleanupCustomBannerState({ keepFileInputValue: true });
                    } catch (cleanupError) {
                        showAlert('warning', `<strong>Cleanup Warning:</strong> ${cleanupError.message}`);
                    }
                }

                setCustomUploadLoading(true, 'Uploading custom background...');

                const formData = new FormData();
                formData.append('action', 'upload_custom_background');
                formData.append('background_image', file);

                const response = await fetch(`team_banner.php?team_id=${TEAM_ID}`, {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.error || 'Custom background upload failed.');
                }

                customBannerState = {
                    token: data.token,
                    backgroundUrl: data.background_url,
                    originalName: data.original_name || file.name,
                    bannerUrl: '',
                    cached: false,
                    generated: false,
                    fileName: '',
                    expiresAt: data.expires_at || '',
                    isUploading: false
                };
                renderCustomBannerCard();
                scheduleCustomBannerExpiryCleanup();

                pendingRequest = { kind: 'custom', action: 'preview' };
                resetSelectionState();
                selectionModal.show();
            } catch (error) {
                showAlert('danger', `<strong>Upload Failed:</strong> ${error.message}`);
            } finally {
                setCustomUploadLoading(false);
            }
        }

        async function requestCustomBanner(action, orderedPlayerIds = []) {
            if (!customBannerState.token) {
                showAlert('danger', 'Upload a custom background image before generating a custom banner.');
                return;
            }

            if (!(await ensureCustomBannerIsActive())) {
                return;
            }

            const isDownloadAction = action === 'download';
            if (isDownloadAction) {
                setCustomUploadLoading(true, 'Generating custom banner...');
            } else {
                previewLoader.classList.remove('d-none');
                previewImg.classList.add('d-none');
                resetModalDownloadState();
                previewTitle.textContent = 'Generating Custom Banner...';
                previewModal.show();
            }

            try {
                const formData = new FormData();
                formData.append('action', 'generate_custom');
                formData.append('custom_token', customBannerState.token);
                formData.append('player_order', JSON.stringify(orderedPlayerIds));

                const response = await fetch(`team_banner.php?team_id=${TEAM_ID}`, {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.error || 'Unable to generate the custom banner.');
                }

                customBannerState.bannerUrl = data.url;
                customBannerState.cached = true;
                customBannerState.generated = true;
                customBannerState.fileName = data.file_name || `custom_banner_${customBannerState.token}.png`;
                customBannerState.expiresAt = data.expires_at || customBannerState.expiresAt;
                renderCustomBannerCard();
                scheduleCustomBannerExpiryCleanup();

                if (isDownloadAction) {
                    await handleCustomBannerDownload();
                    return;
                }

                const cacheKey = getCustomPreviewKey(customBannerState.token);
                const titleText = data.cached ? 'Custom Banner Preview (Cached)' : 'Custom Banner Preview';
                const bannerUrl = customBannerState.bannerUrl;
                const displayUrl = data.cached ? bannerUrl : `${bannerUrl}?t=${Date.now()}`;

                loadPreviewImage(cacheKey, bannerUrl, displayUrl, titleText, {
                    url: bannerUrl,
                    downloadUrl: getCustomDownloadUrl(),
                    fileName: customBannerState.fileName,
                    isCustom: true,
                    customToken: customBannerState.token
                });
            } catch (error) {
                if (isDownloadAction) {
                    setCustomUploadLoading(false);
                    setDownloadButtonLoading(activeDownloadButton, false);
                    activeDownloadButton = null;
                } else {
                    previewModal.hide();
                }
                showAlert('danger', `<strong>Custom Banner Failed:</strong> ${error.message}`);
            }
        }

        document.querySelectorAll('.quick-preview-btn').forEach(button => {
            button.addEventListener('click', function () {
                const templateId = this.dataset.tplId;
                const { imageUrl, title } = getTemplateQuickPreviewData(templateId);
                if (!quickPreviewImg || !quickPreviewTitle || !imageUrl) {
                    return;
                }

                quickPreviewImg.src = imageUrl;
                quickPreviewTitle.textContent = title;
                quickPreviewModal.show();
            });
        });

        document.querySelectorAll('.action-btn').forEach(button => {
            button.addEventListener('click', async function () {
                const action = this.dataset.action;
                const templateId = this.dataset.tplId;
                const tplCard = this.closest('.tpl-card');
                const isCached = tplCard && tplCard.dataset.cached === '1';
                const bannerUrl = tplCard ? tplCard.dataset.bannerUrl : '';

                clearSelectionValidation();

                if (action === 'download') {
                    activeDownloadButton = this;
                    setDownloadButtonLoading(activeDownloadButton, true);
                }

                if (isCached) {
                    if (action === 'preview' && bannerUrl && openLoadedPreview(
                        getTemplatePreviewKey(templateId),
                        `${tplNames[templateId] || 'Banner'} Preview (Cached)`,
                        {
                            url: bannerUrl,
                            downloadUrl: getTemplateDownloadUrl(templateId),
                            fileName: `squad_banner_template_${templateId}.png`,
                            isCustom: false,
                            customToken: ''
                        }
                    )) {
                        return;
                    }
                    if (action === 'download' && bannerUrl) {
                        await triggerDownload(bannerUrl, templateId);
                        return;
                    }
                    if (action === 'preview' && bannerUrl) {
                        loadPreviewImage(
                            getTemplatePreviewKey(templateId),
                            bannerUrl,
                            bannerUrl,
                            `${tplNames[templateId] || 'Banner'} Preview (Cached)`,
                            {
                                url: bannerUrl,
                                downloadUrl: getTemplateDownloadUrl(templateId),
                                fileName: `squad_banner_template_${templateId}.png`,
                                isCustom: false,
                                customToken: ''
                            }
                        );
                        return;
                    }
                    requestBanner(action, templateId);
                    return;
                }

                pendingRequest = { kind: 'template', templateId, action };
                resetSelectionState();
                selectionModal.show();
            });
        });

        if (customBannerSurface && customBannerInput) {
            customBannerSurface.addEventListener('click', function (event) {
                if (customBannerState.isUploading) {
                    return;
                }

                const clickedControl = event.target.closest('button');
                if (clickedControl) {
                    return;
                }

                if (!customBannerState.backgroundUrl) {
                    customBannerInput.click();
                }
            });
        }

        if (customReplaceBtn && customBannerInput) {
            customReplaceBtn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                if (!customBannerState.isUploading) {
                    customBannerInput.click();
                }
            });
        }

        if (customBannerInput) {
            customBannerInput.addEventListener('change', function () {
                const file = this.files && this.files[0] ? this.files[0] : null;
                this.value = '';
                if (!file) {
                    return;
                }
                uploadCustomBackground(file);
            });
        }

        if (customPreviewBtn) {
            customPreviewBtn.addEventListener('click', async function () {
                clearSelectionValidation();

                if (!(await ensureCustomBannerIsActive())) {
                    return;
                }

                if (!customBannerState.backgroundUrl) {
                    customBannerInput.click();
                    return;
                }

                if (customBannerState.bannerUrl) {
                    const cacheKey = getCustomPreviewKey(customBannerState.token);
                    const titleText = 'Custom Banner Preview (Cached)';
                    const downloadOptions = {
                        url: customBannerState.bannerUrl,
                        downloadUrl: getCustomDownloadUrl(),
                        fileName: customBannerState.fileName || `custom_banner_${customBannerState.token}.png`,
                        isCustom: true,
                        customToken: customBannerState.token
                    };

                    if (openLoadedPreview(cacheKey, titleText, downloadOptions)) {
                        return;
                    }

                    loadPreviewImage(cacheKey, customBannerState.bannerUrl, customBannerState.bannerUrl, titleText, downloadOptions);
                    return;
                }

                pendingRequest = { kind: 'custom', action: 'preview' };
                resetSelectionState();
                selectionModal.show();
            });
        }

        if (customDownloadBtn) {
            customDownloadBtn.addEventListener('click', async function () {
                clearSelectionValidation();
                activeDownloadButton = this;
                setDownloadButtonLoading(activeDownloadButton, true);

                if (!(await ensureCustomBannerIsActive())) {
                    setDownloadButtonLoading(activeDownloadButton, false);
                    activeDownloadButton = null;
                    return;
                }

                if (!customBannerState.backgroundUrl) {
                    setDownloadButtonLoading(activeDownloadButton, false);
                    activeDownloadButton = null;
                    customBannerInput.click();
                    return;
                }

                if (customBannerState.bannerUrl) {
                    await handleCustomBannerDownload();
                    return;
                }

                pendingRequest = { kind: 'custom', action: 'download' };
                resetSelectionState();
                selectionModal.show();
            });
        }

        playerSelectionList.addEventListener('click', event => {
            const row = event.target.closest('.player-select-row');
            if (!row) {
                return;
            }

            const checkbox = row.querySelector('.player-select-checkbox');
            if (!checkbox || checkbox.disabled || event.target === checkbox) {
                return;
            }

            checkbox.checked = !checkbox.checked;
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        });

        playerSelectionList.addEventListener('change', event => {
            if (!event.target.classList.contains('player-select-checkbox')) {
                return;
            }

            const checkbox = event.target;
            const playerId = checkbox.dataset.playerId;
            const alreadySelected = selectedPlayerIds.includes(playerId);

            if (checkbox.checked) {
                if (!alreadySelected && selectedPlayerIds.length >= MAX_SELECTION) {
                    checkbox.checked = false;
                    showSelectionValidation(`You can select a maximum of ${MAX_SELECTION} players.`);
                    return;
                }

                if (!alreadySelected) {
                    selectedPlayerIds.push(playerId);
                }
            } else if (alreadySelected) {
                selectedPlayerIds = selectedPlayerIds.filter(id => id !== playerId);
            }

            clearSelectionValidation();
            updateSelectionSummary();
        });

        confirmSelectionBtn.addEventListener('click', function () {
            if (selectedPlayerIds.length < MIN_SELECTION) {
                showSelectionValidation(`Select at least ${MIN_SELECTION} players to continue.`);
                return;
            }

            if (!pendingRequest) {
                return;
            }

            const orderedPlayerIds = [...selectedPlayerIds];
            const currentRequest = pendingRequest;
            pendingRequest = null;
            selectionModal.hide();
            if (currentRequest.kind === 'custom') {
                requestCustomBanner(currentRequest.action, orderedPlayerIds);
            } else {
                requestBanner(currentRequest.action, currentRequest.templateId, orderedPlayerIds);
            }
        });

        modalDownloadBtn.addEventListener('click', async function (event) {
            event.preventDefault();
            activeDownloadButton = this;
            setDownloadButtonLoading(activeDownloadButton, true);

            if (this.dataset.isCustom === '1') {
                await handleCustomBannerDownload();
                return;
            }

            const downloaded = await forceDownloadImage(
                this.dataset.downloadUrl || this.dataset.bannerUrl,
                this.dataset.fileName || 'team-banner.png'
            );

            if (downloaded) {
                markDownloadStarted(this);
                return;
            }

            setDownloadButtonLoading(this, false);
            if (activeDownloadButton === this) {
                activeDownloadButton = null;
            }
        });

        selectionModalEl.addEventListener('hidden.bs.modal', function () {
            if (!pendingRequest) {
                return;
            }

            const cancelledRequest = pendingRequest;
            pendingRequest = null;

            if (cancelledRequest.action === 'download') {
                setDownloadButtonLoading(activeDownloadButton, false);
                activeDownloadButton = null;
            }

            if (cancelledRequest.kind === 'custom') {
                cleanupCustomBannerState({ keepFileInputValue: true }).catch((cleanupError) => {
                    console.error('Cancelled custom banner cleanup failed:', cleanupError);
                    showAlert('warning', `<strong>Cleanup Warning:</strong> ${cleanupError.message}`);
                });
            }
        });

        renderCustomBannerCard();
    });
</script>

<?php require_once '../includes/footer.php'; ?>
