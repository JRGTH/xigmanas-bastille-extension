/**
 * Breadcrumb Clipboard Handler - Dual Mode
 * Left Click: Relative Path (Jail/root/...)
 * Right Click: Absolute Path (/mnt/pool/...)
 */
document.addEventListener('mousedown', function (e) {
    const container = e.target.closest('.ide-filepath-display');
    if (!container || container.getAttribute('data-is-copying') === 'true') return;

    if (e.target.closest('.bc-folder')) return;

    const relPath = container.getAttribute('data-relpath');
    const fullPath = container.getAttribute('data-fullpath');
    if (!relPath || !fullPath) return;

    // --- LEFT CLICK (Relative) ---
    if (e.button === 0) {
        executeCopySystem(relPath, container, "Relative path copied!");
    }
    // --- RIGHT CLICK (Absolute) ---
    else if (e.button === 2) {
        document.oncontextmenu = () => false;
        executeCopySystem(fullPath, container, "Absolute path copied!");

        setTimeout(() => { document.oncontextmenu = () => true; }, 1000);
    }
}, true);

/**
 * Universal Copy Engine
 */
function executeCopySystem(text, container, message) {
    const textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed";
    textArea.style.left = "-9999px";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
        if (document.execCommand('copy')) {
            showFeedback(container, message);
        }
    } catch (err) {
        console.error('Copy failed:', err);
    }
    document.body.removeChild(textArea);
}

/**
 * UI Feedback Logic
 */
function showFeedback(container, message) {
    container.setAttribute('data-is-copying', 'true');
    const originalHTML = container.innerHTML;

    container.innerHTML = `<span style="color: #fff; font-weight: bold; animation: fadeInBreadcrumb 0.2s;">${message}</span>`;

    setTimeout(() => {
        container.innerHTML = originalHTML;
        container.removeAttribute('data-is-copying');
    }, 1200);
}