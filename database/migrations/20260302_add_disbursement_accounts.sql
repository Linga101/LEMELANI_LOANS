USE lemelani_loans;

CREATE TABLE IF NOT EXISTS customer_accounts (
    account_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    account_type        ENUM('airtel_money','tnm_mpamba','sticpay','mastercard','visa','binance','bank_transfer','bank_account','mobile_money','wallet') NOT NULL DEFAULT 'airtel_money',
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

CREATE TABLE IF NOT EXISTS platform_accounts (
    account_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_type        ENUM('airtel_money','tnm_mpamba','sticpay','mastercard','visa','binance','bank_transfer','bank_account','mobile_money','wallet','escrow') NOT NULL DEFAULT 'airtel_money',
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

INSERT INTO platform_accounts (account_type, account_provider, account_name, account_number, currency_code, current_balance_mwk, is_default, is_active)
SELECT 'bank_transfer', 'National Bank of Malawi', 'Lemelani Loans Treasury', 'LML-TREASURY-001', 'MWK', 0.00, 1, 1
WHERE NOT EXISTS (
    SELECT 1 FROM platform_accounts WHERE account_number = 'LML-TREASURY-001'
);

ALTER TABLE loan_applications
    ADD COLUMN IF NOT EXISTS customer_account_id INT UNSIGNED NULL AFTER interest_rate,
    ADD COLUMN IF NOT EXISTS platform_account_id INT UNSIGNED NULL AFTER customer_account_id;

ALTER TABLE loans
    ADD COLUMN IF NOT EXISTS customer_account_id INT UNSIGNED NULL AFTER due_date,
    ADD COLUMN IF NOT EXISTS platform_account_id INT UNSIGNED NULL AFTER customer_account_id;

SET @idx_app_customer_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'loan_applications' AND index_name = 'idx_app_customer_account'
);
SET @sql = IF(@idx_app_customer_exists = 0,
    'ALTER TABLE loan_applications ADD INDEX idx_app_customer_account (customer_account_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_app_platform_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'loan_applications' AND index_name = 'idx_app_platform_account'
);
SET @sql = IF(@idx_app_platform_exists = 0,
    'ALTER TABLE loan_applications ADD INDEX idx_app_platform_account (platform_account_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_loan_customer_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'loans' AND index_name = 'idx_loan_customer_account'
);
SET @sql = IF(@idx_loan_customer_exists = 0,
    'ALTER TABLE loans ADD INDEX idx_loan_customer_account (customer_account_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_loan_platform_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'loans' AND index_name = 'idx_loan_platform_account'
);
SET @sql = IF(@idx_loan_platform_exists = 0,
    'ALTER TABLE loans ADD INDEX idx_loan_platform_account (platform_account_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_app_customer_exists = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'loan_applications'
      AND constraint_type = 'FOREIGN KEY' AND constraint_name = 'fk_app_customer_account'
);
SET @sql = IF(@fk_app_customer_exists = 0,
    'ALTER TABLE loan_applications ADD CONSTRAINT fk_app_customer_account FOREIGN KEY (customer_account_id) REFERENCES customer_accounts(account_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_app_platform_exists = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'loan_applications'
      AND constraint_type = 'FOREIGN KEY' AND constraint_name = 'fk_app_platform_account'
);
SET @sql = IF(@fk_app_platform_exists = 0,
    'ALTER TABLE loan_applications ADD CONSTRAINT fk_app_platform_account FOREIGN KEY (platform_account_id) REFERENCES platform_accounts(account_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_loan_customer_exists = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'loans'
      AND constraint_type = 'FOREIGN KEY' AND constraint_name = 'fk_loan_customer_account'
);
SET @sql = IF(@fk_loan_customer_exists = 0,
    'ALTER TABLE loans ADD CONSTRAINT fk_loan_customer_account FOREIGN KEY (customer_account_id) REFERENCES customer_accounts(account_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_loan_platform_exists = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'loans'
      AND constraint_type = 'FOREIGN KEY' AND constraint_name = 'fk_loan_platform_account'
);
SET @sql = IF(@fk_loan_platform_exists = 0,
    'ALTER TABLE loans ADD CONSTRAINT fk_loan_platform_account FOREIGN KEY (platform_account_id) REFERENCES platform_accounts(account_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
