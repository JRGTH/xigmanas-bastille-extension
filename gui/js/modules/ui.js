// modules/ui.js

import { cfg } from "./state.js";
import { syncSidebarWithFile, syncSidebarWithFolder } from "./tree.js";

// --- UNHANDLED REJECTION FILTER ---
window.addEventListener("unhandledrejection", (event) => {
  if (event.reason?.name === "Canceled") event.preventDefault();
});

// --- SIDEBAR TOGGLE ---
export function toggleSidebar() {
  const container = document.querySelector(".ide-container");
  if (container) {
    container.classList.toggle("sidebar-hidden");
    setTimeout(() => window.editor?.layout(), 200);
  }
}
window.toggleSidebar = toggleSidebar;

// --- SPINNER ---
export function spinner() {
    var overlay = document.getElementById('spinner_overlay');
    overlay.style.display = 'block';
    var target = document.getElementById('spinner_main');
    if (typeof Spinner !== 'undefined') {
        var opts = {
            lines: 10, length: 7, width: 4, radius: 10, color: '#4D4D4D',
            opacity: 0.45, speed: 1, trail: 60, zIndex: 2e9,
            top: '50%', left: '50%', position: 'fixed'
        };
        new Spinner(opts).spin(target);
    }
}

// --- HIDE SPINNER ---
export function hideSpinner() {
    var overlay = document.getElementById('spinner_overlay');
    var target = document.getElementById('spinner_main');
    if (overlay) {
        overlay.style.setProperty("display", "none", "important");
    }
    if (target) {
        target.innerHTML = '';
    }
}

// --- RESIZER ---
export function initResizer() {
  const resizer = document.getElementById("ide-resizer");
  const container = document.querySelector(".ide-container");
  if (!resizer || !container) {
    return;
  }

  const resize = (e) => {
    const newWidth = e.clientX - container.getBoundingClientRect().left;
    if (newWidth > 180 && newWidth < 600) {
      container.style.gridTemplateColumns = `${newWidth}px 4px 1fr`;
      window.editor?.layout();
    }
  };

  const stopResize = () => {
    document.removeEventListener("mousemove", resize);
    resizer.classList.remove("resizing");
  };

  resizer.addEventListener("mousedown", (e) => {
    e.preventDefault();
    document.addEventListener("mousemove", resize);
    document.addEventListener("mouseup", stopResize);
    resizer.classList.add("resizing");
  });

  // Toggle sidebar button
  document
    .getElementById("toggle-sidebar-btn")
    ?.addEventListener("click", toggleSidebar);
}

// --- BREADCRUMBS ---
export function updateBreadcrumbs(fullPath) {
  const container = document.querySelector(".ide-filepath-display");
  if (!container) return;

  const relativePart = fullPath
    .replace(cfg.jailRoot.replace(/\/$/, ""), "")
    .replace(/^\/+/, "");
  const copyRelPath = cfg.jailname + (relativePart ? "/" + relativePart : "");

  container.setAttribute("data-fullpath", fullPath);
  container.setAttribute("data-relpath", copyRelPath);

  const parts = relativePart.split("/").filter((p) => p !== "");
  let currentPath = cfg.jailRoot.replace(/\/$/, "");

  let html = `<span class="bc-part bc-folder" data-path="${currentPath}">${cfg.jailname}</span>`;

  parts.forEach((part, index) => {
    currentPath += "/" + part;
    html += `<span class="bc-sep">/</span>`;
    const isLast = index === parts.length - 1;

    if (isLast) {
      html += `
                <span class="bc-part bc-file" title="Click: Relative path | Right-Click: Absolute path">
                    ${part}
                    <img src="ext/bastille/images/copy.svg" class="copy-icon-img-header" alt="copy">
                </span>`;
    } else {
      html += `<span class="bc-part bc-folder" data-path="${currentPath}">${part}</span>`;
    }
  });

  container.innerHTML = html;
}

// --- BREADCRUMB CLICK LISTENER ---
export function initBreadcrumbListener() {
  document.addEventListener(
    "click",
    async (e) => {
      const bcPart = e.target.closest(".ide-filepath-display .bc-part");
      if (!bcPart) return;

      e.preventDefault();
      e.stopPropagation();

      const targetPath = bcPart.getAttribute("data-path");
      if (!targetPath) return;

      if (bcPart.classList.contains("bc-file")) {
        await syncSidebarWithFile();
        return;
      }

      await syncSidebarWithFolder(targetPath);
    },
    true,
  );
}
