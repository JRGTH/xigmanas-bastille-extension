<?php

require_once 'auth.inc';
require_once 'guiconfig.inc';
require_once 'bastille_manager-lib.inc';
require_once 'bastille_manager_MwExecParallel.php';

$jailname = $_GET['jailname'] ?? $_POST['jailname'] ?? '';

if (empty($jailname)) {
    header("Location: bastille_manager_gui.php");
    exit;
}

$jail_root = realpath("{$jail_dir}/{$jailname}");
$current_dir = $_GET['dir'] ?? $_POST['dir'] ?? $jail_root;
$filepath = $_GET['filepath'] ?? $_POST['filepath'] ?? '';

$img_path   = "ext/bastille/images";
$icon_folder = "<img src='{$img_path}/folder.svg' class='tree-icon' width='16' height='16'/>";
$icon_file   = "<img src='{$img_path}/file.svg' class='tree-icon' width='16' height='16'/>";
$icon_up     = "<img src='{$img_path}/up.svg' class='tree-icon' width='16' height='16'/>";
$icon_toggle = "<img src='{$img_path}/sidebar-toggle.svg' width='18' height='18' alt='Toggle'>";

// FIXME Directory Traversal ?
$real_current_dir = realpath($current_dir);
if ($real_current_dir === false || strpos($real_current_dir, $jail_root) !== 0) {
    $current_dir = $jail_root;
    $real_current_dir = $jail_root;
}

// ROUTER AJAX (API) ---
// If there is an asynchronous request, enter here and stop the execution of the rest.
if (isset($_GET['ajax']) || isset($_GET['ajax_search']) || isset($_GET['ajax_get_dir'])) {
    include 'bastille_manager_edit_api.inc';
}

// STORAGE AND BACKUPS
if ($_POST && isset($_POST['save'])) {
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
        if (file_put_contents($filepath, $new_content) !== false) {
            $savemsg = sprintf('%s %s', gtext('Saved file to'), $filepath);
            global $g;
            if (isset($g['cf_conf_path']) && $filepath === "{$g['cf_conf_path']}/config.xml") {
                unlink_if_exists("{$g['tmp_path']}/config.cache");
            }
        } else {
            $input_errors[] = sprintf('%s %s', gtext('Failed to write to file:'), $filepath);
        }
        conf_mount_ro();
    } else {
        $input_errors[] = gtext('Invalid file path.');
    }
}

// --- INITIAL STATUS LOAD ---
$content = "";
if (!empty($filepath) && file_exists($filepath) && is_file($filepath)) {
    $content = file_get_contents($filepath);
}

$items = @scandir($real_current_dir);
$folders = [];
$files = [];

if ($items !== false) {
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '.backups') {
            continue;
        }
        $full_path = $real_current_dir . '/' . $item;
        clearstatcache(true, $full_path);
        if (is_link($full_path)) {
            continue;
        }
        if (is_dir($full_path)) {
            $folders[] = $item;
        } else {
            $files[] = $item;
        }
    }
}
natcasesort($folders);
natcasesort($files);

// --- VIEW RENDERING ---
$pgtitle = [gtext("Extensions"), gtext('Bastille'), gtext('File Editor v2'), $jailname];
include 'fbegin.inc';

// Inject the HTML of the view
include 'bastille_manager_edit_view.inc';

include 'fend.inc';
