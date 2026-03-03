<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';

header('Content-Type: application/json; charset=utf-8');

$requestId = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
if ($requestId === '') {
    $requestId = bin2hex(random_bytes(8));
}
header('X-Request-Id: ' . $requestId);

function api_success(int $status, array $data = []): void
{
    http_response_code($status);
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(int $status, string $code, string $message, array $fields = []): void
{
    http_response_code($status);
    $error = ['code' => $code, 'message' => $message];
    if (!empty($fields)) {
        $error['fields'] = $fields;
    }
    echo json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_SLASHES);
    exit;
}

function api_json_input(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            api_error(400, 'BAD_REQUEST', 'Invalid JSON payload');
        }
        return $decoded;
    }
    return $_POST;
}

function api_require_auth(?array $roles = null): void
{
    if (!is_logged_in() && function_exists('attempt_remember_me_login')) {
        attempt_remember_me_login();
    }

    if (!is_logged_in()) {
        api_error(401, 'UNAUTHORIZED', 'Authentication required');
    }

    $now = time();
    $loginTime = (int)($_SESSION['login_time'] ?? $now);
    $lastActivity = (int)($_SESSION['last_activity'] ?? $now);

    if (($now - $loginTime) > SESSION_LIFETIME || ($now - $lastActivity) > LOGIN_TIMEOUT) {
        $_SESSION = [];
        clear_remember_me_token();
        session_destroy();
        api_error(401, 'SESSION_EXPIRED', 'Session expired. Please login again.');
    }

    $_SESSION['last_activity'] = $now;

    if ($roles !== null && !api_role_allowed((string)get_user_role(), $roles)) {
        api_error(403, 'FORBIDDEN', 'You do not have permission to access this resource');
    }
}

function api_role_allowed(string $actualRole, array $allowedRoles): bool
{
    if (in_array($actualRole, $allowedRoles, true)) {
        return true;
    }

    // Compatibility bridge: legacy "user" role name maps to "customer".
    if ($actualRole === 'customer' && in_array('user', $allowedRoles, true)) {
        return true;
    }
    if ($actualRole === 'user' && in_array('customer', $allowedRoles, true)) {
        return true;
    }

    return false;
}

function api_require_csrf_header(): void
{
    $submitted = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    if ($submitted === '' || $sessionToken === '' || !hash_equals($sessionToken, $submitted)) {
        api_error(403, 'INVALID_CSRF_TOKEN', 'Invalid request token. Please refresh and try again.');
    }
}

function api_user_payload(array $user): array
{
    return [
        'userId' => (int)$user['user_id'],
        'fullName' => (string)$user['full_name'],
        'email' => (string)$user['email'],
        'role' => (string)$user['role'],
        'verificationStatus' => (string)$user['verification_status'],
        'accountStatus' => (string)$user['account_status'],
        'creditScore' => (int)$user['credit_score'],
    ];
}

