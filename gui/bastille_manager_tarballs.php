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
require_once 'bastille_manager-lib.inc';

// The release job classes live next to this page (Bastille*.php).
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . "/{$class}.php";
    if (str_starts_with($class, 'Bastille') && is_file($file)) {
        require_once $file;
    }
});

$pgtitle = [gtext('Extensions'), gtext('Bastille'), gtext('Releases')];

// Set RELEASE_RESUME_DOWNLOADS="NO" in the extension config to fall back to a plain
// "bastille bootstrap" (see the header of bastille_manager_release_job.sh).
$resume_downloads = strtoupper(trim((string)shell_exec('/usr/sbin/sysrc -f ' . escapeshellarg($configfile) . ' -qn RELEASE_RESUME_DOWNLOADS 2>/dev/null'))) !== 'NO';

$release_cache = new BastilleReleaseCache((string)$config_path);
$release_view = new BastilleReleaseView(gtext(...));
$release_controller = new BastilleReleaseController(
    new BastilleReleaseJob("{$rootfolder}/log/release_job", __DIR__ . '/bastille_manager_release_job.sh'),
    $release_cache,
    "{$rootfolder}/releases",
    $jail_dir,
    (string)$config_path,
    (string)$default_distfiles,
    $resume_downloads,
    static fn(): bool => get_state_zfs() === 'Invalid ZFS configuration',
    gtext(...),
);

// --- JSON endpoints polled by js/modules/release-job.js ---
$release_action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if (BastilleReleaseController::handles($release_action)) {
    // Do not hold the PHP session lock, otherwise the rest of the WebGUI blocks while a job runs.
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        $release_controller->handle($release_action, $_SERVER['REQUEST_METHOD'], $_GET, $_POST),
        JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
    );
    exit;
}

function get_rel_list() {
	global $rootfolder;
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

if (get_state_zfs() == 'Invalid ZFS configuration'):
	// Warning if invalid ZFS configuration.
	$input_errors[] = gtext('WARNING: Invalid ZFS configuration detected.');
endif;

$sphere_array = get_rel_list();

// Releases offered for download. Linux base release bootstrap is allowed from command-line.
$a_action = [
	'14.4-RELEASE' => gettext('14.4-RELEASE'),
	'14.3-RELEASE' => gettext('14.3-RELEASE'),
	'14.2-RELEASE' => gettext('14.2-RELEASE'),
	'14.1-RELEASE' => gettext('14.1-RELEASE'),
	'14.0-RELEASE' => gettext('14.0-RELEASE'),
	'13.5-RELEASE' => gettext('13.5-RELEASE'),
	'13.4-RELEASE' => gettext('13.4-RELEASE'),
];

if (isset($_POST['Cancel'])):
	header('Location: bastille_manager_gui.php');
	exit;
endif;

include 'fbegin.inc';
?>

<script>window.RELEASE_CONFIG = <?=$release_view->clientConfig('bastille_manager_tarballs.php');?>;</script>
<script type="module" src="ext/bastille/js/release_main.js?v=<?=filemtime(__DIR__ . '/js/release_main.js');?>"></script>

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
                print_info_box('<div id="job-status" style="font-weight:bold; padding:5px;"></div><div id="log-area" style="text-align: left; white-space: pre-wrap; font-family: monospace; font-size: 11px; padding: 5px; max-height: 400px; overflow-y: auto;"></div>');
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
           <input name="Download" id="btn-download" type="button" class="formbtn" data-job-action="bootstrap" value="<?=gtext('Download');?>" />
           <input name="Destroy" id="btn-destroy" type="button" class="formbtn" data-job-action="destroy" value="<?=gtext('Destroy');?>" />
           <input name="Cancel" type="submit" class="formbtn" value="<?=gtext('Cancel');?>" />
        </div>

        <div id="partial-box" style="margin-top:10px;"><?=$release_view->partialBox($release_cache->partials());?></div>

    </td></tr></tbody></table>
    <?php include 'formend.inc'; ?>
</form>

<?php include 'fend.inc'; ?>
