// modules/upload.js

import { cfg }                  from './state.js';
import { spinner, hideSpinner } from './ui.js';
import { showConfirmDialog }    from './modal.js';

const CHUNK_SIZE = 25 * 1024 * 1024; // 25MB

// --- INJECT ITEM INTO TREE (optimistic UI) ---
export function injectItemIntoTree(destination, itemName, isFolder = false) {
    let parentLi = destination === cfg.jailRoot
        ? document.querySelector('#fileList > li.folder-item')
        : Array.from(document.querySelectorAll('.folder-item > a'))
            .find(a => a.getAttribute('onclick')?.includes(`'${destination}'`))
            ?.closest('li') ?? null;

    if (!parentLi) return;
    const ul = parentLi.querySelector('ul');
    if (!ul) return;

    const li       = document.createElement('li');
    li.className   = isFolder ? 'tree-item folder-item' : 'tree-item file-item';
    li.dataset.flag = '';
    li.style.opacity    = '0';
    li.style.transition = 'opacity 0.5s ease-in, background-color 0.5s';

    const fullPath = destination + '/' + itemName;

    if (isFolder) {
        const safePath = fullPath.replace(/'/g, "\\'");
        li.innerHTML = `
            <a href="javascript:void(0)" onclick="toggleFolder(this, '${safePath}')">
                ${cfg.icons.caret} ${cfg.icons.folder} <span>${itemName}</span>
            </a>`;
    } else {
        const editUrl = `?jailname=${encodeURIComponent(cfg.jailname)}&dir=${encodeURIComponent(destination)}&filepath=${encodeURIComponent(fullPath)}`;
        li.innerHTML = `
            <a href="${editUrl}" onclick="if(typeof spinner === 'function') spinner();">
                ${cfg.icons.file} <span>${itemName}</span>
            </a>`;
    }

    ul.appendChild(li);

    requestAnimationFrame(() => {
        li.style.opacity = '1';
        const a = li.querySelector('a');
        if (a) {
            a.style.backgroundColor = 'rgba(76, 175, 80, 0.2)';
            setTimeout(() => { a.style.backgroundColor = ''; }, 1000);
        }
    });
}

// --- HASH CALCULATION ---
export async function calculateFileHash(file, onProgress) {
    const hasher    = sha256.create();
    const size      = file.size;
    const sliceSize = 50 * 1024 * 1024;
    let offset      = 0;

    while (offset < size) {
        if (window.cancelUpload) return null;
        const slice  = file.slice(offset, offset + sliceSize);
        const buffer = await slice.arrayBuffer();
        hasher.update(buffer);
        offset += sliceSize;
        onProgress(Math.min(Math.round((offset / size) * 100), 100));
    }
    return hasher.hex();
}

// --- RECURSIVE DIRECTORY SCANNER ---
export async function scanFiles(item, container, path = '') {
    if (item.isFile) {
        const file = await new Promise(resolve => item.file(resolve));
        file.customRelativePath = path + file.name;
        container.push(file);
    } else if (item.isDirectory) {
        const reader  = item.createReader();
        const entries = await new Promise(resolve => reader.readEntries(resolve));
        for (const entry of entries) await scanFiles(entry, container, path + item.name + '/');
    }
}

// --- UPLOAD MODAL UI ---
export function openUploadModal() {
    document.querySelector('.header-plus-submenu')?.classList.remove('show');
    setUploadState('selector');
    document.getElementById('upload-modal-overlay').style.display = 'flex';
}
window.openUploadModal = openUploadModal;

export function closeUploadModal() {
    if (window.isUploading) {
        if (!confirm('Upload in progress. Are you sure you want to cancel?')) return;
        window.cancelUpload = true;
        window.uploadController?.abort();
    } else {
        document.getElementById('upload-modal-overlay').style.display = 'none';
        document.getElementById('up-file-input').value  = '';
        document.getElementById('up-folder-input').value = '';
        document.getElementById('up-remote-url').value  = '';
    }
}
window.closeUploadModal = closeUploadModal;

export function setUploadState(state) {
    const selector  = document.getElementById('up-state-selector');
    const progress  = document.getElementById('up-state-progress');
    const cancelBtn = document.getElementById('up-cancel-btn');

    if (state === 'selector') {
        selector.style.display  = 'block';
        progress.style.display  = 'none';
        cancelBtn.innerText     = 'Close';
    } else if (state === 'progress') {
        selector.style.display  = 'none';
        progress.style.display  = 'block';
        cancelBtn.innerText     = 'Cancel Upload';
    }
}

// --- REMOTE DOWNLOAD ---
export async function handleRemoteDownload() {
    const urlInput    = document.getElementById('up-remote-url');
    const url         = urlInput.value.trim();
    if (!url) { alert('Please enter a valid URL.'); return; }

    const destination = cfg.lastSelectedDir || cfg.jailRoot;

    setUploadState('progress');
    document.getElementById('up-current-filename').innerText = 'Downloading from remote URL...';
    document.getElementById('up-progress-fill').style.width  = '100%';
    document.getElementById('up-progress-percent').innerText = 'Processing...';
    document.getElementById('up-progress-info').innerText    = url;
    window.isUploading = true;

    const formData = new FormData(document.getElementById('iform'));
    formData.append('ajax_remote_download', '1');
    formData.append('remote_url',  url);
    formData.append('target_dir',  destination);

    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const data     = await response.json();

        if (data.success) {
            showConfirmDialog('Success', 'File imported from URL successfully.', 'success');
            injectItemIntoTree(destination, data.fileName, false);
            closeUploadModal();
        } else {
            throw new Error(data.error || 'Failed to download.');
        }
    } catch (err) {
        showConfirmDialog('Error', err.message, 'error');
        setUploadState('selector');
    } finally {
        window.isUploading = false;
        urlInput.value = '';
    }
}
window.handleRemoteDownload = handleRemoteDownload;

