<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/classes/User.php';
require_once dirname(__DIR__, 2) . '/classes/Loan.php';
require_once dirname(__DIR__, 2) . '/classes/Payment.php';

header('Content-Type: application/json; charset=utf-8');

$requestId = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
if ($requestId === '') {
    $requestId = bin2hex(random_bytes(8));
}
header('X-Request-Id: ' . $requestId);
$_SERVER['APP_REQUEST_ID'] = $requestId;

$auditEventId = trim((string)($_SERVER['HTTP_X_AUDIT_EVENT_ID'] ?? ''));
if ($auditEventId === '') {
    $auditEventId = generate_audit_event_id();
}
header('X-Audit-Event-Id: ' . $auditEventId);
$_SERVER['APP_AUDIT_EVENT_ID'] = $auditEventId;


try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Throwable $e) {
    error_log('API database bootstrap error: ' . $e->getMessage());
    api_error(500, 'INTERNAL_ERROR', 'Service unavailable');
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$uriPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$basePath = rtrim(APP_BASE_PATH, '/');
$apiPrefix = ($basePath === '' ? '' : $basePath) . '/api/v1';

if (!str_starts_with($uriPath, $apiPrefix)) {
    api_error(404, 'NOT_FOUND', 'API endpoint not found');
}

$routePath = substr($uriPath, strlen($apiPrefix));
$routePath = $routePath === '' ? '/' : $routePath;
if ($routePath !== '/' && str_ends_with($routePath, '/')) {
    $routePath = rtrim($routePath, '/');
}
