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
    };

    const onCancel = () => {
      cleanup();
      resolve(false);
    };
    const onConfirm = () => {
      cleanup();
      resolve(true);
    };

    btnCancel.addEventListener("click", onCancel);
    btnConfirm.addEventListener("click", onConfirm);

    if (!conf.showCancel) setTimeout(() => btnConfirm.focus(), 100);
  });
}

window.showConfirmDialog = showConfirmDialog;
