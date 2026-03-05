<?php
require_once 'auth.inc';
require_once 'guiconfig.inc';
require_once("bastille_manager-lib.inc");

$gt_selection_delete_confirm = gtext('Do you really want to destroy this base release?');
$pgtitle = [gtext("Extensions"), gtext('Bastille'), gtext('Releases')];

// --- PROCESAMIENTO ASÍNCRONO (Streaming Real-Time) ---
if (isset($_GET['action']) && $_GET['action'] === 'stream') {
    // 1. Liberar la web (Session Unlocking)
    session_write_close();

    // 2. Destruir cualquier tipo de buffer del servidor/PHP
    @ini_set('output_buffering', '0');
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');
    ob_implicit_flush(1);
    header('X-Accel-Buffering: no'); // Crítico si usas Nginx o proxy
    header('Content-Encoding: none'); // Apagar Gzip
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    while (ob_get_level()) ob_end_clean();

    $mode = $_GET['mode'] ?? '';
    $get_release = $_GET['release'] ?? '';

    if ($mode === 'bootstrap') {
        $lib32 = ($_GET['lib32'] === 'true') ? "lib32" : "";
        $ports = ($_GET['ports'] === 'true') ? "ports" : "";
        $src = ($_GET['src'] === 'true') ? "src" : "";
        if (!empty($config_path)) {
            exec("/usr/sbin/sysrc -f {$config_path} bastille_bootstrap_archives=\"base $lib32 $ports $src\"");
        }
        // stdbuf -o0 fuerza a FreeBSD a no retener el log
        $command = sprintf('stdbuf -o0 /usr/local/bin/bastille bootstrap %s 2>&1', escapeshellarg($get_release));
    } else {
        $command = sprintf('stdbuf -o0 /usr/local/bin/bastille destroy %s 2>&1', escapeshellarg($get_release));
    }

    $handle = popen($command, 'r');
    if ($handle) {
        stream_set_blocking($handle, false); // 3. Lectura No Bloqueante
        while (!feof($handle)) {
            $chunk = fread($handle, 32); // Leemos miguitas de pan
            if ($chunk !== false && $chunk !== '') {
                echo preg_replace('/\e[[][A-Za-z0-9];?[0-9]*m?/', '', $chunk);
                flush();
            } else {
                usleep(15000); // 15ms de pausa para no fundir la CPU
            }
        }
        pclose($handle);
    }

    if ($mode === 'bootstrap' && !empty($config_path)) {
        exec("/usr/sbin/sysrc -f {$config_path} bastille_bootstrap_archives=\"$default_distfiles\"");
    }
    exit;
}

// Lógica de listado para la tabla
$sphere_array = [];
if (is_dir("{$rootfolder}/releases")):
   $entries = preg_grep('/^[0-9]+\.[0-9]+\-RELEASE|(Debian[0-9]{1,2}$)|(Ubuntu_[0-9]{4}$)/', scandir("{$rootfolder}/releases"));
   foreach($entries as $entry):
      $sphere_array[] = ['relname' => $entry];
   endforeach;
endif;

$a_action = [
   '14.3-RELEASE' => '14.3-RELEASE', '14.2-RELEASE' => '14.2-RELEASE',
   '14.1-RELEASE' => '14.1-RELEASE', '14.0-RELEASE' => '14.0-RELEASE',
   '13.5-RELEASE' => '13.5-RELEASE', '13.4-RELEASE' => '13.4-RELEASE',
];

include 'fbegin.inc';
?>

<script type="text/javascript">
async function runBastilleAction(mode) {
    const release = document.getElementsByName('release_item')[0].value;
    if(!release) return;
    if(mode === 'destroy' && !confirm('<?=$gt_selection_delete_confirm;?>')) return;

    const logArea = document.getElementById('log-area');
    const logContainer = document.getElementById('log-container');

    logContainer.style.display = 'block';
    logArea.textContent = "Processing " + mode + " for " + release + "...\n";

    const btnDown = document.getElementById('btn-download');
    const btnDest = document.getElementById('btn-destroy');
    btnDown.disabled = btnDest.disabled = true;

    const params = new URLSearchParams({
        action: 'stream', mode: mode, release: release,
        lib32: document.getElementsByName('lib32')[0].checked,
        ports: document.getElementsByName('ports')[0].checked,
        src: document.getElementsByName('src')[0].checked
    });

    try {
        const response = await fetch('bastille_manager_tarballs.php?' + params.toString());
        const reader = response.body.getReader();
        const decoder = new TextDecoder();

        let currentText = logArea.textContent;

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            const chunk = decoder.decode(value, { stream: true });

            for (let i = 0; i < chunk.length; i++) {
                const char = chunk[i];
                if (char === '\r') {
                    // Evitar que un \r\n (salto de línea clásico) nos borre la línea
                    if (i + 1 < chunk.length && chunk[i + 1] === '\n') {
                        currentText += '\n';
                        i++; // Saltamos el \n porque ya lo hemos procesado
                    } else {
                        // Es un \r puro (Barra de progreso de Bastille)
                        const lastNewline = currentText.lastIndexOf('\n');
                        currentText = lastNewline !== -1 ? currentText.substring(0, lastNewline + 1) : '';
                    }
                } else {
                    currentText += char;
                }
            }
            logArea.textContent = currentText;
            logArea.scrollTop = logArea.scrollHeight; // Auto-scroll
        }
    } catch (e) {
        logArea.textContent += "\n[Error]: " + e;
    } finally {
        btnDown.disabled = btnDest.disabled = false;
        if(mode === 'destroy') { setTimeout(() => { location.reload(); }, 1500); }
    }
}
</script>

<form action="bastille_manager_tarballs.php" method="post" name="iform" id="iform">
    <table id="area_data"><tbody><tr><td id="area_data_frame">

        <div id="log-container" style="display:none; margin-bottom:15px;">
            <pre id="log-area" style="background:#f0f0f0; border:1px solid #ccc; padding:10px; font-family:monospace; white-space:pre-wrap; font-size:11px; color:#333;"></pre>
        </div>

        <table class="area_data_settings">
           <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
           <thead>
              <?php
              if (!empty($sphere_array)):
                 html_titleline2(gettext('FreeBSD/Linux Base Release Installed'));
                 foreach ($sphere_array as $sphere_record):
                    html_text2('releases', gettext('Installed Base:'), htmlspecialchars($sphere_record['relname']));
                 endforeach;
              endif;
              html_separator();
              html_titleline2(gettext('FreeBSD Base Release Download'));
              ?>
           </thead>
           <tbody>
              <?php
              html_combobox2('release_item', gettext('Select Base Release'), '', $a_action, '', true, false);
              html_titleline2(gettext('Optional Distfiles (Overrides config, has no effect on Linux Releases)'));
              html_checkbox2('lib32', gettext('32-bit Compatibility'), false, 'lib32.txz', '', false);
              html_checkbox2('ports', gettext('Ports tree'), false, 'ports.txz', '', false);
              html_checkbox2('src', gettext('System source tree'), false, 'src.txz', '', false);
              ?>
           </tbody>
        </table>

        <div id="submit">
           <input name="Download" id="btn-download" type="button" class="formbtn" value="<?=gtext("Download");?>" onclick="runBastilleAction('bootstrap')" />
           <input name="Destroy" id="btn-destroy" type="button" class="formbtn" value="<?=gtext("Destroy");?>" onclick="runBastilleAction('destroy')" />
           <input name="Cancel" type="submit" class="formbtn" value="<?=gtext("Cancel");?>" />
        </div>

    </td></tr></tbody></table>
</form>

<?php include 'fend.inc'; ?>