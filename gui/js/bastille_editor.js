/**
 * bastille_editor.js - Core Javascript Engine
 */

let searchTimer;
let selectedIndex = -1;
let isDirty = false;
let isInjectingCode = false;
let sidebarTimer;
let originalSidebarHTML = '';

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
        // 1. Manage active state safely
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

        // 2. Sync hidden form inputs for Save button
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
                    .then(response => response.json())
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
                return Array.from(spans)
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
        .then((res) => res.json())
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
        })
        .finally(() => {
            if (typeof hideSpinner === 'function') hideSpinner();
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
                    <img src="ext/bastille/images/copy.svg" class="copy-icon-img" alt="copy">
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
    const contextMenu = document.createElement('div');
    contextMenu.id = 'ide-context-menu';
    contextMenu.innerHTML = `
        <div class="ide-cm-item has-submenu" id="cm-new-menu">
            <span>New</span>
            <img src="ext/bastille/images/right-arrow.svg" class="cm-arrow" alt="arrow">
            <div class="ide-cm-submenu">
                    <div class="ide-cm-item" id="cm-new-file">
                        <img src="ext/bastille/images/file.svg" width="16" style="margin: 0;"> File
                    </div>
                    <div class="ide-cm-item" id="cm-new-folder">
                        <img src="ext/bastille/images/folder.svg" width="16" style="margin: 0px;"> Directory
                    </div>
                </div>
            </div>
        <div class="ide-cm-separator"></div>

        <div class="ide-cm-item" id="cm-copy-path">
            <img src="ext/bastille/images/copy.svg" class="copy-icon-img" alt="copy" style="margin: 0"> Copy Full Path
        </div>

        <div class="ide-cm-item cm-unlock" id="cm-unlock-item" style="display: none;">
            <img src="ext/bastille/images/lock.svg" class="lock-icon" alt="unlock" style="width: 18px; height: 18px; margin: 0px;">
            Unlock (Clear Flags)
        </div>

        <div class="ide-cm-separator"></div>

        <div class="ide-cm-item cm-delete" id="cm-delete-file">
            <img src="ext/bastille/images/delete.svg" class="delete-icon-img" alt="delete" style="width: 20px; height: 20px;"> Delete
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

    // 6. ACTION: Delete
    document.getElementById('cm-delete-file').addEventListener('click', () => {
        if (!cmTargetData) return;
        contextMenu.style.display = 'none';
        executeDelete(cmTargetData.filepath, cmTargetData.filename, cmTargetData.liElement, cmTargetData.isFolder);
    });
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
                    return Array.from(spans).some(span => span.innerText.trim() === segment);
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
                const match = link.getAttribute('onclick')?.match(/'([^']+)'/);
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

    // --- 2. EL BOTÓN UPLOAD ---
    if (uploadButton) {
        uploadButton.addEventListener('change', function() {
            if (this.files.length > 0) {
                const dest = window.IDE_CONFIG.lastSelectedDir
                             || window.IDE_CONFIG.currentDir
                             || window.IDE_CONFIG.jailRoot;

                console.log("Subiendo manual a:", dest);
                handleFileUpload(this.files, dest);
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

        console.log("Drag & Drop destino:", destination);
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

// --- Upload function ---
async function handleFileUpload(files, destination) {
    const mainForm = document.getElementById('iform');
    const formData = new FormData(mainForm);
    formData.append('ajax_upload', '1');
    formData.append('target_dir', destination);
    formData.append('jailname', window.IDE_CONFIG.jailname);

    for (let i = 0; i < files.length; i++) {
        formData.append('files[]', files[i]);
    }

    if (typeof spinner === 'function') spinner();

    try {
         const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });
        const result = await response.text();

        if (result.trim().startsWith('<!DOCTYPE')) {
            throw new Error("Security rejection: Token invalid or Session expired. Please refresh.");
        }

        const data = JSON.parse(result);

        if (data.success) {
            showConfirmDialog("Upload Complete", `${files.length} items uploaded to ${destination}`, "success");
            for (let i = 0; i < files.length; i++) {
                injectItemIntoTree(destination, files[i].name, false);
            }
        } else {
            throw new Error(data.error || "Upload failed");
        }
    } catch (err) {
        console.error("Upload Error: ", err);
        showConfirmDialog("Upload Error", err.message, "error");
    } finally {
        hideSpinner();
    }
}

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

// --- PREVENT DATA LOSS (F5) ---
window.addEventListener('beforeunload', function (e) {
    if (isDirty) {
        e.preventDefault();
        return '';
    }
});
