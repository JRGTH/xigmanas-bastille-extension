// modules/download.js

import { cfg, cmTargetData, currentFileData, setCurrentFileData } from './state.js';
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

}

export async function showFileInfo(filePath) {
    const sidebar = document.getElementById('ide-info-sidebar');
    const content = document.getElementById('info-sidebar-content');

    sidebar.classList.add('open');
    content.innerHTML = '<div style="text-align:center; padding-top:50px; color:#adb5bd;">Loading...</div>';

    const formData = new FormData(document.getElementById('iform'));
    formData.append('ajax_get_info', '1');
    formData.append('target_path', filePath);

    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        setCurrentFileData(await response.json());

        if (currentFileData.success) {
            // Update Header
            document.getElementById('sidebar-filename').innerText = currentFileData.name;
            // Force Overview tab when opening
            switchTab('overview', document.querySelector('.tab-link'));
        }
    } catch (e) {
        console.error('[showFileInfo] error:', e);
        content.innerHTML = '<div class="modern-card" style="color:red">Connection error</div>';
    }
}

// --- FILE INFO SIDEBAR LOGIC ---
export function closeInfoSidebar() {
    document.getElementById('ide-info-sidebar').classList.remove('open');
}

export function switchTab(tabName, element) {
    if (!currentFileData) return;

    // // UI: Manage active classes
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
export function renderStorageChart() {
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