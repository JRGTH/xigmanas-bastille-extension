// modules/modal.js

const MODAL_CONFIG = {
  warning: {
    iconClass: "icon-warning",
    btnClass: "ide-btn-primary",
    btnText: "OK",
    showCancel: true,
    svg: `<img src="ext/bastille/images/delete.svg" alt="Delete" style="width: 35px; height: 35px; display: block; margin: auto;">`,
  },
  delete: {
    iconClass: "icon-error",
    btnClass: "ide-btn-primary",
    btnText: "Delete",
    showCancel: true,
    svg: `<img src="ext/bastille/images/delete.svg" alt="Delete" style="width: 35px; height: 35px; display: block; margin: auto;">`,
  },
  error: {
    iconClass: "icon-error",
    btnClass: "ide-btn-primary",
    btnText: "OK",
    showCancel: false,
    svg: `<img src="ext/bastille/images/delete.svg" alt="Delete" style="width: 35px; height: 35px; display: block; margin: auto;">`,
  },
  success: {
    iconClass: "icon-success",
    btnClass: "ide-btn-primary",
    btnText: "OK",
    showCancel: false,
    svg: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>`,
  },
  overwrite: {
    iconClass: "icon-error",
    btnClass: "ide-btn-primary",
    btnText: "Overwrite",
    cancelText: "Skip",
    showCancel: true,
    svg: `<img src="ext/bastille/images/warning.svg" alt="Overwrite" style="width: 25px; height: 25px; display: block; margin: auto;">`,
  },
};

export function showConfirmDialog(title, message, type = "warning") {
  return new Promise((resolve) => {
    const overlay = document.getElementById("ide-confirm-modal");
    const titleEl = document.getElementById("ide-modal-title");
    const msgEl = document.getElementById("ide-modal-message");
    const iconWrapper = document.getElementById("ide-modal-icon-wrapper");
    const btnConfirm = document.getElementById("ide-modal-btn-confirm");
    const btnCancel = document.getElementById("ide-modal-btn-cancel");

    const configType = type === "info" ? "success" : type;
    const conf = MODAL_CONFIG[configType] || MODAL_CONFIG.warning;

    titleEl.innerText = title;
    msgEl.innerText = message;
    iconWrapper.className = `ide-modal-icon-wrapper ${conf.iconClass}`;
    btnConfirm.className = `ide-btn ${conf.btnClass}`;
    btnConfirm.innerText = conf.btnText;
    btnCancel.style.display = conf.showCancel ? "inline-block" : "none";
    iconWrapper.innerHTML = conf.svg;

    const svg = iconWrapper.querySelector("svg");
    if (svg) {
      svg.setAttribute("width", "20");
      svg.setAttribute("height", "20");
      svg.style.display = "block";
      svg.style.color = "inherit";
    }

    overlay.classList.add("show");

    const cleanup = () => {
      overlay.classList.remove("show");
      btnCancel.removeEventListener("click", onCancel);
      btnConfirm.removeEventListener("click", onConfirm);
      window.removeEventListener("keydown", handleEsc);
    };

    const onCancel = () => {
      cleanup();
      resolve(false);
    };
    const onConfirm = () => {
      cleanup();
      resolve(true);
    };
    const handleEsc = (e) => {
      if (e.key === "Escape") {
        onCancel();
      }
    };

    btnCancel.addEventListener("click", onCancel);
    btnConfirm.addEventListener("click", onConfirm);
    window.addEventListener("keydown", handleEsc);
    if (!conf.showCancel) {
      setTimeout(() => btnConfirm.focus(), 100);
    }
  });
}

// --- NEW ITEM MODAL ---
export function showNewItemModal(type) {
    return new Promise((resolve) => {
        const modal   = document.getElementById('ide-new-item-modal');
        const titleEl = document.getElementById('ide-new-item-title');
        const input   = document.getElementById('ide-new-item-input');

        titleEl.innerText = type === 'folder' ? 'New Directory' : 'New File';
        input.value       = '';
        modal.style.display = 'flex';
        setTimeout(() => input.focus(), 50);

        const cleanup = () => {
            modal.style.display = 'none';
            input.removeEventListener('keydown', handleKey);
            modal.removeEventListener('click', handleClickOutside);
        };

        const handleKey = (e) => {
            if (e.key === 'Enter')  { cleanup(); resolve(input.value.trim() || null); }
            if (e.key === 'Escape') { cleanup(); resolve(null); }
        };

        const handleClickOutside = (e) => {
            if (e.target === modal) { cleanup(); resolve(null); }
        };

        input.addEventListener('keydown', handleKey);
        modal.addEventListener('click', handleClickOutside);
    });
}

// --- RENAME MODAL  ---
export function showRenameModal(type, currentName) {
    return new Promise((resolve) => {
        const modal   = document.getElementById('ide-new-item-modal');
        const titleEl = document.getElementById('ide-new-item-title');
        const input   = document.getElementById('ide-new-item-input');

        titleEl.innerText = type === 'folder' ? 'Rename Directory' : 'Rename File';

        input.value = currentName;
        modal.style.display = 'flex';

        setTimeout(() => {
            input.focus();
            input.select();
        }, 50);

        const cleanup = () => {
            modal.style.display = 'none';
            input.removeEventListener('keydown', handleKey);
            modal.removeEventListener('click', handleClickOutside);
        };

        const handleKey = (e) => {
            if (e.key === 'Enter') {
              cleanup();
              resolve(input.value.trim() || null);
            }
            if (e.key === 'Escape') {
              cleanup();
              resolve(null);
            }
        };

        const handleClickOutside = (e) => {
            if (e.target === modal) {
              cleanup();
              resolve(null);
            }
        };

        input.addEventListener('keydown', handleKey);
        modal.addEventListener('click', handleClickOutside);
    });
}

export function showOverwriteDialog(filename) {
  return new Promise((resolve) => {
    const overlay = document.getElementById("ide-confirm-modal");
    const titleEl = document.getElementById("ide-modal-title");
    const msgEl = document.getElementById("ide-modal-message");
    const iconWrapper = document.getElementById("ide-modal-icon-wrapper");
    const btnConfirm = document.getElementById("ide-modal-btn-confirm");
    const btnCancel = document.getElementById("ide-modal-btn-cancel");

    const conf = MODAL_CONFIG.overwrite;

    titleEl.innerText = "File Already Exists";
    msgEl.innerText = `The file "${filename}" already exists in this destination. Do you want to overwrite it?`;

    iconWrapper.className = `ide-modal-icon-wrapper ${conf.iconClass}`;
    iconWrapper.innerHTML = conf.svg;

    btnConfirm.className = `ide-btn ${conf.btnClass}`;
    btnConfirm.innerText = conf.btnText;

    //btnCancel.className = "ide-btn ide-btn-secondary";
    btnCancel.innerText = conf.cancelText;
    //btnCancel.style.display = "inline-block";
    btnCancel.style.display = conf.showCancel ? "inline-block" : "none";

    overlay.classList.add("show");

    setTimeout(() => btnConfirm.focus(), 50);

    const cleanup = () => {
      overlay.classList.remove("show");
      btnCancel.removeEventListener("click", onSkip);
      btnConfirm.removeEventListener("click", onOverwrite);
      window.removeEventListener("keydown", handleEsc);
      btnCancel.innerText = "Cancel";
    };

    const onSkip = () => {
      cleanup();
      resolve(false);
    };

    const onOverwrite = () => {
      cleanup();
      resolve(true);
    };

    const handleEsc = (e) => {
      if (e.key === "Escape") {
        onSkip();
      }
    };

    btnCancel.addEventListener("click", onSkip);
    btnConfirm.addEventListener("click", onOverwrite);
    window.addEventListener("keydown", handleEsc);
  });
}