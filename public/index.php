<?php

// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Bas van den Dikkenberg

declare(strict_types=1);

require dirname(__DIR__) . '/src/common.php';
require dirname(__DIR__) . '/src/db.php';
require dirname(__DIR__) . '/src/auth.php';
require dirname(__DIR__) . '/src/handlers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$rawBody = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? aw_read_raw_body() : '';

try {
    if ($method === 'GET' && $path === '/api/v1/health') {
        aw_handle_health();
    }

    if ($method === 'POST' && $path === '/api/v1/vaults') {
        aw_handle_create_vault($rawBody);
    }

    if ($method === 'GET' && $path === '/api/v1/me') {
        $auth = aw_require_auth($rawBody);
        aw_handle_me($auth);
    }

    if ($method === 'GET' && $path === '/api/v1/devices') {
        $auth = aw_require_auth($rawBody);
        aw_handle_devices($auth);
    }

    if ($method === 'POST' && $path === '/api/v1/records') {
        $auth = aw_require_auth($rawBody);
        aw_handle_upsert_record($auth, $rawBody);
    }

    if ($method === 'GET' && $path === '/api/v1/sync') {
        $auth = aw_require_auth($rawBody);
        aw_handle_sync($auth);
    }

    if ($method === 'DELETE' && preg_match('#^/api/v1/vaults/([0-9a-f-]{36})$#i', $path, $m) === 1) {
        $vaultId = strtolower($m[1]);
        if (!aw_valid_uuid($vaultId)) {
            aw_json_response(404, ['error' => 'not_found']);
        }
        $auth = aw_require_auth($rawBody);
        aw_handle_delete_vault($auth, $rawBody, $vaultId);
    }

    aw_json_response(404, ['error' => 'not_found']);
} catch (mysqli_sql_exception $e) {
    error_log('Unhandled database error: ' . $e->getMessage());
    aw_json_response(500, ['error' => 'database_error']);
} catch (Throwable $e) {
    error_log('Unhandled API error: ' . $e->getMessage());
    aw_json_response(500, ['error' => 'internal_error']);
}
