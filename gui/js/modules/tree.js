// modules/search.js

import { cfg }                              from './state.js';
import { spinner, hideSpinner }             from './ui.js';
import { syncSidebarWithFolder }            from './tree.js';
import { setSelectedIndex, setSearchTimer,
         setSidebarTimer, setOriginalSidebarHTML,
         selectedIndex, searchTimer,
         sidebarTimer, originalSidebarHTML } from './state.js';

// --- QUICK SEARCH ---
const qsModal           = document.getElementById('quick-search-modal');
const qsBackdrop        = document.getElementById('quick-search-backdrop');
const qsInput           = document.getElementById('qs-input');
const qsClearBtn        = document.getElementById('qs-clear-btn');
const qsResultsList     = document.getElementById('qs-results-list');
const qsHistoryContainer = document.getElementById('qs-history-container');
const qsBadges          = document.getElementById('qs-badges');

let searchHistory = JSON.parse(localStorage.getItem('bastilleSearchHistory')) || [];

function renderHistory() {
    if (searchHistory.length === 0) {
        qsHistoryContainer.style.display = 'none';
        return;
    }
    qsHistoryContainer.style.display = 'flex';
    qsBadges.innerHTML = '';
    searchHistory.forEach((term) => {
        const badge = document.createElement('span');
        badge.className = 'qs-badge';
        badge.innerHTML = `${term} <span class="badge-delete" onclick="event.stopPropagation(); removeHistoryItem('${term}')">&times;</span>`;
        badge.onclick = () => { qsInput.value = term; runQuickSearch(); };
        qsBadges.appendChild(badge);
    });
}

export function removeHistoryItem(term) {
    searchHistory = searchHistory.filter(t => t !== term);
    localStorage.setItem('bastilleSearchHistory', JSON.stringify(searchHistory));
    renderHistory();
}
window.removeHistoryItem = removeHistoryItem;

function saveHistory(term) {
    if (!term?.trim()) return;
    term = term.trim().toLowerCase();
    searchHistory = searchHistory.filter(t => t !== term);
    searchHistory.unshift(term);
    if (searchHistory.length > 5) searchHistory.pop();
    localStorage.setItem('bastilleSearchHistory', JSON.stringify(searchHistory));
    renderHistory();
}

export function openQuickSearch() {
    qsModal.style.display = 'block';
    qsBackdrop.style.display = 'block';
    renderHistory();
    qsInput.value = '';
    runQuickSearch();
    setTimeout(() => qsInput.focus(), 100);
}

export function closeQuickSearch() {
    qsModal.style.display = 'none';
    qsBackdrop.style.display = 'none';
}

function clearQuickSearch() {
    qsInput.value = '';
    runQuickSearch();
    qsInput.focus();
}

function updateSelection(items) {
    Array.from(items).forEach(li => li.classList.remove('selected'));
    if (items[selectedIndex]) {
        items[selectedIndex].classList.add('selected');
        items[selectedIndex].scrollIntoView({ block: 'nearest' });
    }
}

