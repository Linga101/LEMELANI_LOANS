-- ============================================================
--  LEMELANI LOANS — INTEGRATED MALAWI SCHEMA
--  Engine: MySQL 8.0+
--  Currency: MWK (Malawian Kwacha)
--  Loan processing: FIFO (First In, First Out)
-- ============================================================

CREATE DATABASE IF NOT EXISTS lemelani_loans
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE lemelani_loans;

-- ============================================================
-- 1. USERS — Core borrower and staff accounts (Lemelani + Malawi)
-- ============================================================
CREATE TABLE users (
    user_id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name         VARCHAR(150)        NOT NULL,
    national_id       VARCHAR(30)         NOT NULL UNIQUE,
    phone             VARCHAR(20)         NOT NULL UNIQUE,
    email             VARCHAR(150)       UNIQUE,
    date_of_birth     DATE,
    gender            ENUM('male','female','other'),
    password_hash     VARCHAR(255)        NOT NULL,
    profile_photo     VARCHAR(255),
    is_verified       TINYINT(1)          DEFAULT 0,
    verification_status ENUM('pending','verified','rejected') DEFAULT 'pending',
    account_status    ENUM('active','suspended','blacklisted','closed') DEFAULT 'active',
    role              ENUM('customer','admin','manager')     DEFAULT 'customer',
    credit_score      SMALLINT UNSIGNED   DEFAULT 300,
    last_login        TIMESTAMP NULL,
    created_at        TIMESTAMP           DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP           DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_national_id (national_id),
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_account_status (account_status),
    INDEX idx_is_verified (is_verified)
);

