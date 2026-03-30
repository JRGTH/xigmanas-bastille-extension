// modules/context-menu.js

import {
  cfg,
  cmTargetData,
  setCmTargetData,
  contextMenu,
  setContextMenu,
  clipboard,
  setClipboard,
  clearClipboard,
} from "./state.js";
import { spinner, hideSpinner } from "./ui.js";
import {
  showConfirmDialog,
  showNewItemModal,
  showRenameModal,
} from "./modal.js";
import { refreshDir } from "./tree.js";
import {
  executeUnlock,
  executeDelete,
  executeCreateItem,
  executeRename,
  executeMove,
} from "./filesystem.js";
import { executeDownloadRequest } from "./download.js";
import { showFileInfo } from "./sidebar-info.js";
import { openDiffViewer } from "./editor.js";
import { showNotification } from "./download.js";

// --- CONTEXT MENU HTML ---
const CONTEXT_MENU_HTML = `
    <div class="ide-cm-item has-submenu" id="cm-new-menu">
        <div class="icon-wrapper"></div>
        <span class="ide-cm-item-text">New</span>
        <img src="ext/bastille/images/right-arrow.svg" class="cm-arrow" alt="arrow">
        <div class="ide-cm-submenu">
            <div class="ide-cm-item" id="cm-new-file">
                <div class="icon-wrapper"><img src="ext/bastille/images/file.svg" class="ide-cm-item-svg"></div>
                <span class="ide-cm-item-text">File</span>
            </div>
            <div class="ide-cm-item" id="cm-new-folder">
                <div class="icon-wrapper"><img src="ext/bastille/images/folder.svg" class="ide-cm-item-svg"></div>
                <span class="ide-cm-item-text">Directory</span>
            </div>
        </div>
    </div>

    <div class="ide-cm-separator"></div>

    <div class="ide-cm-item" id="cm-copy-path">
        <div class="icon-wrapper"><img src="ext/bastille/images/copy.svg" class="ide-cm-item-svg" alt="copy"></div>
        <span class="ide-cm-item-text">Copy Full Path</span>
    </div>

    <div class="ide-cm-item cm-unlock" id="cm-unlock-item" style="display:none;">
        <div class="icon-wrapper"><img src="ext/bastille/images/lock.svg" class="ide-cm-item-svg" alt="unlock"></div>
        <span class="ide-cm-item-text">Unlock (Clear Flags)</span>
    </div>

    <div class="ide-cm-item" id="cm-info-item">
        <div class="icon-wrapper"><img src="ext/bastille/images/info-ssl.svg" class="ide-cm-item-svg" alt="info"></div>
        <span class="ide-cm-item-text">Information</span>
    </div>

    <div class="ide-cm-item" id="cm-compare-history">
        <div class="icon-wrapper"><img src="ext/bastille/images/diff.svg" class="ide-cm-item-svg" alt="diff"></div>
        <span class="ide-cm-item-text">Compare History</span>
    </div>

    <div class="ide-cm-item has-submenu" id="cm-download-menu">
        <div class="icon-wrapper"><img src="images/fm_img/smallicons/drive-download.png" class="cm-download-menu" alt="download"></div>
        <span class="ide-cm-item-text">Download</span>
        <img src="ext/bastille/images/right-arrow.svg" class="cm-arrow" alt="arrow">
        <div class="ide-cm-submenu download-modifier">
            <div class="ide-cm-item" id="cm-download-file">
                <div class="icon-wrapper"><img src="ext/bastille/images/file.svg" class="ide-cm-item-svg"></div>
                <span class="ide-cm-item-text">Direct Download</span>
            </div>
            <div class="ide-cm-item" id="cm-download-zip">
                <div class="icon-wrapper"><img src="ext/bastille/images/zip-file-icon.svg" class="ide-cm-item-svg" alt="zip"></div>
                <span class="ide-cm-item-text">Compress as ZIP...</span>
            </div>
            <div class="ide-cm-item" id="cm-download-targz">
                <div class="icon-wrapper"><img src="ext/bastille/images/gzip.svg" class="ide-cm-item-svg"></div>
                <span class="ide-cm-item-text">Compress as .tar.gz</span>
            </div>
            <div class="ide-cm-item" id="cm-download-tarlz4">
                <div class="icon-wrapper"><img src="ext/bastille/images/lz4.svg" class="ide-cm-item-svg"></div>
                <span class="ide-cm-item-text">Compress as .tar.lz4</span>
            </div>
            <div class="ide-cm-item" id="cm-download-tarzst" title="Zstandard - Fast real-time compression">
                <div class="icon-wrapper"><img src="ext/bastille/images/zstd85.png" class="cm-download-tarzst"></div>
                <span class="ide-cm-item-text">Compress as .tar.zst</span>
            </div>
        </div>
    </div>

    <div class="ide-cm-item" id="cm-refresh-dir">
        <div class="icon-wrapper"><img src="images/fm_img/smallicons/arrow_refresh_small.png" class="cm-refresh-dir" alt="refresh"></div>
        <span class="ide-cm-item-text">Refresh Directory</span>
    </div>

    <div class="ide-cm-separator"></div>
    <div class="ide-cm-item" id="cm-rename">
        <div class="icon-wrapper"></div>
        <span class="ide-cm-item-text">Rename</span>
        <span class="ide-cm-item-text-secondary">F2</span>
    </div>
    <div class="ide-cm-separator"></div>
        <div class="ide-cm-item" id="cm-cut">
            <div class="icon-wrapper"><img src="ext/bastille/images/cut.svg" class="ide-cm-item-svg" onerror="this.style.display='none'"></div>
            <span class="ide-cm-item-text">Cut</span>
            <span class="ide-cm-item-text-secondary">Ctrl+X</span>
        </div>
        <div class="ide-cm-item" id="cm-paste">
            <div class="icon-wrapper"><img src="ext/bastille/images/paste.svg" class="ide-cm-item-svg" onerror="this.style.display='none'"></div>
            <span class="ide-cm-item-text">Paste</span>
            <span class="ide-cm-item-text-secondary">Ctrl+V</span>
        </div>
    <div class="ide-cm-separator"></div>
    <div class="ide-cm-item cm-delete" id="cm-delete-file">
        <div class="icon-wrapper"><img src="ext/bastille/images/delete.svg" class="ide-cm-item-svg" alt="delete"></div>
        <span class="ide-cm-item-text">Delete</span>
        <span class="ide-cm-item-text-secondary">(Supr - del)</span>
    </div>`;

