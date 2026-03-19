// modules/tree.js

import { cfg, isDirty, isInjectingCode,
         setIsDirty, setIsInjectingCode }  from './state.js';
import { spinner, hideSpinner,
         updateBreadcrumbs }               from './ui.js';
import { showConfirmDialog }               from './modal.js';
import { clearFilter }                     from './search.js';

const BINARY_EXTS = new Set([
    'png','jpg','jpeg','gif','svg','ico','mp3','mp4','mkv','avi','mov','wav','flac',
    'iso','gz','zip','tar','rar','7z','pdf','bin','exe','dll','so','db','sqlite'
]);

// --- HELPERS ---
function renderLockIcon(flag) {
    return flag && flag !== '0'
        ? `<img src="ext/bastille/images/lock.svg" class="lock-icon" title="Flags: ${flag}">`
        : '';
}

function buildFolderLi(name, fullPath, flag) {
    const safePath = fullPath.replace(/'/g, "\\'");
    const li       = document.createElement('li');
    li.className   = 'tree-item folder-item';
    li.dataset.flag = flag || '';
    li.innerHTML   = `
        <a href="javascript:void(0)" onclick="toggleFolder(this, '${safePath}')">
            ${cfg.icons.caret} ${cfg.icons.folder} <span>${name}</span> ${renderLockIcon(flag)}
        </a>`;
    return li;
}

function buildFileLi(name, fullPath, dirPath, flag) {
    const editUrl = `?jailname=${encodeURIComponent(cfg.jailname)}&dir=${encodeURIComponent(dirPath)}&filepath=${encodeURIComponent(fullPath)}`;
    const li      = document.createElement('li');
    li.className  = 'tree-item file-item';
    li.dataset.flag = flag || '';
    li.innerHTML  = `
        <a href="${editUrl}" onclick="if(typeof spinner === 'function') spinner();">
            ${cfg.icons.file} <span>${name}</span> ${renderLockIcon(flag)}
        </a>`;
    return li;
}

function flashNew(li) {
    li.style.opacity    = '0';
    li.style.transition = 'opacity 0.5s ease-in, background-color 0.5s';
    requestAnimationFrame(() => {
        li.style.opacity = '1';
        const a = li.querySelector('a');
        if (a) {
            a.style.backgroundColor = 'rgba(76, 175, 80, 0.3)';
            a.style.borderRadius    = '4px';
            setTimeout(() => { a.style.backgroundColor = ''; }, 1500);
        }
    });
}

// --- TOGGLE FOLDER ---
export function toggleFolder(element, path) {
    const li      = element.parentElement;
    let subList   = li.querySelector('ul');

    if (subList) {
        const isHidden = subList.style.display === 'none';
        subList.style.display = isHidden ? 'block' : 'none';
        isHidden ? li.classList.add('open') : li.classList.remove('open');
        return;
    }

    spinner();

    const url = new URL(window.location.origin + window.location.pathname);
    url.searchParams.set('jailname', cfg.jailname);
    url.searchParams.set('ajax_get_dir', path);

    return fetch(url)
        .then(async res => {
            const raw = await res.text();
            try { return JSON.parse(raw); }
            catch { console.error('CRITICAL PHP ERROR:', raw); throw new Error('Server returned invalid JSON.'); }
        })
        .then(data => {
            if (data.error) throw new Error(data.error);

            subList = document.createElement('ul');
            subList.className   = 'ide-file-list';
            subList.style.paddingLeft = '15px';

            data.folders.forEach(f => subList.appendChild(buildFolderLi(f.name, path + '/' + f.name, f.flag)));
            data.files.forEach(f   => subList.appendChild(buildFileLi(f.name, path + '/' + f.name, path, f.flag)));

            li.appendChild(subList);
            li.classList.add('open');
        })
        .catch(err => {
            console.error('Tree Load Error:', err);
            li.classList.remove('open');
            showConfirmDialog('Directory Load Error', err.message || 'Failed to read directory.', 'error');
        })
        .finally(() => hideSpinner());
}
window.toggleFolder = toggleFolder;

// --- SYNC SIDEBAR WITH FILE ---
export async function syncSidebarWithFile() {
    const targetFile = cfg.filepath;
    if (!targetFile) return;

    const relativePath = targetFile.replace(cfg.jailRoot, '');
    const segments     = relativePath.split('/').filter(s => s !== '');
    segments.pop();

    let currentPath       = cfg.jailRoot.replace(/\/$/, '');
    let $currentContainer = document.getElementById('fileList');
    if (!$currentContainer) return;

    for (const segment of segments) {
        currentPath += '/' + segment;
        const folderLink = Array.from($currentContainer.querySelectorAll('.folder-item > a'))
            .find(a => Array.from(a.querySelectorAll('span')).some(s => s.innerText.trim() === segment));

        if (!folderLink) break;

        const li      = folderLink.parentElement;
        const subList = li.querySelector('ul');

        if (subList && subList.style.display !== 'none' && li.classList.contains('open')) {
            $currentContainer = subList;
        } else {
            await toggleFolder(folderLink, currentPath);
            const nextUl = li.querySelector('ul');
            if (nextUl) $currentContainer = nextUl;
            else break;
        }
    }

    setTimeout(() => {
        const targetLink = Array.from(document.querySelectorAll('.file-item > a'))
            .find(a => new URL(a.href, window.location.origin).searchParams.get('filepath') === targetFile);

        if (!targetLink) return;

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
    }, 150);
}

// --- SYNC SIDEBAR WITH FOLDER ---
export async function syncSidebarWithFolder(targetPath) {
    const fileFilterInput = document.getElementById('fileFilter');
    if (fileFilterInput?.value !== '') clearFilter();

    const relativePath = targetPath.replace(cfg.jailRoot, '').replace(/^\/+/, '');
    const segments     = relativePath.split('/').filter(s => s !== '');

    let currentPath       = cfg.jailRoot.replace(/\/$/, '');
    let $currentContainer = document.getElementById('fileList');
    if (!$currentContainer) return;

    let targetLi = null;

    if (segments.length === 0) {
        const rootFolder = $currentContainer.querySelector('li.folder-item');
        if (rootFolder) {
            if (!rootFolder.classList.contains('open')) await toggleFolder(rootFolder.querySelector('a'), currentPath);
            targetLi = rootFolder;
        }
    } else {
        for (const segment of segments) {
            currentPath += '/' + segment;
            const folderLink = Array.from($currentContainer.querySelectorAll('.folder-item > a'))
                .find(a => Array.from(a.querySelectorAll('span')).some(s => s.innerText.trim() === segment));

            if (!folderLink) break;

            const li      = folderLink.parentElement;
            targetLi      = li;
            const subList = li.querySelector('ul');

            if (subList && subList.style.display !== 'none' && li.classList.contains('open')) {
                $currentContainer = subList;
            } else {
                await toggleFolder(folderLink, currentPath);
                const nextUl = li.querySelector('ul');
                if (nextUl) $currentContainer = nextUl;
                else break;
            }
        }
    }

    if (targetLi) {
        document.querySelectorAll('.tree-item').forEach(el => el.classList.remove('active'));
        targetLi.classList.add('active');
        setTimeout(() => {
            (targetLi.querySelector('a') || targetLi).scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 150);
    }
}
window.syncSidebarWithFolder = syncSidebarWithFolder;

// --- REFRESH DIR (smart diff) ---
export async function refreshDir(dirPath) {
    const cleanDest = dirPath.replace(/\/$/, '');
    const cleanRoot = cfg.jailRoot.replace(/\/$/, '');

    let targetLi = cleanDest === cleanRoot
        ? document.querySelector('#fileList > li.folder-item')
        : Array.from(document.querySelectorAll('.folder-item > a'))
            .find(a => {
                const oc = a.getAttribute('onclick') || '';
                return oc.includes(`'${cleanDest}'`) || oc.includes(`"${cleanDest}"`);
            })?.closest('li') ?? null;

    if (!targetLi) return;
    const ul = targetLi.querySelector('ul');
    if (!ul) return;

    const params = new URLSearchParams({ ajax_get_dir: cleanDest, jailname: cfg.jailname });

    try {
        const res  = await fetch(`${window.location.pathname}?${params.toString()}`);
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        const serverFolders = data.folders.map(f => f.name);
        const serverFiles   = data.files.map(f => f.name);

        // Phase 1 — remove stale items
        Array.from(ul.children).forEach(li => {
            if (li.classList.contains('is-recursive') || li.classList.contains('no-results')) return;
            const nameSpan = li.querySelector('a span:not(.tree-caret)');
            if (!nameSpan) return;
            const name     = nameSpan.innerText.trim();
            const isFolder = li.classList.contains('folder-item');
            if (isFolder ? !serverFolders.includes(name) : !serverFiles.includes(name)) li.remove();
        });

        // Phase 2 — inject new folders
        const existingFolders = new Set(
            Array.from(ul.querySelectorAll('.folder-item a span:not(.tree-caret)')).map(s => s.innerText.trim())
        );
        data.folders.forEach(f => {
            if (existingFolders.has(f.name)) return;
            const li = buildFolderLi(f.name, cleanDest + '/' + f.name, f.flag);
            const firstFile = ul.querySelector('.file-item');
            firstFile ? ul.insertBefore(li, firstFile) : ul.appendChild(li);
            flashNew(li);
        });

        // Phase 3 — inject new files
        const existingFiles = new Set(
            Array.from(ul.querySelectorAll('.file-item a span:not(.tree-caret)')).map(s => s.innerText.trim())
        );
        data.files.forEach(f => {
            if (existingFiles.has(f.name)) return;
            const li = buildFileLi(f.name, cleanDest + '/' + f.name, cleanDest, f.flag);
            ul.appendChild(li);
            flashNew(li);
        });

    } catch (err) {
        console.error('Smart Refresh Error:', err.message);
        showConfirmDialog('Refresh error', err.message, 'error');
    }
}

// --- SPA CLICK HANDLER ---
export function initTreeClickHandler() {
    document.querySelector('.ide-file-list').addEventListener('click', async (e) => {
        const link = e.target.closest('a');
        if (!link || link.getAttribute('onclick')?.includes('toggleFolder')) return;

        const url      = new URL(link.href, window.location.origin);
        const filepath = url.searchParams.get('filepath');
        if (!filepath) return;

        e.preventDefault();

        if (isDirty) {
            hideSpinner();
            const ok = await showConfirmDialog('Unsaved changes', 'You have made changes. If you switch files now, you will lose your changes.', 'warning');
            if (!ok) return;
            clearDirtyState();
        }

        const ext = filepath.split('.').pop().toLowerCase();

        if (BINARY_EXTS.has(ext)) {
            if (window.editor) {
                setIsInjectingCode(true);
                window.editor.setValue(`/*\n * BASTILLE EDITOR WARNING\n * ------------------------\n * The file '${filepath.split('/').pop()}' is a binary or media file.\n * It cannot be safely displayed or edited in a text editor.\n */`);
                window.editor.updateOptions({ readOnly: true });
                setIsInjectingCode(false);
                setIsDirty(false);
                hideSpinner();
            }
            document.querySelectorAll('.tree-item').forEach(el => el.classList.remove('active'));
            link.closest('.tree-item')?.classList.add('active');
            _syncFormInputs(filepath);
            url.searchParams.delete('ajax');
            url.searchParams.delete('filepath');
            window.history.pushState({ filepath }, '', url.toString());
            updateBreadcrumbs(filepath);
            return;
        }

        document.body.style.cursor = 'wait';
        spinner();

        try {
            url.searchParams.set('ajax', '1');
            const response = await fetch(url.toString());
            if (!response.ok) throw new Error('Fetch failed');

            const fileContent = await response.text();

            if (window.editor) {
                setIsInjectingCode(true);
                window.editor.setValue(fileContent);
                window.editor.updateOptions({ readOnly: false });
                setIsInjectingCode(false);
                setIsDirty(false);
            }

            const isSearchResult = link.closest('.is-recursive');
            if (isSearchResult) {
                clearFilter();
                document.querySelector('.ide-search input')?.dispatchEvent(new Event('input'));
                document.querySelectorAll('.is-recursive, .no-results').forEach(el => el.remove());
                document.querySelectorAll('.ide-file-list > li').forEach(el => el.style.display = '');
                cfg.filepath = filepath;
                setTimeout(async () => await syncSidebarWithFile(), 50);
            } else {
                document.querySelectorAll('.tree-item').forEach(el => el.classList.remove('active'));
                link.closest('.tree-item')?.classList.add('active');
            }

            _syncFormInputs(filepath);
            url.searchParams.delete('ajax');
            url.searchParams.delete('filepath');
            window.history.pushState({ filepath }, '', url.toString());
            updateBreadcrumbs(filepath);

        } catch (error) {
            console.error('Editor Error:', error);
            showConfirmDialog('Error', 'The selected file could not be loaded.', 'error');
        } finally {
            document.body.style.cursor = 'default';
            hideSpinner();
        }
    });
}

// --- HOME BUTTON ---
export function initHomeButton() {
    const homeBtn = document.querySelector('.ide-sidebar-header a[title="Reset Tree"]');
    if (!homeBtn) return;

    homeBtn.addEventListener('click', (e) => {
        e.preventDefault();

        document.querySelector('.ide-search input')?.value !== undefined &&
            (document.querySelector('.ide-search input').value = '');
        document.querySelectorAll('.is-recursive, .no-results').forEach(el => el.remove());

        const fileList = document.getElementById('fileList');
        if (!fileList) return;

        const rootLi = fileList.querySelector('li.folder-item');
        const rootUl = rootLi?.querySelector('ul');
        if (!rootUl) return;

        rootUl.innerHTML = '<li class="tree-item" style="padding-left:20px; opacity:0.5;">Updating tree...</li>';

        const params = new URLSearchParams({ ajax_get_dir: cfg.jailRoot, jailname: cfg.jailname });

        fetch(`${window.location.pathname}?${params.toString()}`)
            .then(async res => {
                const raw = await res.text();
                try { return JSON.parse(raw); }
                catch { throw new Error('Server returned invalid JSON.'); }
            })
            .then(data => {
                if (data.error) throw new Error(data.error);
                rootUl.innerHTML = '';
                data.folders.forEach(f => rootUl.appendChild(buildFolderLi(f.name, data.parent + '/' + f.name, f.flag)));
                data.files.forEach(f   => rootUl.appendChild(buildFileLi(f.name, data.parent + '/' + f.name, data.parent, f.flag)));
            })
            .catch(err => {
                console.error('Reset Tree Error:', err);
                rootUl.innerHTML = '<li class="tree-item" style="color:red; padding-left:20px;">Error updating.</li>';
            });

        document.querySelectorAll('.tree-item').forEach(el => el.classList.remove('active', 'open'));
        rootLi?.classList.add('open');
    });
}

// --- INIT ---
export function initTree() {
    if (history.state?.filepath) {
        cfg.filepath = history.state.filepath;
    }

    if (cfg.filepath && cfg.filepath !== '') {
        await syncSidebarWithFile();
    } else if (cfg.currentDir && cfg.currentDir !== cfg.jailRoot) {
        await syncSidebarWithFolder(cfg.currentDir);
    }

}

// --- PRIVATE ---
function _syncFormInputs(filepath) {
    const inputFp = document.querySelector('input[name="filepath"]');
    const inputDr = document.querySelector('input[name="dir"]');
    if (inputFp) inputFp.value = filepath;
    if (inputDr) inputDr.value = filepath.substring(0, filepath.lastIndexOf('/'));
}

export function clearDirtyState() {
    setIsDirty(false);
    document.querySelectorAll('.dirty-dot').forEach(dot => dot.remove());
    document.title = document.title.replace('* ', '');
    const saveBtn = document.getElementById('btn_save');
    if (saveBtn) saveBtn.disabled = true;
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