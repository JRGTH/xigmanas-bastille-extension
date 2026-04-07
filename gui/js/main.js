// main.js

import { cfg } from "./modules/state.js";
import { showConfirmDialog } from "./modules/modal.js";
import {
  toggleSidebar,
  hideSpinner,
  spinner,
  initResizer,
  updateBreadcrumbs,
  initBreadcrumbListener,
} from "./modules/ui.js";
import { initSearch, initKeybinds, clearFilter } from "./modules/search.js";
import {
  initTree,
  toggleFolder,
  syncSidebarWithFolder,
  syncSidebarWithFile,
  refreshDir,
} from "./modules/tree.js";
import {
  executeDownloadRequest,
  openSSE,
  triggerDownload,
  showNotification,
  initPendingJobResume,
} from "./modules/download.js";
import {
  executeUnlock,
  executeDelete,
  executeCreateItem,
} from "./modules/filesystem.js";
import {
  handleFileUpload,
  handleNativeUpload,
  openUploadModal,
  closeUploadModal,
  handleRemoteDownload,
  scanFiles,
  injectItemIntoTree,
  initDropZone,
  initSidebarDragDrop,
} from "./modules/upload.js";
import {
  initMonaco,
  executeSaved,
  initDiffModal,
  openDiffViewer,
  loadBackupDiff,
  closeDiffViewer,
  initBeforeUnload,
  loadFileToEditor,
} from "./modules/editor.js";
import {
  closeInfoSidebar,
  initNotifications,
  showFileInfo,
  switchTab,
} from "./modules/sidebar-info.js";
import { initContextMenu } from "./modules/context-menu.js";
import { initTreeDelegation, initHomeButton } from "./modules/tree.js";
import { initDragAndDrop } from './modules/dragdrop.js';

// --- EXPOSE GLOBALS NEEDED BY PHP-GENERATED HTML ---
window.toggleSidebar = toggleSidebar;
window.toggleFolder = toggleFolder;
window.syncSidebarWithFolder = syncSidebarWithFolder;
window.executeUnlock = executeUnlock;
window.executeDelete = executeDelete;
window.executeCreateItem = executeCreateItem;
window.handleNativeUpload = handleNativeUpload;
window.openUploadModal = openUploadModal;
window.closeUploadModal = closeUploadModal;
window.handleRemoteDownload = handleRemoteDownload;
window.openDiffViewer = openDiffViewer;
window.loadBackupDiff = loadBackupDiff;
window.closeDiffViewer = closeDiffViewer;
window.closeInfoSidebar = closeInfoSidebar;
window.showFileInfo = showFileInfo;
window.switchTab = switchTab;
window.refreshDir = refreshDir;
window.clearFilter = clearFilter;
window.spinner = spinner;
window.hideSpinner = hideSpinner;
window.updateBreadcrumbs = updateBreadcrumbs;

// --- INIT ---
document.addEventListener("DOMContentLoaded", () => {
  initTreeDelegation();
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
  initHomeButton();
  initTree();
  initMonaco();
  initDragAndDrop();
  const initialFile = window.IDE_CONFIG.filepath;
  if (initialFile) {
    loadFileToEditor(initialFile);
  }
  document.addEventListener('click', () => {
    initNotifications();
  }, { once: true });
});

// Recover filepath from history.state on F5
if (history.state?.filepath) {
  cfg.filepath = history.state.filepath;
}
