<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$respond = static function (int $status, bool $ok): never {
    http_response_code($status);
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_SLASHES);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    $respond(405, false);
}
$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length > 4096) $respond(413, false);
$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origin !== '') {
    $originHost = parse_url($origin, PHP_URL_HOST);
    $requestHost = parse_url('http://' . (string)($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    if (!is_string($originHost) || !is_string($requestHost) || !hash_equals(strtolower($requestHost), strtolower($originHost))) $respond(403, false);
}
$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' && strlen($raw) <= 4096 ? json_decode($raw, true) : null;
if (!is_array($input)) $respond(400, false);
$event = is_string($input['event'] ?? null) ? $input['event'] : '';
$token = is_string($input['token'] ?? null) ? $input['token'] : '';
$recorded = jpm_record_event($pdo, $token, $event);
$respond($recorded ? 200 : 400, $recorded);
