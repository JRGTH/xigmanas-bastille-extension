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
        caret: '<span class="tree-caret">▶</span>'
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
        <form action="bastille_manager_editor_v2.php?jailname=<?=urlencode($jailname)?>&dir=<?=urlencode($real_current_dir)?>&filepath=<?=urlencode($filepath)?>" method="post" name="iform" id="iform">
            
            <div class="ide-container">
                <div class="ide-sidebar">
                    <div class="ide-sidebar-header lhetop">
                        <input type="file" id="ide-upload-button" multiple style="display:none;">
                        <a href="javascript:void(0)" title="Upload Files" onclick="document.getElementById('ide-upload-button').click();">
                            <img src="ext/bastille/images/upload.svg" width="16px" height="16px">
                        </a>

                        <a href="?jailname=<?=urlencode($jailname)?>&filepath=<?=urlencode($filepath)?>" title="Reset Tree">
                            <img src="images/fm_img/smallicons/house.png" class="home-icon" alt="Home" width="18px" height="18px">
                        </a>
                    </div>

                    <div class="ide-search">
                        <input type="text" id="fileFilter" placeholder="Search files... (Ctrl + K)">
                        <span class="ide-search-clear" id="clearFilterBtn" style="display:none;">&times;</span>
                    </div>

                   <ul class="ide-file-list" id="fileList">
                       <li class="tree-item folder-item open">
                           <a href="javascript:void(0)" style="cursor:default; pointer-events:none;">
                               <span class="tree-caret">▼</span>
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
                                               <a href="javascript:void(0)" onclick="toggleFolder(this, '<?= addslashes($full_path) ?>')">
                                                   <span class="tree-caret">▶</span>
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

<script src="ext/bastille/js/bastille_editor_clipboard.js?v=<?=time();?>"></script>
<script src="ext/bastille/js/vs/loader.js"></script>
<script src="js/spin.min.js"></script>

<script src="ext/bastille/js/bastille_editor.js?v=<?=time();?>"></script>

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