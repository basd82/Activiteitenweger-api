<?php

declare(strict_types=1);

const BASE_URL = 'https://app.dikkenberg.net';
const STATE_FILE = __DIR__ . '/test-state.json';

function b64u(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function unb64u(string $value): string
{
    $padding = (4 - (strlen($value) % 4)) % 4;
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
    if ($decoded === false) {
        throw new RuntimeException('Invalid Base64URL');
    }
    return $decoded;
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

function canonicalRequest(string $method, string $uri, string $timestamp, string $nonce, string $body): string
{
    return "AW-REQUEST-V1\n" . strtoupper($method) . "\n" . $uri . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
}

function canonicalRecord(string $vaultId, string $recordId, int $revision, int $epoch, bool $deleted, ?string $nonce, ?string $ciphertext): string
{
    return "AW-RECORD-V1\n"
        . $vaultId . "\n"
        . $recordId . "\n"
        . $revision . "\n"
        . $epoch . "\n"
        . ($deleted ? '1' : '0') . "\n"
        . ($nonce === null ? '' : b64u($nonce)) . "\n"
        . hash('sha256', $ciphertext ?? '');
}

function loadState(): array
{
    if (!is_file(STATE_FILE)) {
        throw new RuntimeException('Geen test-state. Voer eerst: php tools/test-client.php create');
    }
    $data = json_decode((string)file_get_contents(STATE_FILE), true, 64, JSON_THROW_ON_ERROR);
    return $data;
}

function saveState(array $state): void
{
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    chmod(STATE_FILE, 0600);
}

function httpRequest(string $method, string $uri, string $body = '', ?array $state = null): array
{
    $headers = ['Accept: application/json'];
    if ($body !== '') {
        $headers[] = 'Content-Type: application/json';
    }

    if ($state !== null) {
        $timestamp = (string)time();
        $nonce = b64u(random_bytes(16));
        $canonical = canonicalRequest($method, $uri, $timestamp, $nonce, $body);
        $signature = sodium_crypto_sign_detached($canonical, unb64u($state['authSecretKey']));

        $headers[] = 'X-AW-Device-Id: ' . $state['deviceId'];
        $headers[] = 'X-AW-Timestamp: ' . $timestamp;
        $headers[] = 'X-AW-Nonce: ' . $nonce;
        $headers[] = 'X-AW-Signature: ' . b64u($signature);
    }

    $ch = curl_init(BASE_URL . $uri);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    if ($response === false) {
        throw new RuntimeException('curl: ' . curl_error($ch));
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseBody = substr($response, $headerSize);
    curl_close($ch);

    $decoded = null;
    if ($responseBody !== '') {
        try {
            $decoded = json_decode($responseBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = $responseBody;
        }
    }

    return [$status, $decoded];
}

function printResponse(int $status, mixed $body): void
{
    echo "HTTP $status\n";
    if (is_array($body)) {
        echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } elseif ($body !== null) {
        echo $body . "\n";
    }
}

$command = $argv[1] ?? '';

try {
    if ($command === 'create') {
        $signKeypair = sodium_crypto_sign_keypair();
        $authSecret = sodium_crypto_sign_secretkey($signKeypair);
        $authPublic = sodium_crypto_sign_publickey($signKeypair);

        $boxKeypair = sodium_crypto_box_keypair();
        $encSecret = sodium_crypto_box_secretkey($boxKeypair);
        $encPublic = sodium_crypto_box_publickey($boxKeypair);

        $vaultKey = random_bytes(32);
        $keyEnvelope = sodium_crypto_box_seal($vaultKey, $encPublic);

        $state = [
            'vaultId' => uuid4(),
            'deviceId' => uuid4(),
            'authSecretKey' => b64u($authSecret),
            'authPublicKey' => b64u($authPublic),
            'encryptionSecretKey' => b64u($encSecret),
            'encryptionPublicKey' => b64u($encPublic),
            'vaultKey' => b64u($vaultKey),
            'cursor' => 0,
        ];

        $body = json_encode([
            'vaultId' => $state['vaultId'],
            'deviceId' => $state['deviceId'],
            'authPublicKey' => $state['authPublicKey'],
            'encryptionPublicKey' => $state['encryptionPublicKey'],
            'keyEpoch' => 1,
            'keyEnvelope' => b64u($keyEnvelope),
            'keyEnvelopeNonce' => null,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        [$status, $response] = httpRequest('POST', '/api/v1/vaults', $body);
        printResponse($status, $response);
        if ($status === 201) {
            saveState($state);
            echo 'Test-state opgeslagen in ' . STATE_FILE . "\n";
        }
        exit($status === 201 ? 0 : 1);
    }

    $state = loadState();

    if ($command === 'me') {
        [$status, $response] = httpRequest('GET', '/api/v1/me', '', $state);
        printResponse($status, $response);
        exit($status === 200 ? 0 : 1);
    }

    if ($command === 'devices') {
        [$status, $response] = httpRequest('GET', '/api/v1/devices', '', $state);
        printResponse($status, $response);
        exit($status === 200 ? 0 : 1);
    }

    if ($command === 'add-record') {
        $recordId = uuid4();
        $revision = 1;
        $epoch = 1;
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $vaultKey = unb64u($state['vaultKey']);

        $activity = json_encode([
            'schemaVersion' => 1,
            'type' => 'activity',
            'startedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'endedAt' => null,
            'description' => 'Testactiviteit',
            'category' => 'licht',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $activity,
            $recordId,
            $nonce,
            $vaultKey
        );

        $recordCanonical = canonicalRecord(
            $state['vaultId'],
            $recordId,
            $revision,
            $epoch,
            false,
            $nonce,
            $ciphertext
        );
        $recordSignature = sodium_crypto_sign_detached($recordCanonical, unb64u($state['authSecretKey']));

        $body = json_encode([
            'recordId' => $recordId,
            'revision' => $revision,
            'keyEpoch' => $epoch,
            'deleted' => false,
            'ciphertext' => b64u($ciphertext),
            'nonce' => b64u($nonce),
            'recordSignature' => b64u($recordSignature),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        [$status, $response] = httpRequest('POST', '/api/v1/records', $body, $state);
        printResponse($status, $response);
        exit(in_array($status, [200, 201], true) ? 0 : 1);
    }

    if ($command === 'sync') {
        $uri = '/api/v1/sync?since=' . (int)$state['cursor'] . '&limit=100';
        [$status, $response] = httpRequest('GET', $uri, '', $state);
        printResponse($status, $response);
        if ($status === 200 && is_array($response) && isset($response['nextCursor'])) {
            $state['cursor'] = (int)$response['nextCursor'];
            saveState($state);
        }
        exit($status === 200 ? 0 : 1);
    }

    if ($command === 'delete-vault') {
        $body = json_encode(['confirm' => 'DELETE'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $uri = '/api/v1/vaults/' . $state['vaultId'];
        [$status, $response] = httpRequest('DELETE', $uri, $body, $state);
        printResponse($status, $response);
        if ($status === 200) {
            @unlink(STATE_FILE);
        }
        exit($status === 200 ? 0 : 1);
    }

    fwrite(STDERR, "Gebruik:\n");
    fwrite(STDERR, "  php tools/test-client.php create\n");
    fwrite(STDERR, "  php tools/test-client.php me\n");
    fwrite(STDERR, "  php tools/test-client.php devices\n");
    fwrite(STDERR, "  php tools/test-client.php add-record\n");
    fwrite(STDERR, "  php tools/test-client.php sync\n");
    fwrite(STDERR, "  php tools/test-client.php delete-vault\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
