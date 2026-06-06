<?php
// includes/onesignal_utils.php

// ─── OneSignal Credentials ───────────────────────────────────────────────────
if (!defined('ONESIGNAL_APP_ID')) {
    define('ONESIGNAL_APP_ID', $_ENV['ONESIGNAL_APP_ID'] ?? '');
}
if (!defined('ONESIGNAL_API_KEY')) {
    define('ONESIGNAL_API_KEY', $_ENV['ONESIGNAL_API_KEY'] ?? '');
}
if (!defined('ONESIGNAL_ANDROID_SOUND')) {
    define('ONESIGNAL_ANDROID_SOUND', 'notification_sound');
}
if (!defined('ONESIGNAL_ANDROID_CHANNEL_ID')) {
    define('ONESIGNAL_ANDROID_CHANNEL_ID', 'cpt_notification_sound_channel_v1');
}

/**
 * Helper to ensure a URL is HTTPS and absolute
 */
function ensure_absolute_url($url)
{
    if (empty($url)) return null;

    // If it's already an absolute URL, just return it
    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
        return $url;
    }

    // Otherwise, we cannot easily fix relative URLs here without a global base_url
    // In production, OneSignal REQUIRES absolute URLs.
    return $url;
}

/**
 * Send a push notification via OneSignal API v1
 * 
 * @param array $player_ids Array of OneSignal Player IDs (subscription IDs)
 * @param string $title Notification heading
 * @param string $message Notification content
 * @param array $additional_data Custom data payload
 * @param string $url Target URL on click
 * @return string API response
 */
function sendOneSignalNotification($player_ids, $title, $message, $additional_data = [], $url = '')
{
    if (empty($player_ids))
        return false;

    $content = array("en" => $message);
    $headings = array("en" => $title);

    $fields = array(
        'app_id' => ONESIGNAL_APP_ID,
        'include_subscription_ids' => $player_ids,
        'headings' => $headings,
        'contents' => $content,
        'android_group' => 'cpl_updates',
        'android_sound' => ONESIGNAL_ANDROID_SOUND,
        'existing_android_channel_id' => ONESIGNAL_ANDROID_CHANNEL_ID
    );

    // Support for images/icons - Native Android requirements
    if (isset($additional_data['small_icon'])) {
        $fields['small_icon'] = $additional_data['small_icon'];
        unset($additional_data['small_icon']);
    }

    if (isset($additional_data['large_icon'])) {
        $img = ensure_absolute_url($additional_data['large_icon']);
        $fields['large_icon'] = $img;
        unset($additional_data['large_icon']);
    }

    if (isset($additional_data['android_sound'])) {
        $fields['android_sound'] = $additional_data['android_sound'];
        unset($additional_data['android_sound']);
    }

    if (isset($additional_data['android_channel_id'])) {
        $fields['android_channel_id'] = $additional_data['android_channel_id'];
        unset($fields['existing_android_channel_id']);
        unset($additional_data['android_channel_id']);
    }

    if (isset($additional_data['existing_android_channel_id'])) {
        $fields['existing_android_channel_id'] = $additional_data['existing_android_channel_id'];
        unset($additional_data['existing_android_channel_id']);
    }

    if (isset($additional_data['big_picture']) || isset($additional_data['image'])) {
        $raw_img = isset($additional_data['big_picture']) ? $additional_data['big_picture'] : $additional_data['image'];
        $img = ensure_absolute_url($raw_img);
        $fields['big_picture'] = $img;
        // chrome_web_image is often used as a fallback/alternative for some Android versions
        $fields['chrome_web_image'] = $img;
        
        // ios_attachments support
        $fields['ios_attachments'] = array("id1" => $img);
        
        unset($additional_data['big_picture']);
        unset($additional_data['image']);
    }

    if (!empty($url)) {
        $additional_data['target_url'] = $url;
    }

    if (!empty($additional_data)) {
        $fields['data'] = $additional_data;
    }

    $fields_json = json_encode($fields);

    // DEBUG: Log the payload to a file for investigation
    file_put_contents(__DIR__ . '/../onesignal_payload.log', date('[Y-m-d H:i:s] ') . $fields_json . PHP_EOL, FILE_APPEND);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Key ' . ONESIGNAL_API_KEY
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_json);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("OneSignal Error: " . $error);
    }
    
    // DEBUG: Log the response
    file_put_contents(__DIR__ . '/../onesignal_payload.log', date('[Y-m-d H:i:s] ') . "Response: " . $response . PHP_EOL, FILE_APPEND);

    return $response;
}



/**
 * Get all OneSignal Player IDs for a specific user
 * 
 * @param PDO $pdo PDO connection
 * @param int $user_id User ID
 * @return array List of player IDs
 */
function getPlayerIdsForUser($pdo, $user_id)
{
    if (!$user_id)
        return [];

    $stmt = $pdo->prepare("SELECT onesignal_player_id FROM user_devices WHERE user_id = ? AND onesignal_player_id IS NOT NULL");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Get all OneSignal Player IDs for specific team(s)
 * 
 * @param PDO $pdo PDO connection
 * @param array $team_ids Array of team IDs
 * @return array List of player IDs
 */
function getPlayerIdsForTeams($pdo, $team_ids)
{
    if (empty($team_ids))
        return [];

    $placeholders = implode(',', array_fill(0, count($team_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT DISTINCT ud.onesignal_player_id 
        FROM user_devices ud
        JOIN team_players tp ON ud.user_id = tp.player_id
        WHERE tp.team_id IN ($placeholders) AND ud.onesignal_player_id IS NOT NULL
    ");
    $stmt->execute($team_ids);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Get all Guest Player IDs (not linked to any user)
 * 
 * @param PDO $pdo PDO connection
 * @return array List of player IDs
 */
function getGuestPlayerIds($pdo)
{
    if (!$pdo)
        return [];

    $stmt = $pdo->query("SELECT onesignal_player_id FROM user_devices WHERE user_id IS NULL AND onesignal_player_id IS NOT NULL");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Get all OneSignal Player IDs for all users with 'admin' role
 * 
 * @param PDO $pdo PDO connection
 * @return array List of player IDs
 */
function getAdminPlayerIds($pdo)
{
    $stmt = $pdo->query("
        SELECT DISTINCT ud.onesignal_player_id 
        FROM user_devices ud
        JOIN users u ON ud.user_id = u.id
        WHERE u.role = 'admin' AND ud.onesignal_player_id IS NOT NULL
    ");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>
