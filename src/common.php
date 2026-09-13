<?php

// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Bas van den Dikkenberg

declare(strict_types=1);

const AW_API_VERSION = 1;
const AW_SERVER_VERSION = '1.1.0';
const AW_MAX_JSON_BYTES = 524288; // 512 KiB

function aw_json_response(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-AW-Server-Version: ' . AW_SERVER_VERSION);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function aw_read_raw_body(): string
{
    $raw = file_get_contents('php://input');
    if ($raw === false) {
        aw_json_response(400, ['error' => 'invalid_request_body']);
    }
    if (strlen($raw) > AW_MAX_JSON_BYTES) {
        aw_json_response(413, ['error' => 'request_too_large']);
    }
    return $raw;
}

function aw_decode_json_object(string $raw): array
{
    if ($raw === '') {
        aw_json_response(400, ['error' => 'empty_request']);
    }

    try {
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        aw_json_response(400, ['error' => 'invalid_json']);
    }

    if (!is_array($data) || array_is_list($data)) {
        aw_json_response(400, ['error' => 'json_object_required']);
    }

    return $data;
}

function aw_valid_uuid(string $uuid): bool
{
    return preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        $uuid
    ) === 1;
}

function aw_required_string(array $data, string $field): string
{
    if (!array_key_exists($field, $data) || !is_string($data[$field]) || $data[$field] === '') {
        aw_json_response(400, ['error' => 'invalid_request', 'field' => $field]);
    }
    return $data[$field];
}

function aw_required_positive_int(array $data, string $field): int
{
    if (!array_key_exists($field, $data) || !is_int($data[$field]) || $data[$field] < 1) {
        aw_json_response(400, ['error' => 'invalid_request', 'field' => $field]);
    }
    return $data[$field];
}

function aw_b64url_encode(string $binary): string
{
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

function aw_b64url_decode(string $value): string|false
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
        return false;
    }

    $padding = (4 - (strlen($value) % 4)) % 4;
    return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
}

/* Body fields accept both normal Base64 and Base64URL during development. */
function aw_base64_any_decode(string $value): string|false
{
    $url = aw_b64url_decode($value);
    if ($url !== false) {
        return $url;
    }
    return base64_decode($value, true);
}

function aw_decode_binary_field(array $data, string $field, ?int $exactBytes = null, ?int $maxBytes = null): string
{
    $encoded = aw_required_string($data, $field);
    $decoded = aw_base64_any_decode($encoded);

    if ($decoded === false) {
        aw_json_response(400, ['error' => 'invalid_base64', 'field' => $field]);
    }

    $length = strlen($decoded);
    if ($exactBytes !== null && $length !== $exactBytes) {
        aw_json_response(400, ['error' => 'invalid_length', 'field' => $field, 'expectedBytes' => $exactBytes]);
    }
    if ($maxBytes !== null && $length > $maxBytes) {
        aw_json_response(400, ['error' => 'field_too_large', 'field' => $field]);
    }

    return $decoded;
}

function aw_optional_binary_field(array $data, string $field, ?int $maxBytes = null): ?string
{
    if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
        return null;
    }
    if (!is_string($data[$field])) {
        aw_json_response(400, ['error' => 'invalid_request', 'field' => $field]);
    }
    $decoded = aw_base64_any_decode($data[$field]);
    if ($decoded === false) {
        aw_json_response(400, ['error' => 'invalid_base64', 'field' => $field]);
    }
    if ($maxBytes !== null && strlen($decoded) > $maxBytes) {
        aw_json_response(400, ['error' => 'field_too_large', 'field' => $field]);
    }
    return $decoded;
}

function aw_header(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!isset($_SERVER[$key]) || !is_string($_SERVER[$key])) {
        return null;
    }
    return trim($_SERVER[$key]);
}

function aw_request_uri(): string
{
    return $_SERVER['REQUEST_URI'] ?? '/';
}
