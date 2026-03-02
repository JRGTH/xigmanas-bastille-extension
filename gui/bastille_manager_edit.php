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

// FIXME "Directory Traversal" ?
$real_current_dir = realpath($current_dir);
if ($real_current_dir === false || strpos($real_current_dir, $jail_root) !== 0) {
    $current_dir = $jail_root;
    $real_current_dir = $jail_root;
}

// Backup and saved, It must be fixed so that it saves correctly.
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

$content = "";
if (!empty($filepath) && file_exists($filepath) && is_file($filepath)) {
    $content = file_get_contents($filepath);
}

$items = @scandir($real_current_dir);
$folders = [];
$files = [];

if ($items !== false) {
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if ($item === '.backups') {
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

// Search engine
if (isset($_GET['ajax_search'])) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    // Fixme
    ini_set('display_errors', 0);
    error_reporting(0);

    $filter = $_GET['ajax_search'];
    $search_term = escapeshellarg("*" . $filter . "*");

    // Search from root
    $search_path = $jail_root;

    // We distribute the work across the main system folders (bin, etc, usr, var...)
    $dirs = glob($search_path . '/*', GLOB_ONLYDIR);
    $commands = [];

    if (!empty($dirs)) {
        foreach ($dirs as $dir) {
            // Remove -maxdepth to search recursively to the bottom
            $commands[basename($dir)] = "find " . escapeshellarg($dir) . " -type f -iname $search_term 2>/dev/null | head -n 20";
        }
    }

    // This command searches for loose files in the jail root
    $commands['jail_root'] = "find " . escapeshellarg($search_path) . " -maxdepth 1 -type f -iname $search_term 2>/dev/null";

    // MwExecParallel using executeWithSelect
    $searcher = new bastille_manager_MwExecParallel($commands);
    $raw_results = $searcher -> executeWithSelect();

    $final_items = [];
    foreach ($raw_results as $source => $output) {
        $lines = explode("\n", trim($output['stdout']));
        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }
            $final_items[] = [
                'full'     => $line,
                'directory' => dirname($line),
                //FIXME to svg ${icon_folder}
                'relative'  => $icon_folder . " " . str_replace($jail_root, "", dirname($line)),
                'name'     => basename($line),
                'source'   => $source
            ];
        }
    }

    usort($final_items, function ($a, $b) use ($filter) {
        // If the name is exactly equal to the filter, it has maximum priority
        $exactA = (strcasecmp($a['name'], $filter) === 0);
        $exactB = (strcasecmp($b['name'], $filter) === 0);

        if ($exactA && !$exactB) {
            return -1;
        }
        if (!$exactA && $exactB) {
            return 1;
        }

        // If neither is exact, sort by name length (shorter is usually more relevant)
        return strlen($a['name']) - strlen($b['name']);
    });

    header('Content-Type: application/json');
    echo json_encode([
        'items' => array_slice($final_items, 0, 50),
        'perf'  => $searcher->getMs()
    ]);
    exit;
}

// (Lazy Loading) with ajax
if (isset($_GET['ajax_get_dir'])) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ini_set('display_errors', 0);
    error_reporting(0);

    $dir_path = $_GET['ajax_get_dir'];
    $real_path = realpath($dir_path);

    // Safety: Do not let them out of the cage.
    if ($real_path === false || strpos($real_path, $jail_root) !== 0) {
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    $items = @scandir($real_path);
    $folders = [];
    $files = [];

    if ($items) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $real_path . '/' . $item;
            clearstatcache(true, $full);
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                $folders[] = $item;
            } else {
                $files[] = $item;
            }
        }
    }
    natcasesort($folders);
    natcasesort($files);

    header('Content-Type: application/json');
    echo json_encode(['folders' => array_values($folders), 'files' => array_values($files), 'parent' => $real_path]);
    exit;
}


$pgtitle = [gtext("Extensions"), gtext('Bastille'), gtext('File Editor v2'), $jailname];
include 'fbegin.inc';
?>

