<link rel="stylesheet" type="text/css" href="ext/bastille/css/styles.css?v=<?=time();?>">

<script>
window.IDE_CONFIG = {
    jailname: <?= json_encode($jailname) ?>,
    jailRoot: <?= json_encode($jail_root) ?>,
    currentDir: <?= json_encode($real_current_dir) ?>,
    filepath: <?= json_encode($filepath) ?>,
    icons: {
        folder: <?= json_encode($icon_folder) ?>,
        file: <?= json_encode($icon_file) ?>,
        caret: '<span class="tree-caret"><img src="ext/bastille/images/right-arrow.svg" style="width: 20px;"></span>'
    }
};
</script>

<table width="100%" border="0" cellpadding="0" cellspacing="0">
    <tr><td class="tabnavtbl">
        <ul id="tabnav">
            <li class="tabinact"><a href="bastille_manager_gui.php"><span><?=gtext("Containers");?></span></a></li>
            <li class="tabinact"><a href="bastille_manager_jconf.php?jailname=<?=$jailname?>"><span><?=gtext("Configuration");?></span></a></li>
            <li class="tabact"><a href="bastille_manager_editor_v2.php?jailname=<?=$jailname?>" title="<?=gtext('Reload');?>"><span><?=gtext("Editor");?></span></a></li>
        </ul>
    </td></tr>
    <tr><td class="tabcont">
        <form action="bastille_manager_editor_v2.php?jailname=<?=urlencode($jailname)?>&dir=<?=urlencode($real_current_dir)?>" method="post" name="iform" id="iform">
            
            <div class="ide-container">
                <div class="ide-sidebar">
                    <div class="ide-sidebar-header lhetop">
                        <input type="file" id="ide-upload-button" multiple style="display:none;">
                        <div class="sidebar-header-actions">

                            <div class="header-plus-container">
                                <span class="plus-icon">+</span>

                                <div class="header-plus-submenu">

                                    <div class="ide-cm-item" id="header-new-file">
                                        <div class="icon-wrapper">
                                            <img src="ext/bastille/images/file.svg" class="ide-cm-item-svg">
                                        </div>
                                        <span class="ide-cm-item-text">File</span>
                                    </div>

                                    <div class="ide-cm-item" id="header-new-folder">
                                        <div class="icon-wrapper">
                                            <img src="ext/bastille/images/folder.svg" class="ide-cm-item-svg">
                                        </div>
                                        <span class="ide-cm-item-text">Directory</span>
                                    </div>

                                    <div class="ide-cm-separator" style="height:1px; background:#eee; margin:4px 0;"></div>

                                    <div class="ide-cm-item" id="cm-upload-icon" onclick="openUploadModal()">
                                         <div class="icon-wrapper">
                                            <img src="ext/bastille/images/upload-cloud.svg" class="ide-cm-item-svg">
                                        </div>
                                        <span class="ide-cm-item-text" style="">Upload Manager</span>
                                     </div>

                                </div>
                            </div>

                            <a href="?jailname=<?=urlencode($jailname)?>&filepath=<?=urlencode($filepath)?>" class="header-action-btn" title="Reset Tree">
                                <img src="images/fm_img/smallicons/house.png" alt="Home" width="18" height="18">
                            </a>
                        </div>
                    </div>

                    <div class="ide-search">
                        <input type="text" id="fileFilter" placeholder="Search files... (Ctrl + K)">
                        <span class="ide-search-clear" id="clearFilterBtn" style="display:none;">&times;</span>
                    </div>

                   <ul class="ide-file-list" id="fileList">
                       <li class="tree-item folder-item open">
                           <a href="javascript:void(0)" style="cursor:default; pointer-events:none;">
                               <span class="tree-caret"><img src="ext/bastille/images/right-arrow.svg" style="width: 20px;"></span>
                               <?= $icon_folder ?> <span style="font-weight:bold;">/</span>
                           </a>

                           <ul class="ide-file-list" style="display:block; padding-left:15px;">
                               <?php
                               $root_flags = freeBsdFileFlags($jail_root);
                               $root_items = @scandir($jail_root);
                               if ($root_items):
                                   // Folder loop
                                   foreach ($root_items as $folder):
                                       if ($folder === '..' || $folder === '.' || $folder === '.backups') continue;
                                       $full_path = rtrim($jail_root, '/') . '/' . $folder;
                                       if (is_dir($full_path)):
                                           $f_flag = isset($root_flags[$folder]) ? $root_flags[$folder] : '';
                                           $lock_img = !empty($f_flag) ? "<img src='ext/bastille/images/lock.svg' class='lock-icon' style='width:14px;'>" : "";
                                           ?>
                                           <li class="tree-item folder-item" data-flag="<?= $f_flag ?>">
                                               <a href="javascript:void(0)" data-folder-path="<?= htmlspecialchars($full_path) ?>">
                                                   <span class="tree-caret"><img src="ext/bastille/images/right-arrow.svg" style="width: 20px;"></span>
                                                   <?= $icon_folder ?> <span><?= htmlspecialchars($folder) ?></span> <?= $lock_img ?>
                                               </a>
                                           </li>
                                       <?php endif; endforeach;

                                   // Dir loop
                                   foreach ($root_items as $file):
                                       $full_file_path = rtrim($jail_root, '/') . '/' . $file;
                                       if (is_file($full_file_path)):
                                           $is_active = ($filepath === $full_file_path) ? 'active' : '';
                                           $f_flag = isset($root_flags[$file]) ? $root_flags[$file] : '';
                                           $lock_img = !empty($f_flag) ? "<img src='ext/bastille/images/lock.svg' class='lock-icon' style='width:14px;'>" : "";
                                           ?>
                                           <li class="tree-item file-item <?= $is_active ?>" data-flag="<?= $f_flag ?>">
                                               <a href="?jailname=<?=$jailname?>&filepath=<?=urlencode($full_file_path)?>"
                                                    onclick="if(typeof spinner === 'function') spinner();">
                                                    <?= $icon_file ?> <span><?= htmlspecialchars($file) ?></span> <?= $lock_img ?>
                                               </a>
                                           </li>
                                       <?php endif; endforeach;
                               endif; ?>
                           </ul>
                       </li>
                   </ul>
                </div>

                <div id="ide-resizer" class="ide-resizer"></div>

                <div class="ide-main">
                    <div class="ide-main-header lhetop">
                        <div class="ide-filepath-container">
                            <?php if (!empty($filepath)):
                                $rel_path = trim(str_replace($jail_root, "", $filepath), '/');
                                $parts = explode('/', $rel_path);
                                $accumulated_path = rtrim($jail_root, '/');
                            ?>
                            <div id="ide-filepath-display" class="ide-filepath-display"
                                data-fullpath="<?=htmlspecialchars($filepath)?>"
                                data-relpath="<?=htmlspecialchars($jailname . str_replace($jail_root, "", $filepath))?>">
                                <span class="bc-part bc-folder" data-path="<?=htmlspecialchars($accumulated_path)?>">
                                    <?=htmlspecialchars($jailname)?>
                                </span>

                                <?php foreach ($parts as $index => $part):
                                    $accumulated_path .= '/' . $part;
                                    $is_last = ($index === count($parts) - 1);
                                ?>
                                    <span class="bc-sep">/</span>
                                    <?php if ($is_last): ?>
                                        <span class="bc-part bc-file" title="Click: Relative path | Right-Click: Absolute path">
                                            <?=htmlspecialchars($part)?>
                                            <img src="ext/bastille/images/copy.svg" class="copy-icon-img" alt="copy">
                                        </span>
                                    <?php else: ?>
                                        <span class="bc-part bc-folder" data-path="<?=htmlspecialchars($accumulated_path)?>">
                                            <?=htmlspecialchars($part)?>
                                        </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                                <div id="ide-filepath-display" class="ide-filepath-display">
                                    <span style="color: #fff;">Select a file to edit</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="ide-main-header-toolbar">
                            <div class="layout-btn" onclick="toggleSidebar()" title="Toggle Sidebar (Ctrl + B)">
                                <?= $icon_toggle ?>
                            </div>
                            <input name="save" id="btn_save" type="button" class="formbtn" title= "Save File (Ctrl + S)" value="<?=gtext("Save File");?>" onclick="executeSaved();" <?=empty($filepath) ? 'disabled' : ''?> />
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

