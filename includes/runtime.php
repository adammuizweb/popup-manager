<?php
declare(strict_types=1);

function jpm_current_path(): string
{
    if (isset($GLOBALS['jpm_request_path']) && is_string($GLOBALS['jpm_request_path'])) return $GLOBALS['jpm_request_path'];
    return jpm_normalize_path((string)($_SERVER['REQUEST_URI'] ?? '/')) ?? '/';
}

function jpm_set_page_context(string $context): void
{
    if (isset(jpm_context_options()[$context])) $GLOBALS['jpm_page_context'] = $context;
}

function jpm_capture_post_context(array $post, ?PDO $pdo = null): array
{
    $type = (string)($post['type'] ?? '');
    if ($type === 'article') jpm_set_page_context('single.article');
    elseif ($type === 'page') jpm_set_page_context('single.page');
    return $post;
}

function jpm_capture_theme_context(array $post, ?PDO $pdo = null): array
{
    jpm_set_page_context('single.theme');
    return $post;
}

function jpm_capture_collection_context(array $rows, array $context = []): array
{
    $map = [
        'article_list' => 'list.article',
        'page_list' => 'list.page',
        'category_index' => 'list.category_index',
        'category_posts' => 'list.category',
        'author_posts' => 'list.author',
        'archive_posts' => 'list.archive',
    ];
    $scope = (string)($context['scope'] ?? '');
    if (isset($map[$scope])) jpm_set_page_context($map[$scope]);
    return $rows;
}

function jpm_capture_collection_item_context(array $item, string $type, array $context = []): array
{
    if ($type === 'category' || (string)($context['scope'] ?? '') === 'category') jpm_set_page_context('list.category');
    return $item;
}

function jpm_capture_search_context(array $rows, ?PDO $pdo = null, string $query = ''): array
{
    jpm_set_page_context('search');
    return $rows;
}

function jpm_is_homepage_request(string $path): bool
{
    if (($GLOBALS['jpm_is_homepage'] ?? false) === true) return true;
    return $path === '/' && trim((string)($_GET['s'] ?? '')) === '';
}

function jpm_current_context(?string $path = null): string
{
    if (http_response_code() === 404) return 'error.404';
    if (isset($GLOBALS['jpm_page_context']) && is_string($GLOBALS['jpm_page_context'])) return $GLOBALS['jpm_page_context'];
    $path ??= jpm_current_path();
    if (jpm_is_homepage_request($path)) return 'home';
    if ($path === '/author') return 'list.author_index';
    if (preg_match('#^/\d{4}(?:/\d{1,2})?(?:/(?:p|page)/\d+)?$#', $path) === 1) return 'list.archive';
    return 'custom';
}

function jpm_runtime_queue(PDO $pdo): array
{
    static $resolved = false;
    static $queue = [];
    if ($resolved) return $queue;
    $resolved = true;
    if (!jpm_schema_is_ready($pdo)) return [];
    $path = jpm_current_path();
    if (jpm_is_sensitive_path($pdo, $path)) return [];
    $homepage = jpm_is_homepage_request($path);
    $context = jpm_current_context($path);
    try {
        foreach (jpm_runtime_campaigns($pdo) as $campaign) {
            if (!jpm_target_matches($campaign, $path, $homepage, $context)) continue;
            $campaign['_path'] = $path;
            $campaign['_context'] = $context;
            $campaign['_slides'] = [];
            foreach (jpm_campaign_slides($campaign) as $slide) {
                if (($slide['type'] ?? '') !== (string)$campaign['content_type']) continue;
                if ((string)$campaign['content_type'] === 'image') {
                    $slide['_media'] = jpm_slide_media($pdo, $slide);
                    if (!is_array($slide['_media']['desktop'])) continue;
                } else {
                    $slide['html_content'] = jpm_sanitize_html((string)($slide['html_content'] ?? ''), (string)($campaign['html_policy'] ?? 'restricted'));
                    if (!jpm_html_has_content($slide['html_content'])) continue;
                }
                $campaign['_slides'][] = $slide;
            }
            if ($campaign['_slides'] === []) continue;
            $queue[] = $campaign;
            if (count($queue) >= 10) break;
        }
    } catch (Throwable $error) {
        error_log('[popup-manager] runtime selection error: ' . $error->getMessage());
    }
    return $queue;
}

function jpm_runtime_campaign(PDO $pdo): ?array
{
    $queue = jpm_runtime_queue($pdo);
    return $queue[0] ?? null;
}

