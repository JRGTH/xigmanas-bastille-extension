<?php
require_once 'auth.inc';
require_once 'guiconfig.inc';
require_once("bastille_manager-lib.inc");

$gt_selection_delete_confirm = gtext('Do you really want to destroy this base release?');
$pgtitle = [gtext("Extensions"), gtext('Bastille'), gtext('Releases')];

// --- MOTOR DE STREAMING (Bootstrap y Destroy) ---
// Colocamos esto arriba para que responda rápido antes de cargar el resto de la UI
if (isset($_GET['action']) && $_GET['action'] === 'stream') {
    // Forzamos salida en tiempo real
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // Para servidores con Nginx

    while (ob_get_level()) ob_end_clean();

    $mode = $_GET['mode'] ?? '';
    $get_release = $_GET['release'] ?? '';

    if ($mode === 'bootstrap') {
        $lib32 = ($_GET['lib32'] === 'true') ? "lib32" : "";
        $ports = ($_GET['ports'] === 'true') ? "ports" : "";
        $src = ($_GET['src'] === 'true') ? "src" : "";
        $opt_tarballs = trim("$lib32 $ports $src");

        if (!empty($opt_tarballs) && !empty($config_path)) {
            exec("/usr/sbin/sysrc -f {$config_path} bastille_bootstrap_archives=\"base $opt_tarballs\"");
        }
        $command = sprintf('/usr/local/bin/bastille bootstrap %s 2>&1', escapeshellarg($get_release));
    } elseif ($mode === 'destroy') {
        $command = sprintf('/usr/local/bin/bastille destroy %s 2>&1', escapeshellarg($get_release));
    } else {
        echo "Modo no válido.";
        exit;
    }

    $handle = popen($command, 'r');
    if ($handle) {
        while (!feof($handle)) {
            $line = fgets($handle);
            if ($line !== false) {
                // Quitamos colores ANSI
                echo preg_replace('/\e[[][A-Za-z0-9];?[0-9]*m?/', '', $line);
                flush();
            }
        }
        pclose($handle);
    }

    // Restaurar config si era bootstrap
    if ($mode === 'bootstrap' && !empty($config_path)) {
        exec("/usr/sbin/sysrc -f {$config_path} bastille_bootstrap_archives=\"$default_distfiles\"");
    }
    exit;
}

// --- LÓGICA DE LISTADO (Original) ---
function get_rel_list() {
    global $rootfolder;
    $result = [];
    if (is_dir("{$rootfolder}/releases")):
       $entries = preg_grep('/^[0-9]+\.[0-9]+\-RELEASE|(Debian[0-9]{1,2}$)|(Ubuntu_[0-9]{4}$)/', scandir("{$rootfolder}/releases"));
       foreach($entries as $entry):
          $result[] = ['relname' => $entry];
       endforeach;
    endif;
    return $result;
}
$sphere_array = get_rel_list();

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
    if(!release) { alert('Selecciona una release'); return; }

    if(mode === 'destroy' && !confirm('<?=$gt_selection_delete_confirm;?>')) return;

    const logArea = document.getElementById('log-area');
    const logContainer = document.getElementById('log-container');

    logContainer.style.display = 'block';
    logArea.textContent = "== Iniciando " + mode.toUpperCase() + " de " + release + " ==\n";

    const btnDown = document.getElementById('btn-download');
    const btnDest = document.getElementById('btn-destroy');
    btnDown.disabled = btnDest.disabled = true;

    // Construimos la URL
    const params = new URLSearchParams({
        action: 'stream',
        mode: mode,
        release: release,
        lib32: document.getElementsByName('lib32')[0].checked,
        ports: document.getElementsByName('ports')[0].checked,
        src: document.getElementsByName('src')[0].checked
    });

    try {
        const response = await fetch('bastille_manager_tarballs.php?' + params.toString());

        if (response.status === 401) {
            logArea.textContent += "\nError 401: Sesión expirada o no autorizada. Recarga la página.";
            return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            const chunk = decoder.decode(value, { stream: true });

            // Lógica para el progreso \r (sobreescribir última línea)
            if (chunk.includes('\r')) {
                const parts = chunk.split('\r');
                const existingLines = logArea.textContent.split('\n');
                existingLines[existingLines.length - 1] = parts[parts.length - 1];
                logArea.textContent = existingLines.join('\n');
            } else {
                logArea.textContent += chunk;
            }

            logArea.scrollTop = logArea.scrollHeight;
        }
        logArea.textContent += "\n\n== PROCESO FINALIZADO ==";

        // Si fue un destroy, recargamos para limpiar la lista de instalados
        if(mode === 'destroy') {
            setTimeout(() => { location.reload(); }, 2000);
        }
    } catch (e) {
        logArea.textContent += "\n[Error de conexión]: " + e;
    } finally {
        btnDown.disabled = btnDest.disabled = false;
    }
}
</script>

<form action="bastille_manager_tarballs.php" method="post" name="iform" id="iform">
    <table id="area_data"><tbody><tr><td id="area_data_frame">

        <div id="log-container" style="display:none; margin-bottom:15px; border:1px solid #ccc; background:#fff;">
            <div style="background:#eee; padding:5px; font-weight:bold; border-bottom:1px solid #ccc;"><?=gtext("Bastille Console Output:");?></div>
            <pre id="log-area" style="margin:0; padding:10px; max-height:300px; overflow:auto; font-family:monospace; white-space:pre-wrap; font-size:11px;"></pre>
        </div>

        <table class="area_data_settings">
           <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
           <thead>
              <?php
              // SECCIÓN: INSTALLED BASE (Como estaba en tu foto)
              if (is_dir($reldir)):
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
              html_titleline2(gettext('Optional Distfiles (Overrides config)'));
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