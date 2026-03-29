// modules/dragdrop.js

import { executeMove } from "./filesystem.js";

export function initDragAndDrop() {
  const fileTreeContainer =
    document.querySelector(".ide-file-list") ||
    document.getElementById("fileList");
  if (!fileTreeContainer) {
    return;
  }

  const cleanupDragVisuals = () => {
    document.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
    document.querySelectorAll('.dragging').forEach(el => el.classList.remove('dragging'));
    document.querySelectorAll('.drag-target').forEach(el => el.classList.remove('drag-target'));
    document.body.classList.remove('is-dragging');
  };

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      cleanupDragVisuals();
    }
  });

  // 1. LET'S START THE INTERNAL SEARCH
  fileTreeContainer.addEventListener("dragstart", (e) => {
    const li = e.target.closest(".tree-item");
    if (!li) return;

    li.classList.add("dragging");

    const isFolder = li.classList.contains("folder-item");
    const link = li.querySelector("a");

    let filepath = isFolder
      ? link.getAttribute("data-folder-path")
      : new URL(link.href, window.location.origin).searchParams.get("filepath");

    const spans = link.querySelectorAll("span");
    const name =
      spans.length > 0
        ? spans[spans.length - 1].innerText.trim()
        : filepath.split("/").pop();

    const dragData = { filepath, name, isFolder };
    e.dataTransfer.setData(
      "application/x-ide-internal",
      JSON.stringify(dragData),
    );
    e.dataTransfer.effectAllowed = "move";
  });

  fileTreeContainer.addEventListener("dragend", () => {
    cleanupDragVisuals();
  });

  fileTreeContainer.addEventListener("dragover", (e) => {
    // We only react if it's an internal drag
    if (!e.dataTransfer.types.includes("application/x-ide-internal")) {
      return;
    }

    e.preventDefault();
    e.dataTransfer.dropEffect = "move";

    const targetLi = e.target.closest(".folder-item");
    if (targetLi) {
      cleanupDragVisuals();
      targetLi.classList.add("drag-over");
    }
  });

  fileTreeContainer.addEventListener("dragleave", (e) => {
    if (!e.dataTransfer.types.includes("application/x-ide-internal")) {
      return;
    }

    const targetLi = e.target.closest(".folder-item");
    if (targetLi && !targetLi.contains(e.relatedTarget)) {
      targetLi.classList.remove("drag-over");
    }
  });

  // DROP THE FILE (INTERNAL DROP)
  fileTreeContainer.addEventListener("drop", async (e) => {
    // Is it an internal call? If not, we wash our hands of it and let upload.js handle it
    if (!e.dataTransfer.types.includes("application/x-ide-internal")) {
      return;
    }

    e.preventDefault();
    e.stopPropagation();

    const targetLi = e.target.closest(".folder-item");

    cleanupDragVisuals();

    if (!targetLi) return;

    const destLink = targetLi.querySelector("a");
    const destDirPath = destLink.getAttribute("data-folder-path");

    try {
      const dragDataString = e.dataTransfer.getData(
        "application/x-ide-internal",
      );
      if (!dragDataString) {
        return;
      }
      const sourceData = JSON.parse(dragDataString);

      console.log(
        `[IDE] Moving internally: ${sourceData.filepath} -> ${destDirPath}`,
      );
      await executeMove(sourceData.filepath, destDirPath, sourceData.name);
    } catch (error) {
      console.error("[IDE] Internal Drag & Drop Error:", error);
    }
  });
}
