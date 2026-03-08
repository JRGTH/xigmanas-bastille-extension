<?php
// bastille_manager_edit_api.inc

// FILE CONTENT LOAD (SPA)
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

// DELETE FILES & FOLDERS
if (isset($_POST['delete_file'])) {
    $is_ajax_delete = isset($_POST['ajax_delete']) && $_POST['ajax_delete'] === '1';

    if ($is_ajax_delete) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
    }

    $filepath = $_POST['filepath'] ?? '';
    $filepath = trim($filepath);

    if (!empty($filepath) && file_exists($filepath) && strpos($filepath, '..') === false) {

        conf_mount_rw();

        $delete_success = is_dir($filepath) ? rrmdir($filepath) : @unlink($filepath);

        if (!$delete_success) {
            $cmd = (is_dir($filepath) ? "rm -rf " : "rm -f ") . escapeshellarg($filepath);

            $executor = new BastilleManagerMwExecParallel([
                'force_delete' => $cmd
            ]);
            $executor->executeWithSelect();

            clearstatcache(true, $filepath);
            $delete_success = !file_exists($filepath);
        }

        conf_mount_ro();

        if ($delete_success) {
            if ($is_ajax_delete) {
                echo json_encode(['success' => true]);
                exit;
            }
        } else {
            if ($is_ajax_delete) {
                echo json_encode(['success' => false, 'error' => 'Permission denied. System executor could not remove the item.']);
                exit;
            }
        }
    } else {
        if ($is_ajax_delete) {
            echo json_encode(['success' => false, 'error' => 'Invalid path or item not found.']);
            exit;
        }
    }
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
            exit;
        } else {
            $input_errors[] = gtext('Invalid file path.');
        }
    }
}

// PARALLEL RECURSIVE SEARCH ENGINE
if (isset($_GET['ajax_search'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $filter = $_GET['ajax_search'];
    if (strlen($filter) < 2) {
        echo json_encode(['items' => []]);
        exit;
    }

    $search_term = escapeshellarg("*" . $filter . "*");
    $commands = [];

    $commands['base_jail'] = "find " . escapeshellarg($jail_root) . " -maxdepth 1 -type f -iname {$search_term} 2>/dev/null";

    $dirs = glob($jail_root . '/*', GLOB_ONLYDIR);
    if (!empty($dirs)) {
        foreach ($dirs as $dir) {
            $base = basename($dir);
            if ($base === '.backups') continue;

            $commands[$base] = "find " . escapeshellarg($dir) . " -type f -iname {$search_term} 2>/dev/null | head -n 20";
        }
    }

    $searcher = new BastilleManagerMwExecParallel($commands);
    $raw_results = $searcher->executeWithSelect();

    $final_items = [];

    foreach ($raw_results as $source => $output) {
        $stdout = trim($output['stdout'] ?? '');
        if (empty($stdout)) continue;

        $lines = explode("\n", $stdout);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || !file_exists($line)) continue;

            $rel_path = str_replace($jail_root, "", dirname($line));
            $display_path = ($rel_path === "" || $rel_path === false) ? "/" : $rel_path;

            $final_items[] = [
                'full'      => $line,
                'directory' => dirname($line),
                'relative'  => $icon_folder . " " . $display_path,
                'name'      => basename($line)
            ];
        }
    }

    usort($final_items, function ($a, $b) use ($filter) {
        if (strcasecmp($a['name'], $filter) === 0) return -1;
        if (strcasecmp($b['name'], $filter) === 0) return 1;
        return strlen($a['name']) - strlen($b['name']);
    });

    echo json_encode([
        'items' => array_slice($final_items, 0, 40),
        'perf'  => $searcher->getMs()
    ]);
    exit;
}

// LAZY LOADING
if (isset($_GET['ajax_get_dir'])) {
    while (ob_get_level() > 0) ob_end_clean();
    ini_set('display_errors', 0); error_reporting(0);

    $dir_path = $_GET['ajax_get_dir'];
    $real_path = realpath($dir_path);

    // Security check to ensure we are inside the jail
    if ($real_path === false || strpos($real_path, $jail_root) !== 0) {
        echo json_encode(['error' => 'Access denied']); exit;
    }

    $file_flags = freeBsdFileFlags($real_path);
    $items = @scandir($real_path);

    $folders = [];
    $files = [];

    if ($items) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.backups') {
                continue;
            }

            $full = $real_path . '/' . $item;

            $clean_name = trim($item);
            $hasFlag = $file_flags[$clean_name] ?? null;

            clearstatcache(true, $full);

            $itemData = [
                'name' => $clean_name,
                'flag' => $hasFlag
            ];

            if (is_link($full)) {
                continue;
            }

            if (is_dir($full)) {
                $folders[] = $itemData;
            } else {
                $files[] = $itemData;
            }
        }
    }

    natcasesort($folders);
    natcasesort($files);

    header('Content-Type: application/json');
    echo json_encode([
        'folders' => array_values($folders),
        'files' => array_values($files),
        'parent' => $real_path
    ]);
    exit;
}

function rrmdir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . DIRECTORY_SEPARATOR . $object) && !is_link($dir . "/" . $object)) {
                    rrmdir($dir . DIRECTORY_SEPARATOR . $object);
                } else {
                    unlink($dir . DIRECTORY_SEPARATOR . $object);
                }
            }
        }
        return rmdir($dir);
    }
    return false;
}

?>