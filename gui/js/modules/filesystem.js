// modules/filesystem.js

import { cfg } from "./state.js";
import { spinner, hideSpinner } from "./ui.js";
import { showConfirmDialog } from "./modal.js";
import { clearDirtyState, refreshDir } from "./tree.js";
import { injectItemIntoTree } from "./upload.js";

// --- UNLOCK ---
export async function executeUnlock(filepath, liElement) {
  spinner();
  const form = document.getElementById("iform");
  const formData = new FormData(form);
  formData.set("ajax_unlock", "1");
  formData.set("filepath", filepath);
  formData.set("jailname", cfg.jailname);

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

// --- DELETE ---
export async function executeDelete(
  filepath,
  fileName,
  liElement,
  isFolder = false,
) {
  const shortName =
    fileName.length > 35
      ? fileName.substring(0, 18) +
        "..." +
        fileName.substring(fileName.length - 12)
      : fileName;

  const modalTitle = isFolder ? "Delete Directory" : `Delete "${shortName}"?`;
  const modalMessage = isFolder
    ? `Delete directory "${shortName}"?\nAll files and subdirectories in "${fileName}" will be deleted.\nYou might not be able to fully undo this operation!`
    : `Are you sure you want to delete "${shortName}"?`;

  const ok = await showConfirmDialog(modalTitle, modalMessage, "delete");
  if (!ok) return;

  spinner();

  const form = document.getElementById("iform");
  if (!form) {
    console.error("Critical: #iform not found.");
    hideSpinner();
    return;
  }

  const formData = new FormData(form);
  formData.set("ajax_delete", "1");
  formData.set("delete_file", "1");
  formData.set("filepath", filepath);
  formData.set("jailname", cfg.jailname);

  try {
    const response = await fetch(window.location.href, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    });

    if (!response.ok)
      throw new Error(`Server returned status ${response.status}`);

    const data = await response.json();

    if (data.success) {
      liElement.style.transition = "opacity 0.2s, height 0.2s";
      liElement.style.opacity = "0";
      setTimeout(() => liElement.remove(), 200);

      const currentOpenFile = document.querySelector(
        'input[name="filepath"]',
      )?.value;
      if (currentOpenFile?.startsWith(filepath)) {
        window.editor?.setValue(
          "# Item deleted.\n# Select another file from the sidebar.",
        );
        window.editor?.updateOptions({ readOnly: true });
        clearDirtyState();
        const container = document.querySelector(".ide-filepath-display");
        if (container)
          container.innerHTML = `<span style="color: #d32f2f; font-weight: bold;">Deleted</span>`;
      }
    } else {
      throw new Error(data.error || "Failed to delete item.");
    }
  } catch (error) {
    console.error("Delete Error:", error);
    showConfirmDialog("Error", error.message, "error");
  } finally {
    hideSpinner();
  }
}
window.executeDelete = executeDelete;

export async function executeRename(oldFilepath, newName, liElement, isFolder) {
  if (!oldFilepath || !newName || !liElement) {
    return;
  }

  spinner();

  try {
      const authToken = document.querySelector('input[name="authtoken"]')?.value || '';
      const formData = new FormData();
      formData.append('rename_file', '1');
      formData.append('ajax_rename', '1');
      formData.append('jailname', cfg.jailname);
      formData.append('filepath', oldFilepath);
      formData.append('newname', newName);
      formData.append('authtoken', authToken);

      const response = await fetch(window.location.pathname, {
          method: 'POST',
          body: formData
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

  const form = document.getElementById("iform");
  const formData = new FormData(form);
  formData.set("ajax_create_item", "1");
  formData.set("parent_dir", parentPath);
  formData.set("new_name", name);
  formData.set("type", type);
  formData.set("jailname", cfg.jailname);

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
