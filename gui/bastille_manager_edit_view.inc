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
                        <a href="?jailname=<?=urlencode($jailname)?>&dir=<?=urlencode($jail_root)?>" title="Go to Jail Root">
                            <img src="ext/bastille/images/home.svg" class="home-icon" alt="Home" width="18" height="18">
                        </a>
                    </div>

                    <div class="ide-search">
                        <input type="text" id="fileFilter" placeholder="Search files... (Ctrl + K)">
                        <span class="ide-search-clear" id="clearFilterBtn" style="display:none;">&times;</span>
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
                                    <?= $icon_folder ?> <span><?= htmlspecialchars($folder) ?></span>
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
                                <span id="ide-filepath-display" style="color: inherit;">Select a file to edit</span>
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