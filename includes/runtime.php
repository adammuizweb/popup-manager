<?php
declare(strict_types=1);

function jpm_current_path(): string
{
    if (isset($GLOBALS['jpm_request_path']) && is_string($GLOBALS['jpm_request_path'])) {
        return $GLOBALS['jpm_request_path'];
    }
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    return jpm_normalize_path($uri) ?? '/';
}

function jpm_is_homepage_request(string $path): bool
{
    if (($GLOBALS['jpm_is_homepage'] ?? false) === true) return true;
    return $path === '/' && trim((string)($_GET['s'] ?? '')) === '';
}

function jpm_runtime_campaign(PDO $pdo): ?array
{
    static $resolved = false;
    static $selected = null;
    if ($resolved) return $selected;
    $resolved = true;
    if (!jpm_schema_is_ready($pdo)) return null;
    $path = jpm_current_path();
    if (jpm_is_sensitive_path($pdo, $path)) return null;
    $homepage = jpm_is_homepage_request($path);
    try {
        foreach (jpm_runtime_campaigns($pdo) as $campaign) {
            if (!jpm_target_matches($campaign, $path, $homepage)) continue;
            $campaign['_path'] = $path;
            if ((string)$campaign['content_type'] === 'image') {
                $campaign['_media'] = jpm_campaign_media($pdo, $campaign);
                if (!is_array($campaign['_media']['desktop'])) continue;
            }
            $selected = $campaign;
            return $selected;
        }
    } catch (Throwable $error) {
        error_log('[popup-manager] runtime selection error: ' . $error->getMessage());
    }
    return null;
}

function jpm_render_frontend(): void
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    $campaign = jpm_runtime_campaign($pdo);
    if (!is_array($campaign)) return;

    $id = (int)$campaign['id'];
    $revision = substr(hash('sha256', $id . '|' . (string)$campaign['updated_at']), 0, 16);
    $config = [
        'id' => $id,
        'revision' => $revision,
        'frequency' => (string)$campaign['frequency'],
        'delay' => (int)$campaign['delay_ms'],
        'path' => (string)$campaign['_path'],
        'closeOnOverlay' => (int)$campaign['close_on_overlay'] === 1,
    ];
    $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if (!is_string($json)) return;
    $version = rawurlencode(JPM_VERSION);
    $label = trim((string)$campaign['name']) !== '' ? (string)$campaign['name'] : jpm_t('Popup announcement');
    ?>
<link rel="stylesheet" href="/static/plugins/popup-manager/frontend.css?v=<?=$version?>">
<div class="jpm-popup" data-jpm-popup hidden aria-hidden="true">
  <div class="jpm-popup__backdrop" data-jpm-overlay></div>
  <section class="jpm-popup__dialog" role="dialog" aria-modal="true" aria-label="<?=jpm_h($label)?>" tabindex="-1" style="--jpm-max-width:<?=(int)$campaign['max_width']?>px">
    <?php if ((int)$campaign['show_close'] === 1): ?><button class="jpm-popup__close" type="button" data-jpm-close aria-label="<?=jpm_h(jpm_t('Close popup'))?>">&times;</button><?php endif; ?>
    <div class="jpm-popup__content">
      <?php if ((string)$campaign['content_type'] === 'html'): ?>
        <div class="jpm-popup__html"><?=jpm_sanitize_html((string)$campaign['html_content'])?></div>
      <?php else:
        $media = $campaign['_media'];
        $desktop = $media['desktop'];
        $tablet = is_array($media['tablet']) ? $media['tablet'] : $desktop;
        $mobile = is_array($media['mobile']) ? $media['mobile'] : $tablet;
        $alt = trim((string)$campaign['image_alt']);
        if ($alt === '') $alt = (string)($desktop['alt'] ?? $label);
        $target = (string)$campaign['target_url'];
        if ($target !== ''): ?><a class="jpm-popup__image-link" href="<?=jpm_h($target)?>"<?=(int)$campaign['open_new_tab']===1?' target="_blank" rel="noopener noreferrer"':''?>><?php endif; ?>
          <picture>
            <source media="(max-width: 640px)" srcset="<?=jpm_h((string)$mobile['url'])?>">
            <source media="(max-width: 1024px)" srcset="<?=jpm_h((string)$tablet['url'])?>">
            <img class="jpm-popup__image" src="<?=jpm_h((string)$desktop['url'])?>" alt="<?=jpm_h($alt)?>" loading="eager" decoding="async">
          </picture>
        <?php if ($target !== ''): ?></a><?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
  <script type="application/json" data-jpm-config><?=$json?></script>
</div>
<script src="/static/plugins/popup-manager/frontend.js?v=<?=$version?>"></script>
    <?php
}