<table width="100%" border="0" cellpadding="0" cellspacing="0">
    <tr><td class="tabnavtbl">
        <ul id="tabnav">
            <li class="tabinact"><a href="bastille_manager_gui.php"><span><?=gtext("Containers");?></span></a></li>
            <li class="tabinact"><a href="bastille_manager_jconf.php?jailname=<?=$jailname?>"><span><?=gtext("Configuration");?></span></a></li>
            <li class="tabact"><a href="bastille_manager_edit.php?jailname=<?=$jailname?>" title="<?=gtext('Reload');?>"><span><?=gtext("Editor");?></span></a></li>
        </ul>
    </td></tr>
    <tr><td class="tabcont">
        <form action="bastille_manager_edit.php?jailname=<?=urlencode($jailname)?>&dir=<?=urlencode($real_current_dir)?>&filepath=<?=urlencode($filepath)?>" method="post" name="iform" id="iform" onsubmit="return saveAndSpin();">
            <?php if (!empty($input_errors)): ?>
                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        const errorMsg = "<?= addslashes(implode('\\n', $input_errors)) ?>";
                        showConfirmDialog("Error Saving", errorMsg, "warning");
                    });
                </script>
            <?php endif; ?>

            <?php if (!empty($savemsg)): ?>
                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        const successMsg = "<?= addslashes($savemsg) ?>";
                        showConfirmDialog("Saved", successMsg, "info");
                        if (typeof isDirty !== 'undefined') isDirty = false;
                    });
                </script>
            <?php endif; ?>

            <div class="ide-container">
                <div class="ide-sidebar">

                    <div class="ide-sidebar-header lhetop">
                        <a href="?jailname=<?=urlencode($jailname)?>&dir=<?=urlencode($jail_root)?>" title="Go to Jail Root">
                            <img src="ext/bastille/images/home.svg" class="home-icon" alt="Home" width="18" height="18">
                        </a>
                    </div>

                    <div class="ide-search">
                        <input type="text" id="fileFilter" onkeyup="filterFiles()" placeholder="Search files... (Ctrl + K)">
                        <span class="ide-search-clear" id="clearFilterBtn" onclick="clearFilter()">&times;</span>
                    </div>

                    <ul class="ide-file-list" id="fileList">
                        <?php if ($real_current_dir !== $jail_root): ?>
                           <li class="tree-item">
                               <a href="?jailname=<?=$jailname?>&dir=<?=urlencode(dirname($real_current_dir))?>">
                                   <?=$icon_up?> <span style="font-style:italic; color:#888;">.. (Up)</span>
                               </a>
                           </li>
                        <?php endif; ?>

                        <?php foreach ($folders as $folder):
                            $full_path = $real_current_dir . '/' . $folder;
                            ?>
                            <li class="tree-item folder-item">
                                <a href="javascript:void(0)" onclick="toggleFolder(this, '<?= addslashes($full_path) ?>')">
                                    <span class="tree-caret">▶</span>
                                    <?= $icon_folder ?> 
                                    <span><?= htmlspecialchars($folder) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>

                        <?php foreach ($files as $file):
                            $full_file_path = $real_current_dir . '/' . $file;
                            $is_active = ($filepath === $full_file_path) ? 'active' : '';
                            ?>
                           <li class="tree-item file-item <?=$is_active?>">
                               <a href="?jailname=<?=$jailname?>&dir=<?=urlencode($real_current_dir)?>&filepath=<?=urlencode($full_file_path)?>"
                                    onclick="if(typeof spinner === 'function') spinner();">
                                    <?= $icon_file ?> <span><?= htmlspecialchars($file) ?></span>
                               </a>
                           </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div id="ide-resizer" class="ide-resizer"></div>

                <div class="ide-main">
                    <div class="ide-main-header lhetop">
                        <div class="ide-filepath-container">
                            <?php if (!empty($filepath)): ?>
                                <span id="ide-filepath-display" class="ide-filepath-display" title="Copy full path to clipboard">
                                    <?=htmlspecialchars($filepath)?>
                                </span>
                            <?php else: ?>
                                <span style="color: inherit;">Select a file to edit</span>
                            <?php endif; ?>
                        </div>

                        <div class="ide-main-header-toolbar">
                            <div class="layout-btn" onclick="toggleSidebar()" title="Toggle Sidebar (Ctrl + B)">
                                <?= $icon_toggle ?>
                            </div>
                            <input name="save" id="btn_save" type="submit" class="formbtn" value="<?=gtext("Save File");?>" <?=empty($filepath) ? 'disabled' : ''?> />
                        </div>

                    </div>
                    <div id="monaco-container"></div>
                </div>
            </div>

            <textarea name="file_content" id="file_content" style="display:none;"><?=htmlspecialchars($content)?></textarea>
            <input type="hidden" name="jailname" value="<?=htmlspecialchars($jailname)?>">
            <input type="hidden" name="dir" value="<?=htmlspecialchars($real_current_dir)?>">
            <input type="hidden" name="filepath" value="<?=htmlspecialchars($filepath)?>">
            <?php include 'formend.inc'; ?>
        </form>
    </td></tr>
</table>

