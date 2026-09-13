USE activiteitenweger;

CREATE TABLE IF NOT EXISTS request_nonces (
    device_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    nonce_hash BINARY(32) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    PRIMARY KEY (device_id, nonce_hash),
    KEY idx_request_nonces_created (created_at),

    CONSTRAINT fk_request_nonces_device
        FOREIGN KEY (device_id)
        REFERENCES devices(device_id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;
