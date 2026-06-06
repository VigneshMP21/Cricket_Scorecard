<?php
require_once __DIR__ . '/html2image_utils.php';

if (!function_exists('build_notification_banner_icon')) {
    function build_notification_banner_icon(string $type): string
    {
        if ($type === 'location') {
            return '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 21C15.8 16.8 18.5 13.8 18.5 10.2C18.5 6.5 15.6 3.5 12 3.5C8.4 3.5 5.5 6.5 5.5 10.2C5.5 13.8 8.2 16.8 12 21Z" stroke="#38BDF8" stroke-width="1.8"/><circle cx="12" cy="10" r="2.3" fill="#38BDF8"/></svg>';
        }

        if ($type === 'trophy') {
            return '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 4H16V7C16 9.2 14.2 11 12 11C9.8 11 8 9.2 8 7V4Z" fill="#FBBF24"/><path d="M9.5 13H14.5" stroke="#FBBF24" stroke-width="1.8" stroke-linecap="round"/><path d="M12 11V16" stroke="#FBBF24" stroke-width="1.8" stroke-linecap="round"/><path d="M9 19H15" stroke="#FBBF24" stroke-width="1.8" stroke-linecap="round"/><path d="M7 5H4.8C4.4 5 4 5.4 4 5.8V6.6C4 8.5 5.5 10 7.4 10H8.2" stroke="#FBBF24" stroke-width="1.8" stroke-linecap="round"/><path d="M17 5H19.2C19.6 5 20 5.4 20 5.8V6.6C20 8.5 18.5 10 16.6 10H15.8" stroke="#FBBF24" stroke-width="1.8" stroke-linecap="round"/></svg>';
        }

        return '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3.5" y="5.5" width="17" height="15" rx="2.5" stroke="#FBBF24" stroke-width="1.8"/><path d="M7.5 3.8V7.2" stroke="#FBBF24" stroke-width="1.8" stroke-linecap="round"/><path d="M16.5 3.8V7.2" stroke="#FBBF24" stroke-width="1.8" stroke-linecap="round"/><path d="M3.8 9.5H20.2" stroke="#FBBF24" stroke-width="1.8" stroke-linecap="round"/><rect x="7.4" y="12.2" width="3.2" height="3.2" rx="0.8" fill="#FBBF24"/></svg>';
    }
}

