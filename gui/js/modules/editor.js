// modules/editor.js

import {
  cfg,
  isDirty,
  isInjectingCode,
  setIsDirty,
  setIsInjectingCode,
  diffEditorInstance,
  currentDiffFilepath,
  setDiffEditorInstance,
  setCurrentDiffFilepath,
  getBaseFormData,
} from "./state.js";
import { spinner, hideSpinner } from "./ui.js";
import { showConfirmDialog } from "./modal.js";

const MONACO_PATH = "/ext/bastille/js/modules/monaco/vs";

export const BINARY_EXTS = new Set([
  "png", "jpg", "jpeg", "gif", "svg", "ico", "mp3", "mp4", "mkv", "avi", "mov",
  "wav", "flac", "iso", "gz", "zip", "tar", "rar", "7z", "pdf", "bin", "exe",
  "dll", "so", "db", "sqlite"
]);

const extensions = {
    image: ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'bmp'],
    video: ['mp4', 'webm', 'ogg'],
    // Tomorrow['mp3', 'wav']
};

// --- LANGUAGE DETECTION ---
function detectLang(filepath) {
  const ext = filepath.split(".").pop().toLowerCase();
  if (["php", "inc"].includes(ext)) return "php";
  if (ext === "xml") return "xml";
  if (ext === "js") return "javascript";
  if (ext === "css") return "css";
  if (ext === "json") return "json";
  if (["html", "htm"].includes(ext)) return "html";
  return "shell";
}

// --- MONACO INIT ---
export function initMonaco() {
  if (typeof require === "undefined") return;

  require.config({
    paths: { vs: MONACO_PATH },
    ignoreDuplicateModules: ["vs/editor/editor.main"],
  });

  window.MonacoEnvironment = {
    getWorkerUrl() {
      const base = window.location.origin + MONACO_PATH;
      const workerCode = `self.MonacoEnvironment = { baseUrl: '${base}' }; importScripts('${base}/base/worker/workerMain.js');`;
      return `data:text/javascript;charset=utf-8,${encodeURIComponent(workerCode)}`;
    },
  };

  require(["vs/editor/editor.main"], () => {
    const filepath = cfg.filepath || "";
    const fileContent = filepath
      ? document.getElementById("file_content")?.value || ""
      : "# Welcome to Bastille Editor\n# Select a file from the sidebar to start editing.";
    const lang = filepath ? detectLang(filepath) : "shell";

    window.editor = monaco.editor.create(
      document.getElementById("monaco-container"),
      {
        value: fileContent,
        language: lang,
        theme: "vs",
        automaticLayout: true,
        wordWrap: "on",
        minimap: { enabled: true },
        fontSize: 11,
        readOnly: filepath === "",
      },
    );

    window.editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, async () => {
        await executeSaved();
      },
    );

    const btnSave = document.getElementById('btn_save');
    if (btnSave) {
      btnSave.addEventListener('click', (e) => {
        e.preventDefault();
        executeSaved();
      });
    }

    window.editor.onDidChangeModelContent(() => {
      if (isInjectingCode) {
        return;
      }
      if (!isDirty) {
        setIsDirty(true);
        const saveBtn = document.getElementById("btn_save");
        if (saveBtn) {
            saveBtn.disabled = false;
        }
        const activeFileLink = document.querySelector(".tree-item.active > a");
        if (activeFileLink && !activeFileLink.querySelector(".dirty-dot")) {
          const dotContainer = document.createElement("span");
          dotContainer.className = "dirty-ping-indicator dirty-dot";
          dotContainer.innerHTML = `
            <span class="ping-bg"></span>
            <span class="ping-core"></span>
          `;
          activeFileLink.appendChild(dotContainer);
        }
        console.log("[IDE] Document modified. 'Dirty' status enabled");
      }
    });

    // Suppress Monaco internal Canceled errors
    if (!window.monacoErrorHandlerSet) {
      monaco.editor.onDidCreateEditor(() => {});
      window.monacoErrorHandlerSet = true;
    }
  });
}

// --- SAVE ---
export async function executeSaved() {
  if (!window.editor || !isDirty) return;

  const urlParams = new URLSearchParams(window.location.search);
  const currentFilepath = urlParams.get("filepath") || document.querySelector('input[name="filepath"]')?.value;

  if (!currentFilepath || currentFilepath === "Select a file") {
    await showConfirmDialog(
      "Error",
      "No file selected to save or form missing.",
      "error",
    );
    return;
  }

  const saveBtn = document.getElementById("btn_save");
  if (saveBtn) {
    saveBtn.disabled = true;
    saveBtn.value = "Saving...";
  }

  const formData = getBaseFormData();
  formData.set("ajax_save", "1");
  formData.set("file_content", window.editor.getValue());
  formData.set("filepath", currentFilepath);
  formData.set("save", "1");

  try {
    const response = await fetch(window.location.href, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    });

    if (!response.ok) {
      const errText = await response.text();
      throw new Error(
        `Server returned status ${response.status}. Details: ${errText.substring(0, 50)}...`,
      );
    }

    const data = await response.json();

    if (data.success) {
      clearDirtyState();
      await showConfirmDialog("Saved", "Saved file to " + currentFilepath, "success");
    } else {
      throw new Error(data.error || "Server rejected the save request.");
    }
  } catch (error) {
    console.error("Save Error:", error);
    const isSessionErr = error.message.includes("Unexpected token");
    await showConfirmDialog(
      isSessionErr ? "Session Error" : "Save Error",
      isSessionErr ? "Your session might have expired. Please reload." : error.message,
      "error",
    );
  } finally {
    if (saveBtn) {
      saveBtn.disabled = !isDirty;
      saveBtn.value = "Save File";
    }
    hideSpinner();
  }
}