<div id="quick-search-backdrop" onclick="closeQuickSearch()"></div>
<div id="quick-search-modal">
    <div class="qs-header">
        <input type="text" id="qs-input" placeholder="Search files..." autocomplete="off">
        <span class="qs-clear" id="qs-clear-btn" onclick="clearQuickSearch()">&times;</span>
    </div>
    <div class="qs-history" id="qs-history-container">
        <span style="margin-right: 5px;">Recent:</span>
        <span id="qs-badges"></span>
    </div>
    <ul class="qs-results" id="qs-results-list"></ul>
    <div id="qs-footer" style="padding: 5px 15px; font-size: 10px; color: #999; border-top: 1px solid #eee; text-align: right; background: #fafafa;">
        Engine: <span id="qs-perf-info">0ms</span>
    </div>
</div>

<div id="ide-confirm-modal" class="ide-modal-overlay">
    <div class="ide-modal-box">
        
        <div class="ide-modal-header">
            <button class="ide-modal-close" onclick="document.getElementById('ide-confirm-modal').classList.remove('show')">×</button>
        </div>

        <div class="ide-modal-content-wrapper">
            <div id="ide-modal-icon-wrapper" class="ide-modal-icon-wrapper">
                <svg id="ide-modal-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </div>
            <div class="ide-modal-text">
                <h3 id="ide-modal-title">Title</h3>
                <p id="ide-modal-message">Message...</p>
            </div>
        </div>

        <div class="ide-modal-footer ">
            <button id="ide-modal-btn-cancel" class="ide-btn ide-btn-cancel">Cancel</button>
            <button id="ide-modal-btn-confirm" class="ide-btn ide-btn-primary">OK</button>
        </div>

    </div>
</div>

<link rel="stylesheet" type="text/css" href="ext/bastille/css/styles.css?v=<?=time();?>">
<script src="ext/bastille/js/bastille_editor_clipboard.js?v=<?=time();?>"></script>
<script src="ext/bastille/js/vs/loader.js"></script>

<script>

let searchTimer;
let selectedIndex = -1;
let isDirty = false;
let isInjectingCode = false;
let sidebarTimer;
let originalSidebarHTML = '';

const ICONS = {
    folder: `<img src="ext/bastille/images/folder.svg" class="tree-icon" />`,
    file:   `<img src="ext/bastille/images/file.svg" class="tree-icon" />`,
    caret:  `<span class="tree-caret">▶</span>`
};

