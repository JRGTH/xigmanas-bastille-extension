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
        // // BLINDAJE ANTIMEMORIA: Prevenir carga de archivos masivos
        $filesize = filesize($file_to_read);
        $max_size = 5 * 1024 * 1024; // 5 MB límite para edición web

        header('Content-Type: text/plain; charset=utf-8');

        if ($filesize > $max_size) {
            echo "/*\n";
            echo " * BASTILLE EDITOR WARNING\n";
            echo " * ------------------------\n";
            echo " * This file is too large to safely edit in the browser.\n";
            echo " * Viewing and editing is restricted to text files under 5 MB to prevent server memory exhaustion.\n";
            echo " */";
        } else {
            echo file_get_contents($file_to_read);
        }
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
    //silence PHP errors again.
    ini_set('display_errors', 0);
    error_reporting(0);

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

// --- 1. CHUNKED UPLOAD HANDLER (The Tank Engine) ---
if (isset($_POST['ajax_upload_chunk'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');

    $target_dir = $_POST['target_dir'] ?? '';
    $rel_path = $_POST['relative_path'] ?? '';
    $chunk_idx = (int)($_POST['chunk_index'] ?? 0);

    $final_path = rtrim($target_dir, '/') . '/' . ltrim($rel_path, '/');
    $dir = dirname($final_path);

    if (!is_dir($dir)) { mkdir($dir, 0755, true); }

    $mode = ($chunk_idx === 0) ? "wb" : "ab";
    $out = fopen($final_path, $mode);
    $in = fopen($_FILES['file_chunk']['tmp_name'], "rb");

    if ($out && $in) {
        // FASTEST METHOD: Passes the write operation directly to the OS (C level)
        // This eliminates the PHP loop overhead and maximizes disk write speed.
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Disk write error']);
    }
    exit;
}

// --- 2. REMOTE DOWNLOAD HANDLER (Import via URL) ---
if (isset($_POST['ajax_remote_download'])) {
    // // Clear buffers
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');

    $url = $_POST['remote_url'] ?? '';
    $target_dir = $_POST['target_dir'] ?? '';

    if (empty($url) || empty($target_dir)) {
        echo json_encode([
            'success' => false,
            'error' => 'Missing URL or target directory'
        ]);
        exit;
    }

    // // Extract filename from URL (fallback to timestamp if missing)
    $fileName = basename(parse_url($url, PHP_URL_PATH));
    if (empty($fileName)) {
        $fileName = 'downloaded_file_' . time();
    }

    $save_to = rtrim($target_dir, '/') . '/' . $fileName;

    // // Open file for writing
    $fp = fopen($save_to, 'w+');
    if (!$fp) {
        echo json_encode([
            'success' => false,
            'error' => 'Cannot open file for writing on server'
        ]);
        exit;
    }

    // // Use cURL to download directly to the server's disk
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_FAILONERROR, true); // // Throw error if 404

    $success = curl_exec($ch);
    $error = curl_error($ch);

    curl_close($ch);
    fclose($fp);

    if ($success) {
        echo json_encode([
            'success' => true,
            'fileName' => $fileName
        ]);
    } else {
        // // Clean up the empty/broken file if download failed
        unlink($save_to);
        echo json_encode([
            'success' => false,
            'error' => 'Download failed: ' . $error
        ]);
    }
    exit;
}

// --- GET FILE INFO HANDLER (Cleaned & Optimized) ---
if (isset($_POST['ajax_get_info'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');

    $target_path = $_POST['target_path'] ?? '';

    // 1. Security Check
    if (empty($target_path) || !file_exists($target_path)) {
        echo json_encode(['success' => false, 'error' => 'Item not found']);
        exit;
    }

    // 2. Fetch basic stats
    $stat = stat($target_path);
    $is_dir = is_dir($target_path);

    // 3. Calculate size (Uses 'du' for directories, stat for files)
    if ($is_dir) {
        $io = popen("du -sk " . escapeshellarg($target_path) . " 2>/dev/null", 'r');
        $line = fgets($io, 256);
        pclose($io);

        $size_kb = (int)strtok($line, "\t ");
        $bytes = $size_kb * 1024;
        $formatted_size = formatBytes($bytes);
    } else {
        $bytes = $stat['size'];
        $formatted_size = formatBytes($bytes);
    }

    // 4. ApexCharts Calculation (Volume Impact)
    $disk_total = disk_total_space(dirname($target_path));
    $usage_percent = ($disk_total > 0) ? round(($bytes / $disk_total) * 100, 2) : 0;

    // Ensure at least 0.1% is visible if the file is not empty
    if ($usage_percent < 0.1 && $bytes > 0) $usage_percent = 0.1;

    // 5. User & Permissions (POSIX)
    $owner_info = function_exists('posix_getpwuid') ? posix_getpwuid($stat['uid']) : ['name' => $stat['uid']];
    $group_info = function_exists('posix_getgrgid') ? posix_getgrgid($stat['gid']) : ['name' => $stat['gid']];

    $perms = $stat['mode'];
    $octal = substr(sprintf('%o', $perms), -4);
    $symbolic = formatPermissions($perms);

    $info = [
        'success'    => true,
        'name'       => basename($target_path),
        'path'       => $target_path,
        'is_dir'     => $is_dir,
        'type'       => $is_dir ? 'Folder' : 'File (' . strtoupper(pathinfo($target_path, PATHINFO_EXTENSION)) . ')',
        'size'       => $formatted_size,
        'bytes'      => $bytes,
        'modified'   => date("Y-m-d H:i:s", $stat['mtime']),
        'permissions' => $symbolic,
        'octal'      => $octal,
        'owner'      => $owner_info['name'] ?? $stat['uid'],
        'group'      => $group_info['name'] ?? $stat['gid'],
        'chart'      => [
            'usage'  => $usage_percent,
            'others' => 100 - $usage_percent
        ]
    ];

    echo json_encode($info);
    exit;
}

/**
 * Convert bytes into readable format (KB, MB, GB...)
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Convert the permissions bitmask to a symbolic string (drwxr-xr-x)
 */
function formatPermissions($perms) {
    if (($perms & 0xC000) == 0xC000) $info = 's';
    elseif (($perms & 0xA000) == 0xA000) $info = 'l';
    elseif (($perms & 0x8000) == 0x8000) $info = '-';
    elseif (($perms & 0x6000) == 0x6000) $info = 'b';
    elseif (($perms & 0x4000) == 0x4000) $info = 'd';
    elseif (($perms & 0x2000) == 0x2000) $info = 'c';
    elseif (($perms & 0x1000) == 0x1000) $info = 'p';
    else $info = 'u';

    // Owner
    $info .= (($perms & 0x0100) ? 'r' : '-');
    $info .= (($perms & 0x0080) ? 'w' : '-');
    $info .= (($perms & 0x0040) ? (($perms & 0x0800) ? 's' : 'x' ) : (($perms & 0x0800) ? 'S' : '-'));

    // Group
    $info .= (($perms & 0x0020) ? 'r' : '-');
    $info .= (($perms & 0x0010) ? 'w' : '-');
    $info .= (($perms & 0x0008) ? (($perms & 0x0400) ? 's' : 'x' ) : (($perms & 0x0400) ? 'S' : '-'));

    // World
    $info .= (($perms & 0x0004) ? 'r' : '-');
    $info .= (($perms & 0x0002) ? 'w' : '-');
    $info .= (($perms & 0x0001) ? (($perms & 0x0200) ? 't' : 'x' ) : (($perms & 0x0200) ? 'T' : '-'));

    return $info;
}

// This is the one that receives the "signal" with the hash calculated by the JS at the beginning.
// --- 2. HASH VERIFICATION (The Auditor) ---
if (isset($_POST['ajax_verify_hash'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');

    $target_dir = $_POST['target_dir'] ?? '';
    $rel_path = $_POST['relative_path'] ?? '';
    $expected_hash = $_POST['expected_hash'] ?? ''; // // La señal del JS

    $final_path = rtrim($target_dir, '/') . '/' . ltrim($rel_path, '/');

    if (!file_exists($final_path)) {
        echo json_encode(['success' => false, 'error' => 'File not found on server']);
        exit;
    }

    // We calculate the SHA-256 of the complete file we just assembled.
    $server_hash = hash_file('sha256', $final_path);

    if ($server_hash === $expected_hash) {
        echo json_encode(['success' => true]);
    } else {
        // unlink($final_path);
        echo json_encode([
            'success' => false,
            'error' => 'Integrity fail',
            'debug' => ['server' => $server_hash, 'client' => $expected_hash]
        ]);
    }
    exit;
}

// --- GET BACKUPS LIST (DIFF VIEWER) ---
if (isset($_POST['ajax_get_backups'])) {
    // 1. Absolute cleanup of output buffers and error reporting to ensure clean JSON
    while (ob_get_level() > 0) ob_end_clean();
    ini_set('display_errors', 0);
    error_reporting(0);
    header('Content-Type: application/json; charset=utf-8');

    $filepath = $_POST['filepath'] ?? '';

    // Validate that the target file exists
    if (empty($filepath) || !file_exists($filepath)) {
        echo json_encode(['success' => false, 'error' => 'File not found on server.']);
        exit;
    }

    $file_dir = dirname($filepath);
    $filename = basename($filepath);
    $backup_dir = $file_dir . '/.backups/' . $filename;

    $backups = [];
    if (is_dir($backup_dir)) {
        $files = glob($backup_dir . '/*.bak');
        if ($files) {
            rsort($files); // Sort by newest first

            foreach ($files as $f) {
                $base = basename($f, '.bak');
                // Format readable date: 20231024_153022 -> 2023-10-24 15:30:22
                $date_obj = DateTime::createFromFormat('Ymd_His', $base);
                $readable_date = $date_obj ? $date_obj->format('Y-m-d H:i:s') : $base;

                $backups[] = [
                    'path' => $f,
                    'name' => $base,
                    'date' => $readable_date
                ];
            }
        }
    }

    echo json_encode(['success' => true, 'backups' => $backups]);
    exit; // <--- CRITICAL: Prevents XigmaNAS from appending the <!DOCTYPE HTML footer
}

// --- READ SPECIFIC BACKUP CONTENT ---
if (isset($_POST['ajax_read_backup'])) {
    while (ob_get_level() > 0) ob_end_clean();
    ini_set('display_errors', 0);
    error_reporting(0);
    header('Content-Type: application/json; charset=utf-8');

    $bak_path = $_POST['bak_path'] ?? '';

    // Security check: Verify file exists and is a .bak inside a .backups folder
    if (file_exists($bak_path) && strpos($bak_path, '.backups') !== false && strpos($bak_path, '.bak') !== false) {
        $content = file_get_contents($bak_path);
        echo json_encode(['success' => true, 'content' => $content]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Backup file not accessible or invalid path.']);
    }
    exit; // <--- MANDATORY
}

// --- FILE DOWNLOAD SYSTEM ---
if (isset($_GET['ajax_download_file']) || isset($_GET['ajax_download_zip']) || isset($_GET['ajax_download_prepared'])) {
    // Prevent session locking so the UI remains responsive
    session_write_close();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ini_set('display_errors', 0);

    $filepath = $_GET['filepath'] ?? '';
    $real_path = realpath($filepath);
    $root_path = realpath($jail_root);

    // Security check
    if (($real_path && strpos($real_path, $root_path) === 0) || isset($_GET['ajax_download_prepared'])) {

        // --- CASE 1: ZIP COMPRESSION (RETURNS JSON) ---
        if (isset($_GET['ajax_download_zip'])) {
            header('Content-Type: application/json');
            $zip = new ZipArchive();
            $tmp_id = 'bastille_zip_' . bin2hex(random_bytes(4));
            $tmp_file = sys_get_temp_dir() . '/' . $tmp_id;

            if ($zip->open($tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                $filename = basename($real_path);

                // Instantiate a new Fiber
                $zipperFiber = new Fiber(function(string $path, ZipArchive $archive, string $baseName) {
                    if (is_dir($path)) {
                        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                        $counter = 0;
                        foreach ($files as $file) {
                            $fPath = $file->getRealPath();
                            $rel = substr($fPath, strlen($path) + 1);
                            is_dir($fPath) ? $archive->addEmptyDir($rel) : $archive->addFile($fPath, $rel);
                            if (++$counter % 50 === 0) {
                                Fiber::suspend();
                            }
                        }
                    } else {
                        $archive->addFile($path, $baseName);
                    }
                });

                $zipperFiber->start($real_path, $zip, $filename);
                while (!$zipperFiber->isTerminated()) { $zipperFiber->resume(); }
                $zip->close();

                // Here's where the fiber ends, and we respond
                echo json_encode([
                    'success' => true,
                    'tmp_file' => $tmp_id,
                    'filename' => $filename . '.zip'
                ]);
                exit;
            }
        }

        // --- CASE 2: DELIVERY (PREPARED OR DIRECT) ---
        $delivery_file = "";
        $download_name = "";

        if (isset($_GET['ajax_download_prepared'])) {
            $delivery_file = sys_get_temp_dir() . '/' . basename($_GET['ajax_download_prepared']);
            $download_name = basename($_GET['filename']);
        } else {
            $delivery_file = $real_path;
            $download_name = basename($real_path);
        }

        if (file_exists($delivery_file)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $download_name . '"');
            header('Content-Length: ' . filesize($delivery_file));
            header('X-Lighttpd-send-file: ' . $delivery_file);
            exit;
        }
    }
    http_response_code(403);
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