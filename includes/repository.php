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
    return $pdo->query('SELECT * FROM `' . JPM_CAMPAIGNS_TABLE . '` ORDER BY sequence_order ASC, priority DESC, id ASC LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function jpm_campaign_summary(PDO $pdo): array
{
    $row = $pdo->query(
        "SELECT COUNT(*) AS total,
                SUM(status = 'active') AS active,
                SUM(status = 'draft') AS draft,
                SUM(status = 'active' AND ((starts_at IS NOT NULL AND starts_at > NOW()) OR (ends_at IS NOT NULL AND ends_at < NOW()))) AS outside_schedule,
                COALESCE(SUM(impression_count), 0) AS impressions,
                COALESCE(SUM(close_count), 0) AS closes,
                COALESCE(SUM(click_count), 0) AS clicks
         FROM `" . JPM_CAMPAIGNS_TABLE . '`'
    )->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : ['total' => 0, 'active' => 0, 'draft' => 0, 'outside_schedule' => 0, 'impressions' => 0, 'closes' => 0, 'clicks' => 0];
}

function jpm_campaign_page(PDO $pdo, array $filters, int $page = 1, int $perPage = 20): array
{
    $where = ['1=1'];
    $params = [];
    $search = mb_substr(trim((string)($filters['search'] ?? '')), 0, 100, 'UTF-8');
    if ($search !== '') {
        $where[] = 'LOCATE(LOWER(:search), LOWER(name)) > 0';
        $params[':search'] = $search;
    }
    $status = (string)($filters['status'] ?? '');
    if (in_array($status, ['draft', 'active', 'paused'], true)) {
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }
    $type = (string)($filters['type'] ?? '');
    if (in_array($type, ['image', 'html'], true)) {
        $where[] = 'content_type = :type';
        $params[':type'] = $type;
    }
    $target = (string)($filters['target'] ?? '');
    if (in_array($target, ['all', 'homepage', 'paths', 'contexts'], true)) {
        $where[] = 'target_mode = :target';
        $params[':target'] = $target;
    }
    $whereSql = implode(' AND ', $where);
    $count = $pdo->prepare('SELECT COUNT(*) FROM `' . JPM_CAMPAIGNS_TABLE . '` WHERE ' . $whereSql);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $perPage = max(5, min(100, $perPage));
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($pages, $page));
    $stmt = $pdo->prepare(
        'SELECT * FROM `' . JPM_CAMPAIGNS_TABLE . '` WHERE ' . $whereSql . '
         ORDER BY sequence_order ASC, priority DESC, id ASC LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) $stmt->bindValue($key, $value, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total, 'pages' => $pages, 'page' => $page, 'per_page' => $perPage];
}

function jpm_runtime_campaigns(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT * FROM `" . JPM_CAMPAIGNS_TABLE . "`
         WHERE status = 'active'
           AND (starts_at IS NULL OR starts_at <= NOW())
           AND (ends_at IS NULL OR ends_at >= NOW())
         ORDER BY sequence_order ASC, priority DESC, id ASC
         LIMIT 50"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function jpm_next_sequence_order(PDO $pdo): int
{
    return min(100000, max(10, (int)$pdo->query('SELECT COALESCE(MAX(sequence_order), 0) + 10 FROM `' . JPM_CAMPAIGNS_TABLE . '`')->fetchColumn()));
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
    foreach (['desktop', 'tablet', 'mobile'] as $device) $result[$device] = jpm_public_media($pdo, (int)($campaign[$device . '_media_id'] ?? 0));
    return $result;
}
