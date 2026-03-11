<?php

require_once 'auth.inc';
require_once 'guiconfig.inc';
require_once 'bastille_manager-lib.inc';
require_once 'BastilleManagerMwExecParallel.php';

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
if (isset($_GET['ajax'])
    || isset($_GET['ajax_search'])
    || isset($_GET['ajax_get_dir'])
    || isset($_POST['ajax_save'])
    || isset($_POST['ajax_delete'])
    || isset($_POST['ajax_unlock'])
    || isset($_POST['ajax_upload'])
    || isset($_POST['ajax_create_item'])
    || isset($_POST['ajax_upload_chunk'])
    || isset($_POST['ajax_remote_download'])
    || isset($_POST['ajax_get_info'])
    || isset($_POST['ajax_verify_hash'])
    || isset($_POST['ajax_read_backup'])
    || isset($_POST['ajax_get_backups'])
    || isset($_GET['ajax_download_file'])
    || isset($_GET['ajax_download_zip'])
    ) {
    include 'bastille_manager_edit_api.inc';
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
