// modules/state.js

export const cfg = window.IDE_CONFIG;

// Editor state
export let searchTimer = null;
export let selectedIndex = -1;
export let isDirty = false;
export let isInjectingCode = false;
export let sidebarTimer = null;
export let originalSidebarHTML = "";
export let currentFileData = null;
export let diffEditorInstance = null;
export let currentDiffFilepath = "";
export let contextMenu = null;
export let cmTargetData = null;

// Disk health state
export let diskWatcherInterval = null;
export let diskHealthChart = null;
export const diskHealthHistory = { avg: [], hot: [] };

export function setIsDirty(v) {
  isDirty = v;
  window.isDirty = v;
  const saveBtn = document.getElementById("btn_save");
  if (saveBtn) {
    saveBtn.disabled = !v;
  }
}

export function setIsInjectingCode(v) {
  isInjectingCode = v;
}
export function setCurrentFileData(v) {
  currentFileData = v;
}
export function setDiffEditorInstance(v) {
  diffEditorInstance = v;
}
export function setCurrentDiffFilepath(v) {
  currentDiffFilepath = v;
}
export function setContextMenu(v) {
  contextMenu = v;
}
export function setCmTargetData(v) {
  cmTargetData = v;
}
export function setDiskWatcherInterval(v) {
  diskWatcherInterval = v;
}
export function setDiskHealthChart(v) {
  diskHealthChart = v;
}
export function setSearchTimer(v) {
  searchTimer = v;
}
export function setSidebarTimer(v) {
  sidebarTimer = v;
}
export function setSelectedIndex(v) {
  selectedIndex = v;
}
export function setOriginalSidebarHTML(v) {
  originalSidebarHTML = v;
}

export let clipboard = {
    filepath: null,
    name: null,
    isFolder: false,
    liElement: null
};

export function setClipboard(data) {
    // 1. Clean up: Remove the visual effect from ANY currently cut items
    document.querySelectorAll('.cut-element').forEach(el => {
        el.classList.remove('cut-element');
    });
    // 2. Normalize data: Ensure 'clipboard' is always an Array
    // If 'data' is a single object, we wrap it in []. If it's already an array, we keep it.
    window.clipboard = Array.isArray(data) ? data : [data];
    // 3. Apply the visual effect to all items now in the clipboard
    window.clipboard.forEach(item => {
        if (item.liElement) {
            item.liElement.classList.add('cut-element');
        }
    });
    console.log(`[STATE] Clipboard updated with ${window.clipboard.length} items.`);
}

export function clearClipboard() {
    // Reset the array
    window.clipboard = [];
    // Remove all visual "cut" styles from the UI
    document.querySelectorAll(".cut-element").forEach(el => {
        el.classList.remove("cut-element");
    });
    console.log("[STATE] Clipboard cleared.");
}

export function getBaseFormData() {
  const formData = new FormData();
  const authToken = document.querySelector('input[name="authtoken"]')?.value || '';
  if (authToken) {
      formData.append('authtoken', authToken);
  }
  if (cfg && cfg.jailname) {
      formData.append('jailname', cfg.jailname);
  }
  return formData;
}