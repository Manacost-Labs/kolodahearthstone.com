<?php

defined('ABSPATH') || exit(1);

function khs_cf_request(string $method, string $path, ?array $payload = null): array
{
    $email = (string) (get_option('manacost_cloudflare_email', '') ?: get_option('cloudflare_api_email', ''));
    $key = (string) (get_option('manacost_cloudflare_api_key', '') ?: get_option('cloudflare_api_key', ''));
    if ($email === '' || $key === '') {
        throw new RuntimeException('Cloudflare credentials are unavailable.');
    }
    $args = [
        'method' => $method,
        'timeout' => 30,
        'redirection' => 0,
        'headers' => [
            'X-Auth-Email' => $email,
            'X-Auth-Key' => $key,
            'Content-Type' => 'application/json',
        ],
    ];
    if ($payload !== null) {
        $args['body'] = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        $args['data_format'] = 'body';
    }
    $response = wp_remote_request('https://api.cloudflare.com/client/v4/' . ltrim($path, '/'), $args);
    if (is_wp_error($response)) {
        throw new RuntimeException($response->get_error_message());
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status >= 300 || !is_array($body) || empty($body['success'])) {
        throw new RuntimeException('Cloudflare API request failed with HTTP ' . $status . '.');
    }
    return $body;
}

$apply = getenv('KHS_DNS_APPLY') === '1';
$zoneResponse = khs_cf_request('GET', 'zones?name=kolodahearthstone.com&status=active');
$zones = $zoneResponse['result'] ?? [];
if (count($zones) !== 1 || empty($zones[0]['id'])) {
    throw new RuntimeException('Expected one active kolodahearthstone.com zone.');
}

$zoneId = (string) $zones[0]['id'];
$recordName = 'test.kolodahearthstone.com';
$recordsResponse = khs_cf_request(
    'GET',
    'zones/' . rawurlencode($zoneId) . '/dns_records?name=' . rawurlencode($recordName) . '&per_page=100'
);
$records = is_array($recordsResponse['result'] ?? null) ? $recordsResponse['result'] : [];
$desiredAddresses = ['194.67.92.242', '186.246.28.244'];
$allowedAddresses = array_merge($desiredAddresses, ['151.80.21.140']);

foreach ($records as $record) {
    $type = strtoupper((string) ($record['type'] ?? ''));
    $content = strtolower(rtrim((string) ($record['content'] ?? ''), '.'));
    if (in_array($type, ['A', 'AAAA', 'CNAME'], true) && ($type !== 'A' || !in_array($content, $allowedAddresses, true))) {
        throw new RuntimeException('Unexpected staging DNS record; refusing to modify DNS.');
    }
}

foreach ($desiredAddresses as $address) {
    $existing = null;
    foreach ($records as $record) {
        if (strtoupper((string) ($record['type'] ?? '')) === 'A' && (string) ($record['content'] ?? '') === $address) {
            $existing = $record;
            break;
        }
    }
    $payload = [
        'type' => 'A',
        'name' => $recordName,
        'content' => $address,
        'ttl' => 60,
        'proxied' => false,
    ];
    if ($existing === null) {
        echo ($apply ? 'create ' : 'would create ') . 'A ' . $recordName . ' -> ' . $address . PHP_EOL;
        if ($apply) {
            khs_cf_request('POST', 'zones/' . rawurlencode($zoneId) . '/dns_records', $payload);
        }
        continue;
    }
    $needsUpdate = (bool) ($existing['proxied'] ?? false) || (int) ($existing['ttl'] ?? 0) !== 60;
    echo ($needsUpdate ? ($apply ? 'update ' : 'would update ') : 'unchanged ') . 'A ' . $recordName . ' -> ' . $address . PHP_EOL;
    if ($apply && $needsUpdate) {
        khs_cf_request('PUT', 'zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode((string) $existing['id']), $payload);
    }
}

$wwwName = 'www.test.kolodahearthstone.com';
$wwwResponse = khs_cf_request(
    'GET',
    'zones/' . rawurlencode($zoneId) . '/dns_records?name=' . rawurlencode($wwwName) . '&per_page=100'
);
$wwwRecords = is_array($wwwResponse['result'] ?? null) ? $wwwResponse['result'] : [];
foreach ($wwwRecords as $record) {
    $type = strtoupper((string) ($record['type'] ?? ''));
    $content = strtolower(rtrim((string) ($record['content'] ?? ''), '.'));
    if ($type !== 'CNAME' || $content !== 'test.kolodahearthstone.com') {
        throw new RuntimeException('Unexpected www staging DNS record; refusing to modify DNS.');
    }
}
$wwwExisting = $wwwRecords[0] ?? null;
$wwwPayload = [
    'type' => 'CNAME',
    'name' => $wwwName,
    'content' => 'test.kolodahearthstone.com',
    'ttl' => 60,
    'proxied' => false,
];
if ($wwwExisting === null) {
    echo ($apply ? 'create ' : 'would create ') . 'CNAME ' . $wwwName . ' -> test.kolodahearthstone.com' . PHP_EOL;
    if ($apply) {
        khs_cf_request('POST', 'zones/' . rawurlencode($zoneId) . '/dns_records', $wwwPayload);
    }
} else {
    $needsUpdate = (bool) ($wwwExisting['proxied'] ?? false) || (int) ($wwwExisting['ttl'] ?? 0) !== 60;
    echo ($needsUpdate ? ($apply ? 'update ' : 'would update ') : 'unchanged ') . 'CNAME ' . $wwwName . ' -> test.kolodahearthstone.com' . PHP_EOL;
    if ($apply && $needsUpdate) {
        khs_cf_request('PUT', 'zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode((string) $wwwExisting['id']), $wwwPayload);
    }
}

if (!$apply) {
    echo 'dry-run only; set KHS_DNS_APPLY=1 to apply' . PHP_EOL;
}
