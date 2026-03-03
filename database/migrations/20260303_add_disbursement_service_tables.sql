USE lemelani_loans;

CREATE TABLE IF NOT EXISTS disbursement_transactions (
    tx_id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id                     INT UNSIGNED NULL,
    application_id              INT UNSIGNED NOT NULL,
    user_id                     INT UNSIGNED NOT NULL,
    platform_account_id         INT UNSIGNED NOT NULL,
    customer_account_id         INT UNSIGNED NOT NULL,
    gateway_channel             VARCHAR(50) NOT NULL,
    amount_mwk                  DECIMAL(15,2) NOT NULL,
    currency_code               CHAR(3) NOT NULL DEFAULT 'MWK',
    external_reference          VARCHAR(100) NOT NULL,
    gateway_transaction_reference VARCHAR(150),
    status                      ENUM('pending','success','failed','reversed','reconciled') NOT NULL DEFAULT 'pending',
    response_code               INT,
    request_payload_json        JSON NULL,
    response_payload_json       JSON NULL,
    error_message               TEXT,
    attempt_count               SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    next_retry_at               DATETIME NULL,
    processed_at                DATETIME NULL,
    created_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_external_reference (external_reference),
    INDEX idx_disb_status (status, processed_at),
    INDEX idx_disb_loan (loan_id),
    INDEX idx_disb_application (application_id),
    INDEX idx_disb_retry (status, next_retry_at)
);

CREATE TABLE IF NOT EXISTS disbursement_reconciliation (
    rec_id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reconciliation_date          DATE NOT NULL,
    platform_account_id          INT UNSIGNED NOT NULL,
    opening_balance_mwk          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_disbursed_mwk          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_failed_mwk             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    closing_balance_mwk          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    gateway_report_reference     VARCHAR(120),
    status                       ENUM('pending','matched','mismatch','resolved') NOT NULL DEFAULT 'pending',
    notes                        TEXT,
    reconciled_by                INT UNSIGNED,
    reconciled_at                DATETIME NULL,
    created_at                   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at                   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_daily_reconciliation (reconciliation_date, platform_account_id),
    INDEX idx_recon_status (status, reconciliation_date)
);

SET @fk_disb_loan = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'disbursement_transactions'
      AND constraint_type = 'FOREIGN KEY' AND constraint_name = 'fk_disb_loan'
);
SET @sql = IF(@fk_disb_loan = 0,
    'ALTER TABLE disbursement_transactions ADD CONSTRAINT fk_disb_loan FOREIGN KEY (loan_id) REFERENCES loans(loan_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_disb_app = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'disbursement_transactions'
      AND constraint_type = 'FOREIGN KEY' AND constraint_name = 'fk_disb_app'
);
SET @sql = IF(@fk_disb_app = 0,
    'ALTER TABLE disbursement_transactions ADD CONSTRAINT fk_disb_app FOREIGN KEY (application_id) REFERENCES loan_applications(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_disb_user = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'disbursement_transactions'
      AND constraint_type = 'FOREIGN KEY' AND constraint_name = 'fk_disb_user'
);
SET @sql = IF(@fk_disb_user = 0,
    'ALTER TABLE disbursement_transactions ADD CONSTRAINT fk_disb_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_disb_platform = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'disbursement_transactions'
      AND constraint_type = 'FOREIGN KEY' AND constraint_name = 'fk_disb_platform'
);
SET @sql = IF(@fk_disb_platform = 0,
    'ALTER TABLE disbursement_transactions ADD CONSTRAINT fk_disb_platform FOREIGN KEY (platform_account_id) REFERENCES platform_accounts(account_id) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_disb_customer = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'disbursement_transactions'
      AND constraint_type = 'FOREIGN KEY' AND constraint_name = 'fk_disb_customer'
);
SET @sql = IF(@fk_disb_customer = 0,
    'ALTER TABLE disbursement_transactions ADD CONSTRAINT fk_disb_customer FOREIGN KEY (customer_account_id) REFERENCES customer_accounts(account_id) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_recon_platform = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'disbursement_reconciliation'
      AND constraint_type = 'FOREIGN KEY' AND constraint_name = 'fk_recon_platform'
);
SET @sql = IF(@fk_recon_platform = 0,
    'ALTER TABLE disbursement_reconciliation ADD CONSTRAINT fk_recon_platform FOREIGN KEY (platform_account_id) REFERENCES platform_accounts(account_id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_recon_user = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'disbursement_reconciliation'
      AND constraint_type = 'FOREIGN KEY' AND constraint_name = 'fk_recon_user'
);
SET @sql = IF(@fk_recon_user = 0,
    'ALTER TABLE disbursement_reconciliation ADD CONSTRAINT fk_recon_user FOREIGN KEY (reconciled_by) REFERENCES users(user_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