const MODAL_CONFIG = {
    warning: {
        iconClass: 'icon-warning',
        btnClass: 'ide-btn-primary',
        btnText: 'OK',
        showCancel: true,
        svg: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>`
    },
    success: {
        iconClass: 'icon-success',
        btnClass: 'ide-btn-primary',
        btnText: 'OK',
        showCancel: false,
        svg: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>`
    },
    error: {
        iconClass: 'icon-error',
        btnClass: 'ide-btn-primary',
        btnText: 'OK',
        showCancel: false,
        svg: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>`
    }
};

// Toggle sidebar
window.toggleSidebar = function() {
    const container = document.querySelector('.ide-container');
    if (container) {
        container.classList.toggle('sidebar-hidden');
        //Redraw the editor
        setTimeout(() => {
            if (typeof window.editor !== 'undefined' && window.editor !== null) {
                window.editor.layout();
            }
        }, 200);
    }
};

// --- QUICK SEARCH LOGIC (Ctrl + K) ---
const qsModal = document.getElementById('quick-search-modal');
const qsBackdrop = document.getElementById('quick-search-backdrop');
const qsInput = document.getElementById('qs-input');
const qsClearBtn = document.getElementById('qs-clear-btn');
const qsResultsList = document.getElementById('qs-results-list');
const qsHistoryContainer = document.getElementById('qs-history-container');
const qsBadges = document.getElementById('qs-badges');
let searchHistory = JSON.parse(localStorage.getItem('bastilleSearchHistory')) || [];

function renderHistory() {
    if (searchHistory.length === 0) {
        qsHistoryContainer.style.display = 'none';
        return;
    }
    qsHistoryContainer.style.display = 'flex';
    qsBadges.innerHTML = '';
    searchHistory.forEach(term => {
        let badge = document.createElement('span');
        badge.className = 'qs-badge';
        badge.innerHTML = `${term} <span class="badge-delete" onclick="event.stopPropagation(); removeHistoryItem('${term}')">&times;</span>`;
        badge.onclick = () => {
            qsInput.value = term;
            runQuickSearch();
        };
        qsBadges.appendChild(badge);
    });
}

function removeHistoryItem(term) {
    searchHistory = searchHistory.filter(t => t !== term);
    localStorage.setItem('bastilleSearchHistory', JSON.stringify(searchHistory));
    renderHistory();
}

function saveHistory(term) {
    if (!term || term.trim() === '') return;
    term = term.trim().toLowerCase();
    searchHistory = searchHistory.filter(t => t !== term);
    searchHistory.unshift(term);
    if (searchHistory.length > 5) searchHistory.pop(); // last 5 items
    localStorage.setItem('bastilleSearchHistory', JSON.stringify(searchHistory));
    renderHistory();
}

function openQuickSearch() {
    qsModal.style.display = 'block';
    qsBackdrop.style.display = 'block';
    renderHistory();
    qsInput.value = '';
    runQuickSearch();
    setTimeout(() => qsInput.focus(), 100);
}

function closeQuickSearch() {
    qsModal.style.display = 'none';
    qsBackdrop.style.display = 'none';
}

function clearQuickSearch() {
    qsInput.value = '';
    runQuickSearch();
    qsInput.focus();
}

function runQuickSearch() {
    selectedIndex = -1;
    let filter = qsInput.value.trim();
    qsClearBtn.style.display = filter.length > 0 ? "flex" : "none";
    if (filter.length < 2) {
        qsResultsList.innerHTML = '<li style="padding: 15px; color:#888;">Type at least 2 chars...</li>';
        return;
    }
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        if (typeof spinner === "function") { 
            spinner(); 
        }
        qsResultsList.innerHTML = '<li style="padding: 15px; color:#888;">Searching recursively... 🚀</li>';
        // We use URLSearchParams to not break the XigmaNAS URL
        let url = new URL(window.location.origin + window.location.pathname);
        url.searchParams.set('jailname', '<?=urlencode($jailname)?>');
        url.searchParams.set('ajax_search', filter);
        fetch(url)
            .then(response => response.json())
            .then(data => {
                const perfInfo = document.getElementById('qs-perf-info');
                if (perfInfo) {
                    perfInfo.innerText = data.perf;
                }
                qsResultsList.innerHTML = '';
                if (!data.items || data.items.length === 0) {
                    qsResultsList.innerHTML = `
                        <li class="no-results" style="padding: 20px; text-align: center; color: #999;">
                            <div style="font-size: 24px;">No files found!</div>
                        </li>`;
                    return;
                }
                data.items.forEach(file => {
                    let li = document.createElement('li');
                    let a = document.createElement('a');
                    let editUrl = `bastille_manager_edit.php?jailname=<?=$jailname?>&dir=${encodeURIComponent(file.directory)}&filepath=${encodeURIComponent(file.full)}`;
                    a.href = editUrl;
                    a.innerHTML = `
                        <span class="qs-item-title">${file.name}</span>
                        <span class="qs-item-path">${file.relative}</span>
                    `;
                    a.addEventListener('click', () => saveHistory(filter));
                    li.appendChild(a);
                    qsResultsList.appendChild(li);
                });
            })
            .catch(err => {
                console.error('Search error:', err);
                qsResultsList.innerHTML = '<li style="padding: 15px; color:red;">Search engine offline.</li>';
            })
            .finally(() => {
                hideSpinner();
            });
    }, 400); // 400ms debounce to avoid flooding the server with requests on every keystroke
}

qsInput.removeEventListener('keyup', runQuickSearch);
qsInput.addEventListener('input', runQuickSearch);

document.addEventListener('keydown', function(e) {
    // TOGGLE SIDEBAR: Ctrl + B
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') {
        e.preventDefault(); 
        toggleSidebar();
    }

    // SAVE: Ctrl + S (Prevents downloading the page)
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault(); 
        e.stopPropagation();

        const saveBtn = document.getElementById('btn_save');
        if (saveBtn && !saveBtn.disabled) {
            saveAndSpin(); 
            const form = document.getElementById('iform');
            if (typeof form.requestSubmit === "function") {
                form.requestSubmit();
            } else {
                form.submit();
            }
        }
    }
    // Ctrl + K to open quick search from anywhere
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        openQuickSearch();
        return;
    }

    // ONLY intercept arrow keys if the MODAL IS OPEN
    if (qsModal.style.display === 'block') {
        
        if (e.key === 'Escape') {
            closeQuickSearch();
            return;
        }

        const items = qsResultsList.getElementsByTagName('li');
        
        if (e.key === 'ArrowDown') {
            e.preventDefault(); // Prevents the text cursor from moving
            selectedIndex = (selectedIndex + 1) < items.length ? selectedIndex + 1 : selectedIndex;
            updateSelection(items);
        }
        else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedIndex = (selectedIndex - 1) >= 0 ? selectedIndex - 1 : 0;
            updateSelection(items);
        }
        else if (e.key === 'Enter') {
            if (selectedIndex > -1 && items[selectedIndex]) {
                e.preventDefault();
                items[selectedIndex].querySelector('a').click();
            } else if (items.length > 0) {
                // Enter opens the first result automatically
                e.preventDefault();
                items[0].querySelector('a').click();
            }
        }
    }                 

});
// --- INTERCEPT CLICKS IN THE TREE (ASYNC SPA MODE) ---
//fixme detect reload
document.querySelector('.ide-file-list').addEventListener('click', async function(e) {
    const link = e.target.closest('a');
    if (!link) return;

    const urlParams = new URLSearchParams(link.href.split('?')[1]);
    const filepath = urlParams.get('filepath');
    const isFile = filepath && filepath !== ''; // Si tiene filepath, es un archivo

    if (isDirty) {
        e.preventDefault(); 
        hideSpinner();
        const userConfirmed = await showConfirmDialog(
            "Unsaved changes",
            "You have made changes. If you switch files now, you will lose your changes.",
            "warning"
        );
        if (!userConfirmed) {
            return;
        }
        isDirty = false; // Aceptó perder los cambios
        document.querySelectorAll('.dirty-dot').forEach(dot => dot.remove());
        if (!isFile) {
            window.location.href = link.href;
            return;
        }
    } else if (!isFile) {
        return; 
    }
    e.preventDefault(); 
    document.body.style.cursor = 'wait';
    try {
        const response = await fetch(link.href + '&ajax=1');
        if (!response.ok) {
            throw new Error('Error reading the file from the server');
        }
        const fileContent = await response.text();
        if (typeof window.editor !== 'undefined') {
            isInjectingCode = true;
            window.editor.setValue(fileContent);
            isInjectingCode = false; 
            isDirty = false; 
            document.querySelectorAll('.dirty-dot').forEach(dot => dot.remove());
        } else {
            window.location.href = link.href;
            return;
        }
        const ext = filepath.split('.').pop().toLowerCase();
        let lang = 'shell';
        if (['php', 'inc'].includes(ext)) lang = 'php';
        else if (ext === 'xml') lang = 'xml';
        else if (ext === 'js') lang = 'javascript';
        else if (ext === 'css') lang = 'css';
        else if (ext === 'json') lang = 'json';
        else if (['html', 'htm'].includes(ext)) lang = 'html';
        
        monaco.editor.setModelLanguage(window.editor.getModel(), lang);

        // Update UI
        document.querySelectorAll('.tree-item').forEach(el => el.classList.remove('active'));
        link.closest('.tree-item').classList.add('active');
        window.history.pushState({}, '', link.href);
        const pathDisplay = document.getElementById('ide-filepath-display');
        const inputFile = document.querySelector('input[name="filepath"]');
        const inputDir = document.querySelector('input[name="dir"]');

        if (inputFile) inputFile.value = filepath;
        if (inputDir) {
            const directory = filepath.substring(0, filepath.lastIndexOf('/'));
            inputDir.value = directory;
        }
        const form = document.getElementById('iform');
        if (form) {
            const currentUrl = new URL(window.location.href);
            form.action = currentUrl.toString();
        }

        if (pathDisplay) {
            pathDisplay.innerText = filepath;
        }

    } catch (error) {
        console.error("Error loading file:", error);
        showConfirmDialog("Error loading file", "The selected file could not be loaded. Check the console for more details.", "error");
    } finally {
        document.body.style.cursor = 'default';
        hideSpinner();
    }
});

//Scrool to active file on load
document.addEventListener("DOMContentLoaded", function() {
    const activeFile = document.querySelector('.tree-item.active');
    if (activeFile) {
        activeFile.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});

// --- REUSABLE MODAL FUNCTION (MODERN UI) ---
function showConfirmDialog(title, message, type = 'warning') {
    return new Promise((resolve) => {
        const overlay = document.getElementById('ide-confirm-modal');
        const titleEl = document.getElementById('ide-modal-title');
        const msgEl = document.getElementById('ide-modal-message');
        const iconWrapper = document.getElementById('ide-modal-icon-wrapper');
        const btnConfirm = document.getElementById('ide-modal-btn-confirm');
        const btnCancel = document.getElementById('ide-modal-btn-cancel');

        const configType = (type === 'info') ? 'success' : type;
        const conf = MODAL_CONFIG[configType] || MODAL_CONFIG.warning;
        //const headerText = document.getElementById('ide-modal-header-text');
        //if (headerText) {
        //    if (type === 'error') headerText.innerText = "Error";
        //    else if (type === 'warning') headerText.innerText = "Confirmación necesaria";
        //    else headerText.innerText = "Information";
        //}
        titleEl.innerText = title;
        msgEl.innerText = message;
        iconWrapper.className = `ide-modal-icon-wrapper ${conf.iconClass}`;
        btnConfirm.className = `ide-btn ${conf.btnClass}`;
        btnConfirm.innerText = conf.btnText;
        btnCancel.style.display = conf.showCancel ? 'inline-block' : 'none';

        iconWrapper.innerHTML = conf.svg; 
        
        const svg = iconWrapper.querySelector('svg');
        if (svg) {
            svg.setAttribute('width', '20');
            svg.setAttribute('height', '20');
            svg.style.display = 'block';
            svg.style.color = 'inherit'; 
        }

        overlay.classList.add('show');

        const cleanup = () => {
            overlay.classList.remove('show');
            btnCancel.removeEventListener('click', onCancel);
            btnConfirm.removeEventListener('click', onConfirm);
        };

        const onCancel = () => { cleanup(); resolve(false); };
        const onConfirm = () => { cleanup(); resolve(true); };

        btnCancel.addEventListener('click', onCancel);
        btnConfirm.addEventListener('click', onConfirm);
        
        if (!conf.showCancel) {
            setTimeout(() => btnConfirm.focus(), 100);
        }
    });
}

function updateSelection(items) {
    Array.from(items)
        .forEach(li => li.classList.remove('selected'));

    if (items[selectedIndex]) {
        items[selectedIndex].classList.add('selected');
        items[selectedIndex].scrollIntoView({ block: 'nearest' });
    }
}

function filterFiles() {
    const input = document.getElementById('fileFilter');
    const clearBtn = document.getElementById('clearFilterBtn');
    const ul = document.getElementById("fileList");
    const filter = input.value.toLowerCase().trim();

    if (originalSidebarHTML === '' && filter !== '') {
        originalSidebarHTML = ul.innerHTML;
    }

    clearBtn.style.display = filter.length > 0 ? "flex" : "none";

    const li = ul.getElementsByTagName('li');
    let localMatches = 0;
    for (let i = 0; i < li.length; i++) {
        if (li[i].textContent.includes('.. (Up)')) continue;
        let a = li[i].getElementsByTagName("a")[0];
        let txtValue = a ? (a.textContent || a.innerText) : "";

        if (txtValue.toLowerCase().indexOf(filter) > -1) {
            li[i].style.display = "";
            localMatches++;
        } else {
            li[i].style.display = "none";
        }
    }

    // PARALLEL RECURSIVE SEARCH (Debounced)
    clearTimeout(sidebarTimer);
    if (filter.length >= 2) {
        sidebarTimer = setTimeout(() => {
            // If there are few local results, we search in depth
            fetchSearchRecursive(filter);
        }, 500);
    } else if (filter === '') {
        clearFilter();
    }
}

function clearFilter() {
    const input = document.getElementById('fileFilter');
    const ul = document.getElementById("fileList");

    input.value = "";
    if (originalSidebarHTML !== '') {
        ul.innerHTML = originalSidebarHTML; // Restore original HTML
        originalSidebarHTML = '';
    }

    // Restore display for all items
    const li = ul.getElementsByTagName('li');
    for (let i = 0; i < li.length; i++) {
        li[i].style.display = "";
    }

    document.getElementById('clearFilterBtn').style.display = "none";
    input.focus();
 }

function fetchSearchRecursive(term) {
    const ul = document.getElementById("fileList");
    let url = new URL(window.location.origin + window.location.pathname);
    url.searchParams.set('jailname', '<?=urlencode($jailname)?>');
    url.searchParams.set('ajax_search', term);
    fetch(url)
        .then(res => res.json())
        .then(data => {
            document.querySelectorAll('.is-recursive .no-results').forEach(el => el.remove());
            if (!data.items || data.items.length === 0) {
                let li = document.createElement('li');
                li.className = 'no-results';
                li.innerHTML = '<span style="padding:10px; color:#888; font-style:italic;">No matches found...</span>';
                ul.appendChild(li);
                return;
            }
            if(data.items) {
                // Don't remove local results, add new ones at the end if they don't exist
                data.items.forEach(file => {
                    // Avoid duplicates if the file is already in the local list
                    if (!document.querySelector(`a[href*="${encodeURIComponent(file.full)}"]`)) {
                        let li = document.createElement('li');
                        li.className = 'is-recursive';
                        let editUrl = `bastille_manager_edit.php?jailname=<?=urlencode($jailname)?>&filepath=${encodeURIComponent(file.full)}`;
                        li.innerHTML = `
                            <a href="${editUrl}" title="${file.full}">
                                <strong>${file.name}</strong>
                                <span class="search-result-path">${file.relative}</span>
                            </a>
                        `;
                        ul.appendChild(li);
                    }
                });
            }
        });
}

//FIXME Duplicate definition of module 'vs/editor/editor.main'
const MONACO_NODE_MODULES = '/ext/bastille/js/vs';
require.config({ paths: { 'vs': MONACO_NODE_MODULES } });
window.MonacoEnvironment = {
    getWorkerUrl: function(workerId, label) {
        const absolutePath = window.location.origin + MONACO_NODE_MODULES;
        const workerCode = `
            self.MonacoEnvironment = { baseUrl: '${absolutePath}' };
            importScripts('${absolutePath}/base/worker/workerMain.js');
        `;
        return `data:text/javascript;charset=utf-8,${encodeURIComponent(workerCode)}`;
    }
};

require(['vs/editor/editor.main'], function() {
    var filepath = '<?=addslashes($filepath)?>';
    if (filepath !== '') {
        var fileExt = filepath.split('.').pop().toLowerCase();
        var lang = 'shell';
        if (['php', 'inc'].includes(fileExt)) lang = 'php';
        else if (fileExt === 'xml') lang = 'xml';
        else if (fileExt === 'js') lang = 'javascript';
        else if (fileExt === 'css') lang = 'css';
        else if (fileExt === 'json') lang = 'json';
        else if (['html', 'htm'].includes(fileExt)) lang = 'html';

        window.editor = monaco.editor.create(document.getElementById('monaco-container'), {
            value: document.getElementById('file_content').value,
            language: lang,
            theme: 'vs',
            automaticLayout: true,
            scrollBeyondLastLine: false,
            wordWrap: 'on',
            minimap: { enabled: true },
            fontSize: 11,
            renderWhitespace: 'boundary'
        });
        window.editor.onKeyDown(function(e) {
            if (e.ctrlKey && e.keyCode === 41) { // 41 is 'K'
                e.preventDefault();
                e.stopPropagation();
                openQuickSearch();
            }
        });
        window.editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, function() {
            executeSaved(); 
        });
        window.editor.onDidChangeModelContent(function() {
            if (isInjectingCode) {
                return;
            }
            if (!isDirty) {
                isDirty = true;
                
                const activeFileLink = document.querySelector('.tree-item.active > a');
                if (activeFileLink && !activeFileLink.querySelector('.dirty-dot')) {
                    const dot = document.createElement('span');
                    dot.className = 'dirty-dot';
                    dot.innerHTML = '•';
                    dot.title = "Cambios sin guardar";
                    activeFileLink.appendChild(dot);
                }
            }
        });
    }
});

document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault(); // ¡We blocked Chrome's ugly "Save as..." feature!
        executeSaved();
    }
});

function executeSaved() {
    // If the editor is not active or there is no file, we do nothing.
    if (typeof window.editor === 'undefined') return;

    // 1. We pass the Monaco code to the hidden textarea
    const fileContentInput = document.getElementById('file_content');
    if (fileContentInput) {
        fileContentInput.value = window.editor.getValue();
    }

    // --- 🛠️ BUG FIX: SYNC HIDDEN INPUTS WITH ACTIVE FILE ---
    const currentPathDisplay = document.getElementById('ide-filepath-display');
    if (currentPathDisplay && currentPathDisplay.innerText.trim() !== 'Select a file') {
        const activeFilepath = currentPathDisplay.innerText.trim();
        
        // Update hidden filepath
        const filepathInput = document.querySelector('input[name="filepath"]');
        if (filepathInput) filepathInput.value = activeFilepath;
        
        // Update hidden directory (extracting path without filename)
        const dirInput = document.querySelector('input[name="dir"]');
        if (dirInput) dirInput.value = activeFilepath.substring(0, activeFilepath.lastIndexOf('/'));
        
        // Update Form Action URL so the page reloads correctly after POST
        const form = document.getElementById('iform');
        if (form) form.action = window.location.href;
    }
    // --------------------------------------------------------

    // 2. We prepare the form
    const form = document.getElementById('iform'); 
    if (!form) {
        return;
    }

    // 3. We inject PHP with the notice that we are saving (the 'save' input).
    if (!document.getElementById('hidden_save_trigger')) {
        const hiddenSave = document.createElement('input');
        hiddenSave.type = 'hidden';
        hiddenSave.name = 'save'; 
        hiddenSave.value = '1';
        hiddenSave.id = 'hidden_save_trigger';
        form.appendChild(hiddenSave);
    }

    if (typeof spinner === "function") { 
        spinner();
    }

    if (typeof form.requestSubmit === "function") {
        form.requestSubmit();
    } else {
        form.submit();
    }
}

window.saveAndSpin = function() {
    if (typeof window.editor !== 'undefined' && window.editor !== null) {
        document.getElementById('file_content').value = window.editor.getValue();
    } 

    // --- 🛠️ BUG FIX: SYNC HIDDEN INPUTS WITH ACTIVE FILE ---
    const currentPathDisplay = document.getElementById('ide-filepath-display');
    if (currentPathDisplay && currentPathDisplay.innerText.trim() !== 'Select a file') {
        const activeFilepath = currentPathDisplay.innerText.trim();
        const filepathInput = document.querySelector('input[name="filepath"]');
        const dirInput = document.querySelector('input[name="dir"]');
        if (filepathInput) filepathInput.value = activeFilepath;
        if (dirInput) dirInput.value = activeFilepath.substring(0, activeFilepath.lastIndexOf('/'));
        
        const form = document.getElementById('iform');
        if (form) form.action = window.location.href;
    }
    // --------------------------------------------------------

    const form = document.getElementById('iform');
    if (!document.getElementById('hidden_save_trigger')) {
        const hiddenSave = document.createElement('input');
        hiddenSave.type = 'hidden';
        hiddenSave.name = 'save';
        hiddenSave.value = '1';
        hiddenSave.id = 'hidden_save_trigger';
        form.appendChild(hiddenSave);
    }
    
    if (typeof spinner === "function") {
        spinner();
    }
    
    const saveBtn = document.getElementById('btn_save');
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.value = 'Saving...'; 
    }
    return true;
};

function toggleFolder(element, path) {
    const li = element.parentElement;
    let subList = li.querySelector('ul');

    if (subList) {
        const isHidden = subList.style.display === 'none';
        subList.style.display = isHidden ? 'block' : 'none';
        if (isHidden) {
            li.classList.add('open');
        } else {
            li.classList.remove('open');
        }
        return;
    }
    if (typeof spinner === "function") { 
        spinner(); 
    }
    // If it does not exist, we request it from the server.
    const url = new URL(window.location.origin + window.location.pathname);
    url.searchParams.set('jailname', '<?=urlencode($jailname)?>');
    url.searchParams.set('ajax_get_dir', path);

    fetch(url)
        .then(res => res.json())
        .then(data => {
            subList = document.createElement('ul');
            subList.className = 'ide-file-list';
            subList.style.paddingLeft = '15px';

            // We inject folders
            data.folders.forEach(f => {
                const fullP = path + '/' + f;
                const safePath = fullP.replace(/'/g, "\\'");
                subList.innerHTML += `
                    <li class="tree-item folder-item">
                        <a href="javascript:void(0)" onclick="toggleFolder(this, '${safePath}')">
                            ${ICONS.caret} ${ICONS.folder} <span>${f}</span>
                        </a>
                    </li>`;
            });

            // We inject files
            data.files.forEach(f => {
                const fullP = path + '/' + f;
                const editUrl = `?jailname=<?=urlencode($jailname)?>&dir=${encodeURIComponent(path)}&filepath=${encodeURIComponent(fullP)}`;
                subList.innerHTML += `
                    <li class="tree-item file-item">
                        <a href="${editUrl}" onclick="if(typeof spinner === 'function') spinner();">
                            ${ICONS.file} <span>${f}</span>
                        </a>
                    </li>`;
            });

            li.appendChild(subList);
            li.classList.add('open');
            /*element.querySelector('.tree-caret').style.transform = 'rotate(90deg)';*/
        })
        .finally(() => {
            hideSpinner();
        });
}

// --- RESIZER LOGIC (SPLIT PANEL) ---
const resizer = document.getElementById('ide-resizer');
const sidebar = document.querySelector('.ide-sidebar');
const container = document.querySelector('.ide-container');

if (resizer) {
    resizer.addEventListener('mousedown', function(e) {
        e.preventDefault();
        document.addEventListener('mousemove', resize);
        document.addEventListener('mouseup', stopResize);
        resizer.classList.add('resizing');
    });
}

function resize(e) {
    const containerRect = container.getBoundingClientRect();
    const newWidth = e.clientX - containerRect.left;
    
    // Limits: minimum 180px, maximum 600px
    if (newWidth > 180 && newWidth < 600) {
        container.style.gridTemplateColumns = `${newWidth}px 4px 1fr`;
        
        // We notified Monaco that the size changed so that it would adjust itself.
        if (window.editor) {
            window.editor.layout();
        }
    }
}

function stopResize() {
    document.removeEventListener('mousemove', resize);
    resizer.classList.remove('resizing');
}

function hideSpinner() {
    if (typeof $ !== 'undefined') {
        $('#spinner_overlay').hide();
    } else {
        const overlay = document.getElementById('spinner_overlay');
        if (overlay) overlay.style.display = 'none';
    }

    const main = document.getElementById('spinner_main');
    if (main) {
        main.innerHTML = ''; 
    }
    if (window.editor) {
        window.editor.layout();
    }
}

</script>
<script src="js/spin.min.js"></script>
<?php include 'fend.inc'; ?>