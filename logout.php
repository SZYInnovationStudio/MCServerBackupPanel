<?php
/**
 * MCServerBackupPanel — Logout
 *
 * Destroys the admin session and redirects to login.
 *
 * @package MCSBP
 * @version 1.0.0
 */

require_once __DIR__ . '/config.php';

// Destroy session
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();

// Redirect to login
header('Location: ' . SITE_URL . '/login.php');
exit;
