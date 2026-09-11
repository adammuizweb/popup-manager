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

function jpm_legacy_campaign_slide(array $campaign): array
{
    $type = (string)($campaign['content_type'] ?? 'image');
    if ($type === 'html') {
        return ['type' => 'html', 'html_content' => (string)($campaign['html_content'] ?? '')];
    }
    $targetUrl = jpm_normalize_target_url((string)($campaign['target_url'] ?? ''));
    return [
        'type' => 'image',
        'desktop_media_id' => (int)($campaign['desktop_media_id'] ?? 0),
        'tablet_media_id' => (int)($campaign['tablet_media_id'] ?? 0),
        'mobile_media_id' => (int)($campaign['mobile_media_id'] ?? 0),
        'image_alt' => (string)($campaign['image_alt'] ?? ''),
        'target_url' => $targetUrl ?? '',
        'open_new_tab' => (int)($campaign['open_new_tab'] ?? 0) === 1,
    ];
}

function jpm_campaign_slides(array $campaign): array
{
    $stored = $campaign['slides'] ?? null;
    if ($stored === null || (is_string($stored) && trim($stored) === '')) return [jpm_legacy_campaign_slide($campaign)];
    if (!is_string($stored)) return [];
    try {
        $document = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }
    if (!is_array($document) || ($document['version'] ?? null) !== JPM_SLIDES_VERSION
        || !isset($document['slides']) || !is_array($document['slides']) || !array_is_list($document['slides'])
        || count($document['slides']) < 1 || count($document['slides']) > JPM_MAX_SLIDES) return [];
    foreach ($document['slides'] as $slide) {
        if (!is_array($slide) || !in_array($slide['type'] ?? null, ['image', 'html'], true)) return [];
        if ($slide['type'] === 'html' && (!is_string($slide['html_content'] ?? null)
            || strlen($slide['html_content']) > JPM_MAX_SLIDE_HTML_BYTES)) return [];
        if ($slide['type'] === 'image') {
            foreach (['desktop_media_id', 'tablet_media_id', 'mobile_media_id'] as $key) {
                if (!isset($slide[$key]) || !is_int($slide[$key]) || $slide[$key] < 0) return [];
            }
            if (!is_string($slide['image_alt'] ?? null) || mb_strlen($slide['image_alt'], 'UTF-8') > 255
                || !is_string($slide['target_url'] ?? null) || strlen($slide['target_url']) > 2048
                || jpm_normalize_target_url($slide['target_url']) !== $slide['target_url']
                || !is_bool($slide['open_new_tab'] ?? null)) return [];
        }
    }
    return $document['slides'];
}

function jpm_encode_slides(array $slides): string
{
    return json_encode(['version' => JPM_SLIDES_VERSION, 'slides' => $slides], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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

function jpm_slide_media(PDO $pdo, array $slide): array
{
    $result = [];
    foreach (['desktop', 'tablet', 'mobile'] as $device) $result[$device] = jpm_public_media($pdo, (int)($slide[$device . '_media_id'] ?? 0));
    return $result;
}
