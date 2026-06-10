<?php
// Central session, cookie, and URL helpers.

function cpt_is_https()
{
    return
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
}

function cpt_base_url()
{
    $protocol = cpt_is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $protocol . '://' . $host . '/CPT_LEAGUE/';
}

function cpt_cookie_options($expires = 0)
{
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => cpt_is_https(),
        'httponly' => true,
        'samesite' => 'Lax'
    ];
}

function cpt_set_cookie($name, $value, $expires = 0)
{
    setcookie($name, $value, cpt_cookie_options($expires));
}

function cpt_clear_cookie($name)
{
    setcookie($name, '', cpt_cookie_options(time() - 3600));
    unset($_COOKIE[$name]);
}

if (session_status() === PHP_SESSION_NONE) {
    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}