// --- HIDE CONTEXT MENU ---
function hideContextMenu() {
  const cm = document.getElementById("ide-context-menu");
  if (cm) cm.style.display = "none";
}

// --- INIT ---
export function initContextMenu() {
  console.log("[CM] fileList:", document.querySelector(".ide-file-list"));
  console.log("[CM] contextmenu init");
  // Inject context menu
  const cm = document.createElement("div");
  cm.id = "ide-context-menu";
  cm.innerHTML = CONTEXT_MENU_HTML;
  document.body.appendChild(cm);
  setContextMenu(cm);

  // Inject new item modal
  const modal = document.createElement("div");
  modal.id = "ide-new-item-modal";
  modal.innerHTML = `
        <div class="ide-new-item-content">
            <div id="ide-new-item-title" class="ide-new-item-title lhetop">New File</div>
            <input type="text" id="ide-new-item-input" placeholder="Name" autocomplete="off" spellcheck="false">
        </div>`;
  document.body.appendChild(modal);

  // Right-click listener
  document
    .querySelector(".ide-file-list")
    .addEventListener("contextmenu", (e) => {
      const link = e.target.closest(".tree-item a");
      if (!link) return;
      e.preventDefault();
      // Change the background when an item is selected
      const liElement = link.closest(".tree-item");
      if (!liElement.classList.contains("active")) {
        console.log("[CM] Item not selected. Clearing previous selection.");
        document.querySelectorAll(".is-selected-target").forEach(el => el.classList.remove("is-selected-target"));
        document.querySelectorAll(".tree-item.active").forEach(el => el.classList.remove("active"));
        document.querySelectorAll("a.active-link").forEach(el => el.classList.remove("active-link"));
        liElement.classList.add("is-selected-target");
        liElement.classList.add("active");
        link.classList.add("active-link");
        window.lastSelectedTreeItem = liElement;
      } else {
        console.log("[CM] Item already part of selection. Keeping group.");
      }

      const isFolder = liElement.classList.contains("folder-item");
      let filepath = "";

      if (isFolder) {
        filepath = link.getAttribute("data-folder-path") ?? "";
        console.log(
          " folder filepath:",
          filepath,
          "data-folder-path:",
          link.getAttribute("data-folder-path"),
        );
      } else {
        filepath =
          new URL(link.href, window.location.origin).searchParams.get(
            "filepath",
          ) ?? "";
      }

      if (!filepath) return;

      const filenameEl = link.querySelector("span:not(.tree-caret)");
      const filename = filenameEl?.innerText.trim() ?? "Unknown";
      const currentFlag = liElement.getAttribute("data-flag") || "";
      const isImmutable = currentFlag.includes("schg");

      const parentUl = liElement.closest("ul.ide-file-list");
      const parentFlag =
        parentUl?.closest(".folder-item")?.getAttribute("data-flag") || "";
      const isParentImmutable = parentFlag.includes("schg");

      const unlockBtn = document.getElementById("cm-unlock-item");
      const deleteBtn = document.getElementById("cm-delete-file");

      if (isImmutable) {
        unlockBtn.style.display = "flex";
        deleteBtn.style.display = "none";
      } else if (isParentImmutable) {
        unlockBtn.style.display = "none";
        deleteBtn.style.display = "none";
      } else {
        unlockBtn.style.display = "none";
        deleteBtn.style.display = "flex";
      }

      setCmTargetData({
        filepath,
        filename,
        liElement,
        isFolder,
        flag: currentFlag,
      });

      document.getElementById("cm-download-file").style.display = isFolder
        ? "none"
        : "flex";
      document.getElementById("cm-download-zip").style.display = "flex";

      const refreshBtn = document.getElementById("cm-refresh-dir");
      refreshBtn.querySelector(".ide-cm-item-text").innerText = isFolder
        ? "Refresh Directory"
        : "Refresh Parent Dir";
      refreshBtn.style.display = "flex";

      // Position and display menu
      contextMenu.style.display = "block";

      let left = e.pageX;
      let top = e.pageY;

      const menuWidth = contextMenu.offsetWidth;
      const menuHeight = contextMenu.offsetHeight;

      if (e.clientX + menuWidth > window.innerWidth) {
        left = e.pageX - menuWidth;
      }

      if (e.clientY + menuHeight > window.innerHeight) {
        top = e.pageY - menuHeight;
      }

      contextMenu.style.left = `${left}px`;
      contextMenu.style.top = `${top}px`;
    });

  // Submenu repositioning
  document.querySelectorAll(".has-submenu").forEach((item) => {
    item.addEventListener("mouseenter", function () {
      const sub = this.querySelector(".ide-cm-submenu");
      if (!sub) return;
      sub.style.top = "0px";
      sub.style.bottom = "auto";
      const rect = this.getBoundingClientRect();
      const subHeight = sub.getBoundingClientRect().height || sub.scrollHeight;
      if (rect.top + subHeight > window.innerHeight - 10) {
        sub.style.top = "auto";
        sub.style.bottom = "0px";
      }
    });
  });

  // Global hide
  document.addEventListener("click", (e) => {
    if (e.button !== 2) hideContextMenu();
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") hideContextMenu();
  });

  // --- ACTIONS ---
  document.getElementById("cm-new-file").addEventListener("click", async () => {
    if (!cmTargetData) return;
    hideContextMenu();
    const name = await showNewItemModal("file");
    if (name) executeCreateItem(name, "file", cmTargetData);
  });

  document
    .getElementById("cm-new-folder")
    .addEventListener("click", async () => {
      if (!cmTargetData) return;
      hideContextMenu();
      const name = await showNewItemModal("folder");
      if (name) executeCreateItem(name, "folder", cmTargetData);
    });

  document.getElementById("cm-copy-path").addEventListener("click", () => {
    if (!cmTargetData) return;
    navigator.clipboard?.writeText(cmTargetData.filepath) ??
      (() => {
        const ta = document.createElement("textarea");
        ta.value = cmTargetData.filepath;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand("copy");
        document.body.removeChild(ta);
      })();
    hideContextMenu();
  });

  document
    .getElementById("cm-unlock-item")
    .addEventListener("click", async () => {
      if (!cmTargetData) return;
      hideContextMenu();
      const ok = await showConfirmDialog(
        "Unlock Protection",
        `The item "${cmTargetData.filename}" is protected with the flag: ${cmTargetData.flag}.\n\nDo you want to remove all protection flags now?`,
        "warning",
      );
      if (ok) executeUnlock(cmTargetData.filepath, cmTargetData.liElement);
    });

  document.getElementById("cm-info-item").addEventListener("click", () => {
    if (!cmTargetData) return;
    hideContextMenu();
    showFileInfo(cmTargetData.filepath);
  });

  document
    .getElementById("cm-compare-history")
    .addEventListener("click", () => {
      if (!cmTargetData) return;
      hideContextMenu();
      if (cmTargetData.isFolder) {
        showConfirmDialog(
          "Error",
          "You can only compare the history of a file, not a directory.",
          "error",
        );
        return;
      }
      openDiffViewer(cmTargetData.filepath, cmTargetData.filename);
    });

  document.getElementById("cm-download-file").addEventListener("click", () => {
    if (!cmTargetData || cmTargetData.isFolder) return;
    executeDownloadRequest(false);
  });

  document
    .getElementById("cm-download-zip")
    .addEventListener(
      "click",
      () => cmTargetData && executeDownloadRequest("zip"),
    );
  document
    .getElementById("cm-download-targz")
    .addEventListener(
      "click",
      () => cmTargetData && executeDownloadRequest("targz"),
    );
  document
    .getElementById("cm-download-tarlz4")
    .addEventListener(
      "click",
      () => cmTargetData && executeDownloadRequest("tarlz4"),
    );
  document
    .getElementById("cm-download-tarzst")
    .addEventListener(
      "click",
      () => cmTargetData && executeDownloadRequest("tarzst"),
    );

  document
    .getElementById("cm-refresh-dir")
    .addEventListener("click", async () => {
      if (!cmTargetData) return;
      hideContextMenu();
      const targetPath = cmTargetData.isFolder
        ? cmTargetData.filepath
        : cmTargetData.filepath.substring(
            0,
            cmTargetData.filepath.lastIndexOf("/"),
          );
      spinner();
      try {
        await refreshDir(targetPath);
        showNotification(
          "Refreshed",
          "Directory contents updated from server.",
        );
      } catch (e) {
        showConfirmDialog("Error", "Failed to refresh directory.", "error");
      } finally {
        hideSpinner();
      }
    });

  const renameBtn = document.getElementById("cm-rename");
  if (renameBtn) {
    renameBtn.addEventListener("click", async () => {
      if (window.contextMenu) window.contextMenu.classList.remove("show");
      if (!cmTargetData) {
        return;
      }
      const currentName = cmTargetData.filename
      const newName = await showRenameModal(
        cmTargetData.isFolder ? "folder" : "file",
        currentName
      );
      if (newName && newName !== currentName) {
        executeRename(
          cmTargetData.filepath,
          newName,
          cmTargetData.liElement,
          cmTargetData.isFolder,
        );
      }
    });
  }

  // --- CUT LOGIC ---
  document.getElementById('cm-cut')?.addEventListener('click', () => {
      if (window.contextMenu) {
        window.contextMenu.classList.remove('show');
      }
      if (!cmTargetData) {
        return;
      }

      setClipboard({
          filepath: cmTargetData.filepath,
          name: cmTargetData.fileName,
          isFolder: cmTargetData.isFolder,
          liElement: cmTargetData.liElement
      });
      console.log("[IDE] Cut:", clipboard.filepath);
  });

  // --- PASTING LOGIC ---
  document.getElementById('cm-paste')?.addEventListener('click', async () => {
      hideContextMenu();

      console.log("[IDE] The Paste button has been clicked. Verifying data...");
      console.log(" -> cmTargetData:", cmTargetData);
      console.log(" -> clipboard:", clipboard);

      if (!cmTargetData || !clipboard.filepath) {
          console.warn("[IDE] Paste failed: Data is missing from cmTargetData or the Clipboard.");
          return;
      }

      let destDirPath = cmTargetData.isFolder ?
                        cmTargetData.filepath :
                        cmTargetData.filepath.substring(0, cmTargetData.filepath.lastIndexOf('/'));

      console.log(`[IDE] Executing Move: Origin(${clipboard.filepath}) -> Destination(${destDirPath})`);

      const success = await executeMove(clipboard.filepath, destDirPath, clipboard.name);

      if (success) {
          clearClipboard();
          console.log("[IDE] Move completed. Clipboard cleared..");
      }
  });

  document.getElementById("cm-delete-file").addEventListener("click", () => {
      if (!cmTargetData) return;
      hideContextMenu();

      const selectedItems = Array.from(document.querySelectorAll(".tree-item.active"));
      const isTargetInSelection = selectedItems.includes(cmTargetData.liElement);

      if (selectedItems.length > 1 && isTargetInSelection) {
          // Prepare data for multiple items
          const bulkItems = selectedItems.map(li => {
              const link = li.querySelector("a");
              const url = new URL(link.href, window.location.origin);
              return {
                  filepath: url.searchParams.get("filepath"),
                  filename: li.textContent.trim(),
                  liElement: li,
                  isFolder: li.classList.contains("folder-item")
              };
          });
          executeDelete(bulkItems);
      } else {
          // Single item fallback
          executeDelete([{
              filepath: cmTargetData.filepath,
              filename: cmTargetData.filename,
              liElement: cmTargetData.liElement,
              isFolder: cmTargetData.isFolder
          }]);
      }
  });

  // --- HEADER + BUTTON ---
  const plusBtn = document.querySelector(".plus-icon");
  const plusMenu = document.querySelector(".header-plus-submenu");
  const headerMain = document.querySelector(".ide-sidebar-header");

  if (plusBtn && plusMenu && headerMain) {
    plusBtn.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();
      const isOpen = plusMenu.classList.toggle("show");
      headerMain.classList.toggle("menu-open", isOpen);
    });

    document.addEventListener("click", (e) => {
      if (!plusMenu.contains(e.target) && !plusBtn.contains(e.target)) {
        plusMenu.classList.remove("show");
        headerMain.classList.remove("menu-open");
      }
    });

    const resetHeader = () => {
      plusMenu.classList.remove("show");
      headerMain.classList.remove("menu-open");
    };

    const handleHeaderCreate = async (type) => {
      const targetPath = cfg.lastSelectedDir || cfg.jailRoot;
      const name = await showNewItemModal(type);
      if (name)
        executeCreateItem(name, type, { filepath: targetPath, isFolder: true });
    };

    document
      .getElementById("header-new-file")
      ?.addEventListener("click", () => {
        resetHeader();
        handleHeaderCreate("file");
      });
    document
      .getElementById("header-new-folder")
      ?.addEventListener("click", () => {
        resetHeader();
        handleHeaderCreate("folder");
      });
  }

  document.addEventListener("keydown", async (e) => {
    const activeTag = document.activeElement
      ? document.activeElement.tagName.toLowerCase()
      : "";
    const isMonaco = document.activeElement?.classList.contains("inputarea");
    if (activeTag === "input" || activeTag === "textarea" || isMonaco) {
      return;
    }

    // --- KEY SUPRIMIR / DELETE ---
    if (e.key === "Delete") {
      const target = getActiveTreeItemData();
      if (target) {
        e.preventDefault();
        if (window.contextMenu) window.contextMenu.classList.remove("show");
        executeDelete(
          target.filepath,
          target.name,
          target.liElement,
          target.isFolder,
        );
      }
    }

    // --- TECLA F2 (RENAME) ---
    if (e.key === "F2") {
      const target = getActiveTreeItemData();
      if (target) {
        e.preventDefault();
        if (window.contextMenu) {
          window.contextMenu.classList.remove("show");
        }
        const newName = await showRenameModal(
          target.isFolder ? "folder" : "file",
          target.name,
        );
        if (newName && newName !== target.name) {
          executeRename(
            target.filepath,
            newName,
            target.liElement,
            target.isFolder,
          );
        }
      }
    }

    // --- Keyboard shortcuts: CTRL+X (Cut) and CTRL+V (Paste) ---
    if (e.ctrlKey && e.key.toLowerCase() === 'x') {
        const target = getActiveTreeItemData();
        if (target) {
            e.preventDefault();
            setClipboard({
                filepath: target.filepath,
                name: target.name,
                isFolder: target.isFolder,
                liElement: target.liElement
            });
            console.log("[IDE] Ctrl+X Cut:", clipboard.filepath);
        }
    }

    if (e.ctrlKey && e.key.toLowerCase() === 'v') {
        const target = getActiveTreeItemData();
        if (target && clipboard.filepath) {
            e.preventDefault();
            let destDirPath = target.isFolder ?
                              target.filepath :
                              target.filepath.substring(0, target.filepath.lastIndexOf('/'));

            const success = await executeMove(clipboard.filepath, destDirPath, clipboard.name);
            if (success) clearClipboard();
        }
    }
    if (e.key === "Escape") {
        hideContextMenu();
        if (clipboard && clipboard.filepath) {
            clearClipboard();
            console.log("[IDE] Canceled Cut/Paste");
        }
        if (window.contextMenu) {
            window.contextMenu.classList.remove('show');
        }
    }
  });
}

function getActiveTreeItemData() {
  const activeLi = document.querySelector(".tree-item.active");
  if (!activeLi) {
    return null;
  }

  const folderLink = activeLi.querySelector("a[data-folder-path]");
  if (folderLink) {
    const path = folderLink.getAttribute("data-folder-path");
    const spans = folderLink.querySelectorAll("span");
    let name = path.split("/").pop();
    if (spans.length > 0) name = spans[spans.length - 1].innerText.trim();
    return {
      filepath: path,
      isFolder: true,
      name: name,
      liElement: activeLi,
    };
  }

  const fileLink = activeLi.querySelector("a");
  if (fileLink && fileLink.href.includes("filepath=")) {
    const url = new URL(fileLink.href, window.location.origin);
    const path = url.searchParams.get("filepath");
    const spans = fileLink.querySelectorAll("span");
    let name = path.split("/").pop();
    if (spans.length > 0) name = spans[spans.length - 1].innerText.trim();
    return { filepath: path, isFolder: false, name: name, liElement: activeLi };
  }

  return null;
}