-- ============================================================
-- 2. USER PROFILES — Employment & financial background (Malawi)
-- ============================================================
CREATE TABLE user_profiles (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id               INT UNSIGNED NOT NULL UNIQUE,
    employment_type       ENUM('employed','self_employed','business_owner','unemployed','student') NOT NULL DEFAULT 'employed',
    employer_name         VARCHAR(150),
    monthly_income_mwk    DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
    occupation            VARCHAR(100),
    residential_address   TEXT,
    district              VARCHAR(100),
    residence_type        ENUM('owned','rented','family') DEFAULT 'rented',
    years_at_address      TINYINT UNSIGNED DEFAULT 0,
    next_of_kin_name      VARCHAR(150),
    next_of_kin_phone     VARCHAR(20),
    next_of_kin_relation  VARCHAR(50),
    created_at            TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ============================================================
-- 2b. CUSTOMER ACCOUNTS -- Beneficiary accounts for disbursement
-- ============================================================
CREATE TABLE customer_accounts (
    account_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    account_type        ENUM('bank_account','mobile_money','wallet') NOT NULL DEFAULT 'bank_account',
    account_provider    VARCHAR(100) NOT NULL,
    account_name        VARCHAR(150) NOT NULL,
    account_number      VARCHAR(40) NOT NULL,
    branch_name         VARCHAR(100),
    swift_code          VARCHAR(20),
    is_default          TINYINT(1) DEFAULT 0,
    is_active           TINYINT(1) DEFAULT 1,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_accounts (user_id, is_active),
    UNIQUE KEY uq_user_account (user_id, account_type, account_provider, account_number)
);

-- ============================================================
-- 2c. PLATFORM ACCOUNTS -- Lending source accounts
-- ============================================================
CREATE TABLE platform_accounts (
    account_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_type        ENUM('bank_account','mobile_money','wallet','escrow') NOT NULL DEFAULT 'bank_account',
    account_provider    VARCHAR(100) NOT NULL,
    account_name        VARCHAR(150) NOT NULL,
    account_number      VARCHAR(40) NOT NULL,
    currency_code       CHAR(3) NOT NULL DEFAULT 'MWK',
    current_balance_mwk DECIMAL(15,2) DEFAULT 0.00,
    is_default          TINYINT(1) DEFAULT 0,
    is_active           TINYINT(1) DEFAULT 1,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_platform_active (is_active, is_default),
    UNIQUE KEY uq_platform_account (account_type, account_provider, account_number)
);

-- ============================================================
-- 3. CREDIT SCORES — Computed creditworthiness (Malawi 300–850)
-- ============================================================
CREATE TABLE credit_scores (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                  INT UNSIGNED NOT NULL,
    total_score              SMALLINT UNSIGNED NOT NULL DEFAULT 300,
    credit_tier              ENUM('exceptional','very_good','good','fair','poor') NOT NULL DEFAULT 'fair',
    payment_history_score    SMALLINT UNSIGNED DEFAULT 0,
    credit_utilization_score SMALLINT UNSIGNED DEFAULT 0,
    loan_history_score       SMALLINT UNSIGNED DEFAULT 0,
    income_stability_score   SMALLINT UNSIGNED DEFAULT 0,
    alternative_data_score   SMALLINT UNSIGNED DEFAULT 0,
    scoring_model_version    VARCHAR(20)      DEFAULT 'MLW-v1.0',
    assessed_by              ENUM('system','manual_review') DEFAULT 'system',
    notes                    TEXT,
    assessed_at              TIMESTAMP        DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_score (user_id, assessed_at)
);

-- ============================================================
-- 4. LOAN PRODUCTS — Product types (Malawi)
-- ============================================================
CREATE TABLE loan_products (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_name          VARCHAR(100)     NOT NULL,
    description           TEXT,
    min_amount_mwk        DECIMAL(15,2)    NOT NULL,
    max_amount_mwk        DECIMAL(15,2)    NOT NULL,
    min_term_months       TINYINT UNSIGNED NOT NULL,
    max_term_months       TINYINT UNSIGNED NOT NULL,
    base_interest_rate    DECIMAL(5,2)     NOT NULL,
    min_credit_score      SMALLINT UNSIGNED NOT NULL DEFAULT 400,
    is_active             TINYINT(1)       DEFAULT 1,
    created_at            TIMESTAMP        DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO loan_products (product_name, description, min_amount_mwk, max_amount_mwk, min_term_months, max_term_months, base_interest_rate, min_credit_score) VALUES
('Salary Advance Loan',   'Short-term loan against monthly salary',    10000,    500000,   1,  6,  36.00, 600),
('SME Business Loan',     'Loan for small and medium enterprises',     50000,   5000000,   3, 24,  28.00, 640),
('Agriculture Loan',      'Seasonal farming input finance',            20000,   2000000,   3, 12,  24.00, 580),
('Emergency Loan',        'Quick cash for urgent personal needs',       5000,    200000,   1,  3,  48.00, 500),
('Asset Finance Loan',    'Purchase of equipment or household assets', 100000, 10000000,   6, 60,  22.00, 680),
('Student Loan',          'Tertiary education financial support',      50000,  2000000,    6, 48,  18.00, 520);

-- ============================================================
-- 5. LOAN APPLICATIONS — FIFO queue (Malawi)
-- ============================================================
CREATE TABLE loan_applications (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id              INT UNSIGNED   NOT NULL,
    loan_product_id      INT UNSIGNED   NOT NULL,
    credit_score_id      INT UNSIGNED,
    application_ref      VARCHAR(20)    NOT NULL UNIQUE,
    requested_amount_mwk DECIMAL(15,2)  NOT NULL,
    approved_amount_mwk  DECIMAL(15,2),
    loan_purpose         VARCHAR(255)   NOT NULL,
    term_months          TINYINT UNSIGNED NOT NULL,
    interest_rate        DECIMAL(5,2),
    status               ENUM('pending','under_review','approved','rejected','disbursed','cancelled') DEFAULT 'pending',
    rejection_reason     TEXT,
    reviewed_by          INT UNSIGNED,
    reviewed_at          TIMESTAMP NULL,
    applied_at            TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)          REFERENCES users(user_id),
    FOREIGN KEY (loan_product_id)  REFERENCES loan_products(id),
    FOREIGN KEY (credit_score_id)  REFERENCES credit_scores(id),
    INDEX idx_user_applications (user_id, status),
    INDEX idx_fifo (status, applied_at)
);

-- ============================================================
-- 6. LOANS — Disbursed and ongoing (Malawi)
-- ============================================================
CREATE TABLE loans (
    loan_id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id           INT UNSIGNED   NOT NULL UNIQUE,
    user_id                  INT UNSIGNED   NOT NULL,
    principal_mwk             DECIMAL(15,2)  NOT NULL,
    interest_rate             DECIMAL(5,2)   NOT NULL,
    term_months               TINYINT UNSIGNED NOT NULL,
    monthly_payment_mwk       DECIMAL(15,2)  NOT NULL,
    total_repayable_mwk       DECIMAL(15,2)  NOT NULL,
    outstanding_balance_mwk   DECIMAL(15,2)  NOT NULL,
    disbursed_at              TIMESTAMP NULL,
    due_date                  DATE           NOT NULL,
    status                    ENUM('active','completed','defaulted','restructured','overdue') DEFAULT 'active',
    created_at                TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES loan_applications(id),
    FOREIGN KEY (user_id)        REFERENCES users(user_id),
    INDEX idx_user_loans (user_id, status),
    INDEX idx_due_date (due_date)
);

-- ============================================================
-- 7. REPAYMENTS — Payment events (Malawi + Lemelani compat)
-- ============================================================
CREATE TABLE repayments (
    repayment_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id            INT UNSIGNED   NOT NULL,
    user_id            INT UNSIGNED   NOT NULL,
    amount_paid_mwk    DECIMAL(15,2)  NOT NULL,
    payment_method     ENUM('airtel_money','tnm_mpamba','bank_transfer','cash','sticpay','mastercard','visa','binance','other') NOT NULL,
    payment_reference  VARCHAR(100),
    due_date           DATE           NOT NULL,
    paid_at            TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    days_late          SMALLINT       DEFAULT 0,
    penalty_mwk        DECIMAL(10,2)  DEFAULT 0.00,
    status             ENUM('on_time','late','missed') NOT NULL DEFAULT 'on_time',
    payment_status     ENUM('pending','completed','failed','cancelled') DEFAULT 'completed',
    is_partial         TINYINT(1)     DEFAULT 0,
    notes              TEXT,
    FOREIGN KEY (loan_id)  REFERENCES loans(loan_id),
    FOREIGN KEY (user_id)  REFERENCES users(user_id),
    INDEX idx_loan_repayments (loan_id, status)
);

-- ============================================================
-- 8. REPAYMENT SCHEDULE — Installments (Lemelani)
-- ============================================================
CREATE TABLE repayment_schedule (
    schedule_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id            INT UNSIGNED   NOT NULL,
    installment_number INT UNSIGNED   NOT NULL,
    due_date           DATE           NOT NULL,
    amount_due         DECIMAL(15,2)  NOT NULL,
    amount_paid        DECIMAL(15,2)  DEFAULT 0.00,
    status             ENUM('pending','paid','overdue','partial') DEFAULT 'pending',
    paid_at            TIMESTAMP NULL,
    penalty_amount     DECIMAL(10,2)  DEFAULT 0.00,
    FOREIGN KEY (loan_id) REFERENCES loans(loan_id) ON DELETE CASCADE,
    INDEX idx_loan_schedule (loan_id),
    INDEX idx_due_date (due_date)
);

-- ============================================================
-- 9. CREDIT HISTORY — Events (Lemelani)
-- ============================================================
CREATE TABLE credit_history (
    history_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    event_type   ENUM('loan_applied','loan_approved','loan_rejected','payment_made','payment_missed','loan_repaid','score_adjusted') NOT NULL,
    loan_id      INT UNSIGNED,
    application_id INT UNSIGNED,
    old_score    INT,
    new_score    INT,
    score_change INT,
    description  TEXT,
    created_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (loan_id) REFERENCES loans(loan_id) ON DELETE SET NULL,
    INDEX idx_user_history (user_id),
    INDEX idx_event_type (event_type)
);

-- ============================================================
-- 10. ALTERNATIVE CREDIT DATA — Mobile money, utilities (Malawi)
-- ============================================================
CREATE TABLE alternative_credit_data (
    id                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                   INT UNSIGNED NOT NULL,
    data_type                 ENUM('mobile_money','utility_payment','rent_payment','savings') NOT NULL,
    provider                  VARCHAR(100),
    avg_monthly_transactions  DECIMAL(10,2) DEFAULT 0,
    months_of_history         TINYINT UNSIGNED DEFAULT 0,
    on_time_payment_rate      DECIMAL(5,2)  DEFAULT 0.00,
    recorded_at               TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ============================================================
-- 11. USER DOCUMENTS — KYC (Malawi)
-- ============================================================
CREATE TABLE user_documents (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED  NOT NULL,
    doc_type      ENUM('national_id','passport','employment_letter','payslip','bank_statement','utility_bill','business_registration') NOT NULL,
    file_path     VARCHAR(255)  NOT NULL,
    is_verified   TINYINT(1)    DEFAULT 0,
    verified_by   INT UNSIGNED,
    uploaded_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ============================================================
-- 12. NOTIFICATIONS — In-app (Lemelani)
-- ============================================================
CREATE TABLE notifications (
    notification_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           INT UNSIGNED NOT NULL,
    notification_type ENUM('reminder','approval','rejection','payment_received','overdue','system') NOT NULL,
    title             VARCHAR(255) NOT NULL,
    message           TEXT NOT NULL,
    is_read           TINYINT(1)   DEFAULT 0,
    sent_via          ENUM('in_app','sms','email','all') DEFAULT 'in_app',
    related_loan_id   INT UNSIGNED,
    related_application_id INT UNSIGNED,
    created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (related_loan_id) REFERENCES loans(loan_id) ON DELETE SET NULL,
    INDEX idx_user_notif (user_id),
    INDEX idx_is_read (is_read)
);

-- ============================================================
-- 13. AUDIT LOG — System events (Lemelani compat)
-- ============================================================
CREATE TABLE audit_log (
    log_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED,
    action       VARCHAR(100)   NOT NULL,
    entity_type  VARCHAR(50),
    entity_id    INT UNSIGNED,
    old_values   TEXT,
    new_values   TEXT,
    ip_address   VARCHAR(45),
    user_agent   TEXT,
    created_at   TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id, created_at),
    INDEX idx_action (action)
);

-- ============================================================
-- 13b. PASSWORD RESET TOKENS - Secure password recovery
-- ============================================================
CREATE TABLE password_reset_tokens (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    selector      VARCHAR(24)  NOT NULL UNIQUE,
    token_hash    CHAR(64)     NOT NULL,
    expires_at    DATETIME     NOT NULL,
    used_at       DATETIME NULL,
    requested_ip  VARCHAR(45),
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_selector_expires (selector, expires_at)
);

-- ============================================================
-- 14. SYSTEM SETTINGS — Config (Lemelani)
-- ============================================================
CREATE TABLE system_settings (
    setting_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    setting_type  ENUM('string','number','boolean','json') DEFAULT 'string',
    description   TEXT,
    updated_by    INT UNSIGNED,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_setting_key (setting_key)
);

INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
('min_loan_amount', '10000', 'number', 'Minimum loan amount in MWK'),
('max_loan_amount', '500000', 'number', 'Maximum loan amount in MWK'),
('default_interest_rate', '36.00', 'number', 'Default interest rate % (annual)'),
('default_loan_term_days', '30', 'number', 'Default loan term in days (legacy)'),
('default_loan_term_months', '3', 'number', 'Default loan term in months'),
('late_payment_penalty_rate', '2.00', 'number', 'Late payment penalty % per day'),
('min_credit_score', '500', 'number', 'Minimum credit score for loan approval'),
('max_active_loans', '3', 'number', 'Maximum active loans per user'),
('fifo_enabled', '1', 'boolean', 'Process loan applications in FIFO order');

-- Default admin (password: admin123 — CHANGE IN PRODUCTION)
INSERT INTO users (national_id, full_name, email, phone, password_hash, role, is_verified, verification_status, account_status, credit_score) VALUES
('ADMIN001', 'System Administrator', 'admin@lemelaniloans.com', '+265999000000', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, 'verified', 'active', 850);

-- Default platform disbursement account
INSERT INTO platform_accounts (account_type, account_provider, account_name, account_number, currency_code, current_balance_mwk, is_default, is_active) VALUES
('bank_account', 'National Bank of Malawi', 'Lemelani Loans Treasury', 'LML-TREASURY-001', 'MWK', 0.00, 1, 1);

-- Link disbursement accounts to loan applications and loans
ALTER TABLE loan_applications
    ADD COLUMN customer_account_id INT UNSIGNED NULL AFTER interest_rate,
    ADD COLUMN platform_account_id INT UNSIGNED NULL AFTER customer_account_id,
    ADD INDEX idx_app_customer_account (customer_account_id),
    ADD INDEX idx_app_platform_account (platform_account_id),
    ADD CONSTRAINT fk_app_customer_account FOREIGN KEY (customer_account_id) REFERENCES customer_accounts(account_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_app_platform_account FOREIGN KEY (platform_account_id) REFERENCES platform_accounts(account_id) ON DELETE SET NULL;

ALTER TABLE loans
    ADD COLUMN customer_account_id INT UNSIGNED NULL AFTER due_date,
    ADD COLUMN platform_account_id INT UNSIGNED NULL AFTER customer_account_id,
    ADD INDEX idx_loan_customer_account (customer_account_id),
    ADD INDEX idx_loan_platform_account (platform_account_id),
    ADD CONSTRAINT fk_loan_customer_account FOREIGN KEY (customer_account_id) REFERENCES customer_accounts(account_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_loan_platform_account FOREIGN KEY (platform_account_id) REFERENCES platform_accounts(account_id) ON DELETE SET NULL;

-- ============================================================
-- VIEWS
-- ============================================================

-- Borrower credit profile (Malawi)
CREATE OR REPLACE VIEW v_borrower_profile AS
SELECT
    u.user_id AS id,
    u.user_id,
    u.full_name,
    u.phone,
    u.national_id,
    u.account_status,
    u.credit_score,
    up.monthly_income_mwk,
    up.employment_type,
    up.district,
    cs.total_score,
    cs.credit_tier,
    cs.assessed_at AS last_scored_at,
    COUNT(DISTINCT l.loan_id) AS total_loans,
    SUM(CASE WHEN l.status = 'active' THEN 1 ELSE 0 END) AS active_loans,
    SUM(CASE WHEN l.status = 'defaulted' THEN 1 ELSE 0 END) AS defaulted_loans
FROM users u
LEFT JOIN user_profiles up ON up.user_id = u.user_id
LEFT JOIN (
    SELECT user_id, total_score, credit_tier, assessed_at,
           ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY assessed_at DESC) AS rn
    FROM credit_scores
) cs ON cs.user_id = u.user_id AND cs.rn = 1
LEFT JOIN loans l ON l.user_id = u.user_id
GROUP BY u.user_id, u.full_name, u.phone, u.national_id, u.account_status, u.credit_score,
         up.monthly_income_mwk, up.employment_type, up.district,
         cs.total_score, cs.credit_tier, cs.assessed_at, cs.rn;

-- Loan repayment performance (Malawi)
CREATE OR REPLACE VIEW v_repayment_performance AS
SELECT
    l.loan_id,
    l.user_id,
    COUNT(r.repayment_id) AS total_payments,
    SUM(CASE WHEN r.status = 'on_time' THEN 1 ELSE 0 END) AS on_time_count,
    SUM(CASE WHEN r.status = 'late' THEN 1 ELSE 0 END) AS late_count,
    SUM(CASE WHEN r.status = 'missed' THEN 1 ELSE 0 END) AS missed_count,
    ROUND(COALESCE(SUM(CASE WHEN r.status = 'on_time' THEN 1 ELSE 0 END), 0) / NULLIF(COUNT(r.repayment_id), 0) * 100, 2) AS on_time_rate_pct
FROM loans l
LEFT JOIN repayments r ON r.loan_id = l.loan_id
GROUP BY l.loan_id, l.user_id;

-- Compatibility view: loans as legacy app expects (loan_amount, remaining_balance, total_amount)
CREATE OR REPLACE VIEW v_loans_legacy AS
SELECT
    loan_id,
    application_id,
    user_id,
    principal_mwk AS loan_amount,
    interest_rate,
    term_months AS loan_term_months,
    monthly_payment_mwk,
    total_repayable_mwk AS total_amount,
    outstanding_balance_mwk AS remaining_balance,
    disbursed_at AS disbursement_date,
    due_date,
    status,
    created_at,
    updated_at
FROM loans;