// --- NATIVE UPLOAD (from input buttons) ---
export function handleNativeUpload(inputElement, isFolder) {
    if (!inputElement.files?.length) return;
    const destination = cfg.lastSelectedDir || cfg.currentDir || cfg.jailRoot;
    handleFileUpload(Array.from(inputElement.files), destination);
    inputElement.value = '';
}
window.handleNativeUpload = handleNativeUpload;

// --- CHUNKED FILE UPLOAD ENGINE ---
export async function handleFileUpload(files, destination) {
    window.isUploading      = true;
    window.cancelUpload     = false;
    window.uploadController = new AbortController();

    const overlay = document.getElementById('upload-modal-overlay');
    if (overlay) overlay.style.display = 'flex';
    setUploadState('progress');

    const bar   = document.getElementById('up-progress-fill');
    const pText = document.getElementById('up-progress-percent');
    const fName = document.getElementById('up-current-filename');
    const fHash = document.getElementById('up-file-hash');

    for (let i = 0; i < files.length; i++) {
        if (window.cancelUpload) break;
        const file = files[i];

        // Step 1 — hash
        if (fName) fName.innerText = `[${i+1}/${files.length}] Calculating signature...`;
        if (fHash) fHash.innerText = 'Processing SHA-256...';
        if (bar)   { bar.style.width = '0%'; bar.style.background = '#9c27b0'; }

        const fullFileHash = await calculateFileHash(file, (p) => {
            if (pText) pText.innerText = `Hashing: ${p}%`;
            if (bar)   bar.style.width = p + '%';
        });

        if (window.cancelUpload) break;
        if (fHash) fHash.innerText = `Hash: ${fullFileHash}`;
        if (bar)   bar.style.background = '#3875d6';

        // Step 2 — chunked upload
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        const relPath     = file.customRelativePath || file.webkitRelativePath || file.name;
        let startTime     = Date.now();
        let lastLoaded    = 0;

        for (let chunkIdx = 0; chunkIdx < totalChunks; chunkIdx++) {
            if (window.cancelUpload) break;

            const start    = chunkIdx * CHUNK_SIZE;
            const chunk    = file.slice(start, Math.min(start + CHUNK_SIZE, file.size));
            const formData = new FormData(document.getElementById('iform'));
            formData.append('ajax_upload_chunk', '1');
            formData.append('chunk_index',  chunkIdx);
            formData.append('total_chunks', totalChunks);
            formData.append('file_name',    file.name);
            formData.append('relative_path', relPath);
            formData.append('target_dir',   destination);
            formData.append('file_chunk',   chunk);

            try {
                await new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', window.location.href, true);

                    window.uploadController.signal.addEventListener('abort', () => {
                        xhr.abort();
                        reject(new DOMException('Aborted', 'AbortError'));
                    });

                    xhr.upload.onprogress = (e) => {
                        if (!e.lengthComputable) return;
                        const totalUploaded = (chunkIdx * CHUNK_SIZE) + e.loaded;
                        const percent       = Math.min(Math.round((totalUploaded / file.size) * 100), 100);
                        if (bar)   bar.style.width    = percent + '%';
                        if (pText) pText.innerText    = `${percent}%`;

                        const now      = Date.now();
                        const timeDiff = (now - startTime) / 1000;
                        if (timeDiff > 0.5) {
                            const speedMBps = ((totalUploaded - lastLoaded) / timeDiff / (1024 * 1024)).toFixed(1);
                            if (fName) fName.innerText = `[${i+1}/${files.length}] Uploading: ${file.name} (${speedMBps} MB/s)`;
                            startTime  = now;
                            lastLoaded = totalUploaded;
                        }
                    };

                    xhr.onload  = () => xhr.status >= 200 && xhr.status < 300 ? resolve(xhr.responseText) : reject(new Error(`Server Error: ${xhr.status}`));
                    xhr.onerror = () => reject(new Error('Network error during upload'));
                    xhr.send(formData);
                });
            } catch (err) {
                if (err.name === 'AbortError') return;
                console.error('Chunk upload error:', err);
                break;
            }
        }

        // Step 3 — verify
        if (!window.cancelUpload) {
            if (pText) pText.innerText = 'Verifying on server...';
            if (bar)   bar.style.background = '#ff9800';
            if (fName) fName.innerText = `[${i+1}/${files.length}] Checking integrity...`;

            const verifyData = new FormData(document.getElementById('iform'));
            verifyData.append('ajax_verify_hash', '1');
            verifyData.append('target_dir',     destination);
            verifyData.append('relative_path',  relPath);
            verifyData.append('expected_hash',  fullFileHash);

            try {
                const vRes  = await fetch(window.location.href, { method: 'POST', body: verifyData });
                const vJson = await vRes.json();

                if (vJson.success) {
                    if (bar) bar.style.background = '#4caf50';
                    injectItemIntoTree(destination, file.name, false);
                } else {
                    showConfirmDialog('Error', `Integrity fail for ${file.name}!`, 'error');
                }
            } catch (e) {
                console.error('Verification error:', e);
            }
        }
    }

    window.isUploading = false;
    if (!window.cancelUpload) setTimeout(() => closeUploadModal(), 2000);
}

