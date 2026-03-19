// js/main.js
import { cfg, State } from './modules/config.js';
import { showConfirmDialog } from './modules/ui_modals.js';
import { initQuickSearch, openQuickSearch, closeQuickSearch } from './modules/search.js';
// import { executeSaved } from './modules/editor.js'; // Lo importarás cuando lo crees

// 1. Inicializar submódulos
document.addEventListener('DOMContentLoaded', async () => {
    initQuickSearch();

    // Aquí inicializaremos el sidebar cuando crees sidebar.js
    // if (cfg.filepath) await syncSidebarWithFile();
});

// 2. Global Keybinds (Ctrl+S, Ctrl+K)
document.addEventListener('keydown', function (e) {
    const isCtrl = e.ctrlKey || e.metaKey;
    const key = e.key.toLowerCase();

    // Ctrl + B: Toggle Sidebar
    if (isCtrl && key === 'b') {
        e.preventDefault();
        e.stopPropagation();
        const container = document.querySelector('.ide-container');
        if (container) {
            container.classList.toggle('sidebar-hidden');
            setTimeout(() => { if (window.editor) window.editor.layout(); }, 200);
        }
        return;
    }

    // Ctrl + K: Quick Search
    if (isCtrl && key === 'k') {
        e.preventDefault();
        e.stopPropagation();
        openQuickSearch();
        return;
    }

    // Ctrl + S: Guardar
    if (isCtrl && key === 's') {
        e.preventDefault();
        e.stopPropagation();
        // executeSaved(); // Función que irá en editor.js
        return;
    }
}, true);