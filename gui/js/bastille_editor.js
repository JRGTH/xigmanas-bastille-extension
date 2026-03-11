/**
 * bastille_editor.js - Core Javascript Engine
 */
let searchTimer;
let selectedIndex = -1;
let isDirty = false;
let isInjectingCode = false;
let sidebarTimer;
let originalSidebarHTML = '';
let currentFileData = null; // We save the data so that it is not requested again when changing tabs.
let diffEditorInstance = null;
let currentDiffFilepath = '';
// Read the configuration injected by PHP
const cfg = window.IDE_CONFIG;

const MODAL_CONFIG = {
    warning: {
        iconClass: 'icon-warning',
        btnClass: 'ide-btn-primary',
        btnText: 'OK',
        showCancel: true,
        svg: `<img src="ext/bastille/images/delete.svg" alt="Delete" style="width: 35px; height: 35px; display: block; margin: auto;">`,
    },
    delete: {
            iconClass: 'icon-error',
            btnClass: 'ide-btn-primary',
            btnText: 'Delete',
            showCancel: true,
            svg: `<img src="ext/bastille/images/delete.svg" alt="Delete" style="width: 35px; height: 35px; display: block; margin: auto;">`,
    },
    error: {
        iconClass: 'icon-error',
        btnClass: 'ide-btn-primary',
        btnText: 'OK',
        showCancel: false,
        svg: `<img src="ext/bastille/images/delete.svg" alt="Delete" style="width: 35px; height: 35px; display: block; margin: auto;">`,
    },
    success: {
        iconClass: 'icon-success',
        btnClass: 'ide-btn-primary',
        btnText: 'OK',
        showCancel: false,
        svg: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>`,
    },
};

/**
 * Global Error Handling:
 * Suppress Monaco Editor's internal "Canceled" promises (e.g., when the Sash/Resizer is interrupted).
 */
window.addEventListener('unhandledrejection', function(event) {
    if (event.reason && event.reason.name === 'Canceled') {
        event.preventDefault();
    }
});

if (typeof monaco !== 'undefined' && !window.monacoErrorHandlerSet) {
    monaco.editor.onDidCreateEditor((e) => {
    });
    window.monacoErrorHandlerSet = true;
}

window.toggleSidebar = function () {
    const container = document.querySelector('.ide-container');
    if (container) {
        container.classList.toggle('sidebar-hidden');
        setTimeout(() => {
            if (window.editor) window.editor.layout();
        }, 200);
    }
};

// --- MODAL SYSTEM ---
window.showConfirmDialog = function (title, message, type = 'warning') {
    return new Promise((resolve) => {
        const overlay = document.getElementById('ide-confirm-modal');
        const titleEl = document.getElementById('ide-modal-title');
        const msgEl = document.getElementById('ide-modal-message');
        const iconWrapper = document.getElementById('ide-modal-icon-wrapper');
        const btnConfirm = document.getElementById('ide-modal-btn-confirm');
        const btnCancel = document.getElementById('ide-modal-btn-cancel');

        const configType = type === 'info' ? 'success' : type;
        const conf = MODAL_CONFIG[configType] || MODAL_CONFIG.warning;

        titleEl.innerText = title;
        msgEl.innerText = message;
        iconWrapper.className = `ide-modal-icon-wrapper ${conf.iconClass}`;
        btnConfirm.className = `ide-btn ${conf.btnClass}`;
        btnConfirm.innerText = conf.btnText;
        btnCancel.style.display = conf.showCancel ? 'inline-block' : 'none';

        iconWrapper.innerHTML = conf.svg;
        const svg = iconWrapper.querySelector('svg');
        if (svg) {
            svg.setAttribute('width', '20');
            svg.setAttribute('height', '20');
            svg.style.display = 'block';
            svg.style.color = 'inherit';
        }

        overlay.classList.add('show');

        const cleanup = () => {
            overlay.classList.remove('show');
            btnCancel.removeEventListener('click', onCancel);
            btnConfirm.removeEventListener('click', onConfirm);
        };

        const onCancel = () => {
            cleanup();
            resolve(false);
        };
        const onConfirm = () => {
            cleanup();
            resolve(true);
        };

        btnCancel.addEventListener('click', onCancel);
        btnConfirm.addEventListener('click', onConfirm);

        if (!conf.showCancel) setTimeout(() => btnConfirm.focus(), 100);
    });
};

// --- QUICK SEARCH LOGIC ---
const qsModal = document.getElementById('quick-search-modal');
const qsBackdrop = document.getElementById('quick-search-backdrop');
const qsInput = document.getElementById('qs-input');
const qsClearBtn = document.getElementById('qs-clear-btn');
const qsResultsList = document.getElementById('qs-results-list');
const qsHistoryContainer = document.getElementById('qs-history-container');
const qsBadges = document.getElementById('qs-badges');
let searchHistory = JSON.parse(localStorage.getItem('bastilleSearchHistory')) || [];

if (qsBackdrop) qsBackdrop.addEventListener('click', closeQuickSearch);
if (qsClearBtn) qsClearBtn.addEventListener('click', clearQuickSearch);

function renderHistory() {
    if (searchHistory.length === 0) {
        qsHistoryContainer.style.display = 'none';
        return;
    }
    qsHistoryContainer.style.display = 'flex';
    qsBadges.innerHTML = '';
    searchHistory.forEach((term) => {
        let badge = document.createElement('span');
        badge.className = 'qs-badge';
        badge.innerHTML = `${term} <span class="badge-delete" onclick="event.stopPropagation(); removeHistoryItem('${term}')">&times;</span>`;
        badge.onclick = () => {
            qsInput.value = term;
            runQuickSearch();
        };
        qsBadges.appendChild(badge);
    });
}

function removeHistoryItem(term) {
    searchHistory = searchHistory.filter((t) => t !== term);
    localStorage.setItem('bastilleSearchHistory', JSON.stringify(searchHistory));
    renderHistory();
}

function saveHistory(term) {
    if (!term || term.trim() === '') return;
    term = term.trim().toLowerCase();
    searchHistory = searchHistory.filter((t) => t !== term);
    searchHistory.unshift(term);
    if (searchHistory.length > 5) searchHistory.pop();
    localStorage.setItem('bastilleSearchHistory', JSON.stringify(searchHistory));
    renderHistory();
}

function openQuickSearch() {
    qsModal.style.display = 'block';
    qsBackdrop.style.display = 'block';
    renderHistory();
    qsInput.value = '';
    runQuickSearch();
    setTimeout(() => qsInput.focus(), 100);
}

function closeQuickSearch() {
    qsModal.style.display = 'none';
    qsBackdrop.style.display = 'none';
}

function clearQuickSearch() {
    qsInput.value = '';
    runQuickSearch();
    qsInput.focus();
}

function runQuickSearch() {
    selectedIndex = -1;
    let filter = qsInput.value.trim();
    qsClearBtn.style.display = filter.length > 0 ? 'flex' : 'none';
    if (filter.length < 2) {
        qsResultsList.innerHTML =
            '<li style="padding: 15px; color:#888;">Type at least 2 chars...</li>';
        return;
    }
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        if (typeof spinner === 'function') spinner();
        qsResultsList.innerHTML = '<li style="padding: 15px; color:#888;">Searching recursively...</li>';

        let url = new URL(window.location.origin + window.location.pathname);
        url.searchParams.set('jailname', cfg.jailname);
        url.searchParams.set('ajax_search', filter);

        fetch(url)
            .then((r) => r.json())
            .then((data) => {
                const perfInfo = document.getElementById('qs-perf-info');
                if (perfInfo) perfInfo.innerText = data.perf;
                qsResultsList.innerHTML = '';
                if (!data.items || data.items.length === 0) {
                    qsResultsList.innerHTML = `<li class="no-results" style="padding: 20px; text-align: center; color: #999;"><div style="font-size: 24px;">No files found!</div></li>`;
                    return;
                }
                data.items.forEach((file) => {
                    let li = document.createElement('li');
                    let a = document.createElement('a');
                    let iconSVG = file.type === 'folder'
                    ? '<img src="ext/bastille/images/folder.svg" style="width:16px; margin-right:8px; vertical-align:middle;">'
                    : '<img src="ext/bastille/images/file.svg" style="width:16px; margin-right:8px; vertical-align:middle;">';

                   a.innerHTML = `<span class="qs-item-title">${iconSVG}${file.name}</span><span class="qs-item-path">${file.relative}</span>`;

                   if (file.type === 'folder') {
                       // Route for folders
                       a.href = "#";
                       a.addEventListener('click', async (e) => {
                           e.preventDefault();
                           saveHistory(filter);
                           closeQuickSearch();
                           // Fly to the folder in the sidebar
                           if (typeof syncSidebarWithFolder === 'function') {
                               await syncSidebarWithFolder(file.full);
                           }
                       });
                   } else {
                       // Route for files (Existing SPA Logic)
                       a.href = `bastille_manager_editor_v2.php?jailname=${encodeURIComponent(cfg.jailname)}&dir=${encodeURIComponent(file.directory)}&filepath=${encodeURIComponent(file.full)}`;
                       a.addEventListener('click', (e) => {
                           e.preventDefault();
                           saveHistory(filter);
                           closeQuickSearch();

                           const fakeLi = document.createElement('li');
                           fakeLi.className = 'tree-item is-recursive';
                           fakeLi.style.display = 'none';
                           const fakeLink = document.createElement('a');
                           fakeLink.href = a.href;

                           fakeLi.appendChild(fakeLink);
                           document.querySelector('.ide-file-list').appendChild(fakeLi);

                           fakeLink.click();
                           fakeLi.remove();
                       });
                   }

                   li.appendChild(a);
                   qsResultsList.appendChild(li);
                });
            })
            .catch((err) => {
                qsResultsList.innerHTML = '<li style="padding: 15px; color:red;">Search engine offline.</li>';
            })
            .finally(() => {
                hideSpinner();
            });
    }, 400);
}

if (qsInput) {
    qsInput.removeEventListener('keyup', runQuickSearch);
    qsInput.addEventListener('input', runQuickSearch);
}

// --- GLOBAL KEYBINDS ---
document.addEventListener('keydown', function (e) {
    const isCtrl = e.ctrlKey || e.metaKey;
    const key = e.key.toLowerCase();

    // Ctrl + B: Sidebar
    if (isCtrl && key === 'b') {
        e.preventDefault();
        e.stopPropagation();
        toggleSidebar();
        return;
    }

    // Ctrl + S: Guardar
    if (isCtrl && key === 's') {
        e.preventDefault();
        e.stopPropagation();
        executeSaved();
        return;
    }

    if (isCtrl && key === 'k') {
        e.preventDefault();
        e.stopPropagation();
        openQuickSearch();
        return;
    }

    if (qsModal && qsModal.style.display === 'block') {
        if (e.key === 'Escape') {
            closeQuickSearch();
            return;
        }

        const items = qsResultsList.getElementsByTagName('li');

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedIndex = selectedIndex + 1 < items.length ? selectedIndex + 1 : selectedIndex;
            updateSelection(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedIndex = selectedIndex - 1 >= 0 ? selectedIndex - 1 : 0;
            updateSelection(items);
        } else if (e.key === 'Enter') {
            if (selectedIndex > -1 && items[selectedIndex]) {
                e.preventDefault();
                items[selectedIndex].querySelector('a').click();
            } else if (items.length > 0) {
                e.preventDefault();
                items[0].querySelector('a').click();
            }
        }
    }
}, true);

function updateSelection(items) {
    Array.from(items).forEach((li) => li.classList.remove('selected'));
    if (items[selectedIndex]) {
        items[selectedIndex].classList.add('selected');
        items[selectedIndex].scrollIntoView({ block: 'nearest' });
    }
}

// --- SIDEBAR FILTER ---
const fileFilterInput = document.getElementById('fileFilter');
const sidebarClearBtn = document.getElementById('clearFilterBtn');
if (fileFilterInput) fileFilterInput.addEventListener('keyup', filterFiles);
if (sidebarClearBtn) sidebarClearBtn.addEventListener('click', clearFilter);

function filterFiles() {
    const input = document.getElementById('fileFilter');
    const clearBtn = document.getElementById('clearFilterBtn');
    const ul = document.getElementById('fileList');
    const filter = input.value.toLowerCase().trim();

    if (originalSidebarHTML === '' && filter !== '') originalSidebarHTML = ul.innerHTML;
    clearBtn.style.display = filter.length > 0 ? 'flex' : 'none';

    const li = ul.getElementsByTagName('li');
    for (let i = 0; i < li.length; i++) {
        let a = li[i].getElementsByTagName('a')[0];
        let txtValue = a ? a.textContent || a.innerText : '';
        li[i].style.display = txtValue.toLowerCase().indexOf(filter) > -1 ? '' : 'none';
    }

    clearTimeout(sidebarTimer);
    if (filter.length >= 2) {
        sidebarTimer = setTimeout(() => fetchSearchRecursive(filter), 500);
    } else if (filter === '') clearFilter();
}

function clearFilter() {
    const input = document.getElementById('fileFilter');
    const ul = document.getElementById('fileList');
    input.value = '';
    if (originalSidebarHTML !== '') {
        ul.innerHTML = originalSidebarHTML;
        originalSidebarHTML = '';
    }

    const li = ul.getElementsByTagName('li');
    for (let i = 0; i < li.length; i++) li[i].style.display = '';
    document.getElementById('clearFilterBtn').style.display = 'none';
    input.focus();
}

//FIXME add a badged filters
function fetchSearchRecursive(term) {
    const ul = document.getElementById('fileList');
    const url = new URL(window.location.origin + window.location.pathname);
    url.searchParams.set('jailname', cfg.jailname);
    url.searchParams.set('ajax_search', term);

    fetch(url)
        .then((res) => res.json())
        .then((data) => {
            ul.querySelectorAll('.is-recursive, .no-results').forEach((el) => el.remove());

            if (!data.items || data.items.length === 0) {
                let li = document.createElement('li');
                li.className = 'no-results';
                li.innerHTML = '<span style="padding:10px; color:#888; font-style:italic;">No matches found...</span>';
                ul.appendChild(li);
                return;
            }

            data.items.forEach((file) => {
                if (!ul.querySelector(`li.is-recursive a[href*="${encodeURIComponent(file.full)}"]`)) {
                    let li = document.createElement('li');
                    // Add the correct class based on type
                    li.className = 'tree-item is-recursive ' + (file.type === 'folder' ? 'folder-item' : 'file-item');

                    const safePath = file.full.replace(/'/g, "\\'");
                    const editUrl = `?jailname=${encodeURIComponent(cfg.jailname)}&dir=${encodeURIComponent(file.directory)}&filepath=${encodeURIComponent(file.full)}`;

                    if (file.type === 'folder') {
                        // Folder logic: Click travels to the actual folder in the tree
                        li.innerHTML = `
                            <a href="javascript:void(0)" onclick="syncSidebarWithFolder('${safePath}')" title="${file.full}">
                                <strong>${cfg.icons.folder} ${file.name}</strong>
                                <span class="search-result-path">${file.relative}</span>
                            </a>`;
                    } else {
                        // File logic: Click loads file into Monaco
                        li.innerHTML = `
                            <a href="${editUrl}" title="${file.full}">
                                <strong>${cfg.icons.file} ${file.name}</strong>
                                <span class="search-result-path">${file.relative}</span>
                            </a>`;
                    }
                    ul.appendChild(li);
                }
            });
        })
        .catch(err => console.error("Search Error:", err));
}

// --- SPA & TREE LOGIC ---
document.querySelector('.ide-file-list').addEventListener('click', async function (e) {
    const link = e.target.closest('a');

    // Skip if not a link or if it's a folder toggle
    if (!link || link.getAttribute('onclick')?.includes('toggleFolder')) return;

    const url = new URL(link.href, window.location.origin);
    const filepath = url.searchParams.get('filepath');

    // If no filepath, let the default behavior handle it (navigation)
    if (!filepath) return;

    e.preventDefault();

    // Check for unsaved changes
    if (isDirty) {
        hideSpinner();
        const ok = await showConfirmDialog(
            'Unsaved changes',
            'You have made changes. If you switch files now, you will lose your changes.',
            'warning'
        );
        if (!ok) {
            return;
        }
        clearDirtyState();
    }

    // --- NEW: BINARY / MEDIA FILE INTERCEPTOR ---
    const ext = filepath.split('.').pop().toLowerCase();
    const binaryExts = [
        // Media
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'mp3', 'mp4', 'mkv', 'avi', 'mov', 'wav', 'flac',
        // Archives & Binaries
        'iso', 'gz', 'zip', 'tar', 'rar', '7z', 'pdf', 'bin', 'exe', 'dll', 'so', 'db', 'sqlite'
    ];

    if (binaryExts.includes(ext)) {
        // We do NOT fetch from server. Just show a placeholder in Monaco.
        if (window.editor) {
            isInjectingCode = true;
            window.editor.setValue(`/*\n * BASTILLE EDITOR WARNING\n * ------------------------\n * The file '${filepath.split('/').pop()}' is a binary or media file.\n * It cannot be safely displayed or edited in a text editor.\n */`);
            window.editor.updateOptions({ readOnly: true });
            isInjectingCode = false;
            isDirty = false;
        }

        // Update UI states to keep it consistent
        document.querySelectorAll('.tree-item').forEach((el) => el.classList.remove('active'));
        const treeItem = link.closest('.tree-item');
        if (treeItem) treeItem.classList.add('active');

        const inputFp = document.querySelector('input[name="filepath"]');
        const inputDr = document.querySelector('input[name="dir"]');
        if (inputFp) inputFp.value = filepath;
        if (inputDr) inputDr.value = filepath.substring(0, filepath.lastIndexOf('/'));

        url.searchParams.delete('ajax');
        window.history.pushState({}, '', url.toString());
        if (typeof updateBreadcrumbs === 'function') updateBreadcrumbs(filepath);

        return; // EXIT EARLY! We save bandwidth and time.
    }
    // ---------------------------------------------

    document.body.style.cursor = 'wait';
    if (typeof spinner === 'function') spinner();

    try {
        url.searchParams.set('ajax', '1');

        const response = await fetch(url.toString());
        if (!response.ok) throw new Error('Fetch failed');

        const fileContent = await response.text();

        // Inject content into Monaco
        if (window.editor) {
            isInjectingCode = true;
            window.editor.setValue(fileContent);
            window.editor.updateOptions({ readOnly: false });
            isInjectingCode = false;
            isDirty = false;
        }

        // --- UI UPDATES (SPA MODE) ---
        const isSearchResult = link.closest('.is-recursive');
        if (isSearchResult) {
            if (typeof clearFilter === 'function') {
                clearFilter();
            }

            const searchInput = document.querySelector('.ide-search input');
            const clearBtn = document.querySelector('.ide-search-clear');

            if (searchInput) {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
            }

            if (clearBtn) {
                clearBtn.style.display = 'none';
                clearBtn.click();
            }

            document.querySelectorAll('.is-recursive, .no-results').forEach((el) => el.remove());

            document.querySelectorAll('.ide-file-list > li').forEach(el => {
                el.style.display = '';
            });

            cfg.filepath = filepath;

            setTimeout(async () => {
                if (typeof syncSidebarWithFile === 'function') {
                    await syncSidebarWithFile();
                }
            }, 50);

        } else {
            document.querySelectorAll('.tree-item').forEach((el) => el.classList.remove('active'));
            const treeItem = link.closest('.tree-item');
            if (treeItem) {
                treeItem.classList.add('active');
            }
        }

        // Sync hidden form inputs for Save button
        const inputFp = document.querySelector('input[name="filepath"]');
        const inputDr = document.querySelector('input[name="dir"]');
        if (inputFp) inputFp.value = filepath;
        if (inputDr) inputDr.value = filepath.substring(0, filepath.lastIndexOf('/'));

        // 3. Update Browser URL and display path
        url.searchParams.delete('ajax');
        window.history.pushState({}, '', url.toString());

        updateBreadcrumbs(filepath);

    } catch (error) {
        console.error("Editor Error:", error);
        showConfirmDialog('Error', 'The selected file could not be loaded.', 'error');
    } finally {
        document.body.style.cursor = 'default';
        hideSpinner();
    }
});

// --- HOME ICON / RESET TREE (SPA MODE) ---
const homeBtn = document.querySelector('.ide-sidebar-header a[title="Reset Tree"]');

if (homeBtn) {
    homeBtn.addEventListener('click', function(e) {
        e.preventDefault();

        const searchInput = document.querySelector('.ide-search input');
        if (searchInput) {
            searchInput.value = '';
        }
        document.querySelectorAll('.is-recursive, .no-results').forEach(el => el.remove());

        const fileList = document.getElementById('fileList');
        if (!fileList) return;
            const rootLi = fileList.querySelector('li.folder-item');
            const rootUl = rootLi ? rootLi.querySelector('ul') : null;

            if (rootUl) {
                rootUl.innerHTML = '<li class="tree-item" style="padding-left:20px; opacity:0.5;">Updating tree...</li>';

                const params = new URLSearchParams({
                    'ajax_get_dir': window.IDE_CONFIG.jailRoot,
                    'jailname': window.IDE_CONFIG.jailname
                });

                fetch(`${window.location.pathname}?${params.toString()}`)
                    .then(async (res) => {
                        const rawText = await res.text();
                        try {
                            return JSON.parse(rawText);
                        } catch (e) {
                            console.error("CRITICAL PHP ERROR:", rawText);
                            throw new Error("Server returned invalid JSON. Check console.");
                        }
                     })
                    .then(data => {
                        if (data.error) {
                            throw new Error(data.error);
                        }

                        rootUl.innerHTML = '';

                        // --- RENDER folders ---
                        data.folders.forEach(folder => {
                            const isLocked = folder.flag && folder.flag.includes('schg');
                            const lockIcon = isLocked ? `<img src="ext/bastille/images/lock.svg" class="lock-icon" style="width:14px; margin-left:5px;">` : '';

                            const li = document.createElement('li');
                            li.className = 'tree-item folder-item';
                            li.dataset.flag = folder.flag || '';
                            li.innerHTML = `
                                <a href="javascript:void(0)" onclick="toggleFolder(this, '${data.parent}/${folder.name}')">
                                    <span class="tree-caret"><img src="ext/bastille/images/right-arrow.svg" style="width: 20px;"></span>
                                    ${cfg.icons.folder} <span>${folder.name}</span> ${lockIcon}
                                </a>
                            `;
                            rootUl.appendChild(li);
                        });

                        // --- RENDER files ---
                        data.files.forEach(file => {
                            const isLocked = file.flag && file.flag.includes('schg');
                            const lockIcon = isLocked ? `<img src="ext/bastille/images/lock.svg" class="lock-icon" style="width:14px; margin-left:5px;">` : '';
                            const editUrl = `?jailname=${encodeURIComponent(cfg.jailname)}&filepath=${encodeURIComponent(data.parent + '/' + file.name)}`;

                            const li = document.createElement('li');
                            li.className = 'tree-item file-item';
                            li.dataset.flag = file.flag || '';
                            li.innerHTML = `
                                <a href="${editUrl}" onclick="if(typeof spinner === 'function') spinner();">
                                    ${cfg.icons.file} <span>${file.name}</span> ${lockIcon}
                                </a>
                            `;
                            rootUl.appendChild(li);
                        });
                    })
                    .catch(err => {
                        console.error("Reset Tree Error:", err);
                        rootUl.innerHTML = '<li class="tree-item" style="color:red; padding-left:20px;">Error updating.</li>';
                    });
            }

            document.querySelectorAll('.tree-item').forEach(el => el.classList.remove('active', 'open'));
            if (rootLi) {
                rootLi.classList.add('open');
            }
        }
    );
}

document.addEventListener('DOMContentLoaded', async function () {
    if (cfg.filepath && cfg.filepath !== '') {
        await syncSidebarWithFile();
    }
});

/**
 * Synchronizes the sidebar tree with the current filepath on page load (F5)
 * Refactored to handle the Persistent Root structure and null-safety.
 - FIXED: Compatible with padlocks (schg) searching in all spans.
 */
async function syncSidebarWithFile() {
    const targetFile = cfg.filepath;
    if (!targetFile) return;

    let relativePath = targetFile.replace(cfg.jailRoot, '');
    let segments = relativePath.split('/').filter(s => s !== '');
    segments.pop();

    let currentPath = cfg.jailRoot.replace(/\/$/, '');
    let $currentContainer = document.getElementById('fileList');
    if (!$currentContainer) {
        return;
    }

    for (const segment of segments) {
        currentPath += '/' + segment;

        const folderLink = Array.from($currentContainer.querySelectorAll('.folder-item > a'))
            .find(a => {
                const span = a.querySelectorAll('span');
                return Array.from(span)
                    .some(span => span.innerText.trim() === segment);
            });

        if (folderLink) {
            const li = folderLink.parentElement;
            const subList = li.querySelector('ul');

            if (subList && subList.style.display !== 'none' && li.classList.contains('open')) {
                $currentContainer = subList;
            } else {
                await window.toggleFolder(folderLink, currentPath);
                const nextUl = li.querySelector('ul');
                if (nextUl) {
                    $currentContainer = nextUl;
                } else {
                    break;
                }
            }
        } else {
            break;
        }
    }

    setTimeout(() => {
        const allFileLinks = document.querySelectorAll('.file-item > a');
        let targetLink = null;

        allFileLinks.forEach(a => {
            const linkUrl = new URL(a.href, window.location.origin);
            if (linkUrl.searchParams.get('filepath') === targetFile) {
                targetLink = a;
            }
        });

        if (targetLink) {
            const li = targetLink.closest('.tree-item');

            let parent = li.parentElement;
            while (parent && parent.id !== 'fileList') {
                if (parent.tagName === 'UL') parent.style.display = 'block';
                if (parent.tagName === 'LI') parent.classList.add('open');
                parent = parent.parentElement;
            }

            document.querySelectorAll('.tree-item').forEach(el => el.classList.remove('active'));
            li.classList.add('active');

            li.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, 150);
}

/**
 * Navigate to a specific folder by opening the tree
 * FIXED: Restore the original HTML before traveling so that it does not remain blank.
 */
async function syncSidebarWithFolder(targetPath) {
    const fileFilterInput = document.getElementById('fileFilter');

    // FIX: Limpiamos la búsqueda restaurando el árbol original correctamente
    if (fileFilterInput && fileFilterInput.value !== '') {
        if (typeof clearFilter === 'function') {
            clearFilter(); // Esto devuelve el DOM a su estado original (quita el blanco)
        } else {
            fileFilterInput.value = '';
        }
    }

    let relativePath = targetPath.replace(cfg.jailRoot, '').replace(/^\/+/, '');
    let segments = relativePath.split('/').filter(s => s !== '');

    let currentPath = cfg.jailRoot.replace(/\/$/, '');
    let $currentContainer = document.getElementById('fileList');
    if (!$currentContainer) return;

    let targetLi = null;

    if (segments.length === 0) {
        const rootFolder = $currentContainer.querySelector('li.folder-item');
        if (rootFolder) {
            if (!rootFolder.classList.contains('open')) {
                await window.toggleFolder(rootFolder.querySelector('a'), currentPath);
            }
            targetLi = rootFolder;
        }
    } else {
        for (const segment of segments) {
            currentPath += '/' + segment;
            const folderLink = Array.from($currentContainer.querySelectorAll('.folder-item > a'))
                .find(a => {
                    // FIX: Compatibilidad con candados también aquí
                    const spans = a.querySelectorAll('span');
                    return Array.from(spans)
                        .some(span => span.innerText.trim() === segment);
                });

            if (folderLink) {
                const li = folderLink.parentElement;
                targetLi = li;
                const subList = li.querySelector('ul');

                if (subList && subList.style.display !== 'none' && li.classList.contains('open')) {
                    $currentContainer = subList;
                } else {
                    await window.toggleFolder(folderLink, currentPath);
                    const nextUl = li.querySelector('ul');
                    if (nextUl) $currentContainer = nextUl;
                    else break;
                }
            } else {
                break;
            }
        }
    }

    // Highlight the folder and scroll
    if (targetLi) {
        document.querySelectorAll('.tree-item').forEach(el => el.classList.remove('active'));

        targetLi.classList.add('active');

        const targetAnchor = targetLi.querySelector('a');
        setTimeout(() => {
            if (targetAnchor) {
                targetAnchor.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                targetLi.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }, 150);
    }
}

// --- EDITOR LOGIC & SAVING ---
window.executeSaved = async function () {
    if (typeof window.editor === 'undefined' || !isDirty) return;

    const filepath = document.querySelector('input[name="filepath"]')?.value;
    const content = window.editor.getValue();
    const form = document.getElementById('iform');

    if (!filepath || filepath === 'Select a file' || !form) {
        showConfirmDialog('Error', 'No file selected to save or form missing.', 'error');
        return;
    }

    if (typeof spinner === 'function') spinner();

    const saveBtn = document.getElementById('btn_save');
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.value = 'Saving...';
    }

    const formData = new FormData(form);

    formData.set('ajax_save', '1');
    formData.set('file_content', content);
    formData.set('filepath', filepath);
    formData.set('jailname', cfg.jailname);
    formData.set('save', '1');

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        if (!response.ok) {
            const errText = await response.text();
            throw new Error(`Server returned status ${response.status}. Details: ${errText.substring(0, 50)}...`);
        }

        const data = await response.json();

        if (data.success) {
            clearDirtyState();
            showConfirmDialog('Saved', 'Saved file to ' + filepath, 'success');
        } else {
            throw new Error(data.error || 'Server rejected the save request.');
        }

    } catch (error) {
        console.error("Save Error:", error);
        if (error.message.includes('Unexpected token')) {
            showConfirmDialog('Session Error', 'Your session might have expired or a security token is missing. Try reloading the page.', 'error');
        } else {
            showConfirmDialog('Save Error', error.message, 'error');
        }
    } finally {
        if (saveBtn) {
            saveBtn.disabled = !isDirty;
            saveBtn.value = 'Save File';
        }
        hideSpinner();
    }
};

function clearDirtyState() {
    isDirty = false;
    originalSidebarHTML = '';
    document.querySelectorAll('.dirty-dot').forEach((dot) => dot.remove());
    document.title = document.title.replace('* ', '');
    const saveBtn = document.getElementById('btn_save');
    if (saveBtn) {
        saveBtn.disabled = true;
    }
}

/**
 * Sidebar Lazy-Loading & Tree Renderer
 *
 * Fetches directory contents and renders items with FreeBSD flag detection.
 * @param {HTMLElement} element - The clicked folder link.
 * @param {string} path - Absolute path to the directory.
 */
window.toggleFolder = function (element, path) {
    const li = element.parentElement;
    let subList = li.querySelector('ul');

    // If sub-items are already in DOM, just toggle visibility
    if (subList) {
        const isHidden = subList.style.display === 'none';
        subList.style.display = isHidden ? 'block' : 'none';
        isHidden ? li.classList.add('open') : li.classList.remove('open');
        return;
    }

    if (typeof spinner === 'function') spinner();

    const url = new URL(window.location.origin + window.location.pathname);
    url.searchParams.set('jailname', cfg.jailname);
    url.searchParams.set('ajax_get_dir', path);

    return fetch(url)
        .then(async (res) => {
            const rawText = await res.text();
            try {
                return JSON.parse(rawText);
            } catch (e) {
                console.error("CRITICAL PHP ERROR:", rawText);
                throw new Error("Server returned invalid JSON. Check console.");
            }
         })
        .then((data) => {
            if (data.error) throw new Error(data.error);
            //console.log("API Response for path: ", path, data); // Debugging info
            subList = document.createElement('ul');
            subList.className = 'ide-file-list';
            subList.style.paddingLeft = '15px';

            // --- Render Folders ---
            data.folders.forEach((f) => {
                const fullP = path + '/' + f.name;
                const safePath = fullP.replace(/'/g, "\\'"); // Escape single quotes for JS

                const hasFlag = f.flag && f.flag !== '' && f.flag !== '0';
                const lockMarkup = hasFlag ? `<img src="ext/bastille/images/lock.svg" class="lock-icon" title="Flags: ${f.flag}">` : '';

                subList.innerHTML += `
                    <li class="tree-item folder-item" data-flag="${f.flag || ''}">
                        <a href="javascript:void(0)" onclick="toggleFolder(this, '${safePath}')">
                            ${cfg.icons.caret} ${cfg.icons.folder} <span>${f.name}</span> ${lockMarkup}
                        </a>
                    </li>`;
            });

            // --- Render Files ---
            data.files.forEach((f) => {
                const fullP = path + '/' + f.name;
                const editUrl = `?jailname=${encodeURIComponent(cfg.jailname)}&dir=${encodeURIComponent(path)}&filepath=${encodeURIComponent(fullP)}`;

                const hasFlag = f.flag && f.flag !== '' && f.flag !== '0';
                const lockMarkup = hasFlag ? `<img src="ext/bastille/images/lock.svg" class="lock-icon" title="Flags: ${f.flag}">` : '';

                // FIX: Added real editUrl and spinner call to files
                subList.innerHTML += `
                    <li class="tree-item file-item" data-flag="${f.flag || ''}">
                        <a href="${editUrl}" onclick="if(typeof spinner === 'function') spinner();">
                            ${cfg.icons.file} <span>${f.name}</span> ${lockMarkup}
                        </a>
                    </li>`;
            });

            li.appendChild(subList);
            li.classList.add('open');
        })
        .catch(err => {
            console.error("Tree Load Error:", err);
            li.classList.remove('open');
            showConfirmDialog(
                "Directory Load Error",
                err.message || "Failed to read directory contents. Check permissions.",
                "error"
            );
        })
        .finally(() => {
            hideSpinner();
        });
};

// --- RESIZER ---
const resizer = document.getElementById('ide-resizer');
const container = document.querySelector('.ide-container');

if (resizer) {
    resizer.addEventListener('mousedown', function (e) {
        e.preventDefault();
        document.addEventListener('mousemove', resize);
        document.addEventListener('mouseup', stopResize);
        resizer.classList.add('resizing');
    });
}
function resize(e) {
    const newWidth = e.clientX - container.getBoundingClientRect().left;
    if (newWidth > 180 && newWidth < 600) {
        container.style.gridTemplateColumns = `${newWidth}px 4px 1fr`;
        if (window.editor) window.editor.layout();
    }
}
function stopResize() {
    document.removeEventListener('mousemove', resize);
    resizer.classList.remove('resizing');
}

function hideSpinner() {
    if (typeof $ !== 'undefined') $('#spinner_overlay').hide();
    else {
        const o = document.getElementById('spinner_overlay');
        if (o) o.style.display = 'none';
    }
    const main = document.getElementById('spinner_main');
    if (main) main.innerHTML = '';
    if (window.editor) window.editor.layout();
}

// --- BREADCRUMBS BUILDER ---
/**
 * Updates the breadcrumb display and sets copy attributes.
 * Fixes the SyntaxError by removing duplicate 'relPath' declarations.
 */
function updateBreadcrumbs(fullPath) {
    const container = document.querySelector('.ide-filepath-display');
    if (!container) {
        return;
    }

    const relativePart = fullPath.replace(cfg.jailRoot.replace(/\/$/, ''), '').replace(/^\/+/, '');
    const copyRelPath = cfg.jailname + (relativePart ? '/' + relativePart : '');

    container.setAttribute('data-fullpath', fullPath);
    container.setAttribute('data-relpath', copyRelPath);

    const parts = relativePart.split('/').filter(p => p !== '');
    let currentPath = cfg.jailRoot.replace(/\/$/, '');

    let html = `<span class="bc-part bc-folder" data-path="${currentPath}">${cfg.jailname}</span>`;

    parts.forEach((part, index) => {
        currentPath += '/' + part;
        html += `<span class="bc-sep">/</span>`;

        const isLast = (index === parts.length - 1);

        if (isLast) {
            const titleAttr = 'title="Click: Relative path | Right-Click: Absolute path"';
            html += `
                <span class="bc-part bc-file" ${titleAttr}>
                    ${part}
                    <img src="ext/bastille/images/copy.svg" class="copy-icon-img" style="filter: brightness(2);" alt="copy">
                </span>`;
        } else {
            html += `<span class="bc-part bc-folder" data-path="${currentPath}">${part}</span>`;
        }
    });

    container.innerHTML = html;
}

// --- BREADCRUMB CLICK LISTENER (SPA NAVIGATION) ---
document.addEventListener('click', async function(e) {
    const bcPart = e.target.closest('.ide-filepath-display .bc-part');
    if (!bcPart) return;

    e.preventDefault();
    e.stopPropagation();

    const targetPath = bcPart.getAttribute('data-path');
    if (!targetPath) return;

    if (bcPart.classList.contains('bc-file')) {
        if (typeof syncSidebarWithFile === 'function') await syncSidebarWithFile();
        return;
    }

    await syncSidebarWithFolder(targetPath);

}, true);

// --- CONTEXT MENU SYSTEM ---
document.addEventListener('DOMContentLoaded', () => {

    // 1. Create and inject Context Menu
  // 1. Create and inject Context Menu
    const contextMenu = document.createElement('div');
    contextMenu.id = 'ide-context-menu';
    contextMenu.innerHTML = `
        <div class="ide-cm-item has-submenu" id="cm-new-menu">
            <div class="icon-wrapper"></div> <span class="ide-cm-item-text">New</span>
            <img src="ext/bastille/images/right-arrow.svg" class="cm-arrow" alt="arrow">
            <div class="ide-cm-submenu">
                <div class="ide-cm-item" id="cm-new-file">
                    <div class="icon-wrapper">
                        <img src="ext/bastille/images/file.svg" class="ide-cm-item-svg">
                    </div>
                    <span class="ide-cm-item-text">File</span>
                </div>
                <div class="ide-cm-item" id="cm-new-folder">
                    <div class="icon-wrapper">
                        <img src="ext/bastille/images/folder.svg" class="ide-cm-item-svg">
                    </div>
                    <span class="ide-cm-item-text">Directory</span>
                </div>
            </div>
        </div>

        <div class="ide-cm-separator"></div>

        <div class="ide-cm-item" id="cm-copy-path">
            <div class="icon-wrapper">
                <img src="ext/bastille/images/copy.svg" class="ide-cm-item-svg" alt="copy">
            </div>
            <span class="ide-cm-item-text">Copy Full Path</span>
        </div>

        <div class="ide-cm-item cm-unlock" id="cm-unlock-item" style="display: none;">
            <div class="icon-wrapper">
                <img src="ext/bastille/images/lock.svg" class="ide-cm-item-svg" alt="unlock">
            </div>
            <span class="ide-cm-item-text">Unlock (Clear Flags)</span>
        </div>

        <div class="ide-cm-item" id="cm-info-item">
            <div class="icon-wrapper">
                <img src="ext/bastille/images/info-ssl.svg" class="ide-cm-item-svg" alt="info">
            </div>
            <span class="ide-cm-item-text">Information</span>
        </div>

        <div class="ide-cm-item" id="cm-compare-history">
            <div class="icon-wrapper">
                <img src="ext/bastille/images/diff.svg" class="ide-cm-item-svg" alt="diff">
            </div>
            <span class="ide-cm-item-text">Compare History</span>
        </div>

        <div class="ide-cm-separator"></div>

        <div class="ide-cm-item cm-delete" id="cm-delete-file">
            <div class="icon-wrapper">
                <img src="ext/bastille/images/delete.svg" class="ide-cm-item-svg" alt="delete">
            </div>
            <span class="ide-cm-item-text">Delete</span>
        </div>
    `;
    document.body.appendChild(contextMenu);

    const newItemModal = document.createElement('div');
    newItemModal.id = 'ide-new-item-modal';
    newItemModal.innerHTML = `
        <div class="ide-new-item-content">
            <div id="ide-new-item-title" class="ide-new-item-title lhetop">New File TEST</div>
            <input type="text" id="ide-new-item-input" placeholder="Name" autocomplete="off" spellcheck="false">
        </div>
    `;
    document.body.appendChild(newItemModal);

    window.showNewItemModal = function(type) {
        return new Promise((resolve) => {
            const modal = document.getElementById('ide-new-item-modal');
            const titleEl = document.getElementById('ide-new-item-title');
            const input = document.getElementById('ide-new-item-input');

            titleEl.innerText = type === 'folder' ? 'New Directory' : 'New File';
            input.value = '';
            modal.style.display = 'flex';

            // Automatic focus on input
            setTimeout(() => input.focus(), 50);

            // Cleaning functions
            const cleanup = () => {
                modal.style.display = 'none';
                input.removeEventListener('keydown', handleKey);
                modal.removeEventListener('click', handleClickOutside);
            };

            const handleKey = (e) => {
                if (e.key === 'Enter') {
                    const val = input.value.trim();
                    cleanup();
                    resolve(val || null);
                } else if (e.key === 'Escape') {
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
    };

    // This variable stores the data of the item we clicked on
    let cmTargetData = null;

    // 2. Right-Click Event (Context Menu detection)
    document.querySelector('.ide-file-list').addEventListener('contextmenu', function(e) {
        const link = e.target.closest('.tree-item a');
        if (!link) {
            return;
        }

        e.preventDefault();

        const liElement = link.closest('.tree-item');
        const isFolder = liElement.classList.contains('folder-item');
        let filepath = '';

        // Extract path logic
        if (isFolder) {
            const onclickAttr = link.getAttribute('onclick');
            if (onclickAttr) {
                const match = onclickAttr.match(/toggleFolder\(.*?,\s*'([^']+)'\)/);
                if (match && match[1]) filepath = match[1];
            }
        } else {
            const url = new URL(link.href, window.location.origin);
            filepath = url.searchParams.get('filepath');
        }

        if (!filepath) {
            return;
        }

        const filenameElement = link.querySelector('span:not(.tree-caret)');
        const filename = filenameElement ? filenameElement.innerText.trim() : 'Unknown';

        // --- LOGIC TO SHOW/HIDE UNLOCK BUTTON ---
        const currentFlag = liElement.getAttribute('data-flag') || '';
        const isImmutable = currentFlag.includes('schg');

        let isParentImmutable = false;
        const parentUl = liElement.closest('ul.ide-file-list');
        if (parentUl) {
            const parentFolderItem = parentUl.closest('.folder-item');
            if (parentFolderItem) {
                const parentFlag = parentFolderItem.getAttribute('data-flag') || '';
                isParentImmutable = parentFlag.includes('schg');
            }
        }

        const unlockBtn = document.getElementById('cm-unlock-item');
        const deleteBtn = document.getElementById('cm-delete-file');

        if (isImmutable) {
            unlockBtn.style.display = 'flex';
            deleteBtn.style.display = 'none';
        } else if (isParentImmutable) {
            unlockBtn.style.display = 'none';
            deleteBtn.style.display = 'none';
        } else {
            unlockBtn.style.display = 'none';
            deleteBtn.style.display = 'flex';
        }

        // Store target data for the button actions
        cmTargetData = { filepath, filename, liElement, isFolder, flag: currentFlag };

        // Position and display menu
        contextMenu.style.display = 'block';
        let left = e.pageX;
        let top = e.pageY;

        // Boundary checks
        if (left + contextMenu.offsetWidth > window.innerWidth) left = window.innerWidth - contextMenu.offsetWidth;
        if (top + contextMenu.offsetHeight > window.innerHeight) top = window.innerHeight - contextMenu.offsetHeight;

        contextMenu.style.left = `${left}px`;
        contextMenu.style.top = `${top}px`;
    });

    // 3. Global click and Escape listeners to hide menu
    document.addEventListener('click', (e) => {
        if (e.button !== 2) contextMenu.style.display = 'none';
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') contextMenu.style.display = 'none';
    });

    // ACTION: Create New File ---
    document.getElementById('cm-new-file').addEventListener('click', async () => {
        if (!cmTargetData) return;
        contextMenu.style.display = 'none';

        const name = await showNewItemModal('file');
        if (name) executeCreateItem(name, 'file', cmTargetData);
    });

    // ACTION: Create New Folder ---
    document.getElementById('cm-new-folder').addEventListener('click', async () => {
        if (!cmTargetData) return;
        contextMenu.style.display = 'none';

        const name = await showNewItemModal('folder');
        if (name) executeCreateItem(name, 'folder', cmTargetData);
    });

    // ACTION: Copy Path
    document.getElementById('cm-copy-path').addEventListener('click', () => {
        if (!cmTargetData) return;
        const textArea = document.createElement("textarea");
        textArea.value = cmTargetData.filepath;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        contextMenu.style.display = 'none';
    });

    // ACTION: Unlock (Clear Flags)
    document.getElementById('cm-unlock-item').addEventListener('click', async () => {
        if (!cmTargetData) return;
        contextMenu.style.display = 'none';

        const ok = await showConfirmDialog(
            'Unlock Protection',
            `The item "${cmTargetData.filename}" is protected with the flag: ${cmTargetData.flag}.\n\nDo you want to remove all protection flags now?`,
            'warning'
        );

        if (ok) {
            executeUnlock(cmTargetData.filepath, cmTargetData.liElement);
        }
    });

    // // NEW ACTION: Show File Information Sidebar
    document.getElementById('cm-info-item').addEventListener('click', () => {
        if (!cmTargetData) return;
        contextMenu.style.display = 'none';
        // Call your function passing the REAL absolute path
        showFileInfo(cmTargetData.filepath);
    });

    // ACTION: Compare History (Diff Viewer)
    document.getElementById('cm-compare-history').addEventListener('click', () => {
        if (!cmTargetData) return;
        contextMenu.style.display = 'none';
        if (cmTargetData.isFolder) {
            showConfirmDialog('Error', 'You can only compare the history of a file, not a directory.', 'error');
            return;
        }
        openDiffViewer(cmTargetData.filepath, cmTargetData.filename);
    });

    // ACTION: Delete
    document.getElementById('cm-delete-file').addEventListener('click', () => {
        if (!cmTargetData) return;
        contextMenu.style.display = 'none';
        executeDelete(cmTargetData.filepath, cmTargetData.filename, cmTargetData.liElement, cmTargetData.isFolder);
    });

    const handleHeaderCreate = async (type) => {
        const targetPath = window.IDE_CONFIG.lastSelectedDir || window.IDE_CONFIG.jailRoot;
        const name = await showNewItemModal(type);
        if (name) {
            const mockTargetData = { filepath: targetPath, isFolder: true };
            if (typeof executeCreateItem === 'function') {
                executeCreateItem(name, type, mockTargetData);
            }
        }
    };

    const headerMain = document.querySelector('.ide-sidebar-header');
    const plusBtn = document.querySelector('.plus-icon');
    const plusMenu = document.querySelector('.header-plus-submenu');

    if (plusBtn && plusMenu && headerMain) {

        plusBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();

            const isNowOpen = plusMenu.classList.toggle('show');

            if (isNowOpen) {
                headerMain.classList.add('menu-open');
            } else {
                headerMain.classList.remove('menu-open');
            }
        });

        document.addEventListener('click', (e) => {
            if (!plusMenu.contains(e.target) && !plusBtn.contains(e.target)) {
                plusMenu.classList.remove('show');
                headerMain.classList.remove('menu-open');
            }
        });

        const resetHeader = () => {
            plusMenu.classList.remove('show');
            headerMain.classList.remove('menu-open');
        };

        document.getElementById('header-new-file')?.addEventListener('click', () => {
            resetHeader();
            handleHeaderCreate('file');
        });

        document.getElementById('header-new-folder')?.addEventListener('click', () => {
            resetHeader();
            handleHeaderCreate('folder');
        });
    }

});

/**
 * Sends a request to the backend to remove FreeBSD chflags
 *
 * @param {string} filepath - Full path of the item
 * @param {HTMLElement} liElement - The tree item element to update UI
 */
window.executeUnlock = async function(filepath, liElement) {
    if (typeof spinner === 'function') {
        spinner();
    }
    // Use the main form to inherit security tokens from XigmaNAS
    const form = document.getElementById('iform');
    const formData = new FormData(form);
    formData.set('ajax_unlock', '1');
    formData.set('filepath', filepath);
    formData.set('jailname', cfg.jailname);
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (data.success) {
            // Success: Remove the lock icon from the UI
            const lock = liElement.querySelector('.lock-icon');
            if (lock) {
                lock.remove();
            }
            // Clear the flag data so the context menu doesn't show "Unlock" anymore
            liElement.dataset.flag = '';
            showConfirmDialog('Unlocked', 'Flags removed successfully. You can now edit or delete the item.', 'success');
        } else {
            throw new Error(data.error || 'Failed to remove flags.');
        }
    } catch (e) {
        console.error("Unlock Error:", e);
        showConfirmDialog('Error', 'Failed to unlock: ' + e.message, 'error');
    } finally {
        hideSpinner();
    }
};

// --- DELETE LOGIC ENGINE ---
window.executeDelete = async function(filepath, fileName, liElement, isFolder = false) {
    let shortName = fileName;
    if (fileName.length > 35) {
        shortName = fileName.substring(0, 18) + '...' + fileName.substring(fileName.length - 12);
    }

    let modalTitle = 'Delete "' + shortName + '"?';
    let modalMessage = `Are you sure you want to delete "${shortName}"?`;

    if (isFolder) {
        modalTitle = 'Delete Directory';
        modalMessage = `Delete directory "${shortName}"?\nAll files and subdirectories in "${fileName}" will be deleted.\nYou might not be able to fully undo this operation!`;
    }

    const ok = await showConfirmDialog(modalTitle, modalMessage, 'delete');
    if (!ok) return;

    if (typeof spinner === 'function') spinner();

    const form = document.getElementById('iform');
    if (!form) {
        console.error("Critical: #iform not found. Cannot bypass NAS security.");
        hideSpinner();
        return;
    }

    const formData = new FormData(form);

    formData.set('ajax_delete', '1');
    formData.set('delete_file', '1');
    formData.set('filepath', filepath);
    formData.set('jailname', cfg.jailname);

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        if (!response.ok) {
            throw new Error(`Server returned status ${response.status}`);
        }

        const data = await response.json();

        if (data.success) {
            liElement.style.transition = "opacity 0.2s, height 0.2s";
            liElement.style.opacity = '0';
            setTimeout(() => liElement.remove(), 200);

            const currentOpenFile = document.querySelector('input[name="filepath"]')?.value;
            if (currentOpenFile && currentOpenFile.startsWith(filepath)) {
                if (window.editor) {
                    window.editor.setValue("# Item deleted.\n# Select another file from the sidebar.");
                    window.editor.updateOptions({ readOnly: true });
                }
                if (typeof clearDirtyState === 'function') clearDirtyState();
                const container = document.querySelector('.ide-filepath-display');
                if (container) container.innerHTML = `<span style="color: #d32f2f; font-weight: bold;">Deleted</span>`;
            }

            const successType = isFolder ? 'Directory' : 'File';
            //showConfirmDialog(`${successType} Deleted`, `The ${successType.toLowerCase()} "${fileName}" was successfully removed.`, 'success');

        } else {
            throw new Error(data.error || 'Failed to delete item.');
        }
    } catch (error) {
        console.error("Delete Error:", error);
        showConfirmDialog('Error', error.message, 'error');
    } finally {
        hideSpinner();
    }
};

// --- FILE UPLOAD HANDLER ---
document.addEventListener('DOMContentLoaded', () => {
    const uploadButton = document.getElementById('ide-upload-button');
    const sidebar = document.querySelector('.ide-sidebar');
    let dragCounter = 0;

    // --- 1. MANUAL DESTINATION SELECTION ---
    document.addEventListener('click', (e) => {
        // Ensure we only trigger when clicking exactly on the link text/icon
        const link = e.target.closest('.tree-item a');

        if (link) {
            const item = link.closest('.tree-item');

            // FULL CLEANUP: Remove both is-selected-target and active from ALL tree items
            document.querySelectorAll('.tree-item').forEach(el => {
                el.classList.remove('is-selected-target', 'active');
            });

            // Mark ONLY the item we just clicked
            item.classList.add('is-selected-target');
            item.classList.add('active');

            let path = window.IDE_CONFIG.jailRoot;

            if (item.classList.contains('folder-item')) {
                // Extract path from folder onclick attribute
                const match = link.getAttribute('onclick')?.match(/['"]([^'"]+)['"]/);
                if (match) path = match[1];
            } else if (item.classList.contains('file-item')) {
                // Extract parent directory path from file URL
                const url = new URL(link.href, window.location.origin);
                const filepath = url.searchParams.get('filepath');
                if (filepath) path = filepath.substring(0, filepath.lastIndexOf('/'));
            }
            window.IDE_CONFIG.lastSelectedDir = path;
            //console.log("Destination set to:", path);
        }
    });

    // --- 2. UPLOAD button ---
    if (uploadButton) {
        uploadButton.addEventListener('change', async function() {
            if (this.files.length > 0) {
                const dest = window.IDE_CONFIG.lastSelectedDir
                             || window.IDE_CONFIG.currentDir
                             || window.IDE_CONFIG.jailRoot;

                const filesToUpload = Array.from(this.files);
                //console.log("Manual Uploading to:", dest);
                await handleFileUpload(filesToUpload, dest);
                this.value = '';
            }
        });
    }

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        document.addEventListener(eventName, e => {
            e.preventDefault();
        }, false);
    });

    document.addEventListener('dragenter', () => {
        dragCounter++;
        sidebar.classList.add('drag-over-active');
        document.body.classList.add('is-dragging');
    });

    sidebar.addEventListener('dragover', (e) => {
        document.querySelectorAll('.drag-target').forEach(el => el.classList.remove('drag-target'));

        const folder = e.target.closest('.folder-item');
        if (folder) {
            folder.classList.add('drag-target');
        }
    });

    sidebar.addEventListener('drop', (e) => {
        dragCounter = 0;
        sidebar.classList.remove('drag-over-active');
        document.querySelectorAll('.drag-target').forEach(el => el.classList.remove('drag-target'));
        document.body.classList.remove('is-dragging');

        const targetFolder = e.target.closest('.folder-item');
        const files = e.dataTransfer.files;
        if (files.length === 0) return;

        let destination = window.IDE_CONFIG.jailRoot;

        if (targetFolder) {
            const match = targetFolder.querySelector('a').getAttribute('onclick')?.match(/'([^']+)'/);
            if (match) destination = match[1];
        } else {
            destination = window.IDE_CONFIG.lastSelectedDir || window.IDE_CONFIG.currentDir || window.IDE_CONFIG.jailRoot;
        }

        console.log("Drag & Drop target:", destination);
        handleFileUpload(files, destination);
    });

    document.addEventListener('dragleave', (e) => {
        if (!e.relatedTarget) {
            dragCounter = 0;
            sidebar.classList.remove('drag-over-active');
            document.querySelectorAll('.drag-target').forEach(el => el.classList.remove('drag-target'));
            document.body.classList.remove('is-dragging');
        }
    });
});

// --- Upload function UNIFIED HIGH-SPEED CHUNKED UPLOADER ---
async function handleFileUpload(files, destination) {
    // 200MB chunks for maximum network throughput
    const CHUNK_SIZE = 25 * 1024 * 1024;

    window.isUploading = true;
    window.cancelUpload = false;
    window.uploadController = new AbortController();

    // Ensure upload modal/UI is visible
    const overlay = document.getElementById('upload-modal-overlay');
    if (overlay) overlay.style.display = 'flex';
    if (typeof setUploadState === 'function') setUploadState('progress');

    const bar = document.getElementById('up-progress-fill');
    const pText = document.getElementById('up-progress-percent');
    const fName = document.getElementById('up-current-filename');
    const fHash = document.getElementById('up-file-hash');

    for (let i = 0; i < files.length; i++) {
        if (window.cancelUpload) break;
        const file = files[i];

        // STEP 1: PRE-CALCULATE HASH
        if (fName) fName.innerText = `[${i+1}/${files.length}] Calculating signature...`;
        if (fHash) fHash.innerText = "Processing SHA-256...";
        if (bar) { bar.style.width = '0%'; bar.style.background = "#9c27b0"; }

        const fullFileHash = await calculateFileHash(file, (p) => {
            if (pText) pText.innerText = `Hashing: ${p}%`;
            if (bar) bar.style.width = p + '%';
        });

        if (window.cancelUpload) break;

        if (fHash) fHash.innerText = `Hash: ${fullFileHash}`;
        if (bar) bar.style.background = "#3875d6";

        // STEP 2: CHUNKED UPLOAD
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        const relPath = file.customRelativePath || file.webkitRelativePath || file.name;

        // Variables for calculating speed
        let startTime = Date.now();
        let lastLoaded = 0;

        for (let chunkIdx = 0; chunkIdx < totalChunks; chunkIdx++) {
            if (window.cancelUpload) break;

            const start = chunkIdx * CHUNK_SIZE;
            const end = Math.min(start + CHUNK_SIZE, file.size);
            const chunk = file.slice(start, end);

            const formData = new FormData(document.getElementById('iform'));
            formData.append('ajax_upload_chunk', '1');
            formData.append('chunk_index', chunkIdx);
            formData.append('total_chunks', totalChunks);
            formData.append('file_name', file.name);
            formData.append('relative_path', relPath);
            formData.append('target_dir', destination);
            formData.append('file_chunk', chunk);

            // --- THE XHR ENGINE FOR REAL-TIME PROGRESS & SPEED ---
            try {
                await new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', window.location.href, true);

                    // Signal abort handling
                    window.uploadController.signal.addEventListener('abort', () => {
                        xhr.abort();
                        reject(new DOMException('Aborted', 'AbortError'));
                    });

                    // Track upload progress of THIS specific chunk
                    xhr.upload.onprogress = (e) => {
                        if (e.lengthComputable) {
                            const totalUploadedSoFar = (chunkIdx * CHUNK_SIZE) + e.loaded;
                            const percent = Math.min(Math.round((totalUploadedSoFar / file.size) * 100), 100);

                            if (bar) bar.style.width = percent + '%';
                            if (pText) pText.innerText = `${percent}%`;

                            // Speed Calculation (MB/s)
                            const now = Date.now();
                            const timeDiff = (now - startTime) / 1000; // in seconds
                            if (timeDiff > 0.5) { // Update speed every 500ms
                                const bytesLoaded = totalUploadedSoFar - lastLoaded;
                                const speedBps = bytesLoaded / timeDiff;
                                const speedMBps = (speedBps / (1024 * 1024)).toFixed(1);

                                if (fName) fName.innerText = `[${i+1}/${files.length}] Uploading: ${file.name} (${speedMBps} MB/s)`;

                                startTime = now;
                                lastLoaded = totalUploadedSoFar;
                            }
                        }
                    };

                    xhr.onload = () => {
                        if (xhr.status >= 200 && xhr.status < 300) {
                            resolve(xhr.responseText);
                        } else {
                            reject(new Error(`Server Error: ${xhr.status}`));
                        }
                    };

                    xhr.onerror = () => reject(new Error('Network error during upload'));

                    xhr.send(formData);
                });

            } catch (err) {
                if (err.name === 'AbortError') return;
                console.error("Chunk upload error:", err);
                break;
            }
        }

        // STEP 3: FINAL VERIFICATION
        if (!window.cancelUpload) {
            if (pText) pText.innerText = "Verifying on server...";
            if (bar) bar.style.background = "#ff9800";
            if (fName) fName.innerText = `[${i+1}/${files.length}] Checking integrity...`;

            const verifyData = new FormData(document.getElementById('iform'));
            verifyData.append('ajax_verify_hash', '1');
            verifyData.append('target_dir', destination);
            verifyData.append('relative_path', relPath);
            verifyData.append('expected_hash', fullFileHash);

            try {
                const vRes = await fetch(window.location.href, { method: 'POST', body: verifyData });
                const vJson = await vRes.json();

                if (vJson.success) {
                    if (bar) bar.style.background = "#4caf50";
                    if (typeof injectItemIntoTree === 'function') {
                        injectItemIntoTree(destination, file.name, false);
                    }
                } else {
                    showConfirmDialog("Error", `Integrity fail for ${file.name}!`, "error");
                }
            } catch (e) {
                console.error("Verification error:", e);
            }
        }
    }

    window.isUploading = false;

    // Auto-close modal after 2 seconds if successful
    if (!window.cancelUpload) {
        setTimeout(() => {
            if (typeof closeUploadModal === 'function') closeUploadModal();
        }, 2000);
    }
}

// --- 3. HANDLE NATIVE INPUTS (Buttons from Upload Modal) ---
window.handleNativeUpload = function(inputElement, isFolder) {
    if (!inputElement.files || inputElement.files.length === 0) {
        return;
    }
    const destination = window.IDE_CONFIG.lastSelectedDir || window.IDE_CONFIG.currentDir || window.IDE_CONFIG.jailRoot;
    const filesArray = Array.from(inputElement.files);
    if (typeof handleFileUpload === 'function') {
        handleFileUpload(filesArray, destination);
    } else {
        console.error("Critical: handleFileUpload engine is missing.");
    }
    inputElement.value = '';
};

// --- OPTIMISTIC UI: INJECT NEW FILE ---
function injectItemIntoTree(destination, itemName, isFolder = false) {
    let parentLi = null;

    // We search for the parent folder in the tree
    if (destination === window.IDE_CONFIG.jailRoot) {
        parentLi = document.querySelector('#fileList > li.folder-item');
    } else {
        const links = document.querySelectorAll('.folder-item > a');
        for (let a of links) {
            if (a.getAttribute('onclick')?.includes(`'${destination}'`)) {
                parentLi = a.closest('li');
                break;
            }
        }
    }

    if (!parentLi) {
        return;
    }

    const ul = parentLi.querySelector('ul');
    if (!ul) {
        return;
    }

    const li = document.createElement('li');
    li.className = isFolder ? 'tree-item folder-item' : 'tree-item file-item';
    li.dataset.flag = '';

    li.style.opacity = '0';
    li.style.transition = 'opacity 0.5s ease-in, background-color 0.5s';

    const fullPath = destination + '/' + itemName;

    if (isFolder) {
        const safePath = fullPath.replace(/'/g, "\\'");
        li.innerHTML = `
            <a href="javascript:void(0)" onclick="toggleFolder(this, '${safePath}')">
                ${window.IDE_CONFIG.icons.caret} ${window.IDE_CONFIG.icons.folder} <span>${itemName}</span>
            </a>
        `;
    } else {
        const editUrl = `?jailname=${encodeURIComponent(window.IDE_CONFIG.jailname)}&dir=${encodeURIComponent(destination)}&filepath=${encodeURIComponent(fullPath)}`;
        li.innerHTML = `
            <a href="${editUrl}" onclick="if(typeof spinner === 'function') spinner();">
                ${window.IDE_CONFIG.icons.file} <span>${itemName}</span>
            </a>
        `;
    }

    ul.appendChild(li);

    requestAnimationFrame(() => {
        li.style.opacity = '1';
        li.querySelector('a').style.backgroundColor = 'rgba(76, 175, 80, 0.2)';

        setTimeout(() => {
            li.querySelector('a').style.backgroundColor = '';
        }, 1000);
    });
}

// --- CREATE ITEM LOGIC ---
window.executeCreateItem = async function(name, type, targetData) {
    if (typeof spinner === 'function') {
        spinner();
    }

    let parentPath = targetData.filepath;
    if (!targetData.isFolder) {
        parentPath = targetData.filepath.substring(0, targetData.filepath.lastIndexOf('/'));
    }

    const form = document.getElementById('iform');
    const formData = new FormData(form);

    formData.set('ajax_create_item', '1');
    formData.set('parent_dir', parentPath);
    formData.set('new_name', name);
    formData.set('type', type);
    formData.set('jailname', window.IDE_CONFIG.jailname);

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (data.success) {
            injectItemIntoTree(parentPath, name, type === 'folder');
        } else {
            throw new Error(data.error || 'Failed to create item.');
        }
    } catch (e) {
        console.error("Create Error:", e);
        showConfirmDialog('Error', e.message, 'error');
    } finally {
        hideSpinner();
    }
};

// // --- HYBRID UPLOAD MODAL CONTROLLER ---
// // 1. Global state for uploads
window.isUploading = false;
window.cancelUpload = false;

// // 2. UI State Management
function openUploadModal() {
    // // Make sure the contextual menu from "+" is closed
    const plusSubmenu = document.querySelector('.header-plus-submenu');
    if (plusSubmenu) plusSubmenu.classList.remove('show');

    // // Reset UI and open
    setUploadState('selector');
    document.getElementById('upload-modal-overlay').style.display = 'flex';
}

function closeUploadModal() {
    if (window.isUploading) {
        if (!confirm("Upload in progress. Are you sure you want to cancel?")) return;
        window.cancelUpload = true;
        if (window.uploadController) {
            window.uploadController.abort(); //cut http connection
        }
    } else {
        document.getElementById('upload-modal-overlay').style.display = 'none';
        // // Clear inputs to allow re-selection
        document.getElementById('up-file-input').value = '';
        document.getElementById('up-folder-input').value = '';
        document.getElementById('up-remote-url').value = '';
    }
}

function setUploadState(state) {
    const selector = document.getElementById('up-state-selector');
    const progress = document.getElementById('up-state-progress');
    const cancelBtn = document.getElementById('up-cancel-btn');

    if (state === 'selector') {
        selector.style.display = 'block';
        progress.style.display = 'none';
        cancelBtn.innerText = 'Close';
    } else if (state === 'progress') {
        selector.style.display = 'none';
        progress.style.display = 'block';
        cancelBtn.innerText = 'Cancel Upload';
    }
}

// // 4. Handle Remote URL Import
async function handleRemoteDownload() {
    const urlInput = document.getElementById('up-remote-url');
    const url = urlInput.value.trim();
    if (!url) {
        alert("Please enter a valid URL.");
        return;
    }

    const destination = window.IDE_CONFIG.lastSelectedDir || window.IDE_CONFIG.jailRoot;

    // // Prepare UI
    setUploadState('progress');
    document.getElementById('up-current-filename').innerText = "Downloading from remote URL...";
    document.getElementById('up-progress-fill').style.width = '100%';
    document.getElementById('up-progress-percent').innerText = "Processing...";
    document.getElementById('up-progress-info').innerText = url;
    window.isUploading = true;

    const formData = new FormData(document.getElementById('iform'));
    formData.append('ajax_remote_download', '1');
    formData.append('remote_url', url);
    formData.append('target_dir', destination);

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            showConfirmDialog("Success", "File imported from URL successfully.", "success");
            if (typeof injectItemIntoTree === 'function') injectItemIntoTree(destination, data.fileName, false);
            closeUploadModal();
        } else {
            throw new Error(data.error || "Failed to download.");
        }
    } catch (err) {
        showConfirmDialog("Error", err.message, "error");
        setUploadState('selector');
    } finally {
        window.isUploading = false;
        urlInput.value = '';
    }
}

async function calculateFileHash(file, onProgress) {
    const hasher = sha256.create();
    const size = file.size;
    const sliceSize = 50 * 1024 * 1024; // FIXME
    let offset = 0;

    while (offset < size) {
        if (window.cancelUpload) {
            return null;
        }
        const slice = file.slice(offset, offset + sliceSize);
        const buffer = await slice.arrayBuffer();
        hasher.update(buffer);
        offset += sliceSize;

        const percent = Math.round((offset / size) * 100);
        onProgress(Math.min(percent, 100));
    }
    return hasher.hex();
}

// --- RECURSIVE DIRECTORY SCANNER ---
async function scanFiles(item, container, path = "") {
    if (item.isFile) {
        // It's a file, we extract it using a Promise
        const file = await new Promise((resolve) => item.file(resolve));

        file.customRelativePath = path + file.name;

        container.push(file);
    } else if (item.isDirectory) {
        // It's a directory, we need to read its contents
        let directoryReader = item.createReader();

        // Read all entries inside the folder
        let entries = await new Promise((resolve) => {
            directoryReader.readEntries(resolve);
        });

        // Loop through entries and scan them recursively
        for (let entry of entries) {
            await scanFiles(entry, container, path + item.name + "/");
        }
    }
}

// 6. Hook up the Drop Zone inside the Modal
const dropZone = document.querySelector('.up-drop-zone');
if (dropZone) {
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) { e.preventDefault(); e.stopPropagation(); }

    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.style.borderColor = '#3875d6', false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.style.borderColor = 'transparent', false);
    });

    dropZone.addEventListener('drop', async (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.style.borderColor = 'transparent';

        const destination = window.IDE_CONFIG.lastSelectedDir || window.IDE_CONFIG.jailRoot;
        const items = e.dataTransfer.items;
        if (!items) return;

        let filesToUpload = [];
        let entries = []; // // NEW: Safe array to store entries

        // // 1. Synchronously extract all entries FIRST before any 'await'
        for (let i = 0; i < items.length; i++) {
            const entry = items[i].webkitGetAsEntry();
            if (entry) {
                entries.push(entry);
            }
        }

        // // 2. Now we can safely use 'await' because 'entries' won't be deleted by the browser
        for (let i = 0; i < entries.length; i++) {
            await scanFiles(entries[i], filesToUpload);
        }

        // // 3. Start the upload engine
        if (filesToUpload.length > 0) {
            handleFileUpload(filesToUpload, destination);
        }
    });
}

// // --- FILE INFO SIDEBAR LOGIC ---

function closeInfoSidebar() {
    document.getElementById('ide-info-sidebar').classList.remove('open');
}

async function showFileInfo(filePath) {
    const sidebar = document.getElementById('ide-info-sidebar');
    const content = document.getElementById('info-sidebar-content');

    sidebar.classList.add('open');
    content.innerHTML = '<div style="text-align:center; padding-top:50px; color:#adb5bd;">Loading...</div>';

    const formData = new FormData(document.getElementById('iform'));
    formData.append('ajax_get_info', '1');
    formData.append('target_path', filePath);

    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        currentFileData = await response.json();

        if (currentFileData.success) {
            // Update Header
            document.getElementById('sidebar-filename').innerText = currentFileData.name;
            // Force Overview tab when opening
            switchTab('overview', document.querySelector('.tab-link'));
        }
    } catch (e) {
        content.innerHTML = '<div class="modern-card" style="color:red">Connection error</div>';
    }
}

function switchTab(tabName, element) {
    if (!currentFileData) return;

    // // UI: Gestionar clases activas
    if (element) {
        document.querySelectorAll('.tab-link').forEach(t => t.classList.remove('active'));
        element.classList.add('active');
    }

    const content = document.getElementById('info-sidebar-content');
    let html = '';

    if (tabName === 'overview') {
        html = `
            <div class="modern-card">
                <div class="card-label">Item Type</div>
                <div class="card-value">${currentFileData.type}</div>
            </div>
            <div class="modern-card">
                <div class="card-label">Last Modified</div>
                <div class="card-value">${currentFileData.modified}</div>
            </div>
            <div class="modern-card">
                <div class="card-label">Full Path</div>
                <div class="card-value" style="font-size:11px; color:#6c757d;">${currentFileData.path}</div>
            </div>
        `;
    } else if (tabName === 'security') {
        html = `
            <div class="modern-card">
                <div class="card-label">Permissions</div>
                <div class="card-value">${currentFileData.permissions} (${currentFileData.octal})</div>
            </div>
            <div class="modern-card">
                <div class="card-label">Ownership</div>
                <div class="card-value">${currentFileData.owner}:${currentFileData.group}</div>
            </div>
        `;
    } else if (tabName === 'storage') {
        html = `
            <div class="modern-card">
                <div class="card-label">Actual Size</div>
                <div class="card-value">${currentFileData.size}</div>
            </div>
            <div class="modern-card">
                <div class="card-label">Volume Impact</div>
                <div id="storage-chart-container" style="min-height: 220px;"></div>
            </div>
        `;
    }

    content.innerHTML = html;

    // Graphics rendering
    if (tabName === 'storage') {
        setTimeout(() => {
            renderStorageChart();
        }, 100);
    }
}

/*
* We created a donut chart to show the free and used space for the selected file or directory.
*/
function renderStorageChart() {
    if (!currentFileData || !currentFileData.chart) {
        return;
    }

    let realUsage = currentFileData.chart.usage;

    let visualUsage = (realUsage > 0 && realUsage < 1) ? 1 : realUsage;
    let visualOthers = 100 - visualUsage;

    const chartColor = realUsage > 50 ? '#e74c3c' : '#3875d6';

    const options = {
        series: [visualUsage, visualOthers],
        chart: {
            type: 'donut',
            height: 220,
            fontFamily: 'Inter, sans-serif'
        },
        colors: [chartColor, '#78AAFF'],
        labels: ['This Item', 'Free Space'],
        stroke: { show: true, colors: '#ffffff', width: 2 },
        dataLabels: { enabled: false },
        fill: { type: 'gradient'},
        states: {
                    hover: {
                        filter: {
                            type: 'darken',
                            value: 0.15
                        }
                    },
                    active: {
                        allowMultipleDataPointsSelection: false,
                        filter: {
                            type: 'darken',
                            value: 0.2
                        }
                    }
                },
        tooltip: {
            theme: 'dark',
            fillSeriesColor: false,
            y: {
                formatter: function (val, opts) {
                    if (opts.seriesIndex === 0) return realUsage + "% of total volume";
                    return (100 - realUsage).toFixed(2) + "% of total volume";
                }
            }
        },

        plotOptions: {
            pie: {
                donut: {
                    size: '78%',
                    labels: {
                        show: true,
                        name: {
                            show: true,
                            color: '#adb5bd',
                            fontSize: '11px',
                            fontWeight: 700
                        },

                        value: {
                            show: true,
                            color: '#1a1e23',
                            fontSize: '18px',
                            fontWeight: 600,
                            formatter: function (val) {
                                let numVal = parseFloat(val);
                                 if (isNaN(numVal)) {
                                    return val;
                                 }
                                 if (numVal === visualUsage) {
                                    return realUsage + "%";
                                 }
                                 return numVal.toFixed(2) + "%";
                            }
                        },
                        total: {
                            show: true,
                            showAlways: true,
                            label: 'ITEM SIZE',
                            color: '#adb5bd',
                            formatter: function () {
                                return currentFileData.size;
                            }
                        }
                    }
                }
            }
        },
        legend: {
            position: 'bottom',
            fontSize: '12px',
            markers: { radius: 12 }
        }
    };

    const chartContainer = document.querySelector("#storage-chart-container");
    chartContainer.innerHTML = '';

    const chart = new ApexCharts(chartContainer, options);
    chart.render();
}
// --- MONACO INIT ---
const MONACO_NODE_MODULES = '/ext/bastille/js/vs';
if (typeof require !== 'undefined') {
    require.config({
        paths: { vs: MONACO_NODE_MODULES },
        ignoreDuplicateModules: ['vs/editor/editor.main'],
    });

    window.MonacoEnvironment = {
        getWorkerUrl: function (workerId, label) {
            const absolutePath = window.location.origin + MONACO_NODE_MODULES;
            const workerCode = `self.MonacoEnvironment = { baseUrl: '${absolutePath}' }; importScripts('${absolutePath}/base/worker/workerMain.js');`;
            return `data:text/javascript;charset=utf-8,${encodeURIComponent(workerCode)}`;
        },
    };

    require(['vs/editor/editor.main'], function () {
        let filepath = cfg.filepath || '';
        let fileContent = document.getElementById('file_content').value || '';

        let lang = 'plaintext';
        if (filepath !== '') {
            let fileExt = filepath.split('.').pop().toLowerCase();
            lang = 'shell';
            if (['php', 'inc'].includes(fileExt)) lang = 'php';
            else if (fileExt === 'xml') lang = 'xml';
            else if (fileExt === 'js') lang = 'javascript';
            else if (fileExt === 'css') lang = 'css';
            else if (fileExt === 'json') lang = 'json';
            else if (['html', 'htm'].includes(fileExt)) lang = 'html';
        } else {
            fileContent = "# Welcome to Bastille Editor\n# Select a file from the sidebar to start editing.";
            lang = 'shell';
        }

        window.editor = monaco.editor.create(document.getElementById('monaco-container'), {
            value: fileContent,
            language: lang,
            theme: 'vs',
            automaticLayout: true,
            wordWrap: 'on',
            minimap: { enabled: true },
            fontSize: 11,
            readOnly: (filepath === '')
        });

        window.editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, function () {
            executeSaved();
        });

        window.editor.onDidChangeModelContent(function () {
            if (isInjectingCode) return;
            if (!isDirty) {
                isDirty = true;
                const saveBtn = document.getElementById('btn_save');
                if (saveBtn) {
                    saveBtn.disabled = false;
                }
                const activeFileLink = document.querySelector('.tree-item.active > a');
                if (activeFileLink && !activeFileLink.querySelector('.dirty-dot')) {
                    const dot = document.createElement('span');
                    dot.className = 'dirty-dot';
                    dot.innerHTML = '•';
                    activeFileLink.appendChild(dot);
                }
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    // We inject the Modal from the Diff Viewer
    const diffModal = document.createElement('div');
    diffModal.id = 'ide-diff-modal';
    diffModal.className = 'diff-modal-overlay';
    diffModal.innerHTML = `
        <div class="diff-modal-content">
            <div class="diff-modal-header lhetop">
                <div>
                    <strong style="color: #fff; font-size: 16px;">History Compare:</strong>
                    <span id="diff-filename" class="diff-filename">filename.php</span>
                    <select id="diff-backup-select">
                        <option value="">Loading backups...</option>
                    </select>
                </div>
                <div style="display:flex; gap: 10px;">
                    <button class="diff-close-x" onclick="closeDiffViewer()" title="Close">&times;</button>
                </div>
            </div>
            <div class="diff-modal-body" id="diff-monaco-container"></div>
        </div>
    `;
    document.body.appendChild(diffModal);
});

// --- MONACO DIFF VIEWER ENGINE ---
window.openDiffViewer = async function(filepath, filename) {
    if (typeof spinner === 'function') spinner();

    currentDiffFilepath = filepath;
    document.getElementById('diff-filename').innerText = filename;

    const selectEl = document.getElementById('diff-backup-select');
    selectEl.innerHTML = '<option value="">Loading backups...</option>';
    selectEl.disabled = true;

    // 1. Mostrar la Modal
    const modal = document.getElementById('ide-diff-modal');
    modal.style.display = 'flex';

    // 2. Crear la instancia del Diff Editor si no existe
    if (!diffEditorInstance) {
        diffEditorInstance = monaco.editor.createDiffEditor(document.getElementById('diff-monaco-container'), {
            theme: 'vs', // Pon 'vs-dark' si quieres que el modal sea oscuro
            readOnly: true,
            automaticLayout: true,
            renderSideBySide: true, // Pantalla dividida
            ignoreTrimWhitespace: false
        });
    }

    // 3. Obtener la lista de backups desde PHP
    const formData = new FormData(document.getElementById('iform'));
    formData.append('ajax_get_backups', '1');
    formData.append('filepath', filepath);

    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        // --- SAFE JSON PARSING ---
        const rawText = await response.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (e) {
            console.error("SERVER REJECTED DIFF REQUEST. RAW OUTPUT:", rawText);
            throw new Error("Invalid server response. Check console.");
        }
        // -------------------------
        if (data.success && data.backups && data.backups.length > 0) {
            // ... resto de tu código para llenar el select ...
            selectEl.innerHTML = '';
            data.backups.forEach(bak => {
                const opt = document.createElement('option');
                opt.value = bak.path;
                opt.innerText = bak.date;
                selectEl.appendChild(opt);
            });
            selectEl.disabled = false;
            await loadBackupDiff(data.backups[0].path);
        } else {
            selectEl.innerHTML = '<option value="">No history found</option>';
        }
    } catch (err) {
        console.error("Diff Engine Error:", err);
        selectEl.innerHTML = '<option value="">Error loading history</option>';
    }
};

window.loadBackupDiff = async function(backupPath) {
    if (typeof spinner === 'function') spinner();

    // 1. Obtener el contenido del Backup (Izquierda)
    const formData = new FormData(document.getElementById('iform'));
    formData.append('ajax_read_backup', '1');
    formData.append('bak_path', backupPath);

    try {
        const resBak = await fetch(window.location.href, { method: 'POST', body: formData });
        const dataBak = await resBak.json();
        const backupContent = dataBak.success ? dataBak.content : "Error loading backup content.";

        // 2. Obtener el contenido actual (Derecha)
        let currentContent = "";
        const activeFilepath = document.querySelector('input[name="filepath"]')?.value;

        // Si el archivo que estamos comparando es el que está abierto AHORA en el editor,
        // usamos el contenido del editor (así vemos las diferencias incluso si no hemos guardado)
        if (currentDiffFilepath === activeFilepath && window.editor) {
            currentContent = window.editor.getValue();
        } else {
            // If it is not open, we request the current content from the server.
            const resCur = await fetch(window.location.pathname + '?ajax=1&filepath=' + encodeURIComponent(currentDiffFilepath));
            if (resCur.ok) currentContent = await resCur.text();
        }

        // Detecting language for syntax coloring
        let fileExt = currentDiffFilepath.split('.').pop().toLowerCase();
        let lang = 'shell';
        if (['php', 'inc'].includes(fileExt)) lang = 'php';
        else if (fileExt === 'xml') lang = 'xml';
        else if (fileExt === 'js') lang = 'javascript';
        else if (fileExt === 'css') lang = 'css';
        else if (fileExt === 'json') lang = 'json';
        else if (['html', 'htm'].includes(fileExt)) lang = 'html';

        // Inject the models into the Diff Editor
        const originalModel = monaco.editor.createModel(backupContent, lang);
        const modifiedModel = monaco.editor.createModel(currentContent, lang);

        diffEditorInstance.setModel({
            original: originalModel,
            modified: modifiedModel
        });

    } catch (err) {
        console.error("Diff Load Error:", err);
    } finally {
        if (typeof hideSpinner === 'function') hideSpinner();
    }
};

window.closeDiffViewer = function() {
    document.getElementById('ide-diff-modal').style.display = 'none';
};

// --- PREVENT DATA LOSS (F5) ---
window.addEventListener('beforeunload', function (e) {
    if (isDirty) {
        e.preventDefault();
        return '';
    }
});