function jpm_render_campaign(array $campaign): void
{
    $id = (int)$campaign['id'];
    $revision = substr(hash('sha256', $id . '|' . (string)$campaign['updated_at']), 0, 16);
    $config = [
        'id' => $id,
        'revision' => $revision,
        'frequency' => (string)$campaign['frequency'],
        'delay' => (int)$campaign['delay_ms'],
        'path' => (string)$campaign['_path'],
        'closeOnOverlay' => (int)$campaign['close_on_overlay'] === 1,
        'eventUrl' => '/popup-manager/event/',
        'eventToken' => jpm_event_token($id, $revision),
    ];
    $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if (!is_string($json)) return;
    $label = trim((string)$campaign['name']) !== '' ? (string)$campaign['name'] : jpm_t('Popup announcement');
    $slides = $campaign['_slides'];
    $slideCount = count($slides);
    ?>
<div class="jpm-popup" data-jpm-popup hidden aria-hidden="true">
  <div class="jpm-popup__backdrop" data-jpm-overlay></div>
  <section class="jpm-popup__dialog" role="dialog" aria-modal="true" aria-label="<?=jpm_h($label)?>" tabindex="-1" style="--jpm-max-width:<?=(int)$campaign['max_width']?>px">
    <?php if ((int)$campaign['show_close'] === 1): ?><button class="jpm-popup__close" type="button" data-jpm-close aria-label="<?=jpm_h(jpm_t('Close popup'))?>"><svg class="jpm-popup__close-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg></button><?php endif; ?>
    <div class="jpm-popup__content" data-jpm-carousel aria-roledescription="carousel">
      <div class="jpm-popup__slides" aria-live="polite">
      <?php foreach ($slides as $index => $slide): ?>
        <div class="jpm-popup__slide" data-jpm-slide role="group" aria-roledescription="slide" aria-label="<?=jpm_h(jpm_t('Slide %d of %d', $index + 1, $slideCount))?>" aria-hidden="<?=$index === 0 ? 'false' : 'true'?>" <?=$index === 0 ? '' : 'hidden'?>>
          <?php if ((string)$campaign['content_type'] === 'html'): ?>
            <div class="jpm-popup__html"><?=$slide['html_content']?></div>
          <?php else:
            $media = $slide['_media'];
            $desktop = $media['desktop'];
            $tablet = is_array($media['tablet']) ? $media['tablet'] : $desktop;
            $mobile = is_array($media['mobile']) ? $media['mobile'] : $tablet;
            $alt = trim((string)$slide['image_alt']);
            if ($alt === '') $alt = (string)($desktop['alt'] ?? $label);
            $target = (string)$slide['target_url'];
            if ($target !== ''): ?><a class="jpm-popup__image-link" href="<?=jpm_h($target)?>"<?=!empty($slide['open_new_tab'])?' target="_blank" rel="noopener noreferrer"':''?>><?php endif; ?>
              <picture>
                <source media="(max-width: 640px)" srcset="<?=jpm_h((string)$mobile['url'])?>">
                <source media="(max-width: 1024px)" srcset="<?=jpm_h((string)$tablet['url'])?>">
                <img class="jpm-popup__image" src="<?=jpm_h((string)$desktop['url'])?>" alt="<?=jpm_h($alt)?>" loading="<?=$index === 0 ? 'eager' : 'lazy'?>" decoding="async">
              </picture>
            <?php if ($target !== ''): ?></a><?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
      <?php if ($slideCount > 1): ?>
        <div class="jpm-popup__nav">
          <button type="button" class="jpm-popup__arrow" data-jpm-prev aria-label="<?=jpm_h(jpm_t('Previous slide'))?>">&larr;</button>
          <div class="jpm-popup__dots" role="group" aria-label="<?=jpm_h(jpm_t('Choose slide'))?>">
            <?php foreach ($slides as $index => $_slide): ?><button type="button" data-jpm-dot data-jpm-slide-index="<?=$index?>" aria-label="<?=jpm_h(jpm_t('Go to slide %d', $index + 1))?>" aria-current="<?=$index === 0 ? 'true' : 'false'?>"></button><?php endforeach; ?>
          </div>
          <span class="jpm-popup__status" data-jpm-status aria-live="polite"><?=jpm_h(jpm_t('%d of %d', 1, $slideCount))?></span>
          <button type="button" class="jpm-popup__arrow" data-jpm-next aria-label="<?=jpm_h(jpm_t('Next slide'))?>">&rarr;</button>
        </div>
      <?php endif; ?>
    </div>
  </section>
  <script type="application/json" data-jpm-config><?=$json?></script>
</div>
    <?php
}

function jpm_render_frontend(): void
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    $queue = jpm_runtime_queue($pdo);
    if ($queue === []) return;
    $version = rawurlencode(JPM_VERSION);
    echo '<link rel="stylesheet" href="/static/plugins/popup-manager/frontend.css?v=' . $version . '">' . PHP_EOL;
    echo '<div data-jpm-queue>' . PHP_EOL;
    foreach ($queue as $campaign) jpm_render_campaign($campaign);
    echo '</div>' . PHP_EOL;
    echo '<script src="/static/plugins/popup-manager/frontend.js?v=' . $version . '"></script>' . PHP_EOL;
}
