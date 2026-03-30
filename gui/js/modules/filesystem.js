// modules/filesystem.js

import { cfg, getBaseFormData } from "./state.js";
import { spinner, hideSpinner } from "./ui.js";
import { showConfirmDialog } from "./modal.js";
import { refreshDir } from "./tree.js";
import { injectItemIntoTree } from "./upload.js";
import { clearDirtyState } from "./editor.js";

// --- UNLOCK ---
export async function executeUnlock(filepath, liElement) {
  spinner();
  const formData = getBaseFormData();
  formData.set("ajax_unlock", "1");
  formData.set("filepath", filepath);

  try {
    const response = await fetch(window.location.href, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    });
    const data = await response.json();

    if (data.success) {
      liElement.querySelector(".lock-icon")?.remove();
      liElement.dataset.flag = "";
      showConfirmDialog(
        "Unlocked",
        "Flags removed successfully. You can now edit or delete the item.",
        "success",
      );
    } else {
      throw new Error(data.error || "Failed to remove flags.");
    }
  } catch (e) {
    console.error("Unlock Error:", e);
    showConfirmDialog("Error", "Failed to unlock: " + e.message, "error");
  } finally {
    hideSpinner();
  }
}
window.executeUnlock = executeUnlock;

// --- BULK DELETE ---
export async function executeDelete(items) {
    // 1. MODAL LOGIC: Check if it's one or many
    const isMultiple = items.length > 1;
    let modalTitle, modalMessage;

    if (isMultiple) {
        modalTitle = `Delete ${items.length} items?`;
        modalMessage = `Are you sure you want to delete these ${items.length} selected items?\nThis operation cannot be undone!`;
    } else {
        const item = items[0];
        const shortName = item.filename.length > 35
            ? item.filename.substring(0, 18) + "..." + item.filename.substring(item.filename.length - 12)
            : item.filename;
        modalTitle = item.isFolder ? "Delete Directory" : `Delete "${shortName}"?`;
        modalMessage = `Are you sure you want to delete "${shortName}"?`;
    }

    const ok = await showConfirmDialog(modalTitle, modalMessage, "delete");
    if (!ok) return;

    spinner();

    const formData = getBaseFormData();
    formData.set("ajax_delete", "1");
    formData.set("delete_file", "1");

    // 2. DATA LOGIC: We send a JSON array to the PHP
    const paths = items.map(i => i.filepath);
    formData.set("filepaths", JSON.stringify(paths));

    // Fallback for single file (backwards compatibility for the PHP)
    if (!isMultiple) {
        formData.set("filepath", items[0].filepath);
    }

    try {
        const response = await fetch(window.location.href, {
            method: "POST",
            body: formData,
        });

        const data = await response.json();

        if (data.success) {
            // Remove all elements from the sidebar
            items.forEach(item => {
                item.liElement.style.transition = "opacity 0.2s, height 0.2s";
                item.liElement.style.opacity = "0";
                setTimeout(() => item.liElement.remove(), 200);
            });

            // If the active file in the editor was deleted, clear it
            const currentOpenFile = document.querySelector('input[name="filepath"]')?.value;
            const wasOpenedFileDeleted = items.some(i => currentOpenFile === i.filepath);

            if (wasOpenedFileDeleted) {
                window.editor?.setValue("# Item deleted.\n# Select another file from the sidebar.");
                window.editor?.updateOptions({ readOnly: true });
                if (typeof clearDirtyState === 'function') clearDirtyState();
                const container = document.querySelector(".ide-filepath-display");
                if (container) container.innerHTML = `<span style="color: #d32f2f; font-weight: bold;">Deleted</span>`;
            }
        } else {
            throw new Error(data.error || "Failed to delete items.");
        }
    } catch (error) {
        console.error("Delete Error:", error);
        showConfirmDialog("Error", error.message, "error");
    } finally {
        hideSpinner();
    }
}

