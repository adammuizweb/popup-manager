<?php
declare(strict_types=1);

function jpm_campaign(PDO $pdo, int $id, bool $lock = false): ?array
{
    if ($id <= 0) return null;
    $stmt = $pdo->prepare('SELECT * FROM `' . JPM_CAMPAIGNS_TABLE . '` WHERE id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function jpm_campaigns(PDO $pdo, int $limit = 200): array
{
    $limit = max(1, min(200, $limit));
    return $pdo->query(
        'SELECT * FROM `' . JPM_CAMPAIGNS_TABLE . '`
         ORDER BY priority DESC, updated_at DESC, id DESC LIMIT ' . $limit
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function jpm_runtime_campaigns(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT * FROM `" . JPM_CAMPAIGNS_TABLE . "`
         WHERE status = 'active'
           AND (starts_at IS NULL OR starts_at <= NOW())
           AND (ends_at IS NULL OR ends_at >= NOW())
         ORDER BY priority DESC, updated_at DESC, id DESC
         LIMIT 50"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function jpm_public_media(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    $stmt = $pdo->prepare(
        "SELECT id,url,mime,alt,width,height FROM media
         WHERE id = ? AND mime LIKE 'image/%'
           AND visibility = 'public' AND storage_disk = 'public' AND access_scope = 'public'
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) && trim((string)($row['url'] ?? '')) !== '' ? $row : null;
}

function jpm_campaign_media(PDO $pdo, array $campaign): array
{
    $result = [];
    foreach (['desktop', 'tablet', 'mobile'] as $device) {
        $result[$device] = jpm_public_media($pdo, (int)($campaign[$device . '_media_id'] ?? 0));
    }
    return $result;
}
