#!/usr/bin/env php
<?php

declare(strict_types=1);

$port = (int) getenv('VERIFY_AUTHORITY_PORT');
$appUrl = (string) getenv('VERIFY_APP_URL');
$secret = (string) getenv('VERIFY_AUTHORITY_SECRET');
$code = (string) getenv('VERIFY_AUTHORITY_CODE');
$evidence = (string) getenv('VERIFY_AUTHORITY_EVIDENCE');
$context = stream_context_create(['ssl' => [
    'local_cert' => (string) getenv('VERIFY_AUTHORITY_CERT'),
    'local_pk' => (string) getenv('VERIFY_AUTHORITY_KEY'),
    'verify_peer' => false,
    'allow_self_signed' => true,
]]);
$server = stream_socket_server("tls://127.0.0.1:{$port}", $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "authority stub failed to bind loopback TLS\n");
    exit(1);
}

$record = static function (string $path, string $verdict) use ($evidence): void {
    file_put_contents($evidence, json_encode(['path' => $path, 'verdict' => $verdict], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
};
$json = static fn (array $payload): string => json_encode($payload, JSON_THROW_ON_ERROR);
$binding = static fn (): array => [
    'contract_version' => 'managed-auth-v1',
    'issuer' => 'https://sink-verify-authority.test',
    'connection_id' => 'sink-verify-connection',
    'organization_id' => 'sink-verify-organization',
    'installation_id' => 'sink-verify-installation',
    'authority_generation' => 2,
    'roster_version' => 1,
    'response_sequence' => 1,
    'responded_at' => gmdate(DATE_ATOM),
];

while (true) {
    $connection = @stream_socket_accept($server, 1);
    if ($connection === false) {
        continue;
    }

    $requestLine = trim((string) fgets($connection));
    $headers = [];
    while (($line = fgets($connection)) !== false && trim($line) !== '') {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $headers[strtolower(trim($name))] = trim($value);
    }
    if (strcasecmp($headers['expect'] ?? '', '100-continue') === 0) {
        fwrite($connection, "HTTP/1.1 100 Continue\r\n\r\n");
    }
    $length = (int) ($headers['content-length'] ?? 0);
    $body = $length > 0 ? stream_get_contents($connection, $length) : '';
    [$method, $target] = array_pad(explode(' ', $requestLine, 3), 3, '');
    $path = (string) parse_url($target, PHP_URL_PATH);
    parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
    $payload = json_decode($body, true) ?: [];
    $status = '200 OK';
    $responseHeaders = ['Content-Type: application/json'];
    $response = '';

    if ($method === 'GET' && $path === '/managed-auth/v1/authorize' && preg_match('/^[A-Za-z0-9_-]{43}$/', (string) ($query['state'] ?? '')) === 1) {
        $status = '302 Found';
        $responseHeaders = ['Location: '.$appUrl.'/bfc/managed/callback?'.http_build_query(['state' => $query['state'], 'code' => $code])];
        $record($path, 'authorized');
    } elseif (($headers['authorization'] ?? '') !== 'Bearer '.$secret || ($headers['bfc-contract-version'] ?? '') !== 'managed-auth-v1') {
        $status = '401 Unauthorized';
        $response = $json(['contract_version' => 'managed-auth-v1', 'error' => 'invalid_client']);
        $record($path, 'refused');
    } elseif ($method === 'POST' && $path === '/managed-auth/v1/handoffs') {
        $response = $json([
            'contract_version' => 'managed-auth-v1',
            'request_id' => $payload['request_id'] ?? null,
            'authorization_url' => "https://127.0.0.1:{$port}/managed-auth/v1/authorize",
            'expires_at' => gmdate(DATE_ATOM, time() + 90),
        ]);
        $record($path, 'accepted');
    } elseif ($method === 'POST' && preg_match('#^/managed-auth/v1/handoffs/([^/]+)/exchange$#', $path) === 1 && ($payload['code'] ?? '') === $code) {
        $response = $json(array_merge($binding(), [
            'scalpels_id' => 'sink-verify-subject',
            'membership_id' => 'sink-verify-membership',
            'membership_status' => 'active',
            'connection_status' => 'active',
            'role' => 'owner',
            'display_name' => 'Sink Verify Owner',
            'contact_email' => 'owner@verify.test',
            'contact_email_verified' => true,
        ]));
        $record('/managed-auth/v1/handoffs/{request}/exchange', 'accepted');
    } elseif ($method === 'POST' && $path === '/managed-auth/v1/memberships/confirm') {
        $response = $json(array_merge($binding(), [
            'scalpels_id' => $payload['scalpels_id'] ?? '',
            'membership_status' => 'active',
            'connection_status' => 'active',
            'role' => 'owner',
        ]));
        $record($path, 'accepted');
    } else {
        $status = '404 Not Found';
        $response = $json(['contract_version' => 'managed-auth-v1', 'error' => 'not_found']);
        $record($path, 'not-found');
    }

    fwrite($connection, "HTTP/1.1 {$status}\r\n".implode("\r\n", $responseHeaders)."\r\nContent-Length: ".strlen($response)."\r\nConnection: close\r\n\r\n{$response}");
    fclose($connection);
}
