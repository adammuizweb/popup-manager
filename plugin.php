<?php
declare(strict_types=1);

if (!defined('BACKEND_PATH')) return;

const JPM_VERSION = '1.0.0';
const JPM_SCHEMA_VERSION = '1';
const JPM_CAMPAIGNS_TABLE = 'jpm_popup_campaigns';
const JPM_PERMISSION = 'plugin.popup-manager.campaigns.manage';

function jpm_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function jpm_t(string $source, mixed ...$args): string
{
    $translated = function_exists('__') ? __($source) : $source;
    if ($translated === $source) {
        $locale = function_exists('get_locale') ? strtolower((string)get_locale()) : 'en';
        $locale = explode('-', str_replace('_', '-', $locale), 2)[0];
        static $catalogs = [];
        if (!isset($catalogs[$locale])) {
            $file = __DIR__ . '/languages/' . $locale . '.php';
            $catalog = is_file($file) ? require $file : [];
            $catalogs[$locale] = is_array($catalog) ? $catalog : [];
        }
        $translated = (string)($catalogs[$locale][$source] ?? $source);
    }
    return $args === [] ? $translated : sprintf($translated, ...$args);
}

require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/targeting.php';
require_once __DIR__ . '/includes/repository.php';
require_once __DIR__ . '/includes/runtime.php';

function jpm_is_admin_request(): bool
{
    $page = trim((string)($_GET['page'] ?? ''), '/');
    return $page === 'admin/tools/popup-manager' || str_starts_with($page, 'admin/tools/popup-manager/');
}

function jpm_admin_bootstrap(): void
{
    if (!jpm_is_admin_request()) return;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO || !function_exists('current_user_can') || !current_user_can($pdo, JPM_PERMISSION)) return;
    jpm_ensure_schema($pdo);
}

function jpm_admin_assets(): void
{
    if (!jpm_is_admin_request()) return;
    $version = rawurlencode(JPM_VERSION);
    echo '<link rel="stylesheet" href="/static/plugins/popup-manager/admin.css?v=' . $version . '">' . PHP_EOL;
    echo '<script src="/static/js/add/modal-helpers.js" defer></script>' . PHP_EOL;
    echo '<script src="/static/js/add/media-selector.js" defer></script>' . PHP_EOL;
    echo '<script src="/static/plugins/popup-manager/admin.js?v=' . $version . '" defer></script>' . PHP_EOL;
}

function jpm_capture_router_path(string $path): string
{
    $normalized = jpm_normalize_path('/' . ltrim($path, '/'));
    if ($normalized !== null) $GLOBALS['jpm_request_path'] = $normalized;
    return $path;
}

function jpm_capture_layout_slot(string $html, string $slot, array $context = []): string
{
    if ($slot === 'main.homepage') $GLOBALS['jpm_is_homepage'] = true;
    return $html;
}

function jpm_uninstall(string $name): void
{
    if ($name !== 'popup-manager') return;
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) jpm_drop_schema($pdo);
}

add_action('admin_init', 'jpm_admin_bootstrap');
add_action('admin_head', 'jpm_admin_assets');
add_action('jy_footer', 'jpm_render_frontend');
add_action('plugin_uninstall', 'jpm_uninstall');
add_filter('router_path', 'jpm_capture_router_path', PHP_INT_MAX);
add_filter('layout_slot_html', 'jpm_capture_layout_slot', PHP_INT_MAX);
