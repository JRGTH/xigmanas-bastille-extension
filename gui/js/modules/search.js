// js/modules/search.js
import { cfg, State } from './config.js';

let searchTimer;
let searchHistory = JSON.parse(localStorage.getItem('bastilleSearchHistory')) || [];

export function initQuickSearch() {
    const qsBackdrop = document.getElementById('quick-search-backdrop');
    const qsClearBtn = document.getElementById('qs-clear-btn');
    const qsInput = document.getElementById('qs-input');

    if (qsBackdrop) qsBackdrop.addEventListener('click', closeQuickSearch);
    if (qsClearBtn) qsClearBtn.addEventListener('click', clearQuickSearch);
    if (qsInput) {
        qsInput.addEventListener('input', runQuickSearch);
    }
}

export function openQuickSearch() {
    document.getElementById('quick-search-modal').style.display = 'block';
    document.getElementById('quick-search-backdrop').style.display = 'block';
    const qsInput = document.getElementById('qs-input');
    qsInput.value = '';
    runQuickSearch();
    setTimeout(() => qsInput.focus(), 100);
}

export function closeQuickSearch() {
    document.getElementById('quick-search-modal').style.display = 'none';
    document.getElementById('quick-search-backdrop').style.display = 'none';
}

function clearQuickSearch() {
    const qsInput = document.getElementById('qs-input');
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
    clearTimeout(State.searchTimer);
    State.searchTimer = setTimeout(() => {
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