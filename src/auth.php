<?php

// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Bas van den Dikkenberg

declare(strict_types=1);

const AW_AUTH_MAX_CLOCK_SKEW = 300;

function aw_request_canonical(string $method, string $requestUri, string $timestamp, string $nonceHeader, string $rawBody): string
{
    return "AW-REQUEST-V1\n"
        . strtoupper($method) . "\n"
        . $requestUri . "\n"
        . $timestamp . "\n"
        . $nonceHeader . "\n"
        . hash('sha256', $rawBody);
}

function aw_record_canonical(
    string $vaultId,
    string $recordId,
    int $revision,
    int $keyEpoch,
    bool $deleted,
    ?string $nonce,
    ?string $ciphertext
): string {
    return "AW-RECORD-V1\n"
        . $vaultId . "\n"
        . $recordId . "\n"
        . $revision . "\n"
        . $keyEpoch . "\n"
        . ($deleted ? '1' : '0') . "\n"
        . ($nonce === null ? '' : aw_b64url_encode($nonce)) . "\n"
        . hash('sha256', $ciphertext ?? '');
}

function aw_require_auth(string $rawBody): array
{
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        error_log('libsodium extension is missing');
        aw_json_response(500, ['error' => 'server_crypto_unavailable']);
    }

    $deviceId   = aw_header('X-AW-Device-Id');
    $timestamp  = aw_header('X-AW-Timestamp');
    $nonceValue = aw_header('X-AW-Nonce');
    $signatureValue = aw_header('X-AW-Signature');

    if ($deviceId === null || !aw_valid_uuid($deviceId)) {
        aw_json_response(401, ['error' => 'authentication_required']);
    }
    if ($timestamp === null || preg_match('/^[0-9]{10}$/', $timestamp) !== 1) {
        aw_json_response(401, ['error' => 'invalid_timestamp']);
    }

    $timestampInt = (int)$timestamp;
    if (abs(time() - $timestampInt) > AW_AUTH_MAX_CLOCK_SKEW) {
        aw_json_response(401, ['error' => 'timestamp_out_of_range', 'serverTime' => time()]);
    }

    if ($nonceValue === null) {
        aw_json_response(401, ['error' => 'missing_nonce']);
    }
    $nonce = aw_b64url_decode($nonceValue);
    if ($nonce === false || strlen($nonce) !== 16) {
        aw_json_response(401, ['error' => 'invalid_nonce']);
    }

    if ($signatureValue === null) {
        aw_json_response(401, ['error' => 'missing_signature']);
    }
    $signature = aw_b64url_decode($signatureValue);
    if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
        aw_json_response(401, ['error' => 'invalid_signature']);
    }

    $db = aw_db();
    $stmt = $db->prepare(
        'SELECT d.device_id, d.vault_id, d.access_mode, d.is_owner, d.status,
                d.auth_public_key, v.current_key_epoch
           FROM devices d
           JOIN vaults v ON v.vault_id = d.vault_id
          WHERE d.device_id = ?'
    );
    $stmt->bind_param('s', $deviceId);
    $stmt->execute();
    $result = $stmt->get_result();
    $device = $result->fetch_assoc();
    $stmt->close();

    if (!$device || $device['status'] !== 'ACTIVE') {
        aw_json_response(401, ['error' => 'unknown_or_inactive_device']);
    }

    $publicKey = $device['auth_public_key'];
    if (!is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        error_log('Invalid Ed25519 public key stored for device ' . $deviceId);
        aw_json_response(500, ['error' => 'invalid_server_key_state']);
    }

    $canonical = aw_request_canonical(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        aw_request_uri(),
        $timestamp,
        $nonceValue,
        $rawBody
    );

    if (!sodium_crypto_sign_verify_detached($signature, $canonical, $publicKey)) {
        aw_json_response(401, ['error' => 'signature_verification_failed']);
    }

    $nonceHash = hash('sha256', $nonce, true);
    try {
        $stmt = $db->prepare(
            'INSERT INTO request_nonces (device_id, nonce_hash, created_at)
             VALUES (?, ?, UTC_TIMESTAMP(6))'
        );
        $stmt->bind_param('ss', $deviceId, $nonceHash);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() === 1062) {
            aw_json_response(401, ['error' => 'replayed_request']);
        }
        throw $e;
    }

    // Nonces ouder dan de toegestane tijdsvenster zijn niet meer bruikbaar.
    // Beperk de cleanup per request om de tabel klein te houden zonder zware opruimactie.
    $db->query(
        'DELETE FROM request_nonces
          WHERE created_at < UTC_TIMESTAMP(6) - INTERVAL 10 MINUTE
          LIMIT 1000'
    );

    $stmt = $db->prepare('UPDATE devices SET last_seen_at = UTC_TIMESTAMP(6) WHERE device_id = ?');
    $stmt->bind_param('s', $deviceId);
    $stmt->execute();
    $stmt->close();

    $device['is_owner'] = (bool)$device['is_owner'];
    $device['current_key_epoch'] = (int)$device['current_key_epoch'];

    return $device;
}

function aw_require_rw(array $auth): void
{
    if (($auth['access_mode'] ?? null) !== 'RW') {
        aw_json_response(403, ['error' => 'write_access_required']);
    }
}

function aw_require_owner(array $auth): void
{
    aw_require_rw($auth);
    if (($auth['is_owner'] ?? false) !== true) {
        aw_json_response(403, ['error' => 'owner_access_required']);
    }
}
