CREATE TABLE IF NOT EXISTS worker_job_runs (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_type      VARCHAR(80) NOT NULL,
    event_key     VARCHAR(190) NOT NULL,
    run_date      DATE NOT NULL,
    status        ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    attempts      INT UNSIGNED NOT NULL DEFAULT 1,
    payload_json  JSON NULL,
    last_error    TEXT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_job_event_day (job_type, event_key, run_date),
    INDEX idx_job_status (job_type, status, run_date),
    INDEX idx_run_date (run_date)
);

