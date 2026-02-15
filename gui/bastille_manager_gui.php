<?php
/*
	bastille_manager_gui.php

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

function mwexec_parallel($commands) {
	$processes = [];
	$results = [];

	foreach ($commands as $key => $command) {
		$descriptors = [
			0 => ['pipe', 'r'],  // stdin
			1 => ['pipe', 'w'],  // stdout
			2 => ['pipe', 'w']   // stderr
		];

		$process = proc_open($command, $descriptors, $pipes);

		if (is_resource($process)) {

			stream_set_blocking($pipes[1], false);
			stream_set_blocking($pipes[2], false);

			$processes[$key] = [
				'process' => $process,
				'pipes' => $pipes,
				'command' => $command
			];
		}
	}

	$timeout = 30;
	$start_time = time();

	foreach ($processes as $key => $proc) {
		$elapsed = time() - $start_time;
		if ($elapsed < $timeout) {

			$stdout = stream_get_contents($proc['pipes'][1]);
			$stderr = stream_get_contents($proc['pipes'][2]);

			fclose($proc['pipes'][0]);
			fclose($proc['pipes'][1]);
			fclose($proc['pipes'][2]);

			$return_code = proc_close($proc['process']);

			$results[$key] = [
				'return_code' => $return_code,
				'stdout' => $stdout,
				'stderr' => $stderr
			];
		} else {
			proc_terminate($proc['process']);
			proc_close($proc['process']);

			$results[$key] = [
				'return_code' => -1,
				'stdout' => '',
				'stderr' => 'Command timeout'
			];
		}
	}

	return $results;
}

function mwexec_background($command) {
	$command = $command . ' > /dev/null 2>&1 &';
	exec($command);
}

$sphere_scriptname = basename(__FILE__);
$sphere_scriptname_child = 'bastille_manager_util.php';
$sphere_header = 'Location: '.$sphere_scriptname;
$sphere_header_parent = $sphere_header;
$sphere_array = [];
$sphere_record = [];
$checkbox_member_name = 'checkbox_member_array';
$checkbox_member_array = [];
$checkbox_member_record = [];
$gt_record_add = gtext('Create new jail');
$gt_record_mod = gtext('Utilities');
$gt_selection_start = gtext('Start Selected');
$gt_selection_stop = gtext('Stop Selected');
$gt_selection_restart = gtext('Restart Selected');
$gt_selection_autoboot = gtext('Auto-boot Selected');
$gt_record_conf = gtext('Jail Configuration');
$gt_record_inf = gtext('Information');
$gt_selection_start_confirm = gtext('Do you really want to start selected jail(s)?');
$gt_selection_stop_confirm = gtext('Do you want to stop the selected jail(s)?');
$gt_selection_restart_confirm = gtext('Do you want to restart the selected jail(s)?');
$gt_selection_autoboot_confirm = gtext('Do you want to set auto-boot on selected jail(s)?');
$img_path = [
	'add' => 'images/add.png',
	'mod' => 'images/edit.png',
	'del' => 'images/delete.png',
	'loc' => 'images/locked.png',
	'unl' => 'images/unlocked.png',
	'mai' => 'images/maintain.png',
	'inf' => 'images/info.png',
	'ena' => 'images/status_enabled.png',
	'dis' => 'images/status_disabled.png',
	'mup' => 'images/up.png',
	'mdn' => 'images/down.png'
];

$jls_list = get_jail_infos();
$sphere_array = $jls_list;

if(!initial_install_banner()):
	$errormsg = gtext('Bastille Initial Configuration:')
			. ' '
			. '<a href="' . 'bastille_manager_config.php' . '">'
			. gtext('Please check and configure ZFS support option first.')
			. '</a>'
			. '</br>'
			. gtext('Alternatively to skip this step:')
			. ' '
			. '<a href="' . 'bastille_manager_maintenance.php' . '">'
			. gtext('Please click here then push "Save" button.')
			. '</a>';
		$prerequisites_ok = false;
endif;

$zfs_status = get_state_zfs();
if($zfs_status == "Invalid ZFS configuration"):
	// Warning if invalid ZFS configuration.
	$input_errors[] = gtext("WARNING: Invalid ZFS configuration detected.");
endif;

if($_POST):
	if(isset($_POST['apply']) && $_POST['apply']):
		$ret = array('output' => [], 'retval' => 0);
		if(!file_exists($d_sysrebootreqd_path)):
			// Process notifications
		endif;
		$savemsg = get_std_save_message($ret['retval']);
		if($ret['retval'] == 0):
			updatenotify_delete($sphere_notifier);
			header($sphere_header);
			exit;
		endif;
		updatenotify_delete($sphere_notifier);
		$errormsg = implode("\n", $ret['output']);
	endif;

	if(isset($_POST['start_selected_jail']) && $_POST['start_selected_jail']):
		$checkbox_member_array = isset($_POST[$checkbox_member_name]) ? $_POST[$checkbox_member_name] : [];
		$commands = [];
		$jail_names = [];

		foreach($checkbox_member_array as $checkbox_member_record):
			if(false !== ($index = array_search_ex($checkbox_member_record, $sphere_array, 'jailname'))):
				if(!isset($sphere_array[$index]['protected'])):
					$commands[] = "/usr/local/bin/bastille start {$checkbox_member_record}";
					$jail_names[] = $checkbox_member_record;
				endif;
			endif;
		endforeach;

		if (!empty($commands)):

			$results = mwexec_parallel($commands);

			$success_count = 0;
			$fail_count = 0;

			foreach ($results as $result):
				if ($result['return_code'] == 0):
					$success_count++;
				else:
					$fail_count++;
				endif;
			endforeach;

			if (function_exists('invalidate_jail_cache')) {
				invalidate_jail_cache();
			}

			if ($fail_count > 0):
				$errormsg = sprintf(gtext("Started %d jail(s), failed %d jail(s)."), $success_count, $fail_count);
			else:
				$savemsg = sprintf(gtext("%d jail(s) started successfully."), $success_count);
			endif;

			header($sphere_header);
		endif;
	endif;

	if(isset($_POST['stop_selected_jail']) && $_POST['stop_selected_jail']):
		$checkbox_member_array = isset($_POST[$checkbox_member_name]) ? $_POST[$checkbox_member_name] : [];
		$commands = [];

		foreach($checkbox_member_array as $checkbox_member_record):
			if(false !== ($index = array_search_ex($checkbox_member_record, $sphere_array, 'jailname'))):
				if(!isset($sphere_array[$index]['protected'])):
					$commands[] = "/usr/local/bin/bastille stop {$checkbox_member_record}";
				endif;
			endif;
		endforeach;

		if (!empty($commands)):
			$results = mwexec_parallel($commands);

			$success_count = 0;
			$fail_count = 0;

			foreach ($results as $result):
				if ($result['return_code'] == 0):
					$success_count++;
				else:
					$fail_count++;
				endif;
			endforeach;

			if (function_exists('invalidate_jail_cache')) {
				invalidate_jail_cache();
			}

			if ($fail_count > 0):
				$errormsg = sprintf(gtext("Stopped %d jail(s), failed %d jail(s)."), $success_count, $fail_count);
			else:
				$savemsg = sprintf(gtext("%d jail(s) stopped successfully."), $success_count);
			endif;

			header($sphere_header);
		endif;
	endif;

	if(isset($_POST['restart_selected_jail']) && $_POST['restart_selected_jail']):
		$checkbox_member_array = isset($_POST[$checkbox_member_name]) ? $_POST[$checkbox_member_name] : [];
		$commands = [];

		foreach($checkbox_member_array as $checkbox_member_record):
			if(false !== ($index = array_search_ex($checkbox_member_record, $sphere_array, 'jailname'))):
				if(!isset($sphere_array[$index]['protected'])):
					$commands[] = "/usr/local/bin/bastille restart {$checkbox_member_record}";
				endif;
			endif;
		endforeach;

		if (!empty($commands)):
			$results = mwexec_parallel($commands);

			$success_count = 0;
			$fail_count = 0;

			foreach ($results as $result):
				if ($result['return_code'] == 0):
					$success_count++;
				else:
					$fail_count++;
				endif;
			endforeach;

			if (function_exists('invalidate_jail_cache')) {
				invalidate_jail_cache();
			}

			if ($fail_count > 0):
				$errormsg = sprintf(gtext("Restarted %d jail(s), failed %d jail(s)."), $success_count, $fail_count);
			else:
				$savemsg = sprintf(gtext("%d jail(s) restarted successfully."), $success_count);
			endif;

			header($sphere_header);
		endif;
	endif;

	if(isset($_POST['autoboot_selected_jail']) && $_POST['autoboot_selected_jail']):
		$checkbox_member_array = isset($_POST[$checkbox_member_name]) ? $_POST[$checkbox_member_name] : [];
		$commands = [];

		foreach($checkbox_member_array as $checkbox_member_record):
			if(false !== ($index = array_search_ex($checkbox_member_record, $sphere_array, 'jailname'))):
				if(!isset($sphere_array[$index]['protected'])):
					$commands[] = "/usr/local/bin/bastille config {$checkbox_member_record} set boot on";
				endif;
			endif;
		endforeach;

		if (!empty($commands)):
			$results = mwexec_parallel($commands);

			$success_count = 0;
			$fail_count = 0;

			foreach ($results as $result):
				if ($result['return_code'] == 0):
					$success_count++;
				else:
					$fail_count++;
				endif;
			endforeach;

			if (function_exists('invalidate_jail_cache')) {
				invalidate_jail_cache();
			}

			if ($fail_count > 0):
				$errormsg = sprintf(gtext("Set autoboot on %d jail(s), failed %d jail(s)."), $success_count, $fail_count);
			else:
				$savemsg = sprintf(gtext("Autoboot set on %d jail(s) successfully."), $success_count);
			endif;

			header($sphere_header);
		endif;
	endif;
endif;

$pgtitle = [gtext("Extensions"), gtext('Bastille')];
include 'fbegin.inc';
?>
<script type="text/javascript">
//<![CDATA[
$(window).on("load", function() {
	// Init action buttons
	$("#start_selected_jail").click(function () {
		return confirm('<?=$gt_selection_start_confirm;?>');
	});
	$("#stop_selected_jail").click(function () {
		return confirm('<?=$gt_selection_stop_confirm;?>');
	});
	$("#restart_selected_jail").click(function () {
		return confirm('<?=$gt_selection_restart_confirm;?>');
	});
	$("#autoboot_selected_jail").click(function () {
		return confirm('<?=$gt_selection_restart_confirm;?>');
	});
	// Disable action buttons.
	disableactionbuttons(true);

	// Init member checkboxes
	$("input[name='<?=$checkbox_member_name;?>[]']").click(function() {
		controlactionbuttons(this, '<?=$checkbox_member_name;?>[]');
	});
	// Init spinner onsubmit()
	$("#iform").submit(function() { spinner(); });
	$(".spin").click(function() { spinner(); });
});
function disableactionbuttons(ab_disable) {
	$("#start_selected_jail").prop("disabled", ab_disable);
	$("#stop_selected_jail").prop("disabled", ab_disable);
	$("#restart_selected_jail").prop("disabled", ab_disable);
	$("#autoboot_selected_jail").prop("disabled", ab_disable);
}

function controlactionbuttons(ego, triggerbyname) {
	var a_trigger = document.getElementsByName(triggerbyname);
	var n_trigger = a_trigger.length;
	var ab_disable = true;
	var i = 0;
	for (; i < n_trigger; i++) {
		if (a_trigger[i].type == 'checkbox') {
			if (a_trigger[i].checked) {
				ab_disable = false;
				break;
			}
		}
	}
	disableactionbuttons(ab_disable);
}
//]]>
</script>
<?php
$document = new co_DOMDocument();
$document->
	add_area_tabnav()->
		push()->
		add_tabnav_upper()->
			ins_tabnav_record('bastille_manager_gui.php',gettext('Containers'))->
			ins_tabnav_record('bastille_manager_info.php',gettext('Information'))->
			ins_tabnav_record('bastille_manager_maintenance.php',gettext('Maintenance'));
$document->render();
?>
<form action="bastille_manager_gui.php" method="post" name="iform" id="iform"><table id="area_data"><tbody><tr><td id="area_data_frame">
<?php
	global $sphere_notifier;
	if(!empty($errormsg)):
		print_error_box($errormsg);
	endif;
	if(!empty($savemsg)):
		print_info_box($savemsg);
	endif;
	if(!empty($input_errors)):
		print_input_errors($input_errors);
	endif;
	if(updatenotify_exists($sphere_notifier)):
		print_config_change_box();
	endif;
?>
	<table class="area_data_settings">
		<colgroup>
			<col class="area_data_settings_col_tag">
			<col class="area_data_settings_col_data">
		</colgroup>
		<thead>
<?php
?>
		</thead>
		<tbody>
<?php
?>
		</tbody>
	</table>
	<table class="area_data_selection">
		<colgroup>
			<col style="width:2%">
			<col style="width:2%">
			<col style="width:10%">
			<col style="width:3%">
			<col style="width:3%">
			<col style="width:3%">
			<col style="width:3%">
			<col style="width:10%">
			<col style="width:10%">
			<col style="width:5%">
			<col style="width:10%">
			<col style="width:3%">
			<col style="width:3%">
			<col style="width:5%">
		</colgroup>
		<thead>
<?php
			html_separator2();
			html_titleline2(gettext('Overview'), 14);
?>
			<tr>
				<th class="lhelc"><?=gtext('Select');?></th>
				<th class="lhell"><?=gtext('JID');?></th>
				<th class="lhell"><?=gtext('Name');?></th>
				<th class="lhell"><?=gtext('Boot');?></th>
				<th class="lhell"><?=gtext('Prio');?></th>
				<th class="lhell"><?=gtext('State');?></th>
				<th class="lhell"><?=gtext('Type');?></th>
				<th class="lhell"><?=gtext('IP Address');?></th>
				<th class="lhell"><?=gtext('Published Ports');?></th>
				<th class="lhell"><?=gtext('Release');?></th>
				<th class="lhell"><?=gtext('Tags');?></th>
				<th class="lhell"><?=gtext('Active');?></th>
				<th class="lhell"><?=gtext('Template');?></th>
				<th class="lhebl"><?=gtext('Toolbox');?></th>
			</tr>
		</thead>
		<tbody>
<?php
			global $identifier;
			foreach ($sphere_array as $sphere_record):
				$notificationmode = updatenotify_get_mode($sphere_notifier, $identifier);
				$notdirty = (UPDATENOTIFY_MODE_DIRTY != $notificationmode) && (UPDATENOTIFY_MODE_DIRTY_CONFIG != $notificationmode);
				$notprotected = !isset($sphere_record['protected']);
?>
				<tr>
					<td class="lcelc">
<?php
						if ($notdirty && $notprotected):
?>
							<input type="checkbox" name="<?=$checkbox_member_name;?>[]" value="<?=$sphere_record['jailname'];?>" id="<?=$sphere_record['jailname'];?>"/>
<?php
						else:
?>
							<input type="checkbox" name="<?=$checkbox_member_name;?>[]" value="<?=$sphere_record['jailname'];?>" id="<?=$sphere_record['jailname'];?>" disabled="disabled"/>
<?php
						endif;
?>
					</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['id']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['name']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['boot']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['prio']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['state']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['type']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['ip']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['ports']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['rel']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['tags']);?>&nbsp;</td>
					<td class="lcell"><img src="<?=$sphere_record['stat'];?>"></td>
					<td class="lcell"><img src="<?=$sphere_record['logo'];?>"></td>
					<td class="lcebld">
						<table class="area_data_selection_toolbox"><tbody><tr>
							<td>
<?php
								if($notdirty && $notprotected):
?>
									<a href="<?=$sphere_scriptname_child;?>?jailname=<?=urlencode($sphere_record['jailname']);?>"><img src="<?=$img_path['mai'];?>" title="<?=$gt_record_mod;?>" alt="<?=$gt_record_mod;?>"  class="spin oneemhigh"/></a>
<?php
								else:
									if ($notprotected):
?>
										<img src="<?=$img_path['del'];?>" title="<?=$gt_record_del;?>" alt="<?=$gt_record_del;?>"/>
<?php
									else:
?>
										<img src="<?=$img_path['loc'];?>" title="<?=$gt_record_loc;?>" alt="<?=$gt_record_loc;?>"/>
<?php
									endif;
								endif;
?>
							<td>
								<a href="bastille_manager_jconf.php?jailname=<?=urlencode($sphere_record['jailname']);?>"><img src="<?=$g_img['mod'];?>" title="<?=$gt_record_conf?>" alt="<?=$gt_record_conf?>"/></a>
							</td>
							<td>
								<a href="bastille_manager_info.php?uuid=<?=urlencode($sphere_record['jailname']);?>"><img src="<?=$g_img['inf'];?>" title="<?=$gt_record_inf?>" alt="<?=$gt_record_inf?>"/></a>
							</td>
						</tr></tbody></table>
					</td>
				</tr>
<?php
			endforeach;
?>
		</tbody>
		<tfoot>
			<tr>
				<td class="lcenl" colspan="13"></td>
				<td class="lceadd">
					<a href="bastille_manager_add.php"><img src="<?=$img_path['add'];?>" title="<?=$gt_record_add;?>" border="0" alt="<?=$gt_record_add;?>" class="spin oneemhigh"/></a>
				</td>
			</tr>
		</tfoot>
	</table>
	<div id="submit">
		<input name="start_selected_jail" id="start_selected_jail" type="submit" class="formbtn" value="<?=$gt_selection_start;?>"/>
		<input name="stop_selected_jail" id="stop_selected_jail" type="submit" class="formbtn" value="<?=$gt_selection_stop;?>"/>
		<input name="restart_selected_jail" id="restart_selected_jail" type="submit" class="formbtn" value="<?=$gt_selection_restart;?>"/>
		<input name="autoboot_selected_jail" id="autoboot_selected_jail" type="submit" class="formbtn" value="<?=$gt_selection_autoboot;?>"/>
	</div>
<?php
	include 'formend.inc';
?>
</td></tr></tbody></table></form>
<?php
include 'fend.inc';
