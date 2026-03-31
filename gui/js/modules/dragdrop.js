// modules/dragdrop.js

import {executeMove} from "./filesystem.js";

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

  fileTreeContainer.addEventListener("dragstart", (e) => {
    const li = e.target.closest(".tree-item");
    if (!li) {
      return;
    }

    const selectedLinks = Array.from(document.querySelectorAll("a.active-link"));

    const currentLink = li.querySelector("a");

    const isPartOfSelection = currentLink && currentLink.classList.contains("active-link");

    let itemsToDrag = [];

    if (isPartOfSelection && selectedLinks.length > 1) {
      console.log(`[IDE] Multi-Drag: Arrastrando ${selectedLinks.length} elementos.`);
      itemsToDrag = selectedLinks.map(a => {
        const itemLi = a.closest(".tree-item");
        itemLi.classList.add("dragging");
        return {
          filepath: itemLi.classList.contains("folder-item")
            ? a.getAttribute("data-folder-path")
            : new URL(a.href, window.location.origin).searchParams.get("filepath"),
          name: a.textContent.trim(),
          isFolder: itemLi.classList.contains("folder-item")
        };
      });
    } else {
      li.classList.add("dragging");
      const isFolder = li.classList.contains("folder-item");
      const filepath = isFolder
        ? currentLink.getAttribute("data-folder-path")
        : new URL(currentLink.href, window.location.origin).searchParams.get("filepath");
      itemsToDrag = [{
        filepath,
        name: currentLink.textContent.trim(),
        isFolder
      }];
    }

    e.dataTransfer.setData("application/x-ide-internal", JSON.stringify(itemsToDrag));
    e.dataTransfer.effectAllowed = "move";
  });

  fileTreeContainer.addEventListener("dragend", cleanupDragVisuals);

  fileTreeContainer.addEventListener("dragover", (e) => {
    if (!e.dataTransfer.types.includes("application/x-ide-internal")) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = "move";
    const targetLi = e.target.closest(".folder-item");
    if (targetLi) {
      if (!targetLi.classList.contains('drag-over')) {
        document.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
        targetLi.classList.add("drag-over");
      }
    }
  });

  fileTreeContainer.addEventListener("dragleave", (e) => {
    const targetLi = e.target.closest(".folder-item");
    if (targetLi && !targetLi.contains(e.relatedTarget)) {
      targetLi.classList.remove("drag-over");
    }
  });

  fileTreeContainer.addEventListener("drop", async (e) => {
    if (!e.dataTransfer.types.includes("application/x-ide-internal")) {
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    const targetLi = e.target.closest(".folder-item");
    cleanupDragVisuals();
    if (!targetLi) {
      return;
    }
    const destDirPath = targetLi.querySelector("a").getAttribute("data-folder-path");
    try {
      const dragDataString = e.dataTransfer.getData("application/x-ide-internal");
      if (!dragDataString) {
        return;
      }
      const itemsToMove = JSON.parse(dragDataString);
      console.log(`[IDE] Drop masivo: Moviendo ${itemsToMove.length} elementos a ${destDirPath}`);
      spinner();
      for (const item of itemsToMove) {
        if (item.filepath === destDirPath) continue;
        console.log(`[IDE] Moving: ${item.filepath} -> ${destDirPath}`);
        await executeMove(item.filepath, destDirPath, item.name);
      }
    } catch (error) {
      console.error("[IDE] Internal Drag & Drop Error:", error);
    } finally {
      if (typeof hideSpinner === "function") hideSpinner();
    }
  });
}
