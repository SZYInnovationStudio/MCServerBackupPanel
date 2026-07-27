<?php
/**
 * MCServerBackupPanel — Authentication Check
 *
 * Include this file at the top of every admin page to enforce login.
 * Redirects to login.php if no valid session.
 *
 * @package MCSBP
 * @version 1.0.0
 */

require_once __DIR__ . '/config.php';

// If install not completed and installer exists, redirect to installer
if (!file_exists(__DIR__ . '/install.lock') && file_exists(__DIR__ . '/install.php') && basename($_SERVER['SCRIPT_NAME']) !== 'install.php') {
    header('Location: ' . SITE_URL . '/install.php');
    exit;
}

// Check login status
if (empty($_SESSION['admin_id'])) {
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}
