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
                echo json_encode([
                    'success' => true
                ]);
                exit;
            }
        } else {
            if ($is_ajax_delete) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Permission denied. System executor could not remove the item.'
                ]);
                exit;
            }
        }
    } else {
        if ($is_ajax_delete) {
            echo json_encode([
                'success' => false,
                'error' => 'Invalid path or item not found.'
            ]);
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
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to write to file: ' . $filename
                ]);
                exit;
            } else {
                $input_errors[] = sprintf('%s %s', gtext('Failed to write to file:'), $filepath);
            }
        }
    } else {
        if (isset($is_ajax) && $is_ajax) {
            echo json_encode([
                'success' => false,
                'error' => 'Invalid file path.'
            ]);
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

    // Removed '-type f' so it finds both files and directories
    $commands['base_jail'] = "find " . escapeshellarg($jail_root) . " -maxdepth 1 -iname {$search_term} 2>/dev/null";

    $dirs = glob($jail_root . '/*', GLOB_ONLYDIR);
    if (!empty($dirs)) {
        foreach ($dirs as $dir) {
            $base = basename($dir);
            if ($base === '.backups') continue;
            // Removed '-type f' here too
            $commands[$base] = "find " . escapeshellarg($dir) . " -iname {$search_term} 2>/dev/null | head -n 20";
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

            // Detect if the item is a folder or a file
            $is_folder = is_dir($line);

            $rel_path = str_replace($jail_root, "", dirname($line));
            $display_path = ($rel_path === "" || $rel_path === false) ? "/" : $rel_path;

            $final_items[] = [
                'full'      => $line,
                'directory' => dirname($line),
                'relative'  => $icon_folder . " " . $display_path,
                'name'      => basename($line),
                'type'      => $is_folder ? 'folder' : 'file' // Inject the type into JSON
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
    //ini_set('display_errors', 0); error_reporting(0);

    $dir_path = $_GET['ajax_get_dir'];
    $real_path = realpath($dir_path);
    $root_path = realpath($jail_root);

    if ($real_path === false || strpos($real_path, $root_path) !== 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Access denied',
            'debug' => "Path: $real_path | Root: $root_path"
        ]);
        exit;
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

// --- CREATE NEW FILE OR FOLDER ---
if (isset($_POST['create_item'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');

    $parent_dir = $_POST['parent_dir'] ?? '';
    $new_name = trim($_POST['new_name'] ?? '');
    $type = $_POST['type'] ?? 'file'; // 'file' or 'folder'

    $full_path = rtrim($parent_dir, '/') . '/' . $new_name;

    // Security check
    if (strpos($full_path, $jail_root) !== 0 || strpos($new_name, '..') !== false) {
        echo json_encode(['success' => false, 'error' => 'Access denied']); exit;
    }

    conf_mount_rw();
    $success = ($type === 'folder') ? @mkdir($full_path, 0755) : @touch($full_path);
    conf_mount_ro();

    echo json_encode([
        'success' => $success,
        'error' => $success
            ? ''
            : 'Could not create item. Check permissions.'
    ]);
    exit;
}

// --- UNLOCK FILES & FOLDERS (Clear FreeBSD Flags) ---
if (isset($_POST['ajax_unlock'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $filepath = $_POST['filepath'] ?? '';
    $filepath = trim($filepath);

    if (!empty($filepath) && file_exists($filepath) && strpos($filepath, $jail_root) === 0) {
        conf_mount_rw();
        $cmd = "chflags -R 0 " . escapeshellarg($filepath);
        $executor = new BastilleManagerMwExecParallel([
            'unlock' => $cmd
        ]);
        $executor->executeWithSelect();
        conf_mount_ro();
        clearstatcache(true, $filepath);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid path for unlocking.'
        ]);
    }
    exit;
}

// --- FILE UPLOAD HANDLER ---
if (isset($_POST['ajax_upload'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');

    // DEBUG: Vamos a ver si PHP recibió los archivos o si son demasiado grandes
    if (empty($_FILES) && empty($_POST)) {
        echo json_encode([
            'success' => false,
            'error' => 'Server received nothing. Maybe file is too big (check post_max_size in PHP.ini)'
        ]);
        exit;
    }

    $target = $_POST['target_dir'] ?? $jail_root;
    $real_target = realpath($target);

    if ($real_target === false || strpos($real_target, realpath($jail_root)) !== 0) {
        echo json_encode([
            'success' => false,
             'error' => 'Access denied to ' . $target
        ]);
        exit;
    }

    conf_mount_rw();
    $count = 0;
    $errors = [];

    if (!empty($_FILES['files'])) {
        foreach ($_FILES['files']['name'] as $k => $name) {
            $error_code = $_FILES['files']['error'][$k];
            if ($error_code === UPLOAD_ERR_OK) {
                $tmp = $_FILES['files']['tmp_name'][$k];
                $save_to = $real_target . '/' . basename($name);
                if (move_uploaded_file($tmp, $save_to)) {
                    $count++;
                } else {
                    $errors[] = "Failed to move: " . $name;
                }
            } else {
                $errors[] = "PHP Upload Error Code: " . $error_code . " for file " . $name;
            }
        }
    }

    conf_mount_ro();

    echo json_encode([
        'success' => $count > 0,
        'count' => $count,
        'errors' => $errors,
        'received_target' => $target
    ]);
    exit;
}

// --- CREATE ITEM (File/Folder) ---
if (isset($_POST['ajax_create_item'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');

    $parent = $_POST['parent_dir'] ?? '';
    $name = basename($_POST['new_name'] ?? '');
    $type = $_POST['type'] ?? 'file';
    $full_path = rtrim($parent, '/') . '/' . $name;

    // Basic safety measures to keep us from leaving the cage
    if (empty($name) || strpos(realpath($parent), realpath($jail_root)) !== 0) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid path or name'
        ]);
        exit;
    }

    // Check if it already exists
    if (file_exists($full_path)) {
        echo json_encode([
            'success' => false,
            'error' => 'An item with that name already exists.'
        ]);
        exit;
    }

    conf_mount_rw();
    $success = ($type === 'folder') ? @mkdir($full_path, 0755) : @touch($full_path);
    conf_mount_ro();

    echo json_encode([
        'success' => $success,
        'error' => $success
        ? ''
        : 'Could not create item: System error (Check permissions)'
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