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
$check(str_contains($pluginSource, '/static/js/add/modal-helpers.js')
    && str_contains($pluginSource, '/static/js/add/media-selector.js'), 'media selector dependencies are scoped to plugin admin requests');
$check(str_contains($indexSource, 'name="action" value="delete"'), 'delete mutations use the pre-layout Core action router');

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
