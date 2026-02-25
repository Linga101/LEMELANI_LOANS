<?php
// A simple redirector so that any requests to /admin/logout.php go back to the main logout script.
// This prevents 404s if a hardcoded admin link or bookmark is used.

// Use relative location so we don't rely on SITE_URL which may include "/admin".
header('Location: ../logout.php');
exit;