function api_fetch_user(PDO $db, int $userId): ?array
{
    $stmt = $db->prepare(
        "SELECT user_id, full_name, email, role, verification_status, account_status, credit_score
         FROM users
         WHERE user_id = :user_id
         LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

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

try {
    if ($method === 'GET' && $routePath === '/security/csrf-token') {
        api_success(200, ['token' => csrf_token()]);
    }

    if ($method === 'POST' && $routePath === '/auth/login') {
        $body = api_json_input();
        $email = sanitize_input((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $rememberMe = !empty($body['rememberMe']);
        $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $attemptKey = 'login_attempt_' . hash('sha256', strtolower($email) . '|' . $ipAddress);

        $fieldErrors = [];
        if ($email === '') {
            $fieldErrors['email'] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fieldErrors['email'] = 'Invalid email format';
        }
        if ($password === '') {
            $fieldErrors['password'] = 'Password is required';
        }
        if (!empty($fieldErrors)) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid login payload', $fieldErrors);
        }

        $attempts = (int)($_SESSION[$attemptKey]['count'] ?? 0);
        $firstAttempt = (int)($_SESSION[$attemptKey]['first'] ?? time());
        if ($attempts >= MAX_LOGIN_ATTEMPTS && (time() - $firstAttempt) < LOGIN_TIMEOUT) {
            $wait = LOGIN_TIMEOUT - (time() - $firstAttempt);
            api_error(429, 'TOO_MANY_ATTEMPTS', 'Too many login attempts. Try again in ' . (string)ceil($wait / 60) . ' minute(s).');
        }

        $stmt = $db->prepare(
            "SELECT user_id, full_name, email, password_hash, role, account_status, verification_status, credit_score
             FROM users
             WHERE email = :email
             LIMIT 1"
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $credentialsOk = $user && password_verify($password, (string)$user['password_hash']);
        if (!$credentialsOk) {
            if (!isset($_SESSION[$attemptKey]) || (time() - (int)($_SESSION[$attemptKey]['first'] ?? 0)) > LOGIN_TIMEOUT) {
                $_SESSION[$attemptKey] = ['count' => 1, 'first' => time()];
            } else {
                $_SESSION[$attemptKey]['count'] = (int)($_SESSION[$attemptKey]['count'] ?? 0) + 1;
            }
            log_audit(null, 'FAILED_LOGIN_ATTEMPT', 'users', null, null, ['email' => $email]);
            api_error(401, 'INVALID_CREDENTIALS', 'Invalid email or password');
        }

        if ($user['account_status'] === 'suspended' || $user['account_status'] === 'closed') {
            api_error(403, 'ACCOUNT_DISABLED', 'Your account is not active');
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['user_id'];
        $_SESSION['user_role'] = (string)$user['role'];
        $_SESSION['full_name'] = (string)$user['full_name'];
        $_SESSION['email'] = (string)$user['email'];
        $_SESSION['verification_status'] = (string)$user['verification_status'];
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        unset($_SESSION[$attemptKey]);

        $update = $db->prepare("UPDATE users SET last_login = NOW() WHERE user_id = :user_id");
        $update->execute([':user_id' => (int)$user['user_id']]);
        log_audit((int)$user['user_id'], 'USER_LOGIN', 'users', (int)$user['user_id']);

        if ($rememberMe) {
            issue_remember_me_token((int)$user['user_id']);
        }

        api_success(200, [
            'user' => api_user_payload($user),
            'csrfToken' => csrf_token(),
        ]);
    }

    if ($method === 'POST' && $routePath === '/auth/register') {
        $body = api_json_input();
        $fullName = sanitize_input((string)($body['fullName'] ?? ''));
        $email = sanitize_input((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $phone = sanitize_input((string)($body['phone'] ?? ''));
        $nationalId = sanitize_input((string)($body['nationalId'] ?? ''));

        $fieldErrors = [];
        if ($fullName === '') {
            $fieldErrors['fullName'] = 'Full name is required';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fieldErrors['email'] = 'Valid email is required';
        }
        if (strlen($password) < 8) {
            $fieldErrors['password'] = 'Password must be at least 8 characters';
        }
        if ($phone === '') {
            $fieldErrors['phone'] = 'Phone number is required';
        }
        if ($nationalId === '') {
            $fieldErrors['nationalId'] = 'National ID is required';
        }
        if (!empty($fieldErrors)) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid registration payload', $fieldErrors);
        }

        $dupStmt = $db->prepare(
            "SELECT user_id, email, phone, national_id
             FROM users
             WHERE email = :email OR phone = :phone OR national_id = :national_id
             LIMIT 1"
        );
        $dupStmt->execute([
            ':email' => $email,
            ':phone' => $phone,
            ':national_id' => $nationalId,
        ]);
        $dup = $dupStmt->fetch(PDO::FETCH_ASSOC);
        if ($dup) {
            $dupFields = [];
            if ((string)$dup['email'] === $email) {
                $dupFields['email'] = 'Email is already registered';
            }
            if ((string)$dup['phone'] === $phone) {
                $dupFields['phone'] = 'Phone number is already registered';
            }
            if ((string)$dup['national_id'] === $nationalId) {
                $dupFields['nationalId'] = 'National ID is already registered';
            }
            api_error(400, 'VALIDATION_ERROR', 'A matching account already exists', $dupFields);
        }

        $insert = $db->prepare(
            "INSERT INTO users
             (national_id, full_name, email, phone, password_hash, is_verified, verification_status, account_status, role)
             VALUES (:national_id, :full_name, :email, :phone, :password_hash, 0, 'pending', 'active', 'customer')"
        );
        $insert->execute([
            ':national_id' => $nationalId,
            ':full_name' => $fullName,
            ':email' => $email,
            ':phone' => $phone,
            ':password_hash' => password_hash($password, HASH_ALGO),
        ]);

        $userId = (int)$db->lastInsertId();
        log_audit($userId, 'USER_REGISTERED', 'users', $userId);

        $notif = $db->prepare(
            "INSERT INTO notifications (user_id, notification_type, title, message)
             VALUES (:user_id, 'system', 'Welcome to Lemelani Loans', 'Your account has been created successfully. Please wait for verification.')"
        );
        $notif->execute([':user_id' => $userId]);

        $createdUser = api_fetch_user($db, $userId);
        api_success(201, [
            'user' => $createdUser ? api_user_payload($createdUser) : ['userId' => $userId],
            'message' => 'Registration successful',
        ]);
    }

    if ($method === 'POST' && $routePath === '/auth/logout') {
        api_require_auth(['customer', 'user', 'admin', 'manager']);
        api_require_csrf_header();

        $currentUserId = (int)get_user_id();
        $_SESSION = [];
        clear_remember_me_token();
        session_destroy();

        log_audit($currentUserId, 'USER_LOGOUT', 'users', $currentUserId);
        api_success(200, ['message' => 'Logged out']);
    }

    if ($method === 'POST' && $routePath === '/auth/password/forgot') {
        $body = api_json_input();
        $email = strtolower(trim((string)($body['email'] ?? '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid forgot password payload', [
                'email' => 'Valid email is required',
            ]);
        }

        try {
            $stmt = $db->prepare(
                "SELECT user_id, full_name, email, account_status
                 FROM users
                 WHERE email = :email
                 LIMIT 1"
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && $user['account_status'] !== 'closed') {
                if (can_request_password_reset((int)$user['user_id'])) {
                    $token = create_password_reset_token((int)$user['user_id']);
                    if ($token) {
                        $resetUrl = site_url(
                            'reset-password.php?selector=' . urlencode((string)$token['selector']) . '&token=' . urlencode((string)$token['validator'])
                        );
                        try {
                            send_password_reset_email((string)$user['email'], (string)$user['full_name'], $resetUrl, (string)$token['expires_at']);
                            log_audit((int)$user['user_id'], 'PASSWORD_RESET_REQUESTED', 'users', (int)$user['user_id']);
                        } catch (Throwable $mailError) {
                            error_log('Password reset email error: ' . $mailError->getMessage());
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Forgot password request failed: ' . $e->getMessage());
        }

        api_success(200, [
            'message' => 'If an account exists with that email, a password reset link has been sent.',
        ]);
    }

    if ($method === 'POST' && $routePath === '/auth/password/reset') {
        $body = api_json_input();
        $selector = trim((string)($body['selector'] ?? ''));
        $validator = trim((string)($body['validator'] ?? ''));
        $newPassword = (string)($body['newPassword'] ?? '');

        $fieldErrors = [];
        if ($selector === '') {
            $fieldErrors['selector'] = 'Selector is required';
        }
        if ($validator === '') {
            $fieldErrors['validator'] = 'Validator is required';
        }
        if (strlen($newPassword) < 8) {
            $fieldErrors['newPassword'] = 'New password must be at least 8 characters';
        }
        if (!empty($fieldErrors)) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid reset password payload', $fieldErrors);
        }

        $tokenRecord = validate_password_reset_token($selector, $validator);
        if (!$tokenRecord) {
            api_error(400, 'INVALID_TOKEN', 'This reset link is invalid or has expired.');
        }

        $db->beginTransaction();
        try {
            $update = $db->prepare(
                "UPDATE users
                 SET password_hash = :password_hash
                 WHERE user_id = :user_id"
            );
            $update->execute([
                ':password_hash' => password_hash($newPassword, HASH_ALGO),
                ':user_id' => (int)$tokenRecord['user_id'],
            ]);
            if ($update->rowCount() < 1) {
                throw new RuntimeException('Unable to update password');
            }

            $consume = $db->prepare(
                "UPDATE password_reset_tokens
                 SET used_at = NOW()
                 WHERE id = :id AND used_at IS NULL"
            );
            $consume->execute([':id' => (int)$tokenRecord['id']]);
            if ($consume->rowCount() < 1) {
                throw new RuntimeException('Token already used');
            }

            $db->prepare("DELETE FROM password_reset_tokens WHERE user_id = :user_id")
               ->execute([':user_id' => (int)$tokenRecord['user_id']]);

            create_remember_tokens_table($db);
            $db->prepare("DELETE FROM remember_tokens WHERE user_id = :user_id")
               ->execute([':user_id' => (int)$tokenRecord['user_id']]);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Reset password failure: ' . $e->getMessage());
            api_error(500, 'RESET_FAILED', 'Unable to reset password right now. Please request a new reset link.');
        }

        log_audit((int)$tokenRecord['user_id'], 'PASSWORD_RESET_COMPLETED', 'users', (int)$tokenRecord['user_id']);
        api_success(200, ['message' => 'Password reset successful']);
    }

    if ($method === 'GET' && $routePath === '/auth/me') {
        api_require_auth(['customer', 'user', 'admin', 'manager']);
        $user = api_fetch_user($db, (int)get_user_id());
        if (!$user) {
            api_error(401, 'UNAUTHORIZED', 'Session is invalid');
        }
        api_success(200, ['user' => api_user_payload($user)]);
    }

    if ($method === 'GET' && $routePath === '/customer/profile') {
        api_require_auth(['customer', 'user']);
        $stmt = $db->prepare(
            "SELECT u.user_id, u.full_name, u.national_id, u.email, u.phone, u.role, u.verification_status,
                    u.account_status, u.credit_score, u.profile_photo, u.created_at, u.last_login,
                    up.employment_type, up.employer_name, up.monthly_income_mwk, up.occupation,
                    up.residential_address, up.district, up.residence_type, up.years_at_address,
                    up.next_of_kin_name, up.next_of_kin_phone, up.next_of_kin_relation
             FROM users u
             LEFT JOIN user_profiles up ON up.user_id = u.user_id
             WHERE u.user_id = :user_id
             LIMIT 1"
        );
        $stmt->execute([':user_id' => (int)get_user_id()]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$profile) {
            api_error(404, 'NOT_FOUND', 'Profile not found');
        }

        api_success(200, ['profile' => $profile]);
    }

    if ($method === 'PATCH' && $routePath === '/customer/profile') {
        api_require_auth(['customer', 'user']);
        api_require_csrf_header();

        $body = api_json_input();
        $fullName = array_key_exists('fullName', $body) ? sanitize_input((string)$body['fullName']) : null;
        $email = array_key_exists('email', $body) ? sanitize_input((string)$body['email']) : null;
        $phone = array_key_exists('phone', $body) ? sanitize_input((string)$body['phone']) : null;

        if ($fullName === null && $email === null && $phone === null) {
            api_error(400, 'VALIDATION_ERROR', 'No updatable fields provided');
        }

        $fieldErrors = [];
        if ($fullName !== null && $fullName === '') {
            $fieldErrors['fullName'] = 'Full name cannot be empty';
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fieldErrors['email'] = 'Valid email is required';
        }
        if ($phone !== null && $phone === '') {
            $fieldErrors['phone'] = 'Phone number cannot be empty';
        }
        if (!empty($fieldErrors)) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid profile payload', $fieldErrors);
        }

        $userId = (int)get_user_id();
        if ($email !== null) {
            $checkEmail = $db->prepare("SELECT user_id FROM users WHERE email = :email AND user_id != :user_id LIMIT 1");
            $checkEmail->execute([':email' => $email, ':user_id' => $userId]);
            if ($checkEmail->fetch()) {
                api_error(400, 'VALIDATION_ERROR', 'Invalid profile payload', ['email' => 'Email is already in use']);
            }
        }
        if ($phone !== null) {
            $checkPhone = $db->prepare("SELECT user_id FROM users WHERE phone = :phone AND user_id != :user_id LIMIT 1");
            $checkPhone->execute([':phone' => $phone, ':user_id' => $userId]);
            if ($checkPhone->fetch()) {
                api_error(400, 'VALIDATION_ERROR', 'Invalid profile payload', ['phone' => 'Phone is already in use']);
            }
        }

        $setParts = [];
        $params = [':user_id' => $userId];
        if ($fullName !== null) {
            $setParts[] = 'full_name = :full_name';
            $params[':full_name'] = $fullName;
            $_SESSION['full_name'] = $fullName;
        }
        if ($email !== null) {
            $setParts[] = 'email = :email';
            $params[':email'] = $email;
            $_SESSION['email'] = $email;
        }
        if ($phone !== null) {
            $setParts[] = 'phone = :phone';
            $params[':phone'] = $phone;
        }

        $update = $db->prepare("UPDATE users SET " . implode(', ', $setParts) . " WHERE user_id = :user_id");
        $update->execute($params);

        log_audit($userId, 'PROFILE_UPDATED', 'users', $userId, null, [
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
        ]);

        api_success(200, ['message' => 'Profile updated successfully']);
    }

    if ($method === 'PATCH' && $routePath === '/customer/profile/password') {
        api_require_auth(['customer', 'user']);
        api_require_csrf_header();

        $body = api_json_input();
        $currentPassword = (string)($body['currentPassword'] ?? '');
        $newPassword = (string)($body['newPassword'] ?? '');
        if ($currentPassword === '' || $newPassword === '') {
            api_error(400, 'VALIDATION_ERROR', 'Invalid password payload', [
                'currentPassword' => $currentPassword === '' ? 'Current password is required' : '',
                'newPassword' => $newPassword === '' ? 'New password is required' : '',
            ]);
        }
        if (strlen($newPassword) < 8) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid password payload', [
                'newPassword' => 'New password must be at least 8 characters',
            ]);
        }

        $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => (int)get_user_id()]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($currentPassword, (string)$user['password_hash'])) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid password payload', [
                'currentPassword' => 'Current password is incorrect',
            ]);
        }

        $update = $db->prepare("UPDATE users SET password_hash = :password_hash WHERE user_id = :user_id");
        $update->execute([
            ':password_hash' => password_hash($newPassword, HASH_ALGO),
            ':user_id' => (int)get_user_id(),
        ]);

        create_remember_tokens_table($db);
        $db->prepare("DELETE FROM remember_tokens WHERE user_id = :user_id")
           ->execute([':user_id' => (int)get_user_id()]);
        clear_remember_me_token();

        log_audit((int)get_user_id(), 'PASSWORD_CHANGED', 'users', (int)get_user_id());
        api_success(200, ['message' => 'Password changed successfully']);
    }

    api_error(404, 'NOT_FOUND', 'API endpoint not found');
} catch (Throwable $e) {
    error_log('API unhandled error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    api_error(500, 'INTERNAL_ERROR', 'An unexpected error occurred');
}
