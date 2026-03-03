<?php
declare(strict_types=1);

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

function api_repayment_payload(array $repayment): array
{
    return [
        'repaymentId' => (int)($repayment['payment_id'] ?? $repayment['repayment_id'] ?? 0),
        'loanId' => (int)$repayment['loan_id'],
        'amountPaidMwk' => (float)($repayment['payment_amount'] ?? $repayment['amount_paid_mwk'] ?? 0),
        'paymentMethod' => (string)$repayment['payment_method'],
        'paymentStatus' => (string)$repayment['payment_status'],
        'transactionReference' => (string)($repayment['transaction_reference'] ?? $repayment['payment_reference'] ?? ''),
        'paymentDate' => (string)($repayment['payment_date'] ?? $repayment['paid_at'] ?? ''),
        'loanStatus' => isset($repayment['loan_status']) ? (string)$repayment['loan_status'] : null,
    ];
}

function api_loan_payload(array $loan): array
{
    return [
        'loanId' => (int)$loan['loan_id'],
        'applicationId' => isset($loan['application_id']) ? (int)$loan['application_id'] : null,
        'userId' => (int)$loan['user_id'],
        'principalMwk' => (float)($loan['loan_amount'] ?? 0),
        'interestRate' => (float)($loan['interest_rate'] ?? 0),
        'termMonths' => (int)($loan['loan_term_months'] ?? 0),
        'totalRepayableMwk' => (float)($loan['total_amount'] ?? 0),
        'outstandingBalanceMwk' => (float)($loan['remaining_balance'] ?? 0),
        'status' => (string)$loan['status'],
        'dueDate' => (string)($loan['due_date'] ?? ''),
        'disbursementDate' => (string)($loan['disbursement_date'] ?? ''),
    ];
}

function api_application_payload(array $application): array
{
    return [
        'applicationId' => (int)($application['id'] ?? 0),
        'userId' => (int)($application['user_id'] ?? 0),
        'requestedAmountMwk' => (float)($application['requested_amount_mwk'] ?? 0),
        'approvedAmountMwk' => isset($application['approved_amount_mwk']) ? (float)$application['approved_amount_mwk'] : null,
        'loanPurpose' => (string)($application['loan_purpose'] ?? ''),
        'termMonths' => isset($application['term_months']) ? (int)$application['term_months'] : null,
        'interestRate' => isset($application['interest_rate']) ? (float)$application['interest_rate'] : null,
        'status' => (string)($application['status'] ?? ''),
        'applicationRef' => (string)($application['application_ref'] ?? ''),
        'appliedAt' => (string)($application['applied_at'] ?? ''),
        'reviewedAt' => (string)($application['reviewed_at'] ?? ''),
        'rejectionReason' => (string)($application['rejection_reason'] ?? ''),
    ];
}

function api_payout_account_payload(array $account): array
{
    return [
        'accountId' => (int)$account['account_id'],
        'userId' => isset($account['user_id']) ? (int)$account['user_id'] : null,
        'accountType' => (string)$account['account_type'],
        'accountProvider' => (string)($account['account_provider'] ?? ''),
        'accountName' => (string)($account['account_name'] ?? ''),
        'accountNumber' => (string)($account['account_number'] ?? ''),
        'branchName' => (string)($account['branch_name'] ?? ''),
        'swiftCode' => (string)($account['swift_code'] ?? ''),
        'isDefault' => (bool)($account['is_default'] ?? false),
        'isActive' => (bool)($account['is_active'] ?? false),
        'createdAt' => (string)($account['created_at'] ?? ''),
    ];
}

function api_notification_payload(array $notification): array
{
    return [
        'notificationId' => (int)$notification['notification_id'],
        'notificationType' => (string)$notification['notification_type'],
        'title' => (string)$notification['title'],
        'message' => (string)$notification['message'],
        'isRead' => (bool)$notification['is_read'],
        'relatedLoanId' => isset($notification['related_loan_id']) ? (int)$notification['related_loan_id'] : null,
        'relatedApplicationId' => isset($notification['related_application_id']) ? (int)$notification['related_application_id'] : null,
        'createdAt' => (string)($notification['created_at'] ?? ''),
    ];
}

function api_admin_application_payload(array $application): array
{
    return [
        'id' => (int)$application['id'],
        'applicationRef' => (string)($application['application_ref'] ?? ''),
        'userId' => (int)($application['user_id'] ?? 0),
        'fullName' => (string)($application['full_name'] ?? ''),
        'email' => (string)($application['email'] ?? ''),
        'phone' => (string)($application['phone'] ?? ''),
        'creditScore' => isset($application['credit_score']) ? (int)$application['credit_score'] : null,
        'productName' => (string)($application['product_name'] ?? ''),
        'requestedAmountMwk' => (float)($application['requested_amount_mwk'] ?? 0),
        'approvedAmountMwk' => isset($application['approved_amount_mwk']) ? (float)$application['approved_amount_mwk'] : null,
        'interestRate' => isset($application['interest_rate']) ? (float)$application['interest_rate'] : null,
        'termMonths' => isset($application['term_months']) ? (int)$application['term_months'] : null,
        'status' => (string)($application['status'] ?? ''),
        'payoutAccountType' => (string)($application['payout_account_type'] ?? ''),
        'payoutAccountProvider' => (string)($application['payout_account_provider'] ?? ''),
        'payoutAccountName' => (string)($application['payout_account_name'] ?? ''),
        'payoutAccountNumber' => (string)($application['payout_account_number'] ?? ''),
        'appliedAt' => (string)($application['applied_at'] ?? ''),
        'reviewedAt' => (string)($application['reviewed_at'] ?? ''),
        'rejectionReason' => (string)($application['rejection_reason'] ?? ''),
    ];
}

function api_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function api_rate_limit_check(string $bucket, int $maxAttempts, int $windowSeconds): void
{
    if ($maxAttempts <= 0 || $windowSeconds <= 0) {
        return;
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $key = hash('sha256', $bucket . '|' . $ip . '|' . substr($ua, 0, 120));
    $now = time();

    if (!isset($_SESSION['api_rate_limits']) || !is_array($_SESSION['api_rate_limits'])) {
        $_SESSION['api_rate_limits'] = [];
    }
    $bucketData = $_SESSION['api_rate_limits'][$key] ?? [];
    if (!is_array($bucketData)) {
        $bucketData = [];
    }

    $cutoff = $now - $windowSeconds;
    $bucketData = array_values(array_filter($bucketData, static fn($ts): bool => is_int($ts) && $ts >= $cutoff));

    if (count($bucketData) >= $maxAttempts) {
        $oldest = min($bucketData);
        $retryAfter = max(1, $windowSeconds - ($now - $oldest));
        header('Retry-After: ' . $retryAfter);
        api_error(429, 'RATE_LIMITED', 'Too many requests. Please try again shortly.');
    }

    $bucketData[] = $now;
    $_SESSION['api_rate_limits'][$key] = $bucketData;
}
