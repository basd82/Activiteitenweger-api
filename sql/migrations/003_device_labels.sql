-- SPDX-License-Identifier: GPL-3.0-only
-- Copyright (C) 2026 Bas van den Dikkenberg
-- Schema migration 003: encrypted per-vault device labels
-- Target server: 1.3.0
-- Requires schema version 2.

ALTER TABLE vault_devices
    ADD COLUMN label_ciphertext mediumblob NULL AFTER revoked_by,
    ADD COLUMN label_nonce varbinary(32) NULL AFTER label_ciphertext;
