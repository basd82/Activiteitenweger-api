-- SPDX-License-Identifier: GPL-3.0-only
-- Copyright (C) 2026 Bas van den Dikkenberg
-- Schema migration 002: global devices + vault grants + pairing secret hashes
-- Target server: 1.2.0
-- Requires schema version 1.
--
-- IMPORTANT:
-- - make a database backup before applying;
-- - stop API writes during this migration;
-- - run once.

START TRANSACTION;

CREATE TABLE vault_devices (
    vault_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    device_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    access_mode enum('R','RW') NOT NULL,
    is_owner tinyint(1) NOT NULL DEFAULT 0,
    status enum('ACTIVE','REVOKED') NOT NULL DEFAULT 'ACTIVE',
    created_at datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    revoked_at datetime(6) DEFAULT NULL,
    revoked_by char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    PRIMARY KEY (vault_id, device_id),
    KEY idx_vault_devices_device (device_id),
    KEY idx_vault_devices_vault_status (vault_id, status),
    KEY idx_vault_devices_revoked_by (revoked_by),
    CONSTRAINT fk_vault_devices_vault
        FOREIGN KEY (vault_id) REFERENCES vaults(vault_id) ON DELETE CASCADE,
    CONSTRAINT fk_vault_devices_device
        FOREIGN KEY (device_id) REFERENCES devices(device_id) ON DELETE CASCADE,
    CONSTRAINT fk_vault_devices_revoked_by
        FOREIGN KEY (revoked_by) REFERENCES devices(device_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO vault_devices
    (vault_id, device_id, access_mode, is_owner, status, created_at, revoked_at)
SELECT
    vault_id, device_id, access_mode, is_owner, status, created_at, revoked_at
FROM devices;

-- Some existing schema-1 installations contain the historical owner/RW
-- check constraint even though it was absent from the exported baseline.
-- Remove it before dropping access_mode/is_owner.
SET @aw_has_chk_owner_rw := (
    SELECT COUNT(*)
      FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'devices'
       AND constraint_name = 'chk_owner_rw'
       AND constraint_type = 'CHECK'
);
SET @aw_drop_chk_owner_rw := IF(
    @aw_has_chk_owner_rw > 0,
    'ALTER TABLE devices DROP CHECK chk_owner_rw',
    'SELECT 1'
);
PREPARE aw_stmt FROM @aw_drop_chk_owner_rw;
EXECUTE aw_stmt;
DEALLOCATE PREPARE aw_stmt;

ALTER TABLE devices
    DROP FOREIGN KEY fk_devices_vault,
    DROP INDEX idx_devices_vault,
    DROP INDEX idx_devices_vault_status,
    DROP COLUMN vault_id,
    DROP COLUMN access_mode,
    DROP COLUMN is_owner,
    DROP COLUMN revoked_at;

ALTER TABLE pairing_invites
    ADD COLUMN pairing_secret_hash binary(32) DEFAULT NULL AFTER invite_id,
    ADD COLUMN revoked_by char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER claimed_by_device_id,
    ADD UNIQUE KEY uq_pairing_secret_hash (pairing_secret_hash),
    ADD KEY fk_pairing_revoked_by (revoked_by),
    ADD CONSTRAINT fk_pairing_revoked_by
        FOREIGN KEY (revoked_by) REFERENCES devices(device_id) ON DELETE SET NULL;

ALTER TABLE pairing_invites
    MODIFY invite_public_key varbinary(128) NULL;

COMMIT;
