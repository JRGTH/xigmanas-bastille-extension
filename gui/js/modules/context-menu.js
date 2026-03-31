// modules/context-menu.js

import {
  cfg,
  clearClipboard,
  cmTargetData,
  contextMenu,
  setClipboard,
  setCmTargetData,
  setContextMenu,
} from "./state.js";
import {hideSpinner, spinner} from "./ui.js";
import {showConfirmDialog, showNewItemModal, showRenameModal,} from "./modal.js";
import {refreshDir} from "./tree.js";
import {executeCreateItem, executeDelete, executeMove, executeRename, executeUnlock,} from "./filesystem.js";
import {executeDownloadRequest, showNotification} from "./download.js";
import {showFileInfo} from "./sidebar-info.js";
import {openDiffViewer} from "./editor.js";

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
  if (cm) {
    cm.style.display = "none";
  }
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
      // LA CLAVE: Verificamos si tiene CUALQUIERA de las dos clases de selección
      const isAlreadySelected =
        liElement.classList.contains("active") ||
        liElement.classList.contains("is-selected-target") ||
        link.classList.contains("active-link") ||
        link.classList.contains("active");
      if (!isAlreadySelected) {
        console.log("[CM] Target not selected. Clearing ALL previous highlights.");
        document.querySelectorAll(".is-selected-target, .tree-item.active, .active-link").forEach(el => {
          el.classList.remove("is-selected-target", "active", "active-link");
        });
        liElement.classList.add("is-selected-target", "active");
        link.classList.add("active-link");
        window.lastSelectedTreeItem = liElement;
      } else {
        console.log("[CM] Target already selected. Keeping current selection group.");
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
  document.getElementById('cm-cut')?.addEventListener('click', (e) => {
    e.stopPropagation();
    hideContextMenu();
    if (!cmTargetData) {
      return;
    }
    executeCutAction(cmTargetData);
  });

  // --- PASTING LOGIC ---
  document.getElementById("cm-paste")?.addEventListener("click", async () => {
    hideContextMenu();
    if (!window.clipboard || window.clipboard.length === 0) return;

    let destDirPath = cmTargetData.isFolder ?
      cmTargetData.filepath :
      cmTargetData.filepath.substring(0, cmTargetData.filepath.lastIndexOf('/'));

    spinner();
    for (const item of window.clipboard) {
      const success = await executeMove(item.filepath, destDirPath, item.name, true);
      if (success && item.liElement) item.liElement.remove();
    }
    clearClipboard();
    await refreshDir(destDirPath);
    hideSpinner();
  });

  document.getElementById("cm-delete-file").addEventListener("click", async (e) => {
    e.stopPropagation();
    hideContextMenu();
    if (!cmTargetData) return;

    // 1. LA CLAVE: Buscamos por los enlaces (a.active-link) que SÍ sobrevivieron
    const selectedLinks = Array.from(document.querySelectorAll("a.active-link"));

    // 2. Sacamos los <li> padres de esos enlaces
    const uniqueItems = [...new Set(selectedLinks.map(a => a.closest(".tree-item")).filter(Boolean))];

    // 3. Comprobamos si el target es parte del grupo
    const isTargetInSelection = cmTargetData.liElement.querySelector("a")?.classList.contains("active-link") ||
      cmTargetData.liElement.classList.contains("active") ||
      cmTargetData.liElement.classList.contains("is-selected-target");

    if (uniqueItems.length > 1 && isTargetInSelection) {
      console.log(`[IDE] BULK DELETE MODE: Preparando ${uniqueItems.length} archivos.`);
      const itemsToDelete = uniqueItems.map(li => {
        const link = li.querySelector("a");
        return {
          filepath: li.classList.contains("folder-item")
            ? link.getAttribute("data-folder-path")
            : new URL(link.href, window.location.origin).searchParams.get("filepath"),
          filename: li.textContent.trim(),
          liElement: li,
          isFolder: li.classList.contains("folder-item")
        };
      });
      await executeDelete(itemsToDelete);
    } else {
      console.log(`[IDE] SINGLE DELETE MODE.`);
      await executeDelete([{
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
        executeCreateItem(name, type, {filepath: targetPath, isFolder: true});
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

    // 1. CAPTURE THE ENTIRE CURRENT SELECTION
    const selectedLinks = Array.from(document.querySelectorAll("a.active-link"));
    const uniqueItems = [...new Set(selectedLinks.map(a => a.closest(".tree-item")).filter(Boolean))];

    const items = uniqueItems.map(li => {
      const link = li.querySelector("a");
      return {
        filepath: li.classList.contains("folder-item") ? link.getAttribute("data-folder-path") : new URL(link.href, window.location.origin).searchParams.get("filepath"),
        filename: li.textContent.trim(),
        liElement: li,
        isFolder: li.classList.contains("folder-item")
      };
    });

    // --- DELETE (TECLA SUPRIMIR) ---
    if (e.key === "Delete") {
      if (items.length > 0) {
        e.preventDefault();
        executeDelete(items);
        console.log(`[IDE] Deleted item: ${items.length} items`);
      }
    }

    // --- CUT (CTRL + X) ---
    if (e.ctrlKey && e.key.toLowerCase() === "x") {
      e.preventDefault();
      executeCutAction();
    }

    // --- PASTE (CTRL + V) ---
    if (e.ctrlKey && e.key.toLowerCase() === "v") {
      if (window.clipboard && window.clipboard.length > 0) {
        e.preventDefault();
        // Determinamos destino: si no hay nada seleccionado, usamos el root o el último dir
        const target = getActiveTreeItemData(); // Para saber DÓNDE pegar
        let destDirPath = target?.isFolder
          ? target.filepath
          : target?.filepath.substring(0, target.filepath.lastIndexOf("/")) ||
          cfg.jailRoot;

        // Ejecutamos el pegado masivo
        for (const item of window.clipboard) {
          await executeMove(item.filepath, destDirPath, item.name, true);
          if (item.liElement) item.liElement.remove();
        }
        clearClipboard();
        await refreshDir(destDirPath);
      }
    }

    // --- ESCAPE ---
    if (e.key === "Escape") {
      hideContextMenu();
      clearClipboard();
      document
        .querySelectorAll(
          ".tree-item.active, .active-link, .is-selected-target",
        )
        .forEach((el) => {
          el.classList.remove("active", "active-link", "is-selected-target");
        });
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
  });
}

function executeCutAction(targetData = null) {
  const selectedLinks = Array.from(document.querySelectorAll("a.active-link"));
  const uniqueItems = [...new Set(selectedLinks.map(a => a.closest(".tree-item")).filter(Boolean))];

  let itemsToCut = [];

  // 2. This makes sense if we're coming from the right-click (context menu)
  if (targetData) {
    const isTargetInSelection = targetData.liElement.querySelector("a")?.classList.contains("active-link") ||
      targetData.liElement.classList.contains("active") ||
      targetData.liElement.classList.contains("is-selected-target");

    if (uniqueItems.length > 1 && isTargetInSelection) {
      console.log(`[IDE] BULK CUT (Menu): Cutting ${uniqueItems.length} elements.`);
      itemsToCut = uniqueItems; // We will process the list
    } else {
      console.log(`[IDE] SINGLE CUT (Menu).`);
      // Fallback: We cut only where we right-clicked
      setClipboard([{
        filepath: targetData.filepath,
        name: targetData.filename,
        isFolder: targetData.isFolder,
        liElement: targetData.liElement
      }]);
      return;
    }
  } else {
    if (uniqueItems.length > 0) {
      console.log(`[IDE] CUT (Keyboard): Cut ${uniqueItems.length} elements.`);
      itemsToCut = uniqueItems;
    } else {
      console.log(`[IDE] CUT canceled: Nothing is selected.`);
      return;
    }
  }

  const clipboardData = itemsToCut.map(li => {
    const link = li.querySelector("a");
    return {
      filepath: li.classList.contains("folder-item")
        ? link.getAttribute("data-folder-path")
        : new URL(link.href, window.location.origin).searchParams.get("filepath"),
      name: li.textContent.trim(),
      isFolder: li.classList.contains("folder-item"),
      liElement: li
    };
  });

  setClipboard(clipboardData);
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
    return {filepath: path, isFolder: false, name: name, liElement: activeLi};
  }

  return null;
}
