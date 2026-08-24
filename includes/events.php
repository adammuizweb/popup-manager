<?php
declare(strict_types=1);

function jpm_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function jpm_base64url_decode(string $value): ?string
{
    $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return is_string($decoded) ? $decoded : null;
}

function jpm_event_token(int $campaignId, string $revision, int $ttl = 7200): string
{
    $secret = (string)getenv('SESSION_SECRET');
    if ($campaignId <= 0 || $secret === '') return '';
    $payload = jpm_base64url_encode(json_encode([
        'id' => $campaignId,
        'rev' => $revision,
        'exp' => time() + max(300, min(7200, $ttl)),
        'nonce' => bin2hex(random_bytes(16)),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return $payload . '.' . hash_hmac('sha256', $payload, $secret);
}

function jpm_verify_event_token(string $token): ?array
{
    $secret = (string)getenv('SESSION_SECRET');
    $parts = explode('.', $token, 2);
    if ($secret === '' || count($parts) !== 2 || strlen($token) > 1024) return null;
    [$payload, $signature] = $parts;
    if (!preg_match('/^[a-f0-9]{64}$/', $signature) || !hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) return null;
    $json = jpm_base64url_decode($payload);
    $data = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($data) || (int)($data['id'] ?? 0) <= 0 || (int)($data['exp'] ?? 0) < time()
        || (int)$data['exp'] > time() + 7200 || !is_string($data['nonce'] ?? null)
        || preg_match('/^[a-f0-9]{32}$/', $data['nonce']) !== 1) return null;
    return $data;
}

function jpm_record_event(PDO $pdo, string $token, string $event): bool
{
    $map = [
        'impression' => ['impression_counted', 'impression_count'],
        'close' => ['close_counted', 'close_count'],
        'click' => ['click_counted', 'click_count'],
    ];
    $payload = jpm_verify_event_token($token);
    if (!isset($map[$event]) || !is_array($payload) || !jpm_schema_is_ready($pdo)) return false;
    [$flag, $counter] = $map[$event];
    $campaignId = (int)$payload['id'];
    $hash = hash('sha256', $token);
    try {
        $pdo->beginTransaction();
        $insert = $pdo->prepare(
            'INSERT INTO `' . JPM_EVENT_TOKENS_TABLE . '` (token_hash,campaign_id,expires_at)
             VALUES (?,?,?) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash)'
        );
        $insert->execute([$hash, $campaignId, date('Y-m-d H:i:s', (int)$payload['exp'])]);
        $lock = $pdo->prepare('SELECT campaign_id,expires_at,`' . $flag . '` AS counted FROM `' . JPM_EVENT_TOKENS_TABLE . '` WHERE token_hash=? LIMIT 1 FOR UPDATE');
        $lock->execute([$hash]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int)$row['campaign_id'] !== $campaignId || strtotime((string)$row['expires_at']) < time()) {
            $pdo->rollBack();
            return false;
        }
        if ((int)$row['counted'] === 0) {
            $pdo->prepare('UPDATE `' . JPM_EVENT_TOKENS_TABLE . '` SET `' . $flag . '`=1 WHERE token_hash=?')->execute([$hash]);
            $updated = $pdo->prepare('UPDATE `' . JPM_CAMPAIGNS_TABLE . '` SET `' . $counter . '`=`' . $counter . '`+1,last_event_at=NOW() WHERE id=?');
            $updated->execute([$campaignId]);
            if ($updated->rowCount() !== 1) {
                $pdo->rollBack();
                return false;
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[popup-manager] event error: ' . $error->getMessage());
        return false;
    }
    try {
        if (random_int(1, 100) === 1) $pdo->exec('DELETE FROM `' . JPM_EVENT_TOKENS_TABLE . '` WHERE expires_at < NOW() LIMIT 100');
    } catch (Throwable) {
    }
    return true;
}

function jpm_frontend_route(PDO $pdo): void
{
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    if (rtrim($path, '/') !== '/popup-manager/event') {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    require __DIR__ . '/../public/event.php';
}