<div id="quick-search-backdrop"></div>
<div id="quick-search-modal">
    <div class="qs-header">
        <input type="text" id="qs-input" placeholder="Search files..." autocomplete="off">
        <span class="qs-clear" id="qs-clear-btn">&times;</span>
    </div>
    <div class="qs-history" id="qs-history-container">
        <span style="margin-right: 5px;">Recent:</span><span id="qs-badges"></span>
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
            <div id="ide-modal-icon-wrapper" class="ide-modal-icon-wrapper"></div>
            <div class="ide-modal-text">
                <h3 id="ide-modal-title">Title</h3>
                <p id="ide-modal-message">Message...</p>
            </div>
        </div>
        <div class="ide-modal-footer">
            <button id="ide-modal-btn-cancel" class="ide-btn ide-btn-cancel">Cancel</button>
            <button id="ide-modal-btn-confirm" class="ide-btn ide-btn-primary">OK</button>
        </div>
    </div>
</div>

<div id="upload-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); backdrop-filter:blur(5px); z-index:99999; align-items:center; justify-content:center;">
    <div class="upload-modal-box">
        <div class="up-modal-header lhetop">
            <span class="up-modal-title">Upload Manager</span>
            <span class="up-modal-close" onclick="document.getElementById('upload-modal-overlay').style.display='none'">&times;</span>
        </div>

        <div id="up-state-selector" class="up-modal-content">
            <div class="up-drop-zone">
                <div class="up-icon-circle">
                    <img src="ext/bastille/images/upload-cloud.svg" alt="Upload" width="60">
                </div>
                <p class="up-main-text">Drag and drop files or folders to upload</p>
                <p class="up-sub-text">Items will be saved to the selected directory</p>

                <div class="up-action-buttons">
                    <button class="up-btn up-btn-primary" onclick="document.getElementById('up-file-input').click()">
                        <img src="ext/bastille/images/file.svg" width="14">
                        <span style="">Upload Files</span>
                    </button>
                    <button class="up-btn up-btn-secondary" onclick="document.getElementById('up-folder-input').click()">
                        <img src="ext/bastille/images/folder.svg" width="14">
                        <span style="">Upload Folder</span>
                    </button>
                </div>

                <div class="up-import-link-box">
                    <input type="text" id="up-remote-url" placeholder="Paste direct download link (http/https)...">
                    <button class="up-btn-link" onclick="handleRemoteDownload()">Import</button>
                </div>
            </div>
        </div>

        <div id="up-state-progress" class="up-modal-content" style="display:none;">
            <div class="up-progress-wrapper">
                <div id="up-current-filename">Preparing...</div>
                <div id="up-file-hash" style="font-family: monospace; font-size: 10px; color: #999; margin-top: 5px; word-break: break-all;"></div>
                <div class="up-progress-container">
                    <div id="up-progress-fill"></div>
                </div>

                <div class="up-progress-stats">
                    <span id="up-progress-percent">0% completed</span>
                    <span id="up-progress-info">Calculating...</span>
                </div>
            </div>
        </div>

        <div class="up-modal-footer">
            <button id="up-cancel-btn" class="up-btn-cancel" onclick="document.getElementById('upload-modal-overlay').style.display='none'">Close</button>
        </div>
    </div>