export function clearDirtyState() {
  setIsDirty(false);
  document.querySelectorAll(".dirty-dot").forEach((dot) => dot.remove());
  document.title = document.title.replace("* ", "");
  const saveBtn = document.getElementById("btn_save");
  if (saveBtn) {
    saveBtn.disabled = true;
    saveBtn.value = "Save File";
  }
}

// --- DIFF VIEWER ---
export function initDiffModal() {
  const diffModal = document.createElement("div");
  diffModal.id = "ide-diff-modal";
  diffModal.className = "diff-modal-overlay";
  diffModal.innerHTML = `
        <div class="diff-modal-content">
            <div class="diff-modal-header lhetop">
                <div>
                    <span id="diff-modal-header-title" class="diff-modal-header-title">History Compare:</span>
                    <span id="diff-filename" class="diff-filename">filename.php</span>
                    <select id="diff-backup-select">
                        <option value="">Loading backups...</option>
                    </select>
                </div>
                <div style="display:flex; gap: 15px; align-items: center;">
                    <span id="ide-diff-maximize" title="Maximize / Restore">
                        <img src="ext/bastille/images/fullscreen.svg" class="icon-svg fullscreen-icon-darkbg" alt="Fullscreen" style="width: 16px; height: 16px;">
                    </span>
                    <button class="diff-close-x" onclick="closeDiffViewer()" title="Close">&times;</button>
                </div>
            </div>
            <div class="diff-modal-body" id="diff-monaco-container"></div>
        </div>`;
  document.body.appendChild(diffModal);

  document.getElementById("ide-diff-maximize").addEventListener("click", () => {
    document.getElementById("ide-diff-modal").classList.toggle("maximized");
    setTimeout(() => diffEditorInstance?.layout(), 50);
  });
}

export async function openDiffViewer(filepath, filename) {
  spinner();

  diffEditorInstance?.setModel(null);
  setCurrentDiffFilepath(filepath);

  document.getElementById("diff-filename").innerText = filename;
  const selectEl = document.getElementById("diff-backup-select");
  selectEl.innerHTML = '<option value="">Loading backups...</option>';
  selectEl.disabled = true;

  document.getElementById("ide-diff-modal").style.display = "flex";

  if (!diffEditorInstance) {
    setDiffEditorInstance(
      monaco.editor.createDiffEditor(
        document.getElementById("diff-monaco-container"),
        {
          theme: "vs",
          readOnly: true,
          automaticLayout: true,
          renderSideBySide: true,
          renderOverviewRuler: false,
          ignoreTrimWhitespace: false,
          minimap: { enabled: false },
          scrollbar: { verticalScrollbarSize: 10, horizontalScrollbarSize: 10 },
        },
      ),
    );
  }

  const formData = getBaseFormData();
  formData.append("ajax_get_backups", "1");
  formData.append("filepath", filepath);

  try {
    const response = await fetch(window.location.href, {
      method: "POST",
      body: formData,
    });
    const rawText = await response.text();
    let data;
    try {
      data = JSON.parse(rawText);
    } catch {
      console.error("SERVER REJECTED DIFF REQUEST:", rawText);
      throw new Error("Invalid server response.");
    }

    if (data.success && data.backups?.length > 0) {
      selectEl.innerHTML = "";
      data.backups.forEach((bak) => {
        const opt = document.createElement("option");
        opt.value = bak.path;
        opt.innerText = bak.date;
        selectEl.appendChild(opt);
      });
      selectEl.disabled = false;
      await loadBackupDiff(data.backups[0].path);
    } else {
      selectEl.innerHTML = '<option value="">No history found</option>';
      showConfirmDialog(
        "Backup not found",
        "No backup file found or could not read it.",
        "success",
      );
      hideSpinner();
    }
  } catch (err) {
    console.error("Diff Engine Error:", err);
    showConfirmDialog("Diff Engine Error", err, "error");
    selectEl.innerHTML = '<option value="">Error loading history</option>';
  }
}
window.openDiffViewer = openDiffViewer;