if (!function_exists('generate_match_started_notification_banner')) {
    function generate_match_started_notification_banner(array $data): ?string
    {
        $tournament_name = htmlspecialchars((string) ($data['tournament_name'] ?? 'Cricket Tournament'), ENT_QUOTES, 'UTF-8');
        $toss_details = htmlspecialchars((string) ($data['toss_details'] ?? ''), ENT_QUOTES, 'UTF-8');
        $teamA_name = htmlspecialchars((string) ($data['teamA_name'] ?? 'Team A'), ENT_QUOTES, 'UTF-8');
        $teamB_name = htmlspecialchars((string) ($data['teamB_name'] ?? 'Team B'), ENT_QUOTES, 'UTF-8');
        $teamA_logo = htmlspecialchars((string) ($data['teamA_logo'] ?? ''), ENT_QUOTES, 'UTF-8');
        $teamB_logo = htmlspecialchars((string) ($data['teamB_logo'] ?? ''), ENT_QUOTES, 'UTF-8');
        $teamA_color = htmlspecialchars((string) ($data['teamA_color'] ?? '#f59e0b'), ENT_QUOTES, 'UTF-8');
        $teamB_color = htmlspecialchars((string) ($data['teamB_color'] ?? '#ef4444'), ENT_QUOTES, 'UTF-8');
        $venue = htmlspecialchars((string) ($data['venue'] ?? 'Venue TBA'), ENT_QUOTES, 'UTF-8');
        $match_type = htmlspecialchars((string) ($data['match_type'] ?? 'League'), ENT_QUOTES, 'UTF-8');
        $location_icon = build_notification_banner_icon('location');

        $html = "
        <div class='banner-container'>
            <div class='tournament-tag'>{$tournament_name}</div>
            <div class='toss-tag'>{$toss_details}</div>
            <div class='match-main'>
                <div class='team-box'>
                    <div class='logo-wrapper teamA-border'><img src='{$teamA_logo}' alt='Team A logo' /></div>
                    <div class='team-name teamA-color'>{$teamA_name}</div>
                </div>
                <div class='vs-box'>
                    <div class='vs-text'>VS</div>
                    <div class='match-type'>{$match_type}</div>
                </div>
                <div class='team-box'>
                    <div class='logo-wrapper teamB-border'><img src='{$teamB_logo}' alt='Team B logo' /></div>
                    <div class='team-name teamB-color'>{$teamB_name}</div>
                </div>
            </div>
            <div class='match-footer'>
                <div class='footer-item'>{$location_icon}<span>{$venue}</span></div>
            </div>
        </div>";

        $css = "
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { width: 800px; height: 450px; font-family: 'Outfit', sans-serif; }
        .banner-container {
            width: 800px; height: 450px; color: white; position: relative; overflow: hidden;
            display: flex; flex-direction: column; align-items: center; padding: 30px;
            background:
                radial-gradient(circle at top right, rgba(251, 191, 36, 0.24), transparent 30%),
                radial-gradient(circle at bottom left, rgba(56, 189, 248, 0.18), transparent 36%),
                linear-gradient(140deg, #07111f 0%, #112c45 54%, #07111a 100%);
        }
        .banner-container::before {
            content: ''; position: absolute; inset: 0;
            background:
                linear-gradient(120deg, rgba(2, 6, 23, 0.3), rgba(2, 6, 23, 0.72)),
                url('https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/v1777031427/Night-Lights-at-Narendra-Modi-Stadium_cbq1o1.webp') center/cover no-repeat;
            opacity: 0.46; z-index: 0;
        }
        .tournament-tag, .toss-tag, .match-main, .match-footer { position: relative; z-index: 1; }
        .tournament-tag {
            background: rgba(0,0,0,0.68); padding: 6px 20px; border-radius: 50px; font-weight: 600;
            font-size: 14px; color: #fbbf24; text-transform: uppercase; letter-spacing: 2px;
            border: 1px solid rgba(251, 191, 36, 0.3); margin-bottom: 8px;
        }
        .toss-tag {
            background: rgba(16, 185, 129, 0.18); padding: 4px 15px; border-radius: 10px; font-weight: 700;
            font-size: 16px; color: #34d399; border: 1px solid rgba(52, 211, 153, 0.34);
            margin-bottom: 15px; text-transform: capitalize;
        }
        .match-main { display: flex; align-items: center; justify-content: space-around; width: 100%; flex-grow: 1; }
        .team-box { text-align: center; width: 250px; }
        .logo-wrapper {
            width: 120px; height: 120px; background: rgba(255,255,255,0.1); border-radius: 50%;
            display: flex; align-items: center; justify-content: center; margin: 0 auto 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
        }
        .teamA-border { border: 3px solid {$teamA_color}; }
        .teamB-border { border: 3px solid {$teamB_color}; }
        .logo-wrapper img { width: 90px; height: 90px; object-fit: contain; }
        .team-name { font-size: 28px; font-weight: 800; text-shadow: 2px 2px 4px rgba(0,0,0,0.8); }
        .teamA-color { color: {$teamA_color}; }
        .teamB-color { color: {$teamB_color}; }
        .vs-box { text-align: center; }
        .vs-text { font-size: 60px; font-weight: 900; color: #ef4444; line-height: 1; text-shadow: 0 0 20px rgba(239, 68, 68, 0.5); }
        .match-type { font-size: 14px; color: white; font-weight: 600; text-transform: uppercase; margin-top: 5px; }
        .match-footer {
            display: flex; justify-content: center; width: 100%; padding: 14px 16px;
            background: rgba(5,10,20,0.68); border-radius: 14px; border: 1px solid rgba(255,255,255,0.1);
        }
        .footer-item { font-size: 18px; color: white; font-weight: 600; display: flex; align-items: center; gap: 8px; padding: 4px 8px; }
        .footer-item svg { width: 20px; height: 20px; flex-shrink: 0; }";

        $image_url = generate_html2image_link($html, $css, 800, 450, 2200);
        return upload_generated_image_to_cloudinary($image_url, 'match_started_banners');
    }
}

if (!function_exists('generate_match_completed_notification_banner')) {
    function generate_match_completed_notification_banner(array $data): ?string
    {
        $team_name = htmlspecialchars((string) ($data['team_name'] ?? 'Match Result'), ENT_QUOTES, 'UTF-8');
        $subtitle = htmlspecialchars((string) ($data['subtitle'] ?? 'Match Winners'), ENT_QUOTES, 'UTF-8');
        $trophy_icon = build_notification_banner_icon('trophy');
        $players = array_slice($data['players'] ?? [], 0, 11);
        $row1_players = array_slice($players, 0, 6);
        $row2_players = array_slice($players, 6);

        $buildRow = static function (array $rowPlayers): string {
            $row_html = '';
            foreach ($rowPlayers as $player) {
                $player_name = htmlspecialchars((string) ($player['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $player_image = htmlspecialchars((string) ($player['image'] ?? "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/v1743608770/cricket_p_qfscy6.jpg"), ENT_QUOTES, 'UTF-8');
                $row_html .= "
                <div class='player-item'>
                    <div class='img-container'><img src='{$player_image}' alt='{$player_name}' /></div>
                </div>";
            }
            return $row_html;
        };

        $html = "
        <div class='banner-container'>
            <div class='overlay'></div>
            <div class='content-wrapper'>
                <div class='winner-badge'>{$trophy_icon}<span>{$subtitle}</span></div>
                <div class='team-header'>{$team_name}</div>
                <div class='players-grid'>
                    <div class='player-row'>{$buildRow($row1_players)}</div>
                    <div class='player-row row-2'>{$buildRow($row2_players)}</div>
                </div>
            </div>
        </div>";

        $css = "
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { width: 1024px; height: 512px; font-family: 'Outfit', sans-serif; overflow: hidden; }
        .banner-container {
            width: 1024px; height: 512px; position: relative; display: flex; flex-direction: column; align-items: center;
            background:
                radial-gradient(circle at top left, rgba(251, 191, 36, 0.24), transparent 26%),
                radial-gradient(circle at bottom right, rgba(56, 189, 248, 0.16), transparent 30%),
                linear-gradient(145deg, #0b1220 0%, #10253a 55%, #07111a 100%);
        }
        .banner-container::before {
            content: ''; position: absolute; inset: 0;
            background:
                linear-gradient(to bottom, rgba(0,0,0,0.28), rgba(0,0,0,0.74)),
                url('https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/v1778073510/OIP_vxotsb.webp') center/cover no-repeat;
            opacity: 0.42;
        }
        .overlay { position: absolute; inset: 0; z-index: 1; }
        .content-wrapper { position: relative; z-index: 2; width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; padding: 34px 20px; }
        .winner-badge {
            display: inline-flex; align-items: center; gap: 10px; padding: 10px 18px; border-radius: 999px;
            background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(251, 191, 36, 0.28);
            color: #fbbf24; font-size: 16px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.14em;
            margin-bottom: 18px;
        }
        .winner-badge svg { width: 22px; height: 22px; flex-shrink: 0; }
        .team-header {
            font-size: 50px; font-weight: 800; color: #fbbf24; text-transform: uppercase;
            text-shadow: 0 4px 10px rgba(0,0,0,0.8); margin-bottom: 34px; text-align: center; letter-spacing: 2px;
        }
        .players-grid { display: flex; flex-direction: column; gap: 34px; width: 100%; }
        .player-row { display: flex; justify-content: center; gap: 26px; width: 100%; }
        .row-2 { gap: 34px; }
        .player-item { text-align: center; width: 130px; position: relative; }
        .img-container {
            width: 130px; height: 130px; border-radius: 20px; overflow: hidden;
            border: 3px solid rgba(251, 191, 36, 0.8); background: rgba(0,0,0,0.3);
            box-shadow: 0 10px 25px rgba(0,0,0,0.6);
        }
        .img-container img { width: 100%; height: 100%; object-fit: cover; }";

        $image_url = generate_html2image_link($html, $css, 1024, 512, 2200);
        return upload_generated_image_to_cloudinary($image_url, 'match_completion_banners');
    }
}

if (!function_exists('generate_team_status_notification_banner')) {
    function generate_team_status_notification_banner(array $data): ?string
    {
        $eyebrow = htmlspecialchars((string) ($data['eyebrow'] ?? 'Team Update'), ENT_QUOTES, 'UTF-8');
        $team_name = htmlspecialchars((string) ($data['team_name'] ?? 'Team'), ENT_QUOTES, 'UTF-8');
        $subline = htmlspecialchars((string) ($data['subline'] ?? 'Competition update'), ENT_QUOTES, 'UTF-8');
        $team_code = htmlspecialchars((string) ($data['team_code'] ?? 'TM'), ENT_QUOTES, 'UTF-8');
        $team_color = htmlspecialchars((string) ($data['team_color'] ?? '#0d6efd'), ENT_QUOTES, 'UTF-8');
        $captain_name = htmlspecialchars((string) ($data['captain_name'] ?? 'Captain TBA'), ENT_QUOTES, 'UTF-8');
        $captain_label = htmlspecialchars((string) ($data['captain_label'] ?? 'Captain'), ENT_QUOTES, 'UTF-8');
        $vice_captain_name = htmlspecialchars((string) ($data['vice_captain_name'] ?? 'Vice Captain TBA'), ENT_QUOTES, 'UTF-8');
        $vice_captain_label = htmlspecialchars((string) ($data['vice_captain_label'] ?? 'Vice Captain'), ENT_QUOTES, 'UTF-8');
        $primary_stat_label = htmlspecialchars((string) ($data['primary_stat_label'] ?? 'Status'), ENT_QUOTES, 'UTF-8');
        $primary_stat_value = htmlspecialchars((string) ($data['primary_stat_value'] ?? 'Pending'), ENT_QUOTES, 'UTF-8');
        $secondary_stat_label = htmlspecialchars((string) ($data['secondary_stat_label'] ?? 'Players'), ENT_QUOTES, 'UTF-8');
        $secondary_stat_value = htmlspecialchars((string) ($data['secondary_stat_value'] ?? '11'), ENT_QUOTES, 'UTF-8');
        $logo_url = htmlspecialchars((string) ($data['logo_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $captain_image_url = htmlspecialchars((string) ($data['captain_image_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $vice_captain_image_url = htmlspecialchars((string) ($data['vice_captain_image_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $folder = preg_replace('/[^A-Za-z0-9_\-\/]/', '', (string) ($data['folder'] ?? 'team_notifications')) ?: 'team_notifications';

        $logo_markup = $logo_url !== ''
            ? "<img class='team-logo' src='{$logo_url}' alt='Team logo' />"
            : "<div class='team-logo-fallback'>{$team_code}</div>";
        $captain_image = $captain_image_url !== ''
            ? "<img class='captain-image' src='{$captain_image_url}' alt='Captain image' />"
            : '';
        $vice_captain_image = $vice_captain_image_url !== ''
            ? "<img class='captain-image' src='{$vice_captain_image_url}' alt='Vice captain image' />"
            : '';

        $html = "
        <div class='banner-shell'>
            <div class='glow glow-one'></div>
            <div class='glow glow-two'></div>
            <div class='content'>
                <div class='eyebrow'>{$eyebrow}</div>
                <div class='headline'>{$team_name}</div>
                <div class='subline'>{$subline}</div>
                <div class='pill-row'>
                    <div class='pill'>Code: {$team_code}</div>
                    <div class='pill'>{$secondary_stat_label}: {$secondary_stat_value}</div>
                </div>
                <div class='lead-row'>
                    <div class='captain-card'>
                        {$captain_image}
                        <div class='captain-copy'>
                            <div class='captain-label'>{$captain_label}</div>
                            <div class='captain-name'>{$captain_name}</div>
                        </div>
                    </div>
                    <div class='captain-card'>
                        {$vice_captain_image}
                        <div class='captain-copy'>
                            <div class='captain-label'>{$vice_captain_label}</div>
                            <div class='captain-name'>{$vice_captain_name}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class='logo-panel'>{$logo_markup}</div>
        </div>";

        $css = "
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;700;800&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { width: 1024px; height: 576px; font-family: 'Outfit', sans-serif; }
        .banner-shell {
            width: 1024px; height: 576px; position: relative; overflow: hidden; color: #f8fafc;
            background:
                radial-gradient(circle at 12% 18%, rgba(255, 255, 255, 0.09), transparent 24%),
                linear-gradient(145deg, {$team_color} 0%, #0f172a 58%, #020617 100%);
        }
        .glow { position: absolute; border-radius: 999px; filter: blur(6px); opacity: 0.85; }
        .glow-one { width: 340px; height: 340px; right: -80px; top: -100px; background: rgba(251, 191, 36, 0.22); }
        .glow-two { width: 260px; height: 260px; left: -50px; bottom: -70px; background: rgba(59, 130, 246, 0.2); }
        .content { position: relative; z-index: 2; padding: 58px; width: 64%; display: flex; flex-direction: column; gap: 18px; }
        .eyebrow {
            display: inline-flex; width: fit-content; padding: 10px 18px; border-radius: 999px;
            background: rgba(15, 23, 42, 0.42); border: 1px solid rgba(255, 255, 255, 0.18);
            font-size: 15px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.18em; color: #dbeafe;
        }
        .headline { font-size: 62px; line-height: 1.02; font-weight: 800; max-width: 560px; }
        .subline { font-size: 23px; color: #dbeafe; max-width: 560px; }
        .pill-row { display: flex; gap: 14px; margin-top: 8px; }
        .pill {
            padding: 12px 18px; border-radius: 999px; background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.14); font-size: 18px; font-weight: 600;
        }
        .lead-row {
            margin-top: 20px; width: 100%; max-width: 640px;
            display: flex; gap: 18px;
        }
        .captain-card {
            flex: 1; min-height: 112px; padding: 18px 20px; border-radius: 26px;
            background: rgba(15, 23, 42, 0.58); border: 1px solid rgba(255, 255, 255, 0.14);
            display: flex; align-items: center; gap: 16px;
        }
        .captain-image {
            width: 74px; height: 74px; border-radius: 22px; object-fit: cover; border: 2px solid rgba(255, 255, 255, 0.22);
        }
        .captain-copy { min-width: 0; }
        .captain-label, .status-label {
            font-size: 13px; text-transform: uppercase; letter-spacing: 0.14em; color: #93c5fd; margin-bottom: 6px;
        }
        .captain-name, .status-value { font-size: 24px; font-weight: 700; line-height: 1.1; }
        .logo-panel {
            position: absolute; right: 58px; top: 92px; width: 300px; height: 300px; z-index: 2;
            border-radius: 42px; background: rgba(248, 250, 252, 0.10); border: 1px solid rgba(248, 250, 252, 0.16);
            display: flex; align-items: center; justify-content: center; overflow: hidden; box-shadow: 0 24px 48px rgba(2, 6, 23, 0.28);
        }
        .team-logo { width: 100%; height: 100%; object-fit: contain; padding: 26px; }
        .team-logo-fallback { font-size: 74px; font-weight: 800; letter-spacing: 0.08em; color: #ffffff; }";

        $image_url = generate_html2image_link($html, $css, 1024, 576, 2200);
        return upload_generated_image_to_cloudinary($image_url, $folder);
    }
}