</div>

<div id="ide-info-sidebar" class="ide-info-sidebar">
    <div class="info-sidebar-header lhetop">
        <div class="header-main-title">
            <img id="sidebar-icon" src="ext/bastille/images/file.svg" width="20">
            <h3 id="sidebar-filename">Details</h3>
        </div>
        <span onclick="closeInfoSidebar()" class="close-sidebar-btn">&times;</span>
    </div>

    <div class="modern-tabs">
        <div class="tab-link active" onclick="switchTab('overview', this)">Overview</div>
        <div class="tab-link" onclick="switchTab('security', this)">Security</div>
        <div class="tab-link" onclick="switchTab('storage', this)">Storage</div>
    </div>

    <div id="info-sidebar-content" class="info-sidebar-content"></div>
</div>

<input type="file" id="up-file-input" style="display:none" multiple onchange="handleNativeUpload(this, false)">
<input type="file" id="up-folder-input" style="display:none" webkitdirectory directory onchange="handleNativeUpload(this, true)">

<script src="ext/bastille/js/modules/bastille_editor_clipboard.js?v=<?=time();?>"></script>
<script src="ext/bastille/js/modules/sha256.min.js"></script>
<script src="ext/bastille/js/modules/apexcharts.v5.10.3.js"></script>
<script src="js/spin.min.js"></script>

<script src="ext/bastille/js/monaco/vs/loader.js"></script>
<script type="module" src="ext/bastille/js/main.js"></script>

<?php if (!empty($input_errors)): ?>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            showConfirmDialog("Error", "<?= addslashes(implode('\\n', $input_errors)) ?>", "error");
        });
    </script>
<?php endif; ?>
<?php if (!empty($savemsg)): ?>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            showConfirmDialog("Saved", "<?= addslashes($savemsg) ?>", "success");
            if (typeof isDirty !== 'undefined') isDirty = false;
        });
    </script>
<?php endif; ?>