// --- DROP ZONE INIT ---
export function initDropZone() {
    const dropZone = document.querySelector('.up-drop-zone');
    if (!dropZone) return;

    const prevent = (e) => { e.preventDefault(); e.stopPropagation(); };
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(ev => dropZone.addEventListener(ev, prevent));

    ['dragenter', 'dragover'].forEach(ev =>
        dropZone.addEventListener(ev, () => dropZone.style.borderColor = '#3875d6')
    );
    ['dragleave', 'drop'].forEach(ev =>
        dropZone.addEventListener(ev, () => dropZone.style.borderColor = 'transparent')
    );

    dropZone.addEventListener('drop', async (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.style.borderColor = 'transparent';

        const destination = cfg.lastSelectedDir || cfg.jailRoot;
        const items       = e.dataTransfer.items;
        if (!items) return;

        const entries        = [];
        const filesToUpload  = [];

        for (let i = 0; i < items.length; i++) {
            const entry = items[i].webkitGetAsEntry();
            if (entry) entries.push(entry);
        }

        for (const entry of entries) await scanFiles(entry, filesToUpload);
        if (filesToUpload.length > 0) handleFileUpload(filesToUpload, destination);
    });
}

// --- SIDEBAR DRAG & DROP INIT ---
export function initSidebarDragDrop() {
    const sidebar = document.querySelector('.ide-sidebar');
    if (!sidebar) return;

    let dragCounter = 0;

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(ev =>
        document.addEventListener(ev, e => e.preventDefault())
    );

    document.addEventListener('dragenter', () => {
        dragCounter++;
        sidebar.classList.add('drag-over-active');
        document.body.classList.add('is-dragging');
    });

    sidebar.addEventListener('dragover', (e) => {
        document.querySelectorAll('.drag-target').forEach(el => el.classList.remove('drag-target'));
        e.target.closest('.folder-item')?.classList.add('drag-target');
    });

    sidebar.addEventListener('drop', (e) => {
        dragCounter = 0;
        sidebar.classList.remove('drag-over-active');
        document.querySelectorAll('.drag-target').forEach(el => el.classList.remove('drag-target'));
        document.body.classList.remove('is-dragging');

        const targetFolder = e.target.closest('.folder-item');
        const files        = e.dataTransfer.files;
        if (!files.length) return;

        let destination = cfg.jailRoot;
        if (targetFolder) {
            const match = targetFolder.querySelector('a')?.getAttribute('onclick')?.match(/'([^']+)'/);
            if (match) destination = match[1];
        } else {
            destination = cfg.lastSelectedDir || cfg.currentDir || cfg.jailRoot;
        }

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

    // Manual destination selection on click
    document.addEventListener('click', (e) => {
        const link = e.target.closest('.tree-item a');
        if (!link) return;

        const item = link.closest('.tree-item');
        document.querySelectorAll('.tree-item').forEach(el => el.classList.remove('is-selected-target', 'active'));
        item.classList.add('is-selected-target', 'active');

        let path = cfg.jailRoot;
        if (item.classList.contains('folder-item')) {
            const match = link.getAttribute('onclick')?.match(/['"]([^'"]+)['"]/);
            if (match) path = match[1];
        } else if (item.classList.contains('file-item')) {
            const url      = new URL(link.href, window.location.origin);
            const filepath = url.searchParams.get('filepath');
            if (filepath) path = filepath.substring(0, filepath.lastIndexOf('/'));
        }
        cfg.lastSelectedDir = path;
    });
}