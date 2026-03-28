// js/modules/config.js

export const cfg = window.IDE_CONFIG || {};

if (history.state?.filepath) {
    cfg.filepath = history.state.filepath;
}

export const MODAL_CONFIG = {
    warning: {
        iconClass: 'icon-warning',
        btnClass: 'ide-btn-primary',
        btnText: 'OK',
        showCancel: true,
        svg: `<img src="ext/bastille/images/delete.svg" alt="Warning" style="width: 35px; height: 35px; display: block; margin: auto;">`,
    },
    error: {
        iconClass: 'icon-error',
        btnClass: 'ide-btn-primary',
        btnText: 'OK',
        showCancel: false,
        svg: `<img src="ext/bastille/images/delete.svg" alt="Error" style="width: 35px; height: 35px; display: block; margin: auto;">`,
    },
    success: {
        iconClass: 'icon-success',
        btnClass: 'ide-btn-primary',
        btnText: 'OK',
        showCancel: false,
        svg: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>`,
    }
};

// Global status variable to indicate whether there are unsaved changes
export const State = {
    isDirty: false,
    isInjectingCode: false,
    selectedIndex: -1
};