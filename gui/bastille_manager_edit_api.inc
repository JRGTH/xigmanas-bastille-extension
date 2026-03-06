<?php
// bastille_manager_edit_api.inc

// 1. FILE CONTENT LOAD (SPA)
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    ob_clean();
    if (is_file($filepath)) {
        echo file_get_contents($filepath);
    } else {
        http_response_code(404);
        echo "Error: File not found.";
    }
    exit;
}

// 2. PARALLEL RECURSIVE SEARCH ENGINE
if (isset($_GET['ajax_search'])) {
    while (ob_get_level() > 0) ob_end_clean();
    ini_set('display_errors', 0); error_reporting(0);

    $filter = $_GET['ajax_search'];
    $search_term = escapeshellarg("*" . $filter . "*");
    $search_path = $jail_root;
    $dirs = glob($search_path . '/*', GLOB_ONLYDIR);
    $commands = [];

    if (!empty($dirs)) {
        foreach ($dirs as $dir) {
            $commands[basename($dir)] = "find " . escapeshellarg($dir) . " -type f -iname $search_term 2>/dev/null | head -n 20";
        }
    }
    $commands['jail_root'] = "find " . escapeshellarg($search_path) . " -maxdepth 1 -type f -iname $search_term 2>/dev/null";

    $searcher = new BastilleManagerMwExecParallel($commands);
    $raw_results = $searcher->executeWithSelect();
    $final_items = [];

    foreach ($raw_results as $source => $output) {
        $lines = explode("\n", trim($output['stdout']));
        foreach ($lines as $line) {
            if (empty($line)) continue;
            $final_items[] = [
                'full'      => $line,
                'directory' => dirname($line),
                'relative'  => $icon_folder . " " . str_replace($jail_root, "", dirname($line)),
                'name'      => basename($line),
                'source'    => $source
            ];
        }
    }

    usort($final_items, function ($a, $b) use ($filter) {
        $exactA = (strcasecmp($a['name'], $filter) === 0);
        $exactB = (strcasecmp($b['name'], $filter) === 0);
        if ($exactA && !$exactB) return -1;
        if (!$exactA && $exactB) return 1;
        return strlen($a['name']) - strlen($b['name']);
    });

    header('Content-Type: application/json');
    echo json_encode(['items' => array_slice($final_items, 0, 50), 'perf'  => $searcher->getMs()]);
    exit;
}

// 3. LAZY LOADING
if (isset($_GET['ajax_get_dir'])) {
    while (ob_get_level() > 0) ob_end_clean();
    ini_set('display_errors', 0); error_reporting(0);

    $dir_path = $_GET['ajax_get_dir'];
    $real_path = realpath($dir_path);

    if ($real_path === false || strpos($real_path, $jail_root) !== 0) {
        echo json_encode(['error' => 'Access denied']); exit;
    }

    $items = @scandir($real_path);
    $folders = []; $files = [];

    if ($items) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.backups') continue;
            $full = $real_path . '/' . $item;
            clearstatcache(true, $full);
            if (is_link($full)) continue;
            if (is_dir($full)) $folders[] = $item; else $files[] = $item;
        }
    }
    natcasesort($folders); natcasesort($files);

    header('Content-Type: application/json');
    echo json_encode(['folders' => array_values($folders), 'files' => array_values($files), 'parent' => $real_path]);
    exit;
}
?>