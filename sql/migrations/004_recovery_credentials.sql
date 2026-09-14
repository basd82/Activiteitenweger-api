-- SPDX-License-Identifier: GPL-3.0-only
-- Copyright (C) 2026 Bas van den Dikkenberg
-- Schema migration 004: one-time recovery credentials
-- Target server: 1.4.0
-- Requires migration 003.

CREATE TABLE recovery_credentials (
    recovery_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    vault_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    secret_hash binary(32) NOT NULL,
    key_package_ciphertext mediumblob NOT NULL,
    key_package_nonce varbinary(32) NOT NULL,
    key_epoch int UNSIGNED NOT NULL,
    status enum('ACTIVE','USED','REVOKED') NOT NULL DEFAULT 'ACTIVE',
    created_by_device_id char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    created_at datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    used_at datetime(6) DEFAULT NULL,
    used_by_device_id char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    revoked_at datetime(6) DEFAULT NULL,
    revoked_by_device_id char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    PRIMARY KEY (recovery_id),
    UNIQUE KEY uq_recovery_secret_hash (secret_hash),
    KEY idx_recovery_vault_status (vault_id, status),
    KEY fk_recovery_epoch (vault_id, key_epoch),
    KEY fk_recovery_created_by (created_by_device_id),
    KEY fk_recovery_used_by (used_by_device_id),
    KEY fk_recovery_revoked_by (revoked_by_device_id),
    CONSTRAINT fk_recovery_vault
        FOREIGN KEY (vault_id) REFERENCES vaults (vault_id) ON DELETE CASCADE,
    CONSTRAINT fk_recovery_epoch
        FOREIGN KEY (vault_id, key_epoch) REFERENCES key_epochs (vault_id, epoch) ON DELETE CASCADE,
    CONSTRAINT fk_recovery_created_by
        FOREIGN KEY (created_by_device_id) REFERENCES devices (device_id) ON DELETE SET NULL,
    CONSTRAINT fk_recovery_used_by
        FOREIGN KEY (used_by_device_id) REFERENCES devices (device_id) ON DELETE SET NULL,
    CONSTRAINT fk_recovery_revoked_by
        FOREIGN KEY (revoked_by_device_id) REFERENCES devices (device_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
