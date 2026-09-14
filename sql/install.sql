-- SPDX-License-Identifier: GPL-3.0-only
-- Copyright (C) 2026 Bas van den Dikkenberg
-- Activiteitenweger database schema version: 2

-- phpMyAdmin SQL Dump
-- version 5.2.2deb1+deb13u1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Gegenereerd op: 13 sep 2026 om 07:38
-- Serverversie: 9.7.2
-- PHP-versie: 8.4.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Database: `activiteitenweger`
--

-- --------------------------------------------------------

--
-- Tabelstructuur voor tabel `devices`
--

CREATE TABLE `devices` (
  `device_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` enum('ACTIVE','REVOKED') NOT NULL DEFAULT 'ACTIVE',
  `auth_public_key` varbinary(128) NOT NULL,
  `auth_key_algorithm` varchar(32) NOT NULL DEFAULT 'Ed25519',
  `encryption_public_key` varbinary(128) NOT NULL,
  `encryption_key_algorithm` varchar(32) NOT NULL DEFAULT 'X25519',
  `label_ciphertext` mediumblob,
  `label_nonce` varbinary(32) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `last_seen_at` datetime(6) DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Tabelstructuur voor tabel `vault_devices`
--

CREATE TABLE `vault_devices` (
  `vault_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `device_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `access_mode` enum('R','RW') NOT NULL,
  `is_owner` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('ACTIVE','REVOKED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `revoked_at` datetime(6) DEFAULT NULL,
  `revoked_by` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `label_ciphertext` mediumblob,
  `label_nonce` varbinary(32) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabelstructuur voor tabel `device_key_envelopes`
--

CREATE TABLE `device_key_envelopes` (
  `vault_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `epoch` int UNSIGNED NOT NULL,
  `device_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `envelope_ciphertext` mediumblob NOT NULL,
  `envelope_nonce` varbinary(32) DEFAULT NULL,
  `crypto_version` smallint UNSIGNED NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabelstructuur voor tabel `key_epochs`
--

CREATE TABLE `key_epochs` (
  `vault_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `epoch` int UNSIGNED NOT NULL,
  `created_by_device_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `retired_at` datetime(6) DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Tabelstructuur voor tabel `pairing_invites`
--

CREATE TABLE `pairing_invites` (
  `invite_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `pairing_secret_hash` binary(32) DEFAULT NULL,
  `vault_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by_device_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `access_mode` enum('R','RW') NOT NULL,
  `status` enum('PENDING','CLAIMED','REVOKED','EXPIRED') NOT NULL DEFAULT 'PENDING',
  `invite_public_key` varbinary(128) DEFAULT NULL,
  `key_package_ciphertext` mediumblob NOT NULL,
  `key_package_nonce` varbinary(32) DEFAULT NULL,
  `key_epoch` int UNSIGNED NOT NULL,
  `crypto_version` smallint UNSIGNED NOT NULL DEFAULT '1',
  `expires_at` datetime(6) NOT NULL,
  `claimed_at` datetime(6) DEFAULT NULL,
  `claimed_by_device_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `revoked_by` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabelstructuur voor tabel `records`
--

CREATE TABLE `records` (
  `record_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `vault_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `key_epoch` int UNSIGNED NOT NULL,
  `revision` bigint UNSIGNED NOT NULL DEFAULT '1',
  `crypto_version` smallint UNSIGNED NOT NULL DEFAULT '1',
  `ciphertext` mediumblob,
  `nonce` varbinary(32) DEFAULT NULL,
  `writer_device_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `signature` varbinary(128) NOT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `deleted_at` datetime(6) DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Tabelstructuur voor tabel `request_nonces`
--

CREATE TABLE `request_nonces` (
  `device_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nonce_hash` binary(32) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabelstructuur voor tabel `sync_events`
--

CREATE TABLE `sync_events` (
  `event_id` bigint UNSIGNED NOT NULL,
  `vault_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `record_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` bigint UNSIGNED NOT NULL,
  `event_type` enum('UPSERT','DELETE') NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabelstructuur voor tabel `vaults`
--

CREATE TABLE `vaults` (
  `vault_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `current_key_epoch` int UNSIGNED NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ;

--
-- Indexen voor geëxporteerde tabellen
--

--
-- Indexen voor tabel `devices`
--
ALTER TABLE `devices`
  ADD PRIMARY KEY (`device_id`);

--
-- Indexen voor tabel `vault_devices`
--
ALTER TABLE `vault_devices`
  ADD PRIMARY KEY (`vault_id`,`device_id`),
  ADD KEY `idx_vault_devices_device` (`device_id`),
  ADD KEY `idx_vault_devices_vault_status` (`vault_id`,`status`),
  ADD KEY `idx_vault_devices_revoked_by` (`revoked_by`);

--
-- Indexen voor tabel `device_key_envelopes`
--
ALTER TABLE `device_key_envelopes`
  ADD PRIMARY KEY (`vault_id`,`epoch`,`device_id`),
  ADD KEY `idx_envelopes_device` (`device_id`);

--
-- Indexen voor tabel `key_epochs`
--
ALTER TABLE `key_epochs`
  ADD PRIMARY KEY (`vault_id`,`epoch`),
  ADD KEY `fk_key_epochs_creator` (`created_by_device_id`);

--
-- Indexen voor tabel `pairing_invites`
--
ALTER TABLE `pairing_invites`
  ADD PRIMARY KEY (`invite_id`),
  ADD KEY `idx_pairing_vault_status` (`vault_id`,`status`,`expires_at`),
  ADD KEY `fk_pairing_creator` (`created_by_device_id`),
  ADD KEY `fk_pairing_claimed_device` (`claimed_by_device_id`),
  ADD KEY `fk_pairing_epoch` (`vault_id`,`key_epoch`),
  ADD UNIQUE KEY `uq_pairing_secret_hash` (`pairing_secret_hash`),
  ADD KEY `fk_pairing_revoked_by` (`revoked_by`);

--
-- Indexen voor tabel `records`
--
ALTER TABLE `records`
  ADD PRIMARY KEY (`record_id`),
  ADD KEY `idx_records_vault` (`vault_id`),
  ADD KEY `idx_records_vault_updated` (`vault_id`,`updated_at`),
  ADD KEY `idx_records_vault_deleted` (`vault_id`,`is_deleted`,`updated_at`),
  ADD KEY `idx_records_writer` (`writer_device_id`),
  ADD KEY `fk_records_epoch` (`vault_id`,`key_epoch`);

--
-- Indexen voor tabel `request_nonces`
--
ALTER TABLE `request_nonces`
  ADD PRIMARY KEY (`device_id`,`nonce_hash`),
  ADD KEY `idx_request_nonces_created` (`created_at`);

--
-- Indexen voor tabel `sync_events`
--
ALTER TABLE `sync_events`
  ADD PRIMARY KEY (`event_id`),
  ADD UNIQUE KEY `uq_sync_record_revision` (`vault_id`,`record_id`,`revision`),
  ADD KEY `idx_sync_vault_event` (`vault_id`,`event_id`),
  ADD KEY `fk_sync_record` (`record_id`);

--
-- Indexen voor tabel `vaults`
--
ALTER TABLE `vaults`
  ADD PRIMARY KEY (`vault_id`);

--
-- AUTO_INCREMENT voor geëxporteerde tabellen
--

--
-- AUTO_INCREMENT voor een tabel `sync_events`
--
ALTER TABLE `sync_events`
  MODIFY `event_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Beperkingen voor geëxporteerde tabellen
--

--
-- Beperkingen voor tabel `vault_devices`
--
ALTER TABLE `vault_devices`
  ADD CONSTRAINT `fk_vault_devices_vault` FOREIGN KEY (`vault_id`) REFERENCES `vaults` (`vault_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_vault_devices_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_vault_devices_revoked_by` FOREIGN KEY (`revoked_by`) REFERENCES `devices` (`device_id`) ON DELETE SET NULL;

--
-- Beperkingen voor tabel `device_key_envelopes`
--
ALTER TABLE `device_key_envelopes`
  ADD CONSTRAINT `fk_envelope_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_envelope_epoch` FOREIGN KEY (`vault_id`,`epoch`) REFERENCES `key_epochs` (`vault_id`, `epoch`) ON DELETE CASCADE;

--
-- Beperkingen voor tabel `key_epochs`
--
ALTER TABLE `key_epochs`
  ADD CONSTRAINT `fk_key_epochs_creator` FOREIGN KEY (`created_by_device_id`) REFERENCES `devices` (`device_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_key_epochs_vault` FOREIGN KEY (`vault_id`) REFERENCES `vaults` (`vault_id`) ON DELETE CASCADE;

--
-- Beperkingen voor tabel `pairing_invites`
--
ALTER TABLE `pairing_invites`
  ADD CONSTRAINT `fk_pairing_claimed_device` FOREIGN KEY (`claimed_by_device_id`) REFERENCES `devices` (`device_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pairing_creator` FOREIGN KEY (`created_by_device_id`) REFERENCES `devices` (`device_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pairing_epoch` FOREIGN KEY (`vault_id`,`key_epoch`) REFERENCES `key_epochs` (`vault_id`, `epoch`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pairing_revoked_by` FOREIGN KEY (`revoked_by`) REFERENCES `devices` (`device_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pairing_vault` FOREIGN KEY (`vault_id`) REFERENCES `vaults` (`vault_id`) ON DELETE CASCADE;

--
-- Beperkingen voor tabel `records`
--
ALTER TABLE `records`
  ADD CONSTRAINT `fk_records_epoch` FOREIGN KEY (`vault_id`,`key_epoch`) REFERENCES `key_epochs` (`vault_id`, `epoch`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_records_vault` FOREIGN KEY (`vault_id`) REFERENCES `vaults` (`vault_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_records_writer` FOREIGN KEY (`writer_device_id`) REFERENCES `devices` (`device_id`) ON DELETE SET NULL;

--
-- Beperkingen voor tabel `request_nonces`
--
ALTER TABLE `request_nonces`
  ADD CONSTRAINT `fk_request_nonces_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`) ON DELETE CASCADE;

--
-- Beperkingen voor tabel `sync_events`
--
ALTER TABLE `sync_events`
  ADD CONSTRAINT `fk_sync_record` FOREIGN KEY (`record_id`) REFERENCES `records` (`record_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sync_vault` FOREIGN KEY (`vault_id`) REFERENCES `vaults` (`vault_id`) ON DELETE CASCADE;
COMMIT;

