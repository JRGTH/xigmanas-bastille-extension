<?php
/*
	bastille_manager_tarballs.php

	Copyright (c) 2019-2026 José Rivera (joserprg@gmail.com).
    All rights reserved.

	Portions of XigmaNAS® (https://www.xigmanas.com).
	Copyright (c) 2018 XigmaNAS® <info@xigmanas.com>.
	XigmaNAS® is a registered trademark of Michael Zoon (zoon01@xigmanas.com).
	All rights reserved.

	Redistribution and use in source and binary forms, with or without
	modification, are permitted provided that the following conditions
	are met:
	1. Redistributions of source code must retain the above copyright
	    notice, this list of conditions and the following disclaimer.
	2. Redistributions in binary form must reproduce the above copyright
	    notice, this list of conditions and the following disclaimer in the
	    documentation and/or other materials provided with the distribution.
	3. Neither the name of the developer nor the names of contributors
	    may be used to endorse or promote products derived from this software
	    without specific prior written permission.

	THIS SOFTWARE IS PROVIDED BY THE DEVELOPER ``AS IS'' AND
	ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
	IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
	ARE DISCLAIMED.  IN NO EVENT SHALL THE DEVELOPER OR CONTRIBUTORS BE LIABLE
	FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
	DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS
	OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION)
	HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
	LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY
	OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF
	SUCH DAMAGE.
*/

require_once 'auth.inc';
require_once 'guiconfig.inc';
require_once("bastille_manager-lib.inc");

$gt_selection_delete_confirm = gtext('Do you really want to destroy this base release?');
$pgtitle = [gtext("Extensions"), gtext('Bastille'),gtext('Releases')];

// --- ASYNCHRONOUS STREAMING PROCESSING ---
if (isset($_GET['action']) && $_GET['action'] === 'stream') {
    //This line allows us to avoid gluing downloads together, and prevents the PHP session from blocking the application.
    session_write_close();

    @ini_set('output_buffering', '0');
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');
    ob_implicit_flush(1);
    header('X-Accel-Buffering: no');
    header('Content-Encoding: none');
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
        $command = sprintf('/usr/local/bin/bastille bootstrap %s 2>&1', escapeshellarg($get_release));
    } else {
        $command = sprintf('/usr/local/bin/bastille destroy %s 2>&1', escapeshellarg($get_release));
    }

    $handle = popen($command, 'r');
    if ($handle) {
        stream_set_blocking($handle, false);
        while (!feof($handle)) {
            $chunk = fread($handle, 128);
            if ($chunk !== false && $chunk !== '') {
                echo preg_replace('/\e[[][A-Za-z0-9];?[0-9]*m?/', '', $chunk);
                flush();
            } else {
                usleep(20000);
            }
        }
        pclose($handle);
    }

    if ($mode === 'bootstrap' && !empty($config_path)) {
        exec("/usr/sbin/sysrc -f {$config_path} bastille_bootstrap_archives=\"$default_distfiles\"");
    }
    exit;
}

$sphere_array = [];
$sphere_record = [];
$pconfig = [];

function get_rel_list() {
	global $rootfolder;
	global $jail_dir;
	$result = [];
	if (is_dir("{$rootfolder}/releases")):
		$entries = preg_grep('/^[0-9]+\.[0-9]+\-RELEASE|(Debian[0-9]{1,2}$)|(Ubuntu_[0-9]{4}$)/', scandir("{$rootfolder}/releases"));
		foreach($entries as $entry):
		$a = preg_split('/\t/',$entry);
		$r = [];
		$name = $a[0];
		if(preg_match('/^[0-9]+\.[0-9]+\-RELEASE|(Debian[0-9]{1,2}$)|(Ubuntu_[0-9]{4}$)/', $name, $m)):
			$r['name'] = $m[0];
		else:
			$r['name'] = 'unknown';
		endif;
		$r['relname'] = $r['name'];
		$result[] = $r;
		endforeach;
	endif;
	return $result;
}

$zfs_status = get_state_zfs();
if($zfs_status == "Invalid ZFS configuration"):
	// Warning if invalid ZFS configuration.
	$input_errors[] = gtext("WARNING: Invalid ZFS configuration detected.");
endif;

