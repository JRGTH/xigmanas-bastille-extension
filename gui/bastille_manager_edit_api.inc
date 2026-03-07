<?php
// bastille_manager_edit_api.inc

// 1. FILE CONTENT LOAD (SPA)
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    session_write_close();
    $file_to_read = $_GET['filepath'] ?? '';

    if (!empty($file_to_read) && file_exists($file_to_read)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo file_get_contents($file_to_read);
    } else {
        header("HTTP/1.1 404 Not Found");
        echo "Error: File not found.";
    }
    exit;
}

// STORAGE AND BACKUPS
if ($_POST && isset($_POST['save'])) {
    $is_ajax = isset($_POST['ajax_save']) && $_POST['ajax_save'] === '1';

    if ($is_ajax) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
    }

    if (!empty($filepath) && is_file($filepath)) {
        conf_mount_rw();
        $new_content = preg_replace("/\r/", "", $_POST['file_content']);
        $file_dir = dirname($filepath);
        $filename = basename($filepath);
        $backup_dir = $file_dir . '/.backups/' . $filename;

        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        $timestamp = date('Ymd_His');
        $backup_path = $backup_dir . '/' . $timestamp . '.bak';
        @copy($filepath, $backup_path);

        $save_success = file_put_contents($filepath, $new_content) !== false;

        if ($save_success) {
            global $g;
            if (isset($g['cf_conf_path']) && $filepath === "{$g['cf_conf_path']}/config.xml") {
                unlink_if_exists("{$g['tmp_path']}/config.cache");
            }
        }

        conf_mount_ro();

        if ($save_success) {
            if ($is_ajax) {
                echo json_encode(['success' => true]);
                exit; //
            } else {
                $savemsg = sprintf('%s %s', gtext('Saved file to'), $filepath);
            }
        } else {
            if ($is_ajax) {
                echo json_encode(['success' => false, 'error' => 'Failed to write to file: ' . $filename]);
                exit;
            } else {
                $input_errors[] = sprintf('%s %s', gtext('Failed to write to file:'), $filepath);
            }
        }
    } else {
        if (isset($is_ajax) && $is_ajax) {
            echo json_encode(['success' => false, 'error' => 'Invalid file path.']);
            exit; // ¡EL HACHAZO!
        } else {
            $input_errors[] = gtext('Invalid file path.');
        }
    }
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