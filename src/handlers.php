<?php

// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Bas van den Dikkenberg

declare(strict_types=1);

function aw_handle_health(): never
{
    try {
        aw_db()->query('SELECT 1');
        aw_json_response(200, [
            'status' => 'ok',
            'database' => 'ok',
            'apiVersion' => AW_API_VERSION,
            'serverVersion' => AW_SERVER_VERSION,
        ]);
    } catch (Throwable $e) {
        error_log('Healthcheck database error: ' . $e->getMessage());
        aw_json_response(503, [
            'status' => 'error',
            'database' => 'unavailable',
            'apiVersion' => AW_API_VERSION,
            'serverVersion' => AW_SERVER_VERSION,
        ]);
    }
}

function aw_handle_create_vault(string $rawBody): never
{
    $data = aw_decode_json_object($rawBody);

    $vaultId = aw_required_string($data, 'vaultId');
    $deviceId = aw_required_string($data, 'deviceId');
    $keyEpoch = aw_required_positive_int($data, 'keyEpoch');

    if (!aw_valid_uuid($vaultId)) {
        aw_json_response(400, ['error' => 'invalid_vault_id']);
    }
    if (!aw_valid_uuid($deviceId)) {
        aw_json_response(400, ['error' => 'invalid_device_id']);
    }
    if ($keyEpoch !== 1) {
        aw_json_response(400, ['error' => 'invalid_key_epoch']);
    }

    $authPublicKey = aw_decode_binary_field($data, 'authPublicKey', 32);
    $encryptionPublicKey = aw_decode_binary_field($data, 'encryptionPublicKey', 32);
    $keyEnvelope = aw_decode_binary_field($data, 'keyEnvelope', null, 4096);
    if (strlen($keyEnvelope) < 16) {
        aw_json_response(400, ['error' => 'invalid_length', 'field' => 'keyEnvelope']);
    }
    $keyEnvelopeNonce = aw_optional_binary_field($data, 'keyEnvelopeNonce', 32);

    $db = aw_db();

    try {
        $db->begin_transaction();

        $stmt = $db->prepare('INSERT INTO vaults (vault_id, current_key_epoch) VALUES (?, 1)');
        $stmt->bind_param('s', $vaultId);
        $stmt->execute();
        $stmt->close();

        $status = 'ACTIVE';
        $stmt = $db->prepare(
            "INSERT INTO devices
                (device_id, status,
                 auth_public_key, auth_key_algorithm,
                 encryption_public_key, encryption_key_algorithm)
             VALUES (?, ?, ?, 'Ed25519', ?, 'X25519')"
        );
        $stmt->bind_param('ssss', $deviceId, $status, $authPublicKey, $encryptionPublicKey);
        $stmt->execute();
        $stmt->close();

        $accessMode = 'RW';
        $stmt = $db->prepare(
            "INSERT INTO vault_devices
                (vault_id, device_id, access_mode, is_owner, status)
             VALUES (?, ?, ?, TRUE, 'ACTIVE')"
        );
        $stmt->bind_param('sss', $vaultId, $deviceId, $accessMode);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare(
            'INSERT INTO key_epochs (vault_id, epoch, created_by_device_id) VALUES (?, 1, ?)'
        );
        $stmt->bind_param('ss', $vaultId, $deviceId);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare(
            'INSERT INTO device_key_envelopes
                (vault_id, epoch, device_id, envelope_ciphertext, envelope_nonce, crypto_version)
             VALUES (?, 1, ?, ?, ?, 1)'
        );
        $stmt->bind_param('ssss', $vaultId, $deviceId, $keyEnvelope, $keyEnvelopeNonce);
        $stmt->execute();
        $stmt->close();

        $db->commit();

        aw_json_response(201, [
            'status' => 'ok',
            'vaultId' => $vaultId,
            'deviceId' => $deviceId,
            'access' => 'RW',
            'owner' => true,
            'keyEpoch' => 1,
        ]);
    } catch (mysqli_sql_exception $e) {
        try { $db->rollback(); } catch (Throwable) {}
        error_log('Create vault database error: ' . $e->getMessage());

        if ((int)$e->getCode() === 1062) {
            aw_json_response(409, ['error' => 'already_exists']);
        }
        aw_json_response(500, ['error' => 'database_error']);
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable) {}
        error_log('Create vault error: ' . $e->getMessage());
        aw_json_response(500, ['error' => 'internal_error']);
    }
}