$rel_list = get_rel_list();
$sphere_array = $rel_list;

if ($linux_compat_support == "YES"):
	$a_action = [
		'14.3-RELEASE' => gettext('14.3-RELEASE'),
		'14.2-RELEASE' => gettext('14.2-RELEASE'),
		'14.1-RELEASE' => gettext('14.1-RELEASE'),
		'14.0-RELEASE' => gettext('14.0-RELEASE'),
		'13.5-RELEASE' => gettext('13.5-RELEASE'),
		'13.4-RELEASE' => gettext('13.4-RELEASE'),
		// Linux base release bootstrap is allowed from command-line.
		//'ubuntu-noble' => gettext('Ubuntu-noble'),
		//'ubuntu-jammy' => gettext('Ubuntu-Jammy'),
		//'ubuntu-focal' => gettext('Ubuntu-Focal'),
		//'ubuntu-bionic' => gettext('Ubuntu-Bionic'),
		//'debian-bookworm' => gettext('Debian-Bookworm'),
		//'debian-bullseye' => gettext('Debian-Bullseye'),
		//'debian-buster' => gettext('Debian-Buster'),
		//'debian-stretch' => gettext('Debian-Stretch'),
	];
else:
	$a_action = [
		'14.3-RELEASE' => gettext('14.3-RELEASE'),
		'14.2-RELEASE' => gettext('14.2-RELEASE'),
		'14.1-RELEASE' => gettext('14.1-RELEASE'),
		'14.0-RELEASE' => gettext('14.0-RELEASE'),
		'13.5-RELEASE' => gettext('13.5-RELEASE'),
		'13.4-RELEASE' => gettext('13.4-RELEASE'),
	];
endif;

if($_POST):
	unset($input_errors);
	unset($errormsg);
	unset($savemsg);
	$pconfig = $_POST;
	if(isset($_POST['Cancel']) && $_POST['Cancel']):
		header('Location: bastille_manager_gui.php');
		exit;
	endif;

	if (isset($_POST['Download']) && $_POST['Download']):
		$lib32 = "";
		$ports = "";
		$src = "";
		$get_release = $pconfig['release_item'];
		$check_release = ("{$rootfolder}/releases/{$get_release}");
		$cmd = sprintf('/bin/echo "Y" | /usr/local/bin/bastille bootstrap %1$s > %2$s 2>&1', $get_release, $logevent);
		$base_mandatory = "base";
		$zfs_status = get_state_zfs();

		//unset($lib32,$ports,$src);
		if (isset($_POST['lib32'])):
			$lib32 = "lib32";
		endif;
		if (isset($_POST['ports'])):
			$ports = "ports";
		endif;
		if (isset($_POST['src'])):
			$src = "src";
		endif;
		$opt_tarballs = "$lib32 $ports $src";

		// Download a FreeBSD base release.
		if ($_POST['Download']):
			if($zfs_status == "Invalid ZFS configuration"):
				// Abort bootstrap if invalid ZFS configuration.
				$input_errors[] = gtext("Cannot bootstrap with an invalid ZFS configuration.");
			else:
				$savemsg = "";
				$errormsg = "";
				if ($opt_tarballs):
					if ($config_path):
						// Override default distfiles once.
						exec("/usr/sbin/sysrc -f {$config_path} bastille_bootstrap_archives=\"$base_mandatory $opt_tarballs\"");
					endif;
				endif;
				$return_val = 0;
				$output = [];
				exec($cmd,$output,$return_val);
                if($return_val == 0):
                      $ausgabe = "";
                      if (file_exists($logevent)) {
                          $ausgabe = file_get_contents($logevent);
                      }
                      $ausgabe = preg_replace('/\e[[][A-Za-z0-9];?[0-9]*m?/', '', $ausgabe);
                      $ausgabe = trim($ausgabe);
                      if (!empty($ausgabe)) {
                          $savemsg .= str_replace("\n", "<br />", htmlspecialchars($ausgabe)) . "<br />";
                      } else {
                          $savemsg .= sprintf(gtext('%s Bootstrap process completed successfully.'), $get_release) . "<br />";
                      }
                      // Set back default distfiles.
                      exec("/usr/sbin/sysrc -f {$config_path} bastille_bootstrap_archives=\"$default_distfiles\"");
                else:
                      $errormsg .= sprintf(gtext('%s Failed to download and/or extract release base.'),$get_release);
                endif;

			endif;
		endif;
	endif;

	if (isset($_POST['Destroy']) && $_POST['Destroy']):
		if ($_POST['Destroy']):
			$get_release = $pconfig['release_item'];
			if($get_release == 'ubuntu-bionic'):
				$get_release = "Ubuntu_1804";
			elseif($get_release == 'ubuntu-focal'):
				$get_release = "Ubuntu_2004";
			elseif($get_release == 'ubuntu-jammy'):
				$get_release = "Ubuntu_2204";
			elseif($get_release == 'debian-stretch'):
				$get_release = "Debian9";
			elseif($get_release == 'debian-buster'):
				$get_release = "Debian10";
			elseif($get_release == 'debian-bullseye'):
				$get_release = "Debian11";
			elseif($get_release == 'debian-bookworm'):
				$get_release = "Debian12";
			endif;

			$check_release = ("{$rootfolder}/releases/{$get_release}");
			$check_used = exec("/usr/bin/grep -wo {$get_release} {$jail_dir}/*/fstab 2>/dev/null");

			$cmd = ("/usr/local/bin/bastille destroy {$get_release}");

			if (!file_exists($check_release)):
				// FreeBSD base release check.
				$savemsg .= sprintf(gtext('%s base does not exist, nothing to do.'),$get_release);
			else:
				// Do not delete base releases with containers child.
				if ($check_used):
					$errormsg .= sprintf(gtext('%s base appears to have containers child.'),$get_release);
				else:
					// Delete the FreeBSD base release/directory.
					if ($_POST['Destroy']):
						unset($output,$retval);mwexec2($cmd,$output,$retval);
						if($retval == 0):
							//$savemsg .= sprintf(gtext('%s base deleted successfully.'),$get_release);
							header('Location: bastille_manager_tarballs.php');
						else:
							$errormsg .= sprintf(gtext('%s failed to delete.'),$get_release);
						endif;
					endif;
				endif;
			endif;
		endif;
	endif;
