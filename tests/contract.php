<?php
declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$coreRoot = rtrim((string)(getenv('JPM_CORE_ROOT') ?: dirname($pluginRoot, 2) . '/jyavani.lan'), DIRECTORY_SEPARATOR);
$fixture = sys_get_temp_dir() . '/jpm-contract-' . getmypid() . '-' . bin2hex(random_bytes(4));
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

if (!is_file($coreRoot . '/plugins/index.php')) {
    fwrite(STDERR, "Canonical Core not found at {$coreRoot}. Set JPM_CORE_ROOT.\n");
    exit(1);
}

mkdir($fixture . '/cfg/var', 0775, true);
mkdir($fixture . '/public', 0775, true);
define('BACKEND_PATH', $fixture . '/cfg');
define('PUBLIC_PATH', $fixture . '/public');
require_once $coreRoot . '/cfg/helpers/hooks.php';
require_once $coreRoot . '/plugins/index.php';

$manifestRaw = file_get_contents($pluginRoot . '/plugin.json');
$manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
$check(is_array($manifest) && json_last_error() === JSON_ERROR_NONE, 'plugin.json is valid JSON');
if (is_array($manifest)) {
    $check(plugin_manifest_contract_errors($manifest) === [], 'manifest satisfies the canonical permission and route contract');
    $check(plugin_route_collision_errors($manifest) === [], 'admin routes do not collide with canonical Core routes');
    $check(plugin_requirement_errors_without_plugin_state($manifest) === [], 'runtime requirements are available');
    $check(($manifest['assets'] ?? null) === ['css' => [], 'js' => []], 'manifest assets use the canonical object shape');
    $check(!isset($manifest['dependencies']['js']), 'admin-only media dependencies are not loaded on public pages');
    $trustedPermission = null;
    foreach ($manifest['permissions'] ?? [] as $permission) {
        if (($permission['key'] ?? '') === 'plugin.popup-manager.html.trusted') $trustedPermission = $permission;
    }
    $check(is_array($trustedPermission) && ($trustedPermission['default_roles'] ?? null) === []
        && ($trustedPermission['delegable'] ?? true) === false, 'trusted embed HTML is a Site Owner-only nondelegable capability');
    $routes = array_column($manifest['admin']['pages'] ?? [], 'route');
    $check(in_array('admin/tools/popup-manager/bulk', $routes, true), 'bulk mutations have a dedicated guarded admin route');

    $allFilesExist = true;
    foreach ($manifest['admin']['pages'] ?? [] as $page) {
        $allFilesExist = $allFilesExist && is_file($pluginRoot . '/' . (string)($page['file'] ?? ''));
    }
    foreach ($manifest['static']['copy'] ?? [] as $copy) {
        $allFilesExist = $allFilesExist && is_file($pluginRoot . '/' . (string)($copy['from'] ?? ''));
    }
    $check($allFilesExist, 'all declared route and static source files exist');

    $published = plugin_static_copy($pluginRoot, $manifest['static']['copy'] ?? []);
    $check(($published['failed'] ?? 1) === 0 && ($published['copied'] ?? 0) === 4, 'canonical static publication accepts every declared asset');
}

$pluginSource = (string)file_get_contents($pluginRoot . '/plugin.php');
$indexSource = (string)file_get_contents($pluginRoot . '/admin/index.php');
$editSource = (string)file_get_contents($pluginRoot . '/admin/edit.php');
$adminScriptSource = (string)file_get_contents($pluginRoot . '/assets/js/admin.js');
$frontendStyleSource = (string)file_get_contents($pluginRoot . '/assets/css/frontend.css');
$runtimeSource = (string)file_get_contents($pluginRoot . '/includes/runtime.php');
$schemaSource = (string)file_get_contents($pluginRoot . '/includes/schema.php');
$eventSource = (string)file_get_contents($pluginRoot . '/public/event.php');
$check(str_contains($pluginSource, '/static/js/add/modal-helpers.js')
    && str_contains($pluginSource, '/static/js/add/media-selector.js'), 'media selector dependencies are scoped to plugin admin requests');
$check(str_contains($adminScriptSource, "addHandler('image'")
    && str_contains($adminScriptSource, "insertEmbed(range.index, 'image'")
    && str_contains($adminScriptSource, '/admin/modal_img/list_modal.php?embedded=1&visibility=public')
    && str_contains($adminScriptSource, "visibility !== 'private'")
    && str_contains($adminScriptSource, "storageDisk !== 'private'"), 'Quill image toolbar inserts public gallery media at the saved selection');
$check(str_contains($editSource, 'adam-quill adam-quill--auto'), 'Quill editor follows the canonical dashboard theme');
$check(str_contains($adminScriptSource, 'complexPattern')
    && str_contains($adminScriptSource, 'NewNotifConfirm.warning')
    && str_contains($adminScriptSource, "selectMode('code')")
    && str_contains($editSource, 'data-complex-confirm'), 'complex HTML requires confirmation before switching from CodeMirror to Quill');
$check(str_contains($runtimeSource, 'jpm-popup__close-icon')
    && str_contains($frontendStyleSource, '.jpm-popup__close-icon'), 'frontend close control uses a symmetric SVG icon');
$check(str_contains($indexSource, 'name="action" value="delete"'), 'delete mutations use the pre-layout Core action router');
$check(str_contains($pluginSource, "register_frontend_route('popup-manager', 'jpm_frontend_route')"), 'aggregate statistics use an explicit plugin frontend route');
$check(str_contains($runtimeSource, 'jpm_runtime_queue') && str_contains($runtimeSource, 'count($queue) >= 10'), 'runtime emits a bounded ordered campaign queue');
$check(str_contains($indexSource, 'jpm_campaign_page') && str_contains($indexSource, 'jpm-bulk-form'), 'campaign list provides bounded filters, pagination, and bulk selection');
$check(str_contains($indexSource, 'data-jpm-column-toggle') && str_contains($indexSource, 'data-jpm-action-menu'), 'campaign list provides configurable columns and scoped overflow actions');
$check(str_contains($schemaSource, 'impression_count') && str_contains($schemaSource, 'sequence_order')
    && str_contains($schemaSource, 'JPM_EVENT_TOKENS_TABLE'), 'schema owns queue and aggregate statistic storage');
$check(!preg_match('/ip_address|user_agent|visitor_id|cookie_id/i', $schemaSource), 'statistics schema stores no visitor identity, IP, cookie, or user agent');
$check(str_contains($eventSource, "Cache-Control: no-store") && str_contains($eventSource, "X-Content-Type-Options: nosniff"), 'public event response is non-cacheable and MIME hardened');

$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $target = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($target) && !is_link($target)) $remove($target);
        else @unlink($target);
    }
    @rmdir($path);
};
$remove($fixture);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " contract assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
