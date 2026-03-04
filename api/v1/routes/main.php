<?php
declare(strict_types=1);

try {
    if ($method === 'GET' && $routePath === '/security/csrf-token') {
        api_success(200, ['token' => csrf_token()]);
    }

    if ($method === 'POST' && $routePath === '/auth/login') {
        api_rate_limit_check('auth_login', 15, 300);
        $body = api_json_input();
        $email = api_normalize_email(sanitize_input((string)($body['email'] ?? '')));
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
        api_rate_limit_check('auth_register', 10, 300);
        $body = api_json_input();
        $fullName = sanitize_input((string)($body['fullName'] ?? ''));
        $email = api_normalize_email(sanitize_input((string)($body['email'] ?? '')));
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
        api_rate_limit_check('auth_password_forgot', 8, 300);
        $body = api_json_input();
        $email = api_normalize_email((string)($body['email'] ?? ''));

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
        api_rate_limit_check('auth_password_reset', 8, 300);
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

    if ($method === 'GET' && $routePath === '/customer/dashboard/summary') {
        api_require_auth(['customer', 'user']);
        $userId = (int)get_user_id();
        $userObj = new User($db);
        $userData = $userObj->getUserById($userId);
        $userStats = $userObj->getUserStats($userId);

        $loanStmt = $db->prepare(
            "SELECT loan_id, user_id, principal_mwk AS loan_amount, total_repayable_mwk AS total_amount,
                    outstanding_balance_mwk AS remaining_balance, due_date, status, created_at
             FROM loans
             WHERE user_id = :user_id
             ORDER BY created_at DESC
             LIMIT 5"
        );
        $loanStmt->execute([':user_id' => $userId]);
        $recentLoans = $loanStmt->fetchAll(PDO::FETCH_ASSOC);

        $notifStmt = $db->prepare(
            "SELECT notification_id, notification_type, title, message, is_read, created_at
             FROM notifications
             WHERE user_id = :user_id
             ORDER BY created_at DESC
             LIMIT 5"
        );
        $notifStmt->execute([':user_id' => $userId]);
        $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

        $unread = 0;
        foreach ($notifications as $n) {
            if (!(int)$n['is_read']) {
                $unread++;
            }
        }

        api_success(200, [
            'user' => $userData ? api_user_payload($userData) : null,
            'stats' => $userStats,
            'recentLoans' => array_map(static fn(array $loan): array => api_loan_payload($loan), $recentLoans),
            'notifications' => $notifications,
            'unreadNotifications' => $unread,
        ]);
    }

    if ($method === 'GET' && $routePath === '/customer/loans') {
        api_require_auth(['customer', 'user']);
        $status = isset($_GET['status']) ? sanitize_input((string)$_GET['status']) : null;
        if ($status === '') {
            $status = null;
        }

        $loanObj = new Loan($db);
        $items = $loanObj->getUserLoans((int)get_user_id(), $status);
        $payload = array_map(static fn(array $loan): array => api_loan_payload($loan), $items);

        api_success(200, ['items' => $payload]);
    }

    if ($method === 'GET' && preg_match('#^/customer/loans/(\d+)$#', $routePath, $matches) === 1) {
        api_require_auth(['customer', 'user']);
        $loanId = (int)$matches[1];

        $loanObj = new Loan($db);
        $paymentObj = new Payment($db);
        $loan = $loanObj->getLoanById($loanId);

        if (!$loan || (int)$loan['user_id'] !== (int)get_user_id()) {
            api_error(404, 'NOT_FOUND', 'Loan not found');
        }

        $schedule = $paymentObj->getRepaymentSchedule($loanId);
        $payments = $paymentObj->getLoanPayments($loanId);
        $paymentPayload = array_map(static fn(array $p): array => api_repayment_payload($p), $payments);

        api_success(200, [
            'loan' => api_loan_payload($loan),
            'schedule' => $schedule,
            'payments' => $paymentPayload,
        ]);
    }

    if ($method === 'POST' && $routePath === '/customer/loan-applications') {
        api_require_auth(['customer', 'user']);
        api_require_csrf_header();
        $body = api_json_input();

        $loanAmount = (float)($body['loanAmount'] ?? 0);
        $loanPurpose = sanitize_input((string)($body['loanPurpose'] ?? ''));
        $customerAccountId = (int)($body['customerAccountId'] ?? 0);
        $loanProductId = isset($body['loanProductId']) ? (int)$body['loanProductId'] : null;
        $termMonths = isset($body['termMonths']) ? (int)$body['termMonths'] : null;

        $fieldErrors = [];
        if ($loanAmount <= 0) {
            $fieldErrors['loanAmount'] = 'Loan amount must be greater than zero';
        }
        if ($loanPurpose === '') {
            $fieldErrors['loanPurpose'] = 'Loan purpose is required';
        }
        if ($customerAccountId <= 0) {
            $fieldErrors['customerAccountId'] = 'Customer payout account is required';
        }
        if (!empty($fieldErrors)) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid loan application payload', $fieldErrors);
        }

        $userId = (int)get_user_id();
        $userObj = new User($db);
        $eligibility = $userObj->checkLoanEligibility($userId, $loanAmount);
        if (empty($eligibility['eligible'])) {
            api_error(400, 'INELIGIBLE', (string)($eligibility['reason'] ?? 'User is not eligible for this loan'));
        }

        $loanData = [
            'user_id' => $userId,
            'loan_amount' => $loanAmount,
            'loan_purpose' => $loanPurpose,
            'customer_account_id' => $customerAccountId,
        ];
        if ($loanProductId !== null && $loanProductId > 0) {
            $loanData['loan_product_id'] = $loanProductId;
        }
        if ($termMonths !== null && $termMonths > 0) {
            $loanData['term_months'] = $termMonths;
        }

        $loanObj = new Loan($db);
        $applicationId = $loanObj->createLoan($loanData);
        if (!$applicationId) {
            api_error(500, 'CREATE_FAILED', 'Failed to submit loan application');
        }

        $result = $loanObj->processLoanApplication((int)$applicationId);
        log_audit($userId, 'LOAN_APPLICATION_SUBMITTED', 'loan_applications', (int)$applicationId, null, $loanData);

        $application = $loanObj->getApplicationById((int)$applicationId);
        api_success(201, [
            'application' => $application ? api_application_payload($application) : ['applicationId' => (int)$applicationId],
            'processing' => $result,
        ]);
    }

    if ($method === 'POST' && $routePath === '/customer/repayments') {
        api_require_auth(['customer', 'user']);
        api_require_csrf_header();
        $body = api_json_input();

        $loanId = (int)($body['loanId'] ?? 0);
        $amountPaid = (float)($body['amountPaidMwk'] ?? 0);
        $paymentMethod = sanitize_input((string)($body['paymentMethod'] ?? ''));

        $fieldErrors = [];
        if ($loanId <= 0) {
            $fieldErrors['loanId'] = 'Loan ID is required';
        }
        if ($amountPaid <= 0) {
            $fieldErrors['amountPaidMwk'] = 'Payment amount must be greater than zero';
        }
        if ($paymentMethod === '') {
            $fieldErrors['paymentMethod'] = 'Payment method is required';
        }
        if (!empty($fieldErrors)) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid repayment payload', $fieldErrors);
        }

        $userId = (int)get_user_id();
        $paymentObj = new Payment($db);
        $paymentData = [
            'loan_id' => $loanId,
            'user_id' => $userId,
            'payment_amount' => $amountPaid,
            'payment_method' => $paymentMethod,
            'notes' => 'Payment via ' . ucfirst(str_replace('_', ' ', $paymentMethod)),
        ];

        $result = $paymentObj->processRepayment($paymentData);
        if (empty($result['success'])) {
            api_error(400, 'PAYMENT_FAILED', (string)($result['message'] ?? 'Payment failed'));
        }

        log_audit($userId, 'PAYMENT_MADE', 'repayments', (int)$result['payment_id'], null, $paymentData);
        api_success(201, [
            'repayment' => [
                'repaymentId' => (int)$result['payment_id'],
                'loanId' => $loanId,
                'amountPaidMwk' => (float)$result['amount_paid'],
                'paymentMethod' => $paymentMethod,
                'paymentStatus' => 'completed',
                'transactionReference' => (string)$result['transaction_reference'],
                'remainingBalanceMwk' => (float)$result['remaining_balance'],
                'loanStatus' => (string)$result['loan_status'],
                'message' => (string)$result['message'],
            ],
        ]);
    }

    if ($method === 'GET' && $routePath === '/customer/payments/history') {
        api_require_auth(['customer', 'user']);
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
        if ($limit !== null && $limit <= 0) {
            $limit = null;
        }

        $paymentObj = new Payment($db);
        $items = $paymentObj->getUserPayments((int)get_user_id(), $limit);
        $payload = array_map(static fn(array $p): array => api_repayment_payload($p), $items);
        api_success(200, ['items' => $payload]);
    }

    if ($method === 'GET' && $routePath === '/customer/credit-history') {
        api_require_auth(['customer', 'user']);
        $userId = (int)get_user_id();

        $userObj = new User($db);
        $userData = $userObj->getUserById($userId);
        if (!$userData) {
            api_error(404, 'NOT_FOUND', 'User not found');
        }
        $userStats = $userObj->getUserStats($userId);

        $historyStmt = $db->prepare(
            "SELECT ch.*, l.principal_mwk AS loan_amount, l.status as loan_status
             FROM credit_history ch
             LEFT JOIN loans l ON ch.loan_id = l.loan_id
             WHERE ch.user_id = :user_id
             ORDER BY ch.created_at DESC"
        );
        $historyStmt->execute([':user_id' => $userId]);
        $creditHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        $creditScore = (int)$userData['credit_score'];
        $totalLoans = (int)($userStats['loans']['total_loans'] ?? 0);
        $repaidLoans = (int)($userStats['loans']['repaid_loans'] ?? 0);

        $paymentHistoryScore = $totalLoans > 0
            ? (int)round(($repaidLoans / $totalLoans) * 340)
            : 170;
        $maxLoan = 300000.0;
        $outstanding = (float)($userStats['outstanding_balance'] ?? 0);
        $utilization = $outstanding > 0 ? min(($outstanding / $maxLoan), 1.0) : 0.0;
        $creditUtilizationScore = (int)round((1 - $utilization) * 255);
        $accountAgeDays = (time() - strtotime((string)$userData['created_at'])) / 86400;
        $ageFactor = min($accountAgeDays / 365, 1.0);
        $creditAgeScore = (int)round($ageFactor * 170);
        $loanDiversityScore = min($totalLoans * 17, 85);

        $rating = 'Very Poor';
        if ($creditScore >= 750) {
            $rating = 'Excellent';
        } elseif ($creditScore >= 650) {
            $rating = 'Good';
        } elseif ($creditScore >= 500) {
            $rating = 'Fair';
        } elseif ($creditScore >= 400) {
            $rating = 'Poor';
        }

        api_success(200, [
            'creditScore' => $creditScore,
            'rating' => $rating,
            'breakdown' => [
                'paymentHistory' => [
                    'score' => $paymentHistoryScore,
                    'max' => 340,
                    'percentage' => round(($paymentHistoryScore / 340) * 100, 1),
                ],
                'creditUtilization' => [
                    'score' => $creditUtilizationScore,
                    'max' => 255,
                    'percentage' => round(($creditUtilizationScore / 255) * 100, 1),
                ],
                'creditAge' => [
                    'score' => $creditAgeScore,
                    'max' => 170,
                    'percentage' => round(($creditAgeScore / 170) * 100, 1),
                ],
                'loanDiversity' => [
                    'score' => $loanDiversityScore,
                    'max' => 85,
                    'percentage' => round(($loanDiversityScore / 85) * 100, 1),
                ],
            ],
            'events' => $creditHistory,
        ]);
    }

    if ($method === 'GET' && $routePath === '/customer/payout-accounts') {
        api_require_auth(['customer', 'user']);
        $activeOnly = true;
        if (isset($_GET['activeOnly'])) {
            $activeOnly = filter_var($_GET['activeOnly'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($activeOnly === null) {
                $activeOnly = true;
            }
        }

        $loanObj = new Loan($db);
        $accounts = $loanObj->getUserPayoutAccounts((int)get_user_id(), $activeOnly);
        $payload = array_map(static fn(array $a): array => api_payout_account_payload($a), $accounts);
        api_success(200, ['items' => $payload]);
    }

    if ($method === 'GET' && $routePath === '/customer/notifications') {
        api_require_auth(['customer', 'user']);
        $userId = (int)get_user_id();
        $filter = isset($_GET['filter']) ? sanitize_input((string)$_GET['filter']) : 'all';
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 200) {
            $limit = 200;
        }

        $query = "SELECT notification_id, user_id, notification_type, title, message, is_read,
                         related_loan_id, related_application_id, created_at
                  FROM notifications
                  WHERE user_id = :user_id";
        $params = [':user_id' => $userId];

        if ($filter === 'unread') {
            $query .= " AND is_read = 0";
        } elseif ($filter === 'read') {
            $query .= " AND is_read = 1";
        } elseif ($filter !== 'all' && $filter !== '') {
            $query .= " AND notification_type = :type";
            $params[':type'] = $filter;
        }

        $query .= " ORDER BY created_at DESC LIMIT :limit";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        if (isset($params[':type'])) {
            $stmt->bindValue(':type', $params[':type'], PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $db->prepare(
            "SELECT COUNT(*) AS unread_count
             FROM notifications
             WHERE user_id = :user_id AND is_read = 0"
        );
        $countStmt->execute([':user_id' => $userId]);
        $unreadCount = (int)$countStmt->fetchColumn();

        $payload = array_map(static fn(array $n): array => api_notification_payload($n), $items);
        api_success(200, [
            'items' => $payload,
            'unreadCount' => $unreadCount,
            'filter' => $filter,
        ]);
    }

    if ($method === 'POST' && preg_match('#^/customer/notifications/(\d+)/read$#', $routePath, $matches) === 1) {
        api_require_auth(['customer', 'user']);
        api_require_csrf_header();
        $notificationId = (int)$matches[1];
        if ($notificationId <= 0) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid notification id');
        }

        $stmt = $db->prepare(
            "UPDATE notifications
             SET is_read = 1
             WHERE notification_id = :id AND user_id = :user_id"
        );
        $stmt->execute([
            ':id' => $notificationId,
            ':user_id' => (int)get_user_id(),
        ]);

        api_success(200, [
            'updated' => $stmt->rowCount() > 0,
            'notificationId' => $notificationId,
        ]);
    }

    if ($method === 'POST' && $routePath === '/customer/notifications/read-all') {
        api_require_auth(['customer', 'user']);
        api_require_csrf_header();

        $stmt = $db->prepare(
            "UPDATE notifications
             SET is_read = 1
             WHERE user_id = :user_id AND is_read = 0"
        );
        $stmt->execute([':user_id' => (int)get_user_id()]);

        api_success(200, ['updatedCount' => $stmt->rowCount()]);
    }

    if ($method === 'DELETE' && preg_match('#^/customer/notifications/(\d+)$#', $routePath, $matches) === 1) {
        api_require_auth(['customer', 'user']);
        api_require_csrf_header();
        $notificationId = (int)$matches[1];
        if ($notificationId <= 0) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid notification id');
        }

        $stmt = $db->prepare(
            "DELETE FROM notifications
             WHERE notification_id = :id AND user_id = :user_id"
        );
        $stmt->execute([
            ':id' => $notificationId,
            ':user_id' => (int)get_user_id(),
        ]);

        api_success(200, [
            'deleted' => $stmt->rowCount() > 0,
            'notificationId' => $notificationId,
        ]);
    }

    if ($method === 'GET' && preg_match('#^/customer/documents/(selfie|national_id)$#', $routePath, $matches) === 1) {
        api_require_auth(['customer', 'user', 'admin', 'manager']);
        $type = (string)$matches[1];
        $currentUserId = (int)get_user_id();
        $requestedUserId = isset($_GET['userId']) ? (int)$_GET['userId'] : $currentUserId;
        $role = (string)get_user_role();
        $isAdmin = in_array($role, ['admin', 'manager'], true);

        if (!$isAdmin && $requestedUserId !== $currentUserId) {
            api_error(403, 'FORBIDDEN', 'You do not have access to this document');
        }

        $relativePath = null;
        if ($type === 'selfie') {
            $stmt = $db->prepare("SELECT profile_photo FROM users WHERE user_id = :user_id LIMIT 1");
            $stmt->execute([':user_id' => $requestedUserId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $relativePath = $row['profile_photo'] ?? null;
        } else {
            $stmt = $db->prepare(
                "SELECT file_path
                 FROM user_documents
                 WHERE user_id = :user_id AND doc_type = 'national_id'
                 ORDER BY uploaded_at DESC
                 LIMIT 1"
            );
            $stmt->execute([':user_id' => $requestedUserId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $relativePath = $row['file_path'] ?? null;
        }

        if (empty($relativePath)) {
            api_error(404, 'NOT_FOUND', 'Document not found');
        }

        $relativePath = ltrim(str_replace(['\\', '..'], ['/', ''], (string)$relativePath), '/');
        $filePath = storage_resolve_document_path($relativePath);

        if ($filePath === null) {
            api_error(404, 'NOT_FOUND', 'Document file missing');
        }

        $ext = strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };

        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($filePath));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($filePath);
        exit;
    }

    if ($method === 'GET' && $routePath === '/admin/dashboard/summary') {
        api_require_auth(['admin', 'manager']);

        $stats = [];

        $userStmt = $db->prepare(
            "SELECT COUNT(*) as total_users,
                    SUM(CASE WHEN verification_status = 'verified' THEN 1 ELSE 0 END) as verified_users,
                    SUM(CASE WHEN verification_status = 'pending' THEN 1 ELSE 0 END) as pending_verification,
                    SUM(CASE WHEN account_status = 'active' THEN 1 ELSE 0 END) as active_users
             FROM users
             WHERE role = 'customer'"
        );
        $userStmt->execute();
        $stats['users'] = $userStmt->fetch(PDO::FETCH_ASSOC);

        $pendingApps = (int)$db->query("SELECT COUNT(*) FROM loan_applications WHERE status IN ('pending','under_review')")->fetchColumn();

        $loanStmt = $db->prepare(
            "SELECT COUNT(*) as total_loans,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_loans,
                    SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_loans,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as repaid_loans,
                    COALESCE(SUM(principal_mwk), 0) as total_disbursed,
                    COALESCE(SUM(CASE WHEN status IN ('active','overdue') THEN outstanding_balance_mwk ELSE 0 END), 0) as total_outstanding
             FROM loans"
        );
        $loanStmt->execute();
        $stats['loans'] = $loanStmt->fetch(PDO::FETCH_ASSOC);
        $stats['loans']['pending_loans'] = $pendingApps;
        $stats['loans']['rejected_loans'] = (int)$db->query("SELECT COUNT(*) FROM loan_applications WHERE status = 'rejected'")->fetchColumn();

        $paymentStmt = $db->prepare(
            "SELECT COUNT(*) as total_payments,
                    COALESCE(SUM(amount_paid_mwk), 0) as total_collected,
                    COALESCE(SUM(CASE WHEN payment_status = 'completed' THEN amount_paid_mwk ELSE 0 END), 0) as successful_amount
             FROM repayments"
        );
        $paymentStmt->execute();
        $stats['payments'] = $paymentStmt->fetch(PDO::FETCH_ASSOC);

        $approvalRate = (int)$stats['loans']['total_loans'] > 0
            ? round((((float)$stats['loans']['active_loans'] + (float)$stats['loans']['repaid_loans']) / (float)$stats['loans']['total_loans']) * 100)
            : 0;
        $defaultRate = (int)$stats['loans']['total_loans'] > 0
            ? round(((float)$stats['loans']['overdue_loans'] / (float)$stats['loans']['total_loans']) * 100, 1)
            : 0;
        $repaymentRate = (float)$stats['loans']['total_disbursed'] > 0
            ? round((((float)$stats['loans']['total_disbursed'] - (float)$stats['loans']['total_outstanding']) / (float)$stats['loans']['total_disbursed']) * 100, 1)
            : 0;

        $activityStmt = $db->prepare(
            "SELECT 'loan' as type, l.loan_id as id, l.user_id, u.full_name, l.principal_mwk as amount,
                    l.status, l.created_at as activity_date
             FROM loans l
             JOIN users u ON l.user_id = u.user_id
             ORDER BY l.created_at DESC
             LIMIT 10"
        );
        $activityStmt->execute();
        $recentActivities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

        $recentPaymentsStmt = $db->prepare(
            "SELECT r.repayment_id, r.loan_id, r.user_id, r.amount_paid_mwk as payment_amount,
                    r.payment_method, r.payment_reference as transaction_reference, r.paid_at as payment_date,
                    r.payment_status, u.full_name
             FROM repayments r
             JOIN users u ON r.user_id = u.user_id
             ORDER BY r.paid_at DESC
             LIMIT 10"
        );
        $recentPaymentsStmt->execute();
        $recentPayments = $recentPaymentsStmt->fetchAll(PDO::FETCH_ASSOC);

        $pendingVerificationStmt = $db->prepare(
            "SELECT user_id, full_name, email, phone, verification_status, created_at
             FROM users
             WHERE verification_status = 'pending' AND role = 'customer'
             ORDER BY created_at DESC
             LIMIT 5"
        );
        $pendingVerificationStmt->execute();
        $pendingVerifications = $pendingVerificationStmt->fetchAll(PDO::FETCH_ASSOC);

        $highRiskStmt = $db->prepare(
            "SELECT l.loan_id, l.user_id, l.principal_mwk as loan_amount, l.outstanding_balance_mwk as remaining_balance,
                    l.due_date, l.status, u.full_name, u.email, u.credit_score
             FROM loans l
             JOIN users u ON l.user_id = u.user_id
             WHERE l.status = 'overdue'
             ORDER BY l.due_date ASC
             LIMIT 5"
        );
        $highRiskStmt->execute();
        $highRiskLoans = $highRiskStmt->fetchAll(PDO::FETCH_ASSOC);

        $dailyLoansStmt = $db->prepare(
            "SELECT DATE(applied_at) as date, COUNT(*) as count
             FROM loan_applications
             WHERE applied_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY DATE(applied_at)
             ORDER BY date ASC"
        );
        $dailyLoansStmt->execute();
        $dailyLoans = $dailyLoansStmt->fetchAll(PDO::FETCH_ASSOC);

        api_success(200, [
            'stats' => $stats,
            'rates' => [
                'approvalRate' => $approvalRate,
                'defaultRate' => $defaultRate,
                'repaymentRate' => $repaymentRate,
            ],
            'recentActivities' => $recentActivities,
            'recentPayments' => $recentPayments,
            'pendingVerifications' => $pendingVerifications,
            'highRiskLoans' => $highRiskLoans,
            'dailyLoanApplications' => $dailyLoans,
        ]);
    }

    if ($method === 'GET' && $routePath === '/admin/loan-applications/pending') {
        api_require_auth(['admin', 'manager']);

        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 500) {
            $limit = 500;
        }
        $search = sanitize_input((string)($_GET['search'] ?? ''));

        $loanObj = new Loan($db);
        $items = $loanObj->getPendingApplicationsFifo($limit);

        if ($search !== '') {
            $items = array_values(array_filter($items, static function (array $a) use ($search): bool {
                return (stripos((string)($a['full_name'] ?? ''), $search) !== false)
                    || (stripos((string)($a['email'] ?? ''), $search) !== false)
                    || (stripos((string)($a['application_ref'] ?? ''), $search) !== false);
            }));
        }

        $payload = array_map(static fn(array $a): array => api_admin_application_payload($a), $items);
        api_success(200, ['items' => $payload]);
    }

    if ($method === 'POST' && preg_match('#^/admin/loan-applications/(\d+)/process$#', $routePath, $matches) === 1) {
        api_require_auth(['admin', 'manager']);
        api_require_csrf_header();
        $applicationId = (int)$matches[1];
        if ($applicationId <= 0) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid application id');
        }

        $loanObj = new Loan($db);
        $result = $loanObj->processLoanApplication($applicationId, (int)get_user_id());
        if (empty($result['success'])) {
            api_error(400, 'PROCESS_FAILED', (string)($result['message'] ?? 'Unable to process application'));
        }

        log_audit((int)get_user_id(), 'LOAN_APPLICATION_PROCESSED', 'loan_applications', $applicationId, null, $result);
        api_success(200, ['result' => $result]);
    }

    if ($method === 'POST' && preg_match('#^/admin/loan-applications/(\d+)/reject$#', $routePath, $matches) === 1) {
        api_require_auth(['admin', 'manager']);
        api_require_csrf_header();
        $applicationId = (int)$matches[1];
        $body = api_json_input();
        $rejectionReason = sanitize_input((string)($body['rejectionReason'] ?? 'Rejected by admin'));

        if ($applicationId <= 0) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid application id');
        }
        if ($rejectionReason === '') {
            api_error(400, 'VALIDATION_ERROR', 'Rejection reason is required', [
                'rejectionReason' => 'Rejection reason is required',
            ]);
        }

        $loanObj = new Loan($db);
        $result = $loanObj->forceRejectApplication($applicationId, $rejectionReason, (int)get_user_id());
        if (empty($result['success'])) {
            api_error(400, 'REJECT_FAILED', (string)($result['message'] ?? 'Unable to reject application'));
        }

        log_audit((int)get_user_id(), 'LOAN_APPLICATION_REJECTED', 'loan_applications', $applicationId, null, ['reason' => $rejectionReason]);
        api_success(200, ['result' => $result]);
    }

    if ($method === 'GET' && $routePath === '/admin/verifications/pending') {
        api_require_auth(['admin', 'manager']);

        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 500) {
            $limit = 500;
        }

        $stmt = $db->prepare(
            "SELECT u.user_id, u.full_name, u.email, u.phone, u.role, u.verification_status, u.account_status,
                    u.credit_score, u.created_at,
                    u.profile_photo AS selfie_path,
                    (
                        SELECT ud.file_path
                        FROM user_documents ud
                        WHERE ud.user_id = u.user_id AND ud.doc_type = 'national_id'
                        ORDER BY ud.uploaded_at DESC
                        LIMIT 1
                    ) AS id_document_path
             FROM users u
             WHERE u.verification_status = 'pending' AND u.role = 'customer'
             ORDER BY u.created_at ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $payload = array_map(static fn(array $u): array => api_user_payload($u), $items);

        api_success(200, [
            'items' => $payload,
            'pendingCount' => count($items),
            'documents' => array_map(static function (array $u): array {
                return [
                    'userId' => (int)$u['user_id'],
                    'selfiePath' => (string)($u['selfie_path'] ?? ''),
                    'idDocumentPath' => (string)($u['id_document_path'] ?? ''),
                ];
            }, $items),
        ]);
    }

    if ($method === 'POST' && preg_match('#^/admin/verifications/(\d+)/verify$#', $routePath, $matches) === 1) {
        api_require_auth(['admin', 'manager']);
        api_require_csrf_header();
        $userId = (int)$matches[1];

        if ($userId <= 0) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid user id');
        }

        $targetUser = api_fetch_user($db, $userId);
        if (!$targetUser) {
            api_error(404, 'NOT_FOUND', 'User not found');
        }

        $db->beginTransaction();
        try {
            $updateStmt = $db->prepare("UPDATE users SET verification_status = 'verified' WHERE user_id = :user_id");
            $updateStmt->execute([':user_id' => $userId]);

            $creditStmt = $db->prepare("UPDATE users SET credit_score = credit_score + 50 WHERE user_id = :user_id");
            $creditStmt->execute([':user_id' => $userId]);

            $notifStmt = $db->prepare(
                "INSERT INTO notifications (user_id, notification_type, title, message)
                 VALUES (:user_id, 'system', 'Account Verified!',
                         'Your account has been verified. You can now apply for loans.')"
            );
            $notifStmt->execute([':user_id' => $userId]);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        log_audit((int)get_user_id(), 'USER_VERIFIED', 'users', $userId);
        $updatedUser = api_fetch_user($db, $userId);

        api_success(200, [
            'message' => 'User verified successfully',
            'user' => $updatedUser ? api_user_payload($updatedUser) : ['userId' => $userId],
        ]);
    }

    if ($method === 'POST' && preg_match('#^/admin/verifications/(\d+)/reject$#', $routePath, $matches) === 1) {
        api_require_auth(['admin', 'manager']);
        api_require_csrf_header();
        $userId = (int)$matches[1];
        $body = api_json_input();
        $rejectionReason = sanitize_input((string)($body['rejectionReason'] ?? 'Verification documents rejected'));

        if ($userId <= 0) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid user id');
        }
        if ($rejectionReason === '') {
            api_error(400, 'VALIDATION_ERROR', 'Rejection reason is required', [
                'rejectionReason' => 'Rejection reason is required',
            ]);
        }

        $targetUser = api_fetch_user($db, $userId);
        if (!$targetUser) {
            api_error(404, 'NOT_FOUND', 'User not found');
        }

        $db->beginTransaction();
        try {
            $updateStmt = $db->prepare("UPDATE users SET verification_status = 'rejected' WHERE user_id = :user_id");
            $updateStmt->execute([':user_id' => $userId]);

            $notifStmt = $db->prepare(
                "INSERT INTO notifications (user_id, notification_type, title, message)
                 VALUES (:user_id, 'system', 'Verification Rejected', :message)"
            );
            $notifStmt->execute([
                ':user_id' => $userId,
                ':message' => 'Your verification was rejected: ' . $rejectionReason . '. Please resubmit your documents.',
            ]);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        log_audit((int)get_user_id(), 'USER_VERIFICATION_REJECTED', 'users', $userId, null, ['reason' => $rejectionReason]);
        $updatedUser = api_fetch_user($db, $userId);

        api_success(200, [
            'message' => 'Verification rejected',
            'user' => $updatedUser ? api_user_payload($updatedUser) : ['userId' => $userId],
        ]);
    }

    if ($method === 'GET' && $routePath === '/admin/users') {
        api_require_auth(['admin', 'manager']);

        $status = sanitize_input((string)($_GET['status'] ?? 'all'));
        $verification = sanitize_input((string)($_GET['verification'] ?? 'all'));
        $search = sanitize_input((string)($_GET['search'] ?? ''));
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
        if ($limit !== null && $limit <= 0) {
            $limit = null;
        }

        $filters = ['role' => 'customer'];
        if ($status !== '' && $status !== 'all') {
            $filters['account_status'] = $status;
        }
        if ($verification !== '' && $verification !== 'all') {
            $filters['verification_status'] = $verification;
        }
        if ($search !== '') {
            $filters['search'] = $search;
        }
        if ($limit !== null) {
            $filters['limit'] = $limit;
        }

        $userObj = new User($db);
        $items = $userObj->getAllUsers($filters);
        $payload = array_map(static fn(array $u): array => api_user_payload($u), $items);

        $totalUsers = count($userObj->getAllUsers(['role' => 'customer']));
        $verifiedUsers = count($userObj->getAllUsers(['role' => 'customer', 'verification_status' => 'verified']));
        $pendingUsers = count($userObj->getAllUsers(['role' => 'customer', 'verification_status' => 'pending']));
        $activeUsers = count($userObj->getAllUsers(['role' => 'customer', 'account_status' => 'active']));

        api_success(200, [
            'items' => $payload,
            'stats' => [
                'totalUsers' => $totalUsers,
                'verifiedUsers' => $verifiedUsers,
                'pendingUsers' => $pendingUsers,
                'activeUsers' => $activeUsers,
            ],
        ]);
    }

    if ($method === 'POST' && preg_match('#^/admin/users/(\d+)/status$#', $routePath, $matches) === 1) {
        api_require_auth(['admin', 'manager']);
        api_require_csrf_header();
        $userId = (int)$matches[1];
        $body = api_json_input();
        $status = sanitize_input((string)($body['status'] ?? ''));

        if ($userId <= 0) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid user id');
        }
        if (!in_array($status, ['active', 'suspended'], true)) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid status value', [
                'status' => 'Status must be one of: active, suspended',
            ]);
        }

        $targetUser = api_fetch_user($db, $userId);
        if (!$targetUser) {
            api_error(404, 'NOT_FOUND', 'User not found');
        }

        $userObj = new User($db);
        if (!$userObj->updateAccountStatus($userId, $status)) {
            api_error(400, 'UPDATE_FAILED', 'Failed to update account status');
        }

        if ($status === 'suspended') {
            $notifStmt = $db->prepare(
                "INSERT INTO notifications (user_id, notification_type, title, message)
                 VALUES (:user_id, 'system', 'Account Suspended',
                         'Your account has been suspended. Please contact support.')"
            );
            $notifStmt->execute([':user_id' => $userId]);
            log_audit((int)get_user_id(), 'USER_SUSPENDED', 'users', $userId);
        } else {
            log_audit((int)get_user_id(), 'USER_ACTIVATED', 'users', $userId);
        }

        $updatedUser = api_fetch_user($db, $userId);
        api_success(200, [
            'message' => $status === 'suspended' ? 'User account suspended' : 'User account activated',
            'user' => $updatedUser ? api_user_payload($updatedUser) : ['userId' => $userId],
        ]);
    }

    if ($method === 'POST' && preg_match('#^/admin/users/(\d+)/credit-score$#', $routePath, $matches) === 1) {
        api_require_auth(['admin', 'manager']);
        api_require_csrf_header();
        $userId = (int)$matches[1];
        $body = api_json_input();
        $creditScore = isset($body['creditScore']) ? (int)$body['creditScore'] : 0;
        $reason = sanitize_input((string)($body['reason'] ?? ''));

        if ($userId <= 0) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid user id');
        }
        if ($creditScore < 300 || $creditScore > 850) {
            api_error(400, 'VALIDATION_ERROR', 'Credit score must be between 300 and 850', [
                'creditScore' => 'Credit score must be between 300 and 850',
            ]);
        }

        $targetUser = api_fetch_user($db, $userId);
        if (!$targetUser) {
            api_error(404, 'NOT_FOUND', 'User not found');
        }

        $userObj = new User($db);
        if (!$userObj->updateCreditScore($userId, $creditScore, $reason)) {
            api_error(400, 'UPDATE_FAILED', 'Failed to update credit score');
        }

        log_audit((int)get_user_id(), 'CREDIT_SCORE_ADJUSTED', 'users', $userId, null, [
            'new_score' => $creditScore,
            'reason' => $reason,
        ]);
        $updatedUser = api_fetch_user($db, $userId);

        api_success(200, [
            'message' => 'Credit score updated successfully',
            'user' => $updatedUser ? api_user_payload($updatedUser) : ['userId' => $userId],
        ]);
    }

    if ($method === 'GET' && $routePath === '/admin/payments') {
        api_require_auth(['admin', 'manager']);

        $filterMethod = sanitize_input((string)($_GET['method'] ?? 'all'));
        $filterStatus = sanitize_input((string)($_GET['status'] ?? 'all'));
        $search = sanitize_input((string)($_GET['search'] ?? ''));
        $dateFrom = sanitize_input((string)($_GET['date_from'] ?? ''));
        $dateTo = sanitize_input((string)($_GET['date_to'] ?? ''));
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 500) {
            $limit = 500;
        }

        $query = "SELECT r.repayment_id, r.loan_id, r.user_id, r.amount_paid_mwk AS payment_amount, r.payment_method,
                         r.payment_reference AS transaction_reference, r.paid_at AS payment_date, r.payment_status,
                         r.is_partial, u.full_name, u.email, l.principal_mwk AS loan_amount
                  FROM repayments r
                  JOIN users u ON r.user_id = u.user_id
                  JOIN loans l ON r.loan_id = l.loan_id
                  WHERE 1=1";
        $params = [];

        if ($filterMethod !== 'all' && $filterMethod !== '') {
            $query .= " AND r.payment_method = :method";
            $params[':method'] = $filterMethod;
        }
        if ($filterStatus !== 'all' && $filterStatus !== '') {
            $query .= " AND r.payment_status = :status";
            $params[':status'] = $filterStatus;
        }
        if ($search !== '') {
            $query .= " AND (u.full_name LIKE :search OR u.email LIKE :search OR r.payment_reference LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        if ($dateFrom !== '') {
            $query .= " AND DATE(r.paid_at) >= :date_from";
            $params[':date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $query .= " AND DATE(r.paid_at) <= :date_to";
            $params[':date_to'] = $dateTo;
        }
        $query .= " ORDER BY r.paid_at DESC LIMIT :limit";

        $stmt = $db->prepare($query);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $statsStmt = $db->prepare(
            "SELECT COUNT(*) as total_transactions,
                    SUM(CASE WHEN payment_status = 'completed' THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN payment_status = 'failed' THEN 1 ELSE 0 END) as failed,
                    COALESCE(SUM(CASE WHEN payment_status = 'completed' THEN amount_paid_mwk ELSE 0 END), 0) as total_collected,
                    COALESCE(SUM(amount_paid_mwk), 0) as total_amount
             FROM repayments"
        );
        $statsStmt->execute();
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

        $methodStmt = $db->prepare(
            "SELECT payment_method, COUNT(*) as count,
                    COALESCE(SUM(CASE WHEN payment_status = 'completed' THEN amount_paid_mwk ELSE 0 END), 0) as total
             FROM repayments
             WHERE payment_status = 'completed'
             GROUP BY payment_method"
        );
        $methodStmt->execute();
        $paymentMethods = $methodStmt->fetchAll(PDO::FETCH_ASSOC);

        api_success(200, [
            'items' => array_map(static fn(array $p): array => api_repayment_payload($p), $payments),
            'stats' => $stats,
            'methodBreakdown' => $paymentMethods,
        ]);
    }

    if ($method === 'GET' && $routePath === '/admin/platform-accounts') {
        api_require_auth(['admin', 'manager']);
        $loanObj = new Loan($db);
        $accounts = $loanObj->getPlatformAccounts(false);

        $activeCount = 0;
        $defaultAccount = null;
        $totalBalance = 0.0;
        foreach ($accounts as $acc) {
            if ((int)$acc['is_active'] === 1) {
                $activeCount++;
                $totalBalance += (float)$acc['current_balance_mwk'];
            }
            if ((int)$acc['is_default'] === 1 && $defaultAccount === null) {
                $defaultAccount = $acc;
            }
        }

        api_success(200, [
            'items' => $accounts,
            'stats' => [
                'totalAccounts' => count($accounts),
                'activeAccounts' => $activeCount,
                'totalActiveBalanceMwk' => $totalBalance,
            ],
            'defaultAccount' => $defaultAccount,
        ]);
    }

    if ($method === 'POST' && $routePath === '/admin/platform-accounts') {
        api_require_auth(['admin', 'manager']);
        api_require_csrf_header();
        $body = api_json_input();

        $data = [
            'account_type' => sanitize_input((string)($body['accountType'] ?? '')),
            'account_provider' => sanitize_input((string)($body['accountProvider'] ?? '')),
            'account_name' => sanitize_input((string)($body['accountName'] ?? '')),
            'account_number' => sanitize_input((string)($body['accountNumber'] ?? '')),
            'currency_code' => sanitize_input((string)($body['currencyCode'] ?? 'MWK')),
            'current_balance_mwk' => (float)($body['currentBalanceMwk'] ?? 0),
            'is_default' => !empty($body['isDefault']) ? 1 : 0,
            'is_active' => array_key_exists('isActive', $body) ? (!empty($body['isActive']) ? 1 : 0) : 1,
        ];

        $loanObj = new Loan($db);
        $result = $loanObj->createPlatformAccount($data);
        if (empty($result['success'])) {
            api_error(400, 'CREATE_FAILED', (string)($result['message'] ?? 'Failed to create platform account'));
        }

        log_audit((int)get_user_id(), 'PLATFORM_ACCOUNT_CREATED', 'platform_accounts', (int)$result['account_id'], null, $data);
        api_success(201, ['accountId' => (int)$result['account_id']]);
    }

    if ($method === 'POST' && preg_match('#^/admin/platform-accounts/(\d+)/default$#', $routePath, $matches) === 1) {
        api_require_auth(['admin', 'manager']);
        api_require_csrf_header();
        $accountId = (int)$matches[1];
        $loanObj = new Loan($db);
        $result = $loanObj->setDefaultPlatformAccount($accountId);
        if (empty($result['success'])) {
            api_error(400, 'UPDATE_FAILED', (string)($result['message'] ?? 'Failed to set default account'));
        }
        log_audit((int)get_user_id(), 'PLATFORM_ACCOUNT_SET_DEFAULT', 'platform_accounts', $accountId);
        api_success(200, ['message' => 'Default platform account updated']);
    }

    if ($method === 'POST' && preg_match('#^/admin/platform-accounts/(\d+)/status$#', $routePath, $matches) === 1) {
        api_require_auth(['admin', 'manager']);
        api_require_csrf_header();
        $accountId = (int)$matches[1];
        $body = api_json_input();
        $isActive = !empty($body['isActive']) ? 1 : 0;

        $loanObj = new Loan($db);
        $result = $loanObj->setPlatformAccountStatus($accountId, $isActive);
        if (empty($result['success'])) {
            api_error(400, 'UPDATE_FAILED', (string)($result['message'] ?? 'Failed to update account status'));
        }
        log_audit((int)get_user_id(), 'PLATFORM_ACCOUNT_STATUS_CHANGED', 'platform_accounts', $accountId, null, ['is_active' => $isActive]);
        api_success(200, ['message' => $isActive ? 'Account activated' : 'Account deactivated']);
    }

    if ($method === 'POST' && preg_match('#^/admin/platform-accounts/(\d+)/balance$#', $routePath, $matches) === 1) {
        api_require_auth(['admin', 'manager']);
        api_require_csrf_header();
        $accountId = (int)$matches[1];
        $body = api_json_input();
        $newBalance = (float)($body['currentBalanceMwk'] ?? -1);
        if ($newBalance < 0) {
            api_error(400, 'VALIDATION_ERROR', 'Balance must be zero or greater', [
                'currentBalanceMwk' => 'Balance must be zero or greater',
            ]);
        }
        $loanObj = new Loan($db);
        $result = $loanObj->updatePlatformAccountBalance($accountId, $newBalance);
        if (empty($result['success'])) {
            api_error(400, 'UPDATE_FAILED', (string)($result['message'] ?? 'Failed to update account balance'));
        }
        log_audit((int)get_user_id(), 'PLATFORM_ACCOUNT_BALANCE_UPDATED', 'platform_accounts', $accountId, null, ['current_balance_mwk' => $newBalance]);
        api_success(200, ['message' => 'Account balance updated']);
    }

    if ($method === 'GET' && $routePath === '/admin/settings') {
        api_require_auth(['admin']);

        $settingsStmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key");
        $settingsStmt->execute();
        $allSettings = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);

        $settings = [];
        foreach ($allSettings as $setting) {
            $settings[(string)$setting['setting_key']] = $setting['setting_value'];
        }
        if (!isset($settings['default_loan_term_days']) && isset($settings['default_loan_term'])) {
            $settings['default_loan_term_days'] = $settings['default_loan_term'];
        }

        $statsStmt = $db->prepare(
            "SELECT (SELECT COUNT(*) FROM users WHERE role = 'customer') as total_users,
                    (SELECT COUNT(*) FROM loans) as total_loans,
                    (SELECT COUNT(*) FROM repayments) as total_payments,
                    (SELECT COALESCE(SUM(principal_mwk), 0) FROM loans) as total_disbursed"
        );
        $statsStmt->execute();
        $systemStats = $statsStmt->fetch(PDO::FETCH_ASSOC);

        api_success(200, [
            'settings' => $settings,
            'systemStats' => $systemStats,
        ]);
    }

    if ($method === 'POST' && $routePath === '/admin/settings') {
        api_require_auth(['admin']);
        api_require_csrf_header();
        $body = api_json_input();

        $settingsToUpdate = [
            'min_loan_amount',
            'max_loan_amount',
            'default_interest_rate',
            'default_loan_term_days',
            'late_payment_penalty_rate',
            'min_credit_score',
            'max_active_loans',
        ];

        $updatedCount = 0;
        foreach ($settingsToUpdate as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $value = sanitize_input((string)$body[$key]);
            $stmt = $db->prepare(
                "UPDATE system_settings
                 SET setting_value = :value, updated_by = :updated_by
                 WHERE setting_key = :key"
            );
            $ok = $stmt->execute([
                ':value' => $value,
                ':updated_by' => (int)get_user_id(),
                ':key' => $key,
            ]);
            if ($ok) {
                $updatedCount++;
            }
        }

        if ($updatedCount < 1) {
            api_error(400, 'VALIDATION_ERROR', 'No supported settings were updated');
        }

        log_audit((int)get_user_id(), 'SETTINGS_UPDATED', 'system_settings', null, null, $body);
        api_success(200, ['updatedCount' => $updatedCount]);
    }

    if ($method === 'GET' && $routePath === '/admin/reports/summary') {
        api_require_auth(['admin', 'manager']);

        $dateFrom = sanitize_input((string)($_GET['date_from'] ?? date('Y-m-01')));
        $dateTo = sanitize_input((string)($_GET['date_to'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            api_error(400, 'VALIDATION_ERROR', 'Invalid date range. Use YYYY-MM-DD for date_from and date_to.');
        }

        $financialStmt = $db->prepare(
            "SELECT COALESCE(SUM(l.principal_mwk), 0) as total_disbursed,
                    COALESCE(SUM(CASE WHEN l.status IN ('active','overdue') THEN l.outstanding_balance_mwk ELSE 0 END), 0) as total_outstanding,
                    (SELECT COALESCE(SUM(amount_paid_mwk), 0)
                     FROM repayments
                     WHERE payment_status = 'completed'
                       AND DATE(paid_at) BETWEEN :date_from1 AND :date_to1) as total_collected,
                    COUNT(DISTINCT CASE WHEN l.created_at BETWEEN :date_from2 AND :date_to2 THEN l.user_id END) as active_borrowers
             FROM loans l
             WHERE l.created_at BETWEEN :date_from3 AND :date_to3"
        );
        $financialStmt->execute([
            ':date_from1' => $dateFrom,
            ':date_to1' => $dateTo,
            ':date_from2' => $dateFrom,
            ':date_to2' => $dateTo,
            ':date_from3' => $dateFrom,
            ':date_to3' => $dateTo,
        ]);
        $financial = $financialStmt->fetch(PDO::FETCH_ASSOC);

        $performanceStmt = $db->prepare(
            "SELECT COUNT(*) as total_loans,
                    SUM(CASE WHEN status IN ('active', 'completed') THEN 1 ELSE 0 END) as approved_loans,
                    0 as rejected_loans,
                    SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_loans,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as repaid_loans,
                    AVG(principal_mwk) as avg_loan_amount
             FROM loans
             WHERE created_at BETWEEN :date_from AND :date_to"
        );
        $performanceStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
        $performance = $performanceStmt->fetch(PDO::FETCH_ASSOC);

        $dailyStmt = $db->prepare(
            "SELECT DATE(created_at) as date,
                    COUNT(*) as applications,
                    SUM(CASE WHEN status IN ('active', 'completed') THEN 1 ELSE 0 END) as approved
             FROM loans
             WHERE created_at BETWEEN :date_from AND :date_to
             GROUP BY DATE(created_at)
             ORDER BY date ASC"
        );
        $dailyStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
        $dailyLoans = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

        $userGrowthStmt = $db->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as new_users
             FROM users
             WHERE created_at BETWEEN :date_from AND :date_to
               AND role = 'customer'
             GROUP BY DATE(created_at)
             ORDER BY date ASC"
        );
        $userGrowthStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
        $userGrowth = $userGrowthStmt->fetchAll(PDO::FETCH_ASSOC);

        $topBorrowersStmt = $db->prepare(
            "SELECT u.user_id, u.full_name, u.email, u.credit_score,
                    COUNT(l.loan_id) as loan_count,
                    SUM(l.principal_mwk) as total_borrowed,
                    SUM(CASE WHEN l.status = 'completed' THEN 1 ELSE 0 END) as repaid_count
             FROM users u
             JOIN loans l ON u.user_id = l.user_id
             WHERE l.created_at BETWEEN :date_from AND :date_to
             GROUP BY u.user_id
             ORDER BY total_borrowed DESC
             LIMIT 10"
        );
        $topBorrowersStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
        $topBorrowers = $topBorrowersStmt->fetchAll(PDO::FETCH_ASSOC);

        $approvalRate = ((int)($performance['total_loans'] ?? 0) > 0)
            ? round(((float)($performance['approved_loans'] ?? 0) / (float)$performance['total_loans']) * 100, 1)
            : 0;
        $defaultRate = ((int)($performance['total_loans'] ?? 0) > 0)
            ? round(((float)($performance['overdue_loans'] ?? 0) / (float)$performance['total_loans']) * 100, 1)
            : 0;
        $collectionEfficiency = ((float)($financial['total_disbursed'] ?? 0) > 0)
            ? round(((float)($financial['total_collected'] ?? 0) / (float)$financial['total_disbursed']) * 100, 1)
            : 0;

        api_success(200, [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'financial' => $financial,
            'performance' => $performance,
            'rates' => [
                'approvalRate' => $approvalRate,
                'defaultRate' => $defaultRate,
                'collectionEfficiency' => $collectionEfficiency,
            ],
            'dailyLoans' => $dailyLoans,
            'userGrowth' => $userGrowth,
            'topBorrowers' => $topBorrowers,
        ]);
    }

    api_error(404, 'NOT_FOUND', 'API endpoint not found');
} catch (Throwable $e) {
    error_log('API unhandled error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    api_error(500, 'INTERNAL_ERROR', 'An unexpected error occurred');
}