endif;

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
    logArea.textContent = ""; // Empieza vacío, sin "Processing..."

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

        let fullText = "";

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            const chunk = decoder.decode(value, { stream: true });

            for (let i = 0; i < chunk.length; i++) {
                const char = chunk[i];
                if (char === '\r') {
                    // Si hay un retorno de carro, buscamos el último salto de línea
                    const lastNewline = fullText.lastIndexOf('\n');
                    // Borramos desde el último salto de línea hasta el final para sobreescribir el progreso
                    fullText = lastNewline !== -1 ? fullText.substring(0, lastNewline + 1) : '';
                } else {
                    fullText += char;
                }
            }
            logArea.textContent = fullText;
        }
    } catch (e) {
        logArea.textContent += "\n[Error]: " + e;
    } finally {
        btnDown.disabled = btnDest.disabled = false;
    }
}
</script>

<?php
$document = new co_DOMDocument();
$document->
	add_area_tabnav()->
		push()->
		add_tabnav_upper()->
			ins_tabnav_record('bastille_manager_gui.php',gettext('Containers'),gettext('Reload page'),true)->
			ins_tabnav_record('bastille_manager_info.php',gettext('Information'),gettext('Reload page'),true)->
			ins_tabnav_record('bastille_manager_maintenance.php',gettext('Maintenance'),gettext('Reload page'),true)->
		pop()->add_tabnav_lower()->
			ins_tabnav_record('bastille_manager_config.php',gettext('Bastille Configuration'),gettext('Reload page'),true)->
			ins_tabnav_record('bastille_manager_tarballs.php',gettext('Base Releases'),gettext('Reload page'),true);
$document->render();
?>

<form action="bastille_manager_tarballs.php" method="post" name="iform" id="iform">
    <table id="area_data"><tbody><tr><td id="area_data_frame">

        <div id="log-container" style="display:none; margin-bottom:15px;">
            <?php
                print_info_box('<div id="log-area" style="text-align: left; white-space: pre-wrap; font-family: monospace; font-size: 11px; padding: 5px;"></div>');
            ?>
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