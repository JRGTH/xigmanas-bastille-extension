// modules/download.js

import { cfg, cmTargetData }    from './state.js';
import { spinner, hideSpinner } from './ui.js';
import { showConfirmDialog }    from './modal.js';
import { refreshDir }           from './tree.js';

// --- NOTIFICATION ---
export function showNotification(title, bodyText) {
    if (!('Notification' in window)) return;

    const options = {
        body: bodyText,
        icon: '/ext/bastille/images/logo-xigmanas.png'
    };

    if (Notification.permission === 'granted') {
        new Notification(title, options);
    } else if (Notification.permission !== 'denied') {
        Notification.requestPermission().then(permission => {
            if (permission === 'granted') new Notification(title, options);
        });
    }
}

// --- TRIGGER DOWNLOAD ---
export function triggerDownload(url) {
    const a = document.createElement('a');
    a.style.display = 'none';
    a.href          = url;
    a.setAttribute('download', '');
    document.body.appendChild(a);
    a.click();
    setTimeout(() => document.body.removeChild(a), 1000);
}

// --- SSE ---
export function openSSE(jobId, filename, jailName, csrfToken, parentDir = null) {
    const params = new URLSearchParams({
        jailname:    jailName,
        authtoken:   csrfToken,
        ajax_job_sse: '1',
        job_id:      jobId,
        filename
    });

    const evtSource = new EventSource(window.location.pathname + '?' + params.toString());

    evtSource.addEventListener('message', (e) => {
        const data = JSON.parse(e.data);
        if (data.status !== 'done') return;

        evtSource.close();
        hideSpinner();
        setTimeout(() => localStorage.removeItem('bastille_pending_job'), 3000);

        const tmpDir = (cfg.jailRoot + '/root/tmp').trim();
        refreshDir(tmpDir);

        showNotification('Compression complete!', `Downloading ${data.filename}`);

        const dlParams = new URLSearchParams({
            jailname:               jailName,
            ajax_download_prepared: data.tmp_file,
            filename:               data.filename,
            authtoken:              csrfToken
        });
        triggerDownload(window.location.pathname + '?' + dlParams.toString());
    });

    evtSource.addEventListener('error', () => evtSource.close());
}

// --- EXECUTE DOWNLOAD REQUEST ---
export async function executeDownloadRequest(type) {
    const contextMenu = document.getElementById('ide-context-menu');
    if (contextMenu) contextMenu.style.display = 'none';

    const csrfToken = document.querySelector('input[name="authtoken"]')?.value || '';
    const jailName  = cfg.jailname;

    // Direct download — no compression
    if (type === 'none' || !type) {
        const form    = document.createElement('form');
        form.method   = 'POST';
        form.action   = window.location.pathname;
        form.style.display = 'none';

        for (const [name, value] of Object.entries({
            jailname:           jailName,
            filepath:           cmTargetData.filepath,
            authtoken:          csrfToken,
            ajax_download_file: '1'
        })) {
            const input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = name;
            input.value = value;
            form.appendChild(input);
        }

        document.body.appendChild(form);
        form.submit();
        setTimeout(() => document.body.removeChild(form), 1000);
        return;
    }

    spinner();

    try {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            body: new URLSearchParams({
                jailname:           jailName,
                filepath:           cmTargetData.filepath,
                authtoken:          csrfToken,
                ajax_compress_type: type,
                t:                  Date.now()
            })
        });

        const data = await response.json();

        if (!data.success) {
            hideSpinner();
            showConfirmDialog('Error', data.error || 'Unknown compression error from server', 'error');
            return;
        }

        if (!data.async) {
            hideSpinner();
            showNotification('Compression complete!', `Downloading ${data.filename}`);
            const dlParams = new URLSearchParams({
                jailname:               jailName,
                ajax_download_prepared: data.tmp_file,
                filename:               data.filename,
                authtoken:              csrfToken
            });
            triggerDownload(window.location.pathname + '?' + dlParams.toString());
            return;
        }

        const parentDir = cmTargetData.filepath
            .substring(0, cmTargetData.filepath.lastIndexOf('/'))
            .trim();

        localStorage.setItem('bastille_pending_job', JSON.stringify({
            job_id: data.job_id, filename: data.filename, parentDir, jailName, csrfToken
        }));

        openSSE(data.job_id, data.filename, jailName, csrfToken, parentDir);

    } catch (err) {
        hideSpinner();
        showConfirmDialog('Error', err.message, 'error');
    }
}

// --- RESUME PENDING JOB ON PAGE LOAD ---
export function initPendingJobResume() {
    document.addEventListener('DOMContentLoaded', () => {
        const pending = localStorage.getItem('bastille_pending_job');
        if (!pending) return;
        try {
            const { job_id, filename, jailName, csrfToken, parentDir } = JSON.parse(pending);
            localStorage.removeItem('bastille_pending_job');
            spinner();
            openSSE(job_id, filename, jailName, csrfToken, parentDir);
        } catch (_) {
            localStorage.removeItem('bastille_pending_job');
        }
    });
}