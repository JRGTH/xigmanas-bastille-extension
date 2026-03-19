// main.js

import { cfg }                              from './modules/state.js';
import { showConfirmDialog }                from './modules/modal.js';
import { toggleSidebar, hideSpinner,
         spinner, initResizer,
         updateBreadcrumbs,
         initBreadcrumbListener }           from './modules/ui.js';
import { initSearch, initKeybinds,
         clearFilter }                      from './modules/search.js';
import { initTree, toggleFolder,
         syncSidebarWithFolder,
         syncSidebarWithFile,
         refreshDir, clearDirtyState }      from './modules/tree.js';
import { executeDownloadRequest, openSSE,
         triggerDownload, showNotification,
         initPendingJobResume }             from './modules/download.js';
import { executeUnlock, executeDelete,
         executeCreateItem }                from './modules/filesystem.js';
import { handleFileUpload, handleNativeUpload,
         openUploadModal, closeUploadModal,
         handleRemoteDownload, scanFiles,
         injectItemIntoTree, initDropZone,
         initSidebarDragDrop }              from './modules/upload.js';
import { initMonaco, executeSaved,
         initDiffModal, openDiffViewer,
         loadBackupDiff, closeDiffViewer,
         initBeforeUnload }                 from './modules/editor.js';
import { closeInfoSidebar, showFileInfo,
         switchTab, startDiskWatcher,
         stopDiskWatcher }                  from './modules/sidebar-info.js';
import { initContextMenu }                  from './modules/context-menu.js';

// --- EXPOSE GLOBALS NEEDED BY PHP-GENERATED HTML ---
window.toggleSidebar        = toggleSidebar;
window.toggleFolder         = toggleFolder;
window.syncSidebarWithFolder = syncSidebarWithFolder;
window.showConfirmDialog    = showConfirmDialog;
window.executeSaved         = executeSaved;
window.executeUnlock        = executeUnlock;
window.executeDelete        = executeDelete;
window.executeCreateItem    = executeCreateItem;
window.handleNativeUpload   = handleNativeUpload;
window.openUploadModal      = openUploadModal;
window.closeUploadModal     = closeUploadModal;
window.handleRemoteDownload = handleRemoteDownload;
window.openDiffViewer       = openDiffViewer;
window.loadBackupDiff       = loadBackupDiff;
window.closeDiffViewer      = closeDiffViewer;
window.closeInfoSidebar     = closeInfoSidebar;
window.showFileInfo         = showFileInfo;
window.switchTab            = switchTab;
window.refreshDir           = refreshDir;
window.clearDirtyState      = clearDirtyState;
window.clearFilter          = clearFilter;
window.spinner              = spinner;
window.hideSpinner          = hideSpinner;
window.updateBreadcrumbs    = updateBreadcrumbs;

// --- INIT ---
document.addEventListener('DOMContentLoaded', () => {
    initResizer();
    initBreadcrumbListener();
    initSearch();
    initKeybinds();
    initContextMenu();
    initDiffModal();
    initDropZone();
    initSidebarDragDrop();
    initBeforeUnload();
    initPendingJobResume();
});

// Recover filepath from history.state on F5
if (history.state?.filepath) cfg.filepath = history.state.filepath;

initTree();
initMonaco();
```

Con esto la estructura final queda:
```
ext/bastille/js/
├── main.js
├── bastille_editor.js     ← puede vaciarse o eliminarse
└── modules/
    ├── state.js
    ├── modal.js
    ├── ui.js
    ├── search.js
    ├── tree.js
    ├── editor.js
    ├── download.js
    ├── filesystem.js
    ├── upload.js
    ├── sidebar-info.js
    └── context-menu.js