export async function executeRename(oldFilepath, newName, liElement, isFolder) {
  if (!oldFilepath || !newName || !liElement) {
    return;
  }

  spinner();

  try {
      const formData = getBaseFormData();
      formData.append('rename_file', '1');
      formData.append('ajax_rename', '1');
      formData.append('filepath', oldFilepath);
      formData.append('newname', newName);

      const response = await fetch(window.location.pathname, {
          method: 'POST',
          body: formData,
          credentials: "same-origin"
      });

      const result = await response.json();

      if (result.success) {
        console.log(`[IDE] Renamed successfully: ${oldFilepath} -> ${result.newpath}`);
        let basePath = oldFilepath.substring(0, oldFilepath.lastIndexOf('/'));
        if (!basePath || basePath === '') {
            basePath = cfg.jailRoot;
        }
        await refreshDir(basePath);
        const currentUrl = new URL(window.location.href);
        if (currentUrl.searchParams.get('filepath') === oldFilepath) {
            currentUrl.searchParams.set('filepath', result.newpath);
            window.history.replaceState({ filepath: result.newpath }, '', currentUrl.toString());
            window.updateBreadcrumbs(result.newpath);
        }
      } else {
          showConfirmDialog('Rename Error', result.error || 'Could not rename item.', 'error');
      }
  } catch (error) {
      console.error("AJAX Rename Error:", error);
      showConfirmDialog('Rename Error', 'Server connection failed. Check console.', 'error');
  } finally {
      hideSpinner();
  }
}

// --- CREATE ITEM ---
export async function executeCreateItem(name, type, targetData) {
  spinner();

  const parentPath = targetData.isFolder
    ? targetData.filepath
    : targetData.filepath.substring(0, targetData.filepath.lastIndexOf("/"));

  const formData = getBaseFormData();
  formData.set("ajax_create_item", "1");
  formData.set("parent_dir", parentPath);
  formData.set("new_name", name);
  formData.set("type", type);

  try {
    const response = await fetch(window.location.href, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    });
    const data = await response.json();

    if (data.success) {
      await refreshDir(parentPath, name, type === "folder");
    } else {
      throw new Error(data.error || "Failed to create item.");
    }
  } catch (e) {
    console.error("Create Error:", e);
    showConfirmDialog("Error", e.message, "error");
  } finally {
    hideSpinner();
  }
}
window.executeCreateItem = executeCreateItem;

export async function executeMove(sourcePath, destDirPath, itemName) {
    if (!sourcePath || !destDirPath || !itemName) return;

    const sourceBaseDir = sourcePath.substring(0, sourcePath.lastIndexOf('/'));
    if (sourceBaseDir === destDirPath) {
        console.log("[IDE] The file is already in that directory..");
        return false;
    }

    if (destDirPath.startsWith(sourcePath + '/')) {
        showConfirmDialog('Move Error', 'Cannot move a directory into its own subdirectory.', 'error');
        return false;
    }

    spinner();

    try {
        const formData = getBaseFormData();
        formData.append('move_item', '1');
        formData.append('ajax_move', '1');
        formData.append('source', sourcePath);
        formData.append('dest_dir', destDirPath);
        formData.append('name', itemName);

        const response = await fetch(window.location.pathname, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const result = await response.json();

        if (result.success) {
            console.log(`[IDE] Moved successfully: ${sourcePath} -> ${result.newpath}`);
            await refreshDir(sourceBaseDir || cfg.jailRoot);
            await refreshDir(destDirPath);
            const currentUrl = new URL(window.location.href);
            if (currentUrl.searchParams.get('filepath') === sourcePath) {
                currentUrl.searchParams.set('filepath', result.newpath);
                window.history.replaceState({ filepath: result.newpath }, '', currentUrl.toString());
                if (typeof window.updateBreadcrumbs === 'function') window.updateBreadcrumbs(result.newpath);
            }
            return true;
        } else {
            showConfirmDialog('Move Error', result.error || 'Could not move item.', 'error');
            return false;
        }

    } catch (error) {
        console.error("AJAX Move Error:", error);
        showConfirmDialog('Move Error', 'Server connection failed.', 'error');
        return false;
    } finally {
        hideSpinner();
    }
}