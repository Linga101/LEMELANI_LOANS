<?php
require_once 'config/config.php';

// Log the logout action if user is logged in
if (is_logged_in()) {
    log_audit(get_user_id(), 'USER_LOGOUT', 'users', get_user_id());
}

// Clear all session data
$_SESSION = array();

// Delete session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Delete remember me cookie
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Redirect to login page
redirect('/login.php?logout=1');
?>