function runQuickSearch() {
    setSelectedIndex(-1);
    const filter = qsInput.value.trim();
    qsClearBtn.style.display = filter.length > 0 ? 'flex' : 'none';

    if (filter.length < 2) {
        qsResultsList.innerHTML = '<li style="padding: 15px; color:#888;">Type at least 2 chars...</li>';
        return;
    }

    clearTimeout(searchTimer);
    setSearchTimer(setTimeout(() => {
        spinner();
        qsResultsList.innerHTML = '<li style="padding: 15px; color:#888;">Searching recursively...</li>';

        const url = new URL(window.location.origin + window.location.pathname);
        url.searchParams.set('jailname', cfg.jailname);
        url.searchParams.set('ajax_search', filter);

        fetch(url)
            .then(r => r.json())
            .then(data => {
                const perfInfo = document.getElementById('qs-perf-info');
                if (perfInfo) perfInfo.innerText = data.perf;
                qsResultsList.innerHTML = '';

                if (!data.items?.length) {
                    qsResultsList.innerHTML = `<li class="no-results" style="padding: 20px; text-align: center; color: #999;"><div style="font-size: 24px;">No files found!</div></li>`;
                    return;
                }

                data.items.forEach(file => {
                    const li   = document.createElement('li');
                    const a    = document.createElement('a');
                    const icon = file.type === 'folder'
                        ? '<img src="ext/bastille/images/folder.svg" style="width:16px; margin-right:8px; vertical-align:middle;">'
                        : '<img src="ext/bastille/images/file.svg" style="width:16px; margin-right:8px; vertical-align:middle;">';

                    a.innerHTML = `<span class="qs-item-title">${icon}${file.name}</span><span class="qs-item-path">${file.relative}</span>`;

                    if (file.type === 'folder') {
                        a.href = '#';
                        a.addEventListener('click', async (e) => {
                            e.preventDefault();
                            saveHistory(filter);
                            closeQuickSearch();
                            await syncSidebarWithFolder(file.full);
                        });
                    } else {
                        a.href = `bastille_manager_editor_v2.php?jailname=${encodeURIComponent(cfg.jailname)}&dir=${encodeURIComponent(file.directory)}&filepath=${encodeURIComponent(file.full)}`;
                        a.addEventListener('click', (e) => {
                            e.preventDefault();
                            saveHistory(filter);
                            closeQuickSearch();
                            const fakeLi   = document.createElement('li');
                            fakeLi.className = 'tree-item is-recursive';
                            fakeLi.style.display = 'none';
                            const fakeLink = document.createElement('a');
                            fakeLink.href  = a.href;
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
            .catch(() => {
                qsResultsList.innerHTML = '<li style="padding: 15px; color:red;">Search engine offline.</li>';
            })
            .finally(() => hideSpinner());
    }, 400));
}

// --- SIDEBAR FILTER ---
export function filterFiles() {
    const input    = document.getElementById('fileFilter');
    const clearBtn = document.getElementById('clearFilterBtn');
    const ul       = document.getElementById('fileList');
    const filter   = input.value.toLowerCase().trim();

    if (originalSidebarHTML === '' && filter !== '') setOriginalSidebarHTML(ul.innerHTML);
    clearBtn.style.display = filter.length > 0 ? 'flex' : 'none';

    Array.from(ul.getElementsByTagName('li')).forEach(li => {
        const a        = li.getElementsByTagName('a')[0];
        const txtValue = a ? (a.textContent || a.innerText) : '';
        li.style.display = txtValue.toLowerCase().includes(filter) ? '' : 'none';
    });

    clearTimeout(sidebarTimer);
    if (filter.length >= 2) {
        setSidebarTimer(setTimeout(() => fetchSearchRecursive(filter), 500));
    } else if (filter === '') {
        clearFilter();
    }
}

export function clearFilter() {
    const input = document.getElementById('fileFilter');
    const ul    = document.getElementById('fileList');
    input.value = '';

    if (originalSidebarHTML !== '') {
        ul.innerHTML = originalSidebarHTML;
        setOriginalSidebarHTML('');
    }

    Array.from(ul.getElementsByTagName('li')).forEach(li => li.style.display = '');
    document.getElementById('clearFilterBtn').style.display = 'none';
    input.focus();
}
window.clearFilter = clearFilter;

function fetchSearchRecursive(term) {
    const ul  = document.getElementById('fileList');
    const url = new URL(window.location.origin + window.location.pathname);
    url.searchParams.set('jailname', cfg.jailname);
    url.searchParams.set('ajax_search', term);

    fetch(url)
        .then(res => res.json())
        .then(data => {
            ul.querySelectorAll('.is-recursive, .no-results').forEach(el => el.remove());

            if (!data.items?.length) {
                const li = document.createElement('li');
                li.className = 'no-results';
                li.innerHTML = '<span style="padding:10px; color:#888; font-style:italic;">No matches found...</span>';
                ul.appendChild(li);
                return;
            }

            data.items.forEach(file => {
                if (ul.querySelector(`li.is-recursive a[href*="${encodeURIComponent(file.full)}"]`)) return;

                const li       = document.createElement('li');
                li.className   = 'tree-item is-recursive ' + (file.type === 'folder' ? 'folder-item' : 'file-item');
                const safePath = file.full.replace(/'/g, "\\'");
                const editUrl  = `?jailname=${encodeURIComponent(cfg.jailname)}&dir=${encodeURIComponent(file.directory)}&filepath=${encodeURIComponent(file.full)}`;

                li.innerHTML = file.type === 'folder'
                    ? `<a href="javascript:void(0)" onclick="syncSidebarWithFolder('${safePath}')" title="${file.full}">
                           <strong>${cfg.icons.folder} ${file.name}</strong>
                           <span class="search-result-path">${file.relative}</span>
                       </a>`
                    : `<a href="${editUrl}" title="${file.full}">
                           <strong>${cfg.icons.file} ${file.name}</strong>
                           <span class="search-result-path">${file.relative}</span>
                       </a>`;

                ul.appendChild(li);
            });
        })
        .catch(err => console.error('Search Error:', err));
}

// --- INIT ---
export function initSearch() {
    if (qsBackdrop) qsBackdrop.addEventListener('click', closeQuickSearch);
    if (qsClearBtn) qsClearBtn.addEventListener('click', clearQuickSearch);
    if (qsInput)    qsInput.addEventListener('input', runQuickSearch);

    const fileFilterInput = document.getElementById('fileFilter');
    const sidebarClearBtn = document.getElementById('clearFilterBtn');
    if (fileFilterInput) fileFilterInput.addEventListener('keyup', filterFiles);
    if (sidebarClearBtn) sidebarClearBtn.addEventListener('click', clearFilter);
}

// --- GLOBAL KEYBINDS ---
export function initKeybinds() {
    document.addEventListener('keydown', (e) => {
        const isCtrl = e.ctrlKey || e.metaKey;
        const key    = e.key.toLowerCase();

        if (isCtrl && key === 'b') { e.preventDefault(); e.stopPropagation(); window.toggleSidebar(); return; }
        if (isCtrl && key === 's') { e.preventDefault(); e.stopPropagation(); window.executeSaved?.(); return; }
        if (isCtrl && key === 'k') { e.preventDefault(); e.stopPropagation(); openQuickSearch(); return; }

        if (qsModal?.style.display === 'block') {
            if (e.key === 'Escape') { closeQuickSearch(); return; }

            const items = qsResultsList.getElementsByTagName('li');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setSelectedIndex(selectedIndex + 1 < items.length ? selectedIndex + 1 : selectedIndex);
                updateSelection(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setSelectedIndex(selectedIndex - 1 >= 0 ? selectedIndex - 1 : 0);
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
}

export function initFolderDelegation() {
    document.addEventListener('click', async (e) => {
        const link = e.target.closest('a[data-folder-path]');
        if (!link) return;
        e.preventDefault();
        const path = link.getAttribute('data-folder-path');
        if (path) await toggleFolder(link, path);
    });
}