export async function loadBackupDiff(backupPath) {
  spinner();
  const formData = getBaseFormData();
  formData.append("ajax_read_backup", "1");
  formData.append("bak_path", backupPath);

  try {
    const resBak = await fetch(window.location.href, {
      method: "POST",
      body: formData,
    });
    const dataBak = await resBak.json();

    if (!dataBak.success) {
      diffEditorInstance?.setModel(null);
      showConfirmDialog(
        "Backup not found",
        dataBak.error || "No backup file found.",
        "success",
      );
      return;
    }

    const activeFilepath = document.querySelector(
      'input[name="filepath"]',
    )?.value;
    let currentContent = "";

    if (currentDiffFilepath === activeFilepath && window.editor) {
      currentContent = window.editor.getValue();
    } else {
      const resCur = await fetch(
        `${window.location.pathname}?ajax=1&filepath=${encodeURIComponent(currentDiffFilepath)}`,
      );
      if (resCur.ok) currentContent = await resCur.text();
      else {
        showConfirmDialog(
          "Error",
          "Could not load the current file for comparison.",
          "error",
        );
        return;
      }
    }

    const lang = detectLang(currentDiffFilepath);
    const originalModel = monaco.editor.createModel(dataBak.content, lang);
    const modifiedModel = monaco.editor.createModel(currentContent, lang);

    diffEditorInstance.setModel({
      original: originalModel,
      modified: modifiedModel,
    });
  } catch (err) {
    console.error("Diff Load Error:", err);
    showConfirmDialog("Diff Load Error", err, "error");
  } finally {
    hideSpinner();
  }
}
window.loadBackupDiff = loadBackupDiff;

export function closeDiffViewer() {
  document.getElementById("ide-diff-modal").style.display = "none";
}
window.closeDiffViewer = closeDiffViewer;

export function showBinaryWarning(filepath) {
  if (!window.editor) {
    return;
  }

  const filename = filepath.split("/").pop();
  const warning = `/*
 * BASTILLE EDITOR WARNING
 * ------------------------
 * The file '${filename}' is a binary or media file.
 * It cannot be safely displayed or edited in a text editor.
 */`;
  setIsInjectingCode(true);
  window.editor.setValue(warning);
  window.editor.updateOptions({ readOnly: true });
  setIsInjectingCode(false);
  setIsDirty(false);
}

/**
* Deep link
*/
export async function loadFileToEditor(filepath, linkHref) {
  console.log("[DEBUG] Loading:", filepath);
  spinner();

  // Containers references
  const monacoContainer = document.getElementById('monaco-container');
  const mediaContainer = document.getElementById('media-preview-container');

  try {
    // 1. Determine file type
    const ext = filepath.split('.').pop().toLowerCase();
    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico'].includes(ext);
    const isVideo = ['mp4', 'webm', 'ogg'].includes(ext);

    // 2. Construct Base URL
    let url;
    if (!linkHref || linkHref === "undefined" || typeof linkHref !== "string") {
      console.log("[IDE] Deep Link detected, generating URL for:", filepath);
      url = new URL(window.location.pathname, window.location.origin);
      url.searchParams.set("jailname", cfg.jailname);
      url.searchParams.set("filepath", filepath);
      const dir = filepath.substring(0, filepath.lastIndexOf("/"));
      url.searchParams.set("dir", dir);
    } else {
      url = new URL(linkHref, window.location.origin);
    }

    // 3. Branching Logic: Media vs Text
    if (isImage || isVideo) {
      // --- MEDIA VIEW ---
      if (monacoContainer) {
        monacoContainer.style.display = 'none';
      }
      if (mediaContainer) {
        mediaContainer.style.display = 'flex';

        // Use the action for binary streaming
        const streamUrl = new URL(url.toString());
        streamUrl.searchParams.set("action", "stream_binary");

        if (isImage) {
          mediaContainer.innerHTML = `<img src="${streamUrl.toString()}" alt="Preview" class="img-preview" />`;
        } else if (isVideo) {
          mediaContainer.innerHTML = `
            <video controls autoplay class="video-preview">
              <source src="${streamUrl.toString()}" type="video/mp4">
              Your browser does not support the video tag.
            </video>`;
        }
      }
    } else {
      // --- TEXT VIEW (Standard Editor) ---
      if (mediaContainer) {
        mediaContainer.style.display = 'none';
        mediaContainer.innerHTML = '';
      }
      if (monacoContainer) {
        monacoContainer.style.display = 'block';
      }

      url.searchParams.set("ajax", "1");

      const response = await fetch(url.toString());
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      const content = await response.text();

      if (window.editor) {
        setIsInjectingCode(true);
        window.editor.setValue(content);
        window.editor.updateOptions({ readOnly: false });
        setIsInjectingCode(false);
        clearDirtyState();
      }
    }

    // 4. Common: Refresh the address bar and breadcrumbs
    const cleanUrl = new URL(url.toString());
    cleanUrl.searchParams.delete("ajax");
    cleanUrl.searchParams.delete("action");
    window.history.pushState({ filepath }, "", cleanUrl.toString());
    updateBreadcrumbs(filepath);

  } catch (err) {
    console.error("[DEBUG] Error in loadFileToEditor:", err);
  } finally {
    hideSpinner();
  }
}

// --- PREVENT DATA LOSS ON F5 ---
export function initBeforeUnload() {
  window.addEventListener("beforeunload", (e) => {
    if (isDirty) {
      e.preventDefault();
      return "";
    }
  });
}
