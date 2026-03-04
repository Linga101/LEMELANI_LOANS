CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider                VARCHAR(40) NOT NULL,
    event_id                VARCHAR(120) NOT NULL,
    event_type              VARCHAR(80) NULL,
    processing_status       ENUM('received','processed','ignored','failed') NOT NULL DEFAULT 'received',
    http_headers_json       JSON NULL,
    payload_json            JSON NOT NULL,
    normalized_reference    VARCHAR(120) NULL,
    normalized_user_id      INT UNSIGNED NULL,
    normalized_loan_id      INT UNSIGNED NULL,
    normalized_amount_mwk   DECIMAL(15,2) NULL,
    error_message           TEXT NULL,
    received_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at            TIMESTAMP NULL,
    UNIQUE KEY uk_provider_event (provider, event_id),
    INDEX idx_status_received (processing_status, received_at),
    INDEX idx_reference (normalized_reference),
    INDEX idx_user_loan (normalized_user_id, normalized_loan_id)
);