function aw_handle_me(array $auth): never
{
    aw_json_response(200, [
        'deviceId' => $auth['device_id'],
        'vaultId' => $auth['vault_id'],
        'access' => $auth['access_mode'],
        'owner' => (bool)$auth['is_owner'],
        'currentKeyEpoch' => (int)$auth['current_key_epoch'],
    ]);
}

function aw_handle_devices(array $auth): never
{
    aw_require_rw($auth);
    $db = aw_db();
    $stmt = $db->prepare(
        "SELECT vd.device_id, vd.access_mode, vd.is_owner, vd.status,
                vd.label_ciphertext, vd.label_nonce,
                DATE_FORMAT(vd.created_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS created_at,
                DATE_FORMAT(d.last_seen_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS last_seen_at,
                DATE_FORMAT(vd.revoked_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS revoked_at
           FROM vault_devices vd
           JOIN devices d ON d.device_id = vd.device_id
          WHERE vd.vault_id = ?
          ORDER BY vd.created_at"
    );
    $stmt->bind_param('s', $auth['vault_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $devices = [];
    while ($row = $result->fetch_assoc()) {
        $devices[] = [
            'deviceId' => $row['device_id'],
            'access' => $row['access_mode'],
            'owner' => (bool)$row['is_owner'],
            'status' => $row['status'],
            'createdAt' => $row['created_at'],
            'lastSeenAt' => $row['last_seen_at'],
            'revokedAt' => $row['revoked_at'],
            'labelCiphertext' => $row['label_ciphertext'] === null ? null : aw_b64url_encode($row['label_ciphertext']),
            'labelNonce' => $row['label_nonce'] === null ? null : aw_b64url_encode($row['label_nonce']),
        ];
    }
    $stmt->close();

    aw_json_response(200, ['devices' => $devices]);
}

function aw_handle_upsert_record(array $auth, string $rawBody): never
{
    aw_require_rw($auth);
    $data = aw_decode_json_object($rawBody);

    $recordId = aw_required_string($data, 'recordId');
    if (!aw_valid_uuid($recordId)) {
        aw_json_response(400, ['error' => 'invalid_record_id']);
    }

    $revision = aw_required_positive_int($data, 'revision');
    $keyEpoch = aw_required_positive_int($data, 'keyEpoch');
    if ($keyEpoch !== (int)$auth['current_key_epoch']) {
        aw_json_response(409, [
            'error' => 'stale_key_epoch',
            'currentKeyEpoch' => (int)$auth['current_key_epoch'],
        ]);
    }

    if (!array_key_exists('deleted', $data) || !is_bool($data['deleted'])) {
        aw_json_response(400, ['error' => 'invalid_request', 'field' => 'deleted']);
    }
    $deleted = $data['deleted'];

    $ciphertext = null;
    $nonce = null;
    if (!$deleted) {
        $ciphertext = aw_decode_binary_field($data, 'ciphertext', null, 262144);
        if ($ciphertext === '') {
            aw_json_response(400, ['error' => 'invalid_length', 'field' => 'ciphertext']);
        }
        $nonce = aw_decode_binary_field($data, 'nonce', 24);
    }

    $recordSignature = aw_decode_binary_field($data, 'recordSignature', 64);
    $canonical = aw_record_canonical(
        $auth['vault_id'],
        $recordId,
        $revision,
        $keyEpoch,
        $deleted,
        $nonce,
        $ciphertext
    );

    if (!sodium_crypto_sign_verify_detached($recordSignature, $canonical, $auth['auth_public_key'])) {
        aw_json_response(400, ['error' => 'invalid_record_signature']);
    }

    $db = aw_db();

    try {
        $db->begin_transaction();

        $stmt = $db->prepare(
            "SELECT vault_id, revision, is_deleted,
                    DATE_FORMAT(updated_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS updated_at
               FROM records
              WHERE record_id = ?
              FOR UPDATE"
        );
        $stmt->bind_param('s', $recordId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $created = false;

        if ($existing) {
            if ($existing['vault_id'] !== $auth['vault_id']) {
                $db->rollback();
                aw_json_response(409, ['error' => 'record_id_conflict']);
            }

            $expectedRevision = ((int)$existing['revision']) + 1;
            if ($revision !== $expectedRevision) {
                $db->rollback();
                aw_json_response(409, [
                    'error' => 'revision_conflict',
                    'recordId' => $recordId,
                    'currentRevision' => (int)$existing['revision'],
                    'expectedRevision' => $expectedRevision,
                    'currentDeleted' => (bool)$existing['is_deleted'],
                    'currentUpdatedAt' => $existing['updated_at'],
                ]);
            }

            if ($deleted) {
                $stmt = $db->prepare(
                    'UPDATE records
                        SET key_epoch = ?, revision = ?, crypto_version = 1,
                            ciphertext = NULL, nonce = NULL,
                            writer_device_id = ?, signature = ?,
                            is_deleted = TRUE, deleted_at = UTC_TIMESTAMP(6),
                            updated_at = UTC_TIMESTAMP(6)
                      WHERE record_id = ? AND vault_id = ?'
                );
                $stmt->bind_param(
                    'iissss',
                    $keyEpoch,
                    $revision,
                    $auth['device_id'],
                    $recordSignature,
                    $recordId,
                    $auth['vault_id']
                );
            } else {
                $stmt = $db->prepare(
                    'UPDATE records
                        SET key_epoch = ?, revision = ?, crypto_version = 1,
                            ciphertext = ?, nonce = ?,
                            writer_device_id = ?, signature = ?,
                            is_deleted = FALSE, deleted_at = NULL,
                            updated_at = UTC_TIMESTAMP(6)
                      WHERE record_id = ? AND vault_id = ?'
                );
                $stmt->bind_param(
                    'iissssss',
                    $keyEpoch,
                    $revision,
                    $ciphertext,
                    $nonce,
                    $auth['device_id'],
                    $recordSignature,
                    $recordId,
                    $auth['vault_id']
                );
            }
            $stmt->execute();
            $stmt->close();
        } else {
            if ($revision !== 1) {
                $db->rollback();
                aw_json_response(409, [
                    'error' => 'revision_conflict',
                    'recordId' => $recordId,
                    'currentRevision' => 0,
                    'expectedRevision' => 1,
                    'currentDeleted' => false,
                    'currentUpdatedAt' => null,
                ]);
            }
            $created = true;

            if ($deleted) {
                $stmt = $db->prepare(
                    'INSERT INTO records
                        (record_id, vault_id, key_epoch, revision, crypto_version,
                         ciphertext, nonce, writer_device_id, signature,
                         is_deleted, deleted_at)
                     VALUES (?, ?, ?, ?, 1, NULL, NULL, ?, ?, TRUE, UTC_TIMESTAMP(6))'
                );
                $stmt->bind_param(
                    'ssiiss',
                    $recordId,
                    $auth['vault_id'],
                    $keyEpoch,
                    $revision,
                    $auth['device_id'],
                    $recordSignature
                );
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO records
                        (record_id, vault_id, key_epoch, revision, crypto_version,
                         ciphertext, nonce, writer_device_id, signature,
                         is_deleted, deleted_at)
                     VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, FALSE, NULL)'
                );
                $stmt->bind_param(
                    'ssiissss',
                    $recordId,
                    $auth['vault_id'],
                    $keyEpoch,
                    $revision,
                    $ciphertext,
                    $nonce,
                    $auth['device_id'],
                    $recordSignature
                );
            }
            $stmt->execute();
            $stmt->close();
        }

        $eventType = $deleted ? 'DELETE' : 'UPSERT';
        $stmt = $db->prepare(
            'INSERT INTO sync_events (vault_id, record_id, revision, event_type)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('ssis', $auth['vault_id'], $recordId, $revision, $eventType);
        $stmt->execute();
        $eventId = (int)$db->insert_id;
        $stmt->close();

        $db->commit();

        aw_json_response($created ? 201 : 200, [
            'status' => 'ok',
            'recordId' => $recordId,
            'revision' => $revision,
            'deleted' => $deleted,
            'eventId' => $eventId,
        ]);
    } catch (mysqli_sql_exception $e) {
        try { $db->rollback(); } catch (Throwable) {}
        error_log('Upsert record database error: ' . $e->getMessage());
        aw_json_response(500, ['error' => 'database_error']);
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable) {}
        error_log('Upsert record error: ' . $e->getMessage());
        aw_json_response(500, ['error' => 'internal_error']);
    }
}

function aw_handle_sync(array $auth): never
{
    $sinceRaw = $_GET['since'] ?? '0';
    $limitRaw = $_GET['limit'] ?? '100';

    if (!is_string($sinceRaw) || preg_match('/^[0-9]+$/', $sinceRaw) !== 1) {
        aw_json_response(400, ['error' => 'invalid_since']);
    }
    if (!is_string($limitRaw) || preg_match('/^[0-9]+$/', $limitRaw) !== 1) {
        aw_json_response(400, ['error' => 'invalid_limit']);
    }

    $since = (int)$sinceRaw;
    $limit = (int)$limitRaw;
    if ($limit < 1 || $limit > 500) {
        aw_json_response(400, ['error' => 'invalid_limit']);
    }

    $db = aw_db();

    $stmt = $db->prepare(
        'SELECT event_id
           FROM sync_events
          WHERE vault_id = ? AND event_id > ?
          ORDER BY event_id
          LIMIT ?'
    );
    $stmt->bind_param('sii', $auth['vault_id'], $since, $limit);
    $stmt->execute();
    $eventsResult = $stmt->get_result();

    $eventIds = [];
    while ($row = $eventsResult->fetch_assoc()) {
        $eventIds[] = (int)$row['event_id'];
    }
    $stmt->close();

    if ($eventIds === []) {
        aw_json_response(200, [
            'records' => [],
            'nextCursor' => $since,
            'hasMore' => false,
            'currentKeyEpoch' => (int)$auth['current_key_epoch'],
        ]);
    }

    $nextCursor = $eventIds[array_key_last($eventIds)];

    $stmt = $db->prepare(
        "SELECT DISTINCT
                r.record_id, r.key_epoch, r.revision, r.crypto_version,
                r.ciphertext, r.nonce, r.writer_device_id, r.signature, r.is_deleted,
                DATE_FORMAT(r.updated_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS updated_at,
                DATE_FORMAT(r.deleted_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS deleted_at
           FROM records r
           JOIN sync_events e
             ON e.record_id = r.record_id
            AND e.vault_id = r.vault_id
          WHERE e.vault_id = ?
            AND e.event_id > ?
            AND e.event_id <= ?
          ORDER BY r.record_id"
    );
    $stmt->bind_param('sii', $auth['vault_id'], $since, $nextCursor);
    $stmt->execute();
    $recordsResult = $stmt->get_result();

    $records = [];
    while ($row = $recordsResult->fetch_assoc()) {
        $deleted = (bool)$row['is_deleted'];
        $records[] = [
            'recordId' => $row['record_id'],
            'keyEpoch' => (int)$row['key_epoch'],
            'revision' => (int)$row['revision'],
            'cryptoVersion' => (int)$row['crypto_version'],
            'ciphertext' => $deleted || $row['ciphertext'] === null ? null : aw_b64url_encode($row['ciphertext']),
            'nonce' => $deleted || $row['nonce'] === null ? null : aw_b64url_encode($row['nonce']),
            'writerDeviceId' => $row['writer_device_id'],
            'recordSignature' => aw_b64url_encode($row['signature']),
            'deleted' => $deleted,
            'updatedAt' => $row['updated_at'],
            'deletedAt' => $row['deleted_at'],
        ];
    }
    $stmt->close();

    $stmt = $db->prepare(
        'SELECT 1 FROM sync_events WHERE vault_id = ? AND event_id > ? LIMIT 1'
    );
    $stmt->bind_param('si', $auth['vault_id'], $nextCursor);
    $stmt->execute();
    $hasMore = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();

    aw_json_response(200, [
        'records' => $records,
        'nextCursor' => $nextCursor,
        'hasMore' => $hasMore,
        'currentKeyEpoch' => (int)$auth['current_key_epoch'],
    ]);
}

function aw_handle_delete_vault(array $auth, string $rawBody, string $vaultIdFromPath): never
{
    aw_require_owner($auth);

    if ($vaultIdFromPath !== $auth['vault_id']) {
        aw_json_response(404, ['error' => 'not_found']);
    }

    $data = aw_decode_json_object($rawBody);
    if (($data['confirm'] ?? null) !== 'DELETE') {
        aw_json_response(400, ['error' => 'delete_confirmation_required']);
    }

    $db = aw_db();
    try {
        $db->begin_transaction();
        $stmt = $db->prepare('DELETE FROM vaults WHERE vault_id = ?');
        $stmt->bind_param('s', $auth['vault_id']);
        $stmt->execute();
        $deletedRows = $stmt->affected_rows;
        $stmt->close();

        if ($deletedRows !== 1) {
            $db->rollback();
            aw_json_response(404, ['error' => 'not_found']);
        }

        // Devices are global from schema 2 onward. Remove only identities that
        // no longer have any vault grant; devices used by another vault remain.
        $db->query(
            'DELETE d
               FROM devices d
               LEFT JOIN vault_devices vd ON vd.device_id = d.device_id
              WHERE vd.device_id IS NULL'
        );

        $db->commit();
        aw_json_response(200, ['status' => 'deleted']);
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable) {}
        error_log('Delete vault error: ' . $e->getMessage());
        aw_json_response(500, ['error' => 'database_error']);
    }
}


function aw_handle_create_pairing_invite(array $auth, string $rawBody): never
{
    aw_require_owner($auth);
    $data = aw_decode_json_object($rawBody);

    $inviteId = aw_required_string($data, 'inviteId');
    if (!aw_valid_uuid($inviteId)) {
        aw_json_response(400, ['error' => 'invalid_invite_id']);
    }

    $access = aw_required_string($data, 'access');
    if (!in_array($access, ['R', 'RW'], true)) {
        aw_json_response(400, ['error' => 'invalid_access_mode']);
    }

    $secretHash = aw_decode_binary_field($data, 'pairingSecretHash', 32);
    $keyPackage = aw_decode_binary_field($data, 'keyPackageCiphertext', null, 4096);
    if (strlen($keyPackage) < 16) {
        aw_json_response(400, ['error' => 'invalid_length', 'field' => 'keyPackageCiphertext']);
    }
    $keyPackageNonce = aw_decode_binary_field($data, 'keyPackageNonce', 24);

    $expiresIn = $data['expiresInSeconds'] ?? 600;
    if (!is_int($expiresIn) || $expiresIn < 60 || $expiresIn > 3600) {
        aw_json_response(400, ['error' => 'invalid_expiry']);
    }

    $db = aw_db();
    try {
        $stmt = $db->prepare(
            "INSERT INTO pairing_invites
                (invite_id, pairing_secret_hash, vault_id, created_by_device_id,
                 access_mode, status, invite_public_key,
                 key_package_ciphertext, key_package_nonce,
                 key_epoch, crypto_version, expires_at)
             VALUES (?, ?, ?, ?, ?, 'PENDING', NULL, ?, ?, ?, 1,
                     UTC_TIMESTAMP(6) + INTERVAL ? SECOND)"
        );
        $stmt->bind_param(
            'sssssssii',
            $inviteId,
            $secretHash,
            $auth['vault_id'],
            $auth['device_id'],
            $access,
            $keyPackage,
            $keyPackageNonce,
            $auth['current_key_epoch'],
            $expiresIn
        );
        $stmt->execute();
        $stmt->close();

        aw_json_response(201, [
            'status' => 'ok',
            'inviteId' => $inviteId,
            'access' => $access,
            'keyEpoch' => (int)$auth['current_key_epoch'],
            'expiresInSeconds' => $expiresIn,
        ]);
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() === 1062) {
            aw_json_response(409, ['error' => 'already_exists']);
        }
        error_log('Create pairing invite error: ' . $e->getMessage());
        aw_json_response(500, ['error' => 'database_error']);
    }
}

function aw_handle_claim_pairing(string $rawBody): never
{
    $data = aw_decode_json_object($rawBody);

    $deviceId = aw_required_string($data, 'deviceId');
    if (!aw_valid_uuid($deviceId)) {
        aw_json_response(400, ['error' => 'invalid_device_id']);
    }

    $pairingSecret = aw_decode_binary_field($data, 'pairingSecret', 16);
    $authPublicKey = aw_decode_binary_field($data, 'authPublicKey', 32);
    $encryptionPublicKey = aw_decode_binary_field($data, 'encryptionPublicKey', 32);
    $secretHash = hash('sha256', "AW-PAIRING-VERIFY-V1\n" . $pairingSecret, true);

    $db = aw_db();
    try {
        $db->begin_transaction();

        $stmt = $db->prepare(
            "SELECT invite_id, pairing_secret_hash, vault_id, access_mode, status,
                    key_package_ciphertext, key_package_nonce, key_epoch,
                    expires_at
               FROM pairing_invites
              WHERE pairing_secret_hash = ?
              FOR UPDATE"
        );
        $stmt->bind_param('s', $secretHash);
        $stmt->execute();
        $invite = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$invite) {
            $db->rollback();
            aw_json_response(404, ['error' => 'pairing_invite_not_found']);
        }
        if ($invite['status'] !== 'PENDING') {
            $db->rollback();
            aw_json_response(409, ['error' => 'pairing_invite_unavailable']);
        }

        $stmt = $db->prepare('SELECT UTC_TIMESTAMP(6) > ?');
        $stmt->bind_param('s', $invite['expires_at']);
        $stmt->execute();
        $expired = (bool)$stmt->get_result()->fetch_row()[0];
        $stmt->close();
        if ($expired) {
            $stmt = $db->prepare("UPDATE pairing_invites SET status = 'EXPIRED' WHERE invite_id = ?");
            $stmt->bind_param('s', $invite['invite_id']);
            $stmt->execute();
            $stmt->close();
            $db->commit();
            aw_json_response(410, ['error' => 'pairing_invite_expired']);
        }

        $stmt = $db->prepare(
            'SELECT auth_public_key, encryption_public_key, status
               FROM devices WHERE device_id = ?'
        );
        $stmt->bind_param('s', $deviceId);
        $stmt->execute();
        $existingDevice = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existingDevice) {
            if (
                $existingDevice['status'] !== 'ACTIVE' ||
                !hash_equals($existingDevice['auth_public_key'], $authPublicKey) ||
                !hash_equals($existingDevice['encryption_public_key'], $encryptionPublicKey)
            ) {
                $db->rollback();
                aw_json_response(409, ['error' => 'device_identity_conflict']);
            }
        } else {
            $stmt = $db->prepare(
                "INSERT INTO devices
                    (device_id, status, auth_public_key, auth_key_algorithm,
                     encryption_public_key, encryption_key_algorithm)
                 VALUES (?, 'ACTIVE', ?, 'Ed25519', ?, 'X25519')"
            );
            $stmt->bind_param('sss', $deviceId, $authPublicKey, $encryptionPublicKey);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $db->prepare(
            "INSERT INTO vault_devices
                (vault_id, device_id, access_mode, is_owner, status)
             VALUES (?, ?, ?, FALSE, 'ACTIVE')"
        );
        $stmt->bind_param('sss', $invite['vault_id'], $deviceId, $invite['access_mode']);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare(
            "UPDATE pairing_invites
                SET status = 'CLAIMED',
                    claimed_at = UTC_TIMESTAMP(6),
                    claimed_by_device_id = ?
              WHERE invite_id = ?"
        );
        $stmt->bind_param('ss', $deviceId, $invite['invite_id']);
        $stmt->execute();
        $stmt->close();

        $db->commit();

        aw_json_response(201, [
            'status' => 'ok',
            'inviteId' => $invite['invite_id'],
            'vaultId' => $invite['vault_id'],
            'deviceId' => $deviceId,
            'access' => $invite['access_mode'],
            'owner' => false,
            'keyEpoch' => (int)$invite['key_epoch'],
            'keyPackageCiphertext' => aw_b64url_encode($invite['key_package_ciphertext']),
            'keyPackageNonce' => aw_b64url_encode($invite['key_package_nonce']),
        ]);
    } catch (mysqli_sql_exception $e) {
        try { $db->rollback(); } catch (Throwable) {}
        if ((int)$e->getCode() === 1062) {
            aw_json_response(409, ['error' => 'already_paired']);
        }
        error_log('Claim pairing error: ' . $e->getMessage());
        aw_json_response(500, ['error' => 'database_error']);
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable) {}
        error_log('Claim pairing error: ' . $e->getMessage());
        aw_json_response(500, ['error' => 'internal_error']);
    }
}

function aw_handle_revoke_pairing_invite(array $auth, string $inviteId): never
{
    aw_require_owner($auth);
    if (!aw_valid_uuid($inviteId)) {
        aw_json_response(404, ['error' => 'not_found']);
    }

    $db = aw_db();
    $stmt = $db->prepare(
        "UPDATE pairing_invites
            SET status = 'REVOKED', revoked_by = ?
          WHERE invite_id = ? AND vault_id = ? AND status = 'PENDING'"
    );
    $stmt->bind_param('sss', $auth['device_id'], $inviteId, $auth['vault_id']);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected !== 1) {
        aw_json_response(404, ['error' => 'pairing_invite_not_found']);
    }
    aw_json_response(200, ['status' => 'revoked']);
}

function aw_handle_update_device_label(array $auth, string $rawBody, ?string $targetDeviceId = null): never
{
    $data = aw_decode_json_object($rawBody);
    $ciphertext = aw_decode_binary_field($data, 'labelCiphertext', null, 1024);
    if (strlen($ciphertext) < 16) {
        aw_json_response(400, ['error' => 'invalid_length', 'field' => 'labelCiphertext']);
    }
    $nonce = aw_decode_binary_field($data, 'labelNonce', 24);

    $deviceId = $targetDeviceId ?? $auth['device_id'];
    if ($targetDeviceId !== null && $targetDeviceId !== $auth['device_id']) {
        aw_require_owner($auth);
    }
    if (!aw_valid_uuid($deviceId)) {
        aw_json_response(404, ['error' => 'not_found']);
    }

    $db = aw_db();
    $stmt = $db->prepare(
        "UPDATE vault_devices
            SET label_ciphertext = ?, label_nonce = ?
          WHERE vault_id = ? AND device_id = ? AND status = 'ACTIVE'"
    );
    $stmt->bind_param('ssss', $ciphertext, $nonce, $auth['vault_id'], $deviceId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected < 0) {
        aw_json_response(500, ['error' => 'database_error']);
    }
    aw_json_response(200, ['status' => 'ok']);
}

function aw_handle_transfer_ownership(array $auth, string $deviceId): never
{
    aw_require_owner($auth);
    if (!aw_valid_uuid($deviceId) || $deviceId === $auth['device_id']) {
        aw_json_response(400, ['error' => 'invalid_target_device']);
    }

    $db = aw_db();
    try {
        $db->begin_transaction();

        $stmt = $db->prepare(
            "SELECT access_mode, is_owner, status
               FROM vault_devices
              WHERE vault_id = ? AND device_id = ?
              FOR UPDATE"
        );
        $stmt->bind_param('ss', $auth['vault_id'], $deviceId);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$target || $target['status'] !== 'ACTIVE') {
            $db->rollback();
            aw_json_response(404, ['error' => 'active_device_grant_not_found']);
        }
        if ($target['access_mode'] !== 'RW') {
            $db->rollback();
            aw_json_response(409, ['error' => 'owner_target_requires_rw']);
        }
        if ((bool)$target['is_owner']) {
            $db->rollback();
            aw_json_response(409, ['error' => 'target_already_owner']);
        }

        $stmt = $db->prepare(
            "UPDATE vault_devices
                SET is_owner = FALSE
              WHERE vault_id = ? AND device_id = ? AND is_owner = TRUE"
        );
        $stmt->bind_param('ss', $auth['vault_id'], $auth['device_id']);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            $db->rollback();
            aw_json_response(409, ['error' => 'owner_changed']);
        }
        $stmt->close();

        $stmt = $db->prepare(
            "UPDATE vault_devices
                SET is_owner = TRUE
              WHERE vault_id = ? AND device_id = ? AND access_mode = 'RW' AND status = 'ACTIVE'"
        );
        $stmt->bind_param('ss', $auth['vault_id'], $deviceId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            $db->rollback();
            aw_json_response(409, ['error' => 'owner_transfer_failed']);
        }
        $stmt->close();

        $db->commit();
        aw_json_response(200, [
            'status' => 'ok',
            'previousOwnerDeviceId' => $auth['device_id'],
            'ownerDeviceId' => $deviceId,
        ]);
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable) {}
        error_log('Transfer ownership error: ' . $e->getMessage());
        aw_json_response(500, ['error' => 'database_error']);
    }
}

