<?php
/* bastille_manager_tarballs.php - Versión Streaming Real-time */
require_once 'auth.inc';
require_once 'guiconfig.inc';
require_once("bastille_manager-lib.inc");

$pgtitle = [gtext("Extensions"), gtext('Bastille'), gtext('Releases')];

// --- MOTOR DE STREAMING (Lógica para pintar línea a línea) ---
if (isset($_GET['action']) && $_GET['action'] === 'stream_download') {
    // Desactivar buffering para que el texto salga según se genera
    header('Content-Type: text/plain');
    header('Cache-Control: no-cache');
    while (ob_get_level()) ob_end_clean();

    $get_release = $_GET['release'] ?? '';
    $lib32 = ($_GET['lib32'] === 'true') ? "lib32" : "";
    $ports = ($_GET['ports'] === 'true') ? "ports" : "";
    $src = ($_GET['src'] === 'true') ? "src" : "";
    $opt_tarballs = trim("$lib32 $ports $src");

    if (!empty($opt_tarballs) && !empty($config_path)) {
        exec("/usr/sbin/sysrc -f {$config_path} bastille_bootstrap_archives=\"base $opt_tarballs\"");
    }

    // Ejecutamos bastille y leemos su salida en tiempo real
    $command = sprintf('/usr/local/bin/bastille bootstrap %s 2>&1', escapeshellarg($get_release));
    $handle = popen($command, 'r');

    if ($handle) {
        while (!feof($handle)) {
            $line = fgets($handle);
            if ($line !== false) {
                // Limpiar códigos de color ANSI y enviar la línea
                echo preg_replace('/\e[[][A-Za-z0-9];?[0-9]*m?/', '', $line);
                flush(); // Forzar salida al navegador
            }
        }
        pclose($handle);
    }

    // Restaurar configuración por defecto
    if (!empty($config_path)) {
        exec("/usr/sbin/sysrc -f {$config_path} bastille_bootstrap_archives=\"$default_distfiles\"");
    }
    exit;
}

// --- LÓGICA DE BORRADO (POST ORIGINAL) ---
if ($_POST && isset($_POST['Destroy'])) {
    $get_release = $_POST['release_item'];
    mwexec("/usr/local/bin/bastille destroy " . escapeshellarg($get_release));
    header('Location: bastille_manager_tarballs.php');
    exit;
}

$sphere_array = [];
if (is_dir("{$rootfolder}/releases")) {
    $entries = preg_grep('/^[0-9]+\.[0-9]+\-RELEASE|(Debian[0-9]{1,2}$)|(Ubuntu_[0-9]{4}$)/', scandir("{$rootfolder}/releases"));
    foreach($entries as $entry) { $sphere_array[] = ['relname' => $entry]; }
}

$a_action = [
   '14.3-RELEASE' => '14.3-RELEASE', '14.2-RELEASE' => '14.2-RELEASE',
   '14.1-RELEASE' => '14.1-RELEASE', '14.0-RELEASE' => '14.0-RELEASE',
   '13.5-RELEASE' => '13.5-RELEASE', '13.4-RELEASE' => '13.4-RELEASE',
];

include 'fbegin.inc';
?>

<style>
    /* Estilo de consola real */
    #console-output {
        background-color: #1e1e1e;
        color: #00ff00;
        padding: 15px;
        font-family: 'Courier New', Courier, monospace;
        font-size: 12px;
        border-radius: 5px;
        margin-bottom: 20px;
        max-height: 400px;
        overflow-y: auto;
        border: 2px solid #333;
        display: none;
        white-space: pre-wrap;
    }
    .status-msg { font-weight: bold; margin-bottom: 5px; color: #555; }
</style>

<script type="text/javascript">
async function startDownload() {
    const release = document.getElementsByName('release_item')[0].value;
    const lib32 = document.getElementsByName('lib32')[0].checked;
    const ports = document.getElementsByName('ports')[0].checked;
    const src = document.getElementsByName('src')[0].checked;

    const consoleDiv = document.getElementById('console-output');
    consoleDiv.style.display = 'block';
    consoleDiv.innerHTML = "Iniciando proceso...\n";

    // NO usamos spinner() para no bloquear la pantalla
    const btn = document.getElementById('btn-download');
    btn.disabled = true;
    btn.value = "Descargando...";

    try {
        // Usamos fetch para leer el stream línea a línea
        const response = await fetch(`bastille_manager_tarballs.php?action=stream_download&release=${release}&lib32=${lib32}&ports=${ports}&src=${src}`);
        const reader = response.body.getReader();
        const decoder = new TextDecoder();

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            const text = decoder.decode(value, { stream: true });
            consoleDiv.innerHTML += text;
            consoleDiv.scrollTop = consoleDiv.scrollHeight; // Auto-scroll
        }

        consoleDiv.innerHTML += "\n--- PROCESO FINALIZADO ---";
    } catch (error) {
        consoleDiv.innerHTML += "\n[ERROR]: " + error;
    } finally {
        btn.disabled = false;
        btn.value = "Download";
    }
}

$(window).on("load", function() {
    $("#Destroy").click(function () { return confirm('Do you really want to destroy this release?'); });
});
</script>

<form action="bastille_manager_tarballs.php" method="post" name="iform" id="iform">
<table id="area_data"><tbody><tr><td id="area_data_frame">

    <div class="status-msg" id="msg-box">Salida de consola:</div>
    <div id="console-output"></div>

    <table class="area_data_settings">
       <thead>
<?php
          if (is_dir($reldir)):
             html_titleline2(gettext('FreeBSD/Linux Base Release Installed'));
             foreach ($sphere_array as $sphere_record):
                $label = is_dir("{$reldir}/{$sphere_record['relname']}/root") ? 'Installed Base:' : 'Unknown Base:';
                html_text2('releases', $label, $sphere_record['relname']);
             endforeach;
          endif;
          html_separator();
          html_titleline2(gettext('FreeBSD Base Release Download'));
?>
       </thead>
       <tbody>
<?php
          html_combobox2('release_item', gettext('Select Base Release'), '', $a_action, '', true);
          html_titleline2(gettext('Optional Distfiles'));
          html_checkbox2('lib32', gettext('32-bit Compatibility'), false, 'lib32.txz');
          html_checkbox2('ports', gettext('Ports tree'), false, 'ports.txz');
          html_checkbox2('src', gettext('System source tree'), false, 'src.txz');
?>
       </tbody>
    </table>

    <div id="submit">
       <input name="Download" id="btn-download" type="button" class="formbtn" value="Download" onclick="startDownload()" />
       <input name="Destroy" id="Destroy" type="submit" class="formbtn" value="Destroy"/>
    </div>
</td></tr></tbody></table></form>
<?php include 'fend.inc'; ?>