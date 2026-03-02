<?php
require_once 'config/config.php';

require_login();

$requestedUserId = (int)($_GET['user_id'] ?? get_user_id());
$type = $_GET['type'] ?? 'national_id';
$allowedTypes = ['selfie', 'national_id'];

if (!in_array($type, $allowedTypes, true)) {
    http_response_code(400);
    exit('Invalid document type');
}

$currentUserId = (int)get_user_id();
$role = get_user_role();
$isAdmin = in_array($role, ['admin', 'manager'], true);

if (!$isAdmin && $requestedUserId !== $currentUserId) {
    http_response_code(403);
    exit('Forbidden');
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    http_response_code(500);
    exit('Unable to access document');
}

$relativePath = null;
if ($type === 'selfie') {
    $stmt = $db->prepare("SELECT profile_photo FROM users WHERE user_id = :user_id LIMIT 1");
    $stmt->execute([':user_id' => $requestedUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $relativePath = $row['profile_photo'] ?? null;
} else {
    $stmt = $db->prepare("SELECT file_path
                          FROM user_documents
                          WHERE user_id = :user_id AND doc_type = 'national_id'
                          ORDER BY uploaded_at DESC
                          LIMIT 1");
    $stmt->execute([':user_id' => $requestedUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $relativePath = $row['file_path'] ?? null;
}

if (empty($relativePath)) {
    http_response_code(404);
    exit('Document not found');
}

$relativePath = ltrim(str_replace(['\\', '..'], ['/', ''], $relativePath), '/');
$candidates = [
    PRIVATE_STORAGE_PATH . $relativePath,
    ROOT_PATH . '/uploads/' . $relativePath
];

$filePath = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $filePath = $candidate;
        break;
    }
}

if ($filePath === null) {
    http_response_code(404);
    exit('Document file missing');
}

$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'pdf' => 'application/pdf',
    default => 'application/octet-stream'
};

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($filePath);
exit;
?>