function aw_handle_revoke_device(array $auth, string $deviceId): never
{
    aw_require_owner($auth);
    if (!aw_valid_uuid($deviceId)) {
        aw_json_response(404, ['error' => 'not_found']);
    }
    if ($deviceId === $auth['device_id']) {
        aw_json_response(409, ['error' => 'owner_cannot_revoke_self']);
    }

    $db = aw_db();
    $stmt = $db->prepare(
        "UPDATE vault_devices
            SET status = 'REVOKED',
                revoked_at = UTC_TIMESTAMP(6),
                revoked_by = ?
          WHERE vault_id = ? AND device_id = ?
            AND is_owner = FALSE AND status = 'ACTIVE'"
    );
    $stmt->bind_param('sss', $auth['device_id'], $auth['vault_id'], $deviceId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected !== 1) {
        aw_json_response(404, ['error' => 'active_device_grant_not_found']);
    }

    aw_json_response(200, [
        'status' => 'revoked',
        'deviceId' => $deviceId,
    ]);
}

function aw_handle_self_revoke(array $auth): never
{
    if ($auth['is_owner']) {
        aw_json_response(409, ['error' => 'owner_cannot_self_revoke']);
    }

    $db = aw_db();
    $stmt = $db->prepare(
        "UPDATE vault_devices
            SET status = 'REVOKED',
                revoked_at = UTC_TIMESTAMP(6),
                revoked_by = device_id
          WHERE vault_id = ? AND device_id = ? AND status = 'ACTIVE'"
    );
    $stmt->bind_param('ss', $auth['vault_id'], $auth['device_id']);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected !== 1) {
        aw_json_response(404, ['error' => 'active_device_grant_not_found']);
    }

    aw_json_response(200, ['status' => 'revoked']);
}
