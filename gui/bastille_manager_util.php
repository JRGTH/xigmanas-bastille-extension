<?php
/*
	bastille_manager_util.php

	Copyright (c) 2019 José Rivera (joserprg@gmail.com).
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

if(isset($_GET['uuid'])):
	$uuid = $_GET['uuid'];
endif;
if(isset($_POST['uuid'])):
	$uuid = $_POST['uuid'];
endif;

$pgtitle = [gtext("Extensions"), gtext('Bastille'),gtext('Utilities')];

if(isset($_GET['jailname'])):
	$container = $_GET['jailname'];
endif;
if(isset($_POST['jailname'])):
	$container = $_POST['jailname'];
endif;

$cnid = FALSE;
if(isset($container) && !empty($container)):
	$pconfig['uuid'] = uuid();
	$pconfig['jailname'] = $container;
	if(preg_match('/^([^\/\@]+)(\/([^\@]+))?\@(.*)$/', $pconfig['jailname'], $m)):
		$pconfig['name'] = $m[''];
	else:
		$pconfig['name'] = 'unknown';
	endif;
else:
	// not supported
	$pconfig = [];
endif;

if($_POST):
	global $configfile;
	global $backup_path;
	global $rootfolder;
	unset($input_errors);
	$pconfig = $_POST;
	if(isset($_POST['Cancel']) && $_POST['Cancel']):
		header('Location: bastille_manager_gui.php');
		exit;
	endif;
	if(isset($_POST['action'])):
		$action = $_POST['action'];
	endif;
	if(empty($action)):
		$input_errors[] = sprintf(gtext("The attribute '%s' is required."), gtext("Action"));
	else:
		switch($action):
			case 'advanced':
				// Input validation not required
				if(empty($input_errors)):
					$container = [];
					$container['uuid'] = $_POST['uuid'];
					$container['jailname'] = $_POST['jailname'];
					$confirm_name = $pconfig['confirmname'];
					$item = $container['jailname'];
					$_SESSION['item'] = $item;
					if ($_POST['advanced']):
						header('Location: bastille_manager_editor.php');
						exit;
					else:
						$input_errors[] = gtext("Failed to open editor, confirmation is required.");
					endif;
				endif;
				break;

			case 'backup':
				// Input validation not required
				if(empty($input_errors)):
					$container = [];
					$container['uuid'] = $_POST['uuid'];
					$container['jailname'] = $_POST['jailname'];
					$confirm_name = $pconfig['confirmname'];
					$item = $container['jailname'];
					$cmd = ("/usr/local/bin/bastille export '{$item}'");
					unset($output,$retval);mwexec2($cmd,$output,$retval);
					if($retval == 0):
						$savemsg .= gtext("Container backup process completed successfully.");
						exec("echo '{$date}: {$application}: Container backup process completed successfully for {$item}' >> {$logfile}");
						//header('Location: bastille_manager_gui.php');
						//exit;
					else:
						$input_errors[] = gtext("Failed to backup container.");
						exec("echo '{$date}: {$application}: Failed to backup container {$item}' >> {$logfile}");
					endif;
				endif;
				break;

			case 'clone':
				// Clone existing container
				if(empty($input_errors)):
					$container = [];
					$container['uuid'] = $_POST['uuid'];
					$container['jailname'] = $_POST['jailname'];
					$confirm_name = $pconfig['confirmname'];
					$confirm_newname = $pconfig['newname'];
					$confirm_newipaddr = $pconfig['newipaddr'];
					$item = $container['jailname'];

					if ((!$confirm_newname) || (!$confirm_newipaddr)):
						$input_errors[] = gtext("Name/IP fields can't be left blank.");
					else:
						if ($_POST['clonestop']):
							$cmd = ("/usr/local/bin/bastille stop $item && /usr/local/bin/bastille clone $item $confirm_newname $confirm_newipaddr");
						else:
							$cmd = ("/usr/local/bin/bastille clone $item $confirm_newname $confirm_newipaddr");
						endif;
						unset($output,$retval);mwexec2($cmd,$output,$retval);
						if($retval == 0):
							header('Location: bastille_manager_gui.php');
						else:
							$input_errors[] = gtext("Failed to clone container, make sure this container is stopped.");
						endif;
					endif;
				endif;
				break;

			case 'update':
				// Input validation not required
				if(empty($input_errors)):
					$container = [];
					$container['uuid'] = $_POST['uuid'];
					$container['jailname'] = $_POST['jailname'];
					$confirm_name = $pconfig['confirmname'];
					$item = $container['jailname'];
					$current_release = exec("/usr/bin/grep '\-RELEASE' {$jail_dir}/{$item}/fstab | awk '{print $1}' | grep -o '[^/]*$'");

					if ($_POST['update_base']):
						$cmd = ("/usr/local/sbin/bastille-init update '{$current_release}'");
					elseif ($_POST['update_jail']):
						$cmd = ("/usr/local/sbin/bastille-init update '{$item}'");
					else:
						$input_errors[] = sprintf(gtext("Failed to update container %s."),$item);
						break;
					endif;

					unset($output,$retval);mwexec2($cmd,$output,$retval);
					if($retval == 0):
						$update_release = exec("/usr/sbin/jexec -l {$item} freebsd-version");
						$savemsg .= sprintf(gtext("Container release updated to %s successfully."),$update_release);
						exec("echo '{$date}: {$application}: Container release updated to {$update_release} successfully for {$item}' >> {$logfile}");
						//header('Location: bastille_manager_gui.php');
						//exit;
					else:
						$input_errors[] = sprintf(gtext("Failed to update container %s."),$item);
						#$input_errors[] = gtext("Failed to update container, either is not running or is highly secured (check securelevel/allow.chflags).");
						exec("echo '{$date}: {$application}: Failed to update container {$item}' >> {$logfile}");
					endif;
				endif;
				break;

			case 'base':
				// Input validation not required
				if(empty($input_errors)):
					$container = [];
					$container['uuid'] = $_POST['uuid'];
					$container['jailname'] = $_POST['jailname'];
					$item = $container['jailname'];
					$current_release = exec("/usr/bin/grep '\-RELEASE' {$jail_dir}/{$item}/fstab | awk '{print $1}' | grep -o '[^/]*$'");
					$new_release = $pconfig['release'];

					if(!$current_release):
						$savemsg .= gtext("This is a thick container and should be interactively upgraded through the command line.");
					else:
						$cmd = ("/usr/local/sbin/bastille-init --upgrade {$item} {$current_release} {$new_release}");
						unset($output,$retval);mwexec2($cmd,$output,$retval);
						if($retval == 0):
							$savemsg .= sprintf(gtext("Container base release changed to %s successfully."),$new_release);
							exec("echo '{$date}: {$application}: Container base release changed to {$new_release} on {$item} successfully' >> {$logfile}");
							//header('Location: bastille_manager_gui.php');
							//exit;
						else:
							$input_errors[] = sprintf(gtext("Failed to change container base release to %s, either it is running or is not a thin container."),$new_release);
							exec("echo '{$date}: {$application}: Failed to change container base release to {$new_release} on {$item}' >> {$logfile}");
						endif;
					endif;
				endif;
				break;

			case 'autoboot':
				// Input validation not required
				if(empty($input_errors)):
					$container = [];
					$container['uuid'] = $_POST['uuid'];
					$container['jailname'] = $_POST['jailname'];
					$confirm_name = $pconfig['confirmname'];
					$item = $container['jailname'];
					$cmd = ("/usr/sbin/sysrc -f {$configfile} {$item}_AUTO_START=\"YES\"");
					unset($output,$retval);mwexec2($cmd,$output,$retval);
					if($retval == 0):
						header('Location: bastille_manager_gui.php');
						exit;
					else:
						$input_errors[] = gtext("Failed to set auto-boot.");
					endif;
				endif;
				break;

			case 'noauto':
				// Input validation not required
				if(empty($input_errors)):
					$container = [];
					$container['uuid'] = $_POST['uuid'];
					$container['jailname'] = $_POST['jailname'];
					$confirm_name = $pconfig['confirmname'];
					$item = $container['jailname'];
					if(exec("/usr/sbin/sysrc -f $configfile -qn {$item}_AUTO_START")):
						$cmd = ("/usr/sbin/sysrc -f $configfile -x {$item}_AUTO_START");
						unset($output,$retval);mwexec2($cmd,$output,$retval);
						if($retval == 0):
							header('Location: bastille_manager_gui.php');
							exit;
						else:
							$input_errors[] = gtext("Failed to set no-auto.");
						endif;
					endif;
				endif;
				break;

			case 'fstab':
				// Input validation not required
				if(empty($input_errors)):
					$container = [];
					$container['uuid'] = $_POST['uuid'];
					$container['jailname'] = $_POST['jailname'];
					$confirm_name = $pconfig['confirmname'];
					$item = $container['jailname'];
					$sourcedir = $pconfig['source_path'];
					$targetdir = $pconfig['target_path'];
					$is_running = exec("/usr/sbin/jls | /usr/bin/grep -w '{$item}'");
					$paths_exist = exec("/bin/cat {$rootfolder}/jails/{$item}/fstab | /usr/bin/grep -w '{$sourcedir}    {$targetdir}'");

					if ($_POST['readonly']):
						$dir_mode = "ro";
					else:
						$dir_mode = "rw";
					endif;

					if ((!$sourcedir) || (!$targetdir)):
						$input_errors[] = gtext("Soure/Target directory can't be left blank.");
					else:
						if (!isset($_POST['path_check']) && (!preg_match( '/\/mnt\/(.*\S)/', $sourcedir))):
							$input_errors[] = gtext("The Source directory MUST be set to a directory below '/mnt/'.");
						elseif (!isset($_POST['path_check']) && (!preg_match( '/\/(.*\S)\/mnt\/(.*\S)/', $targetdir))):
							$input_errors[] = sprintf(gtext("The Target directory MUST be set to a directory below '/mnt/'."),$targetdir);
						else:
							if (!is_dir("{$sourcedir}")):
								$input_errors[] = sprintf(gtext("Soure directory: %s does not exist."),$sourcedir);
							else:
								if (!$paths_exist):
									$cmd = ("/bin/echo	\"{$sourcedir}    {$targetdir}    nullfs    {$dir_mode}    0    0\" >> {$rootfolder}/jails/{$item}/fstab");
									unset($output,$retval);mwexec2($cmd,$output,$retval);
									if($retval == 0):
										if ($_POST['createdir']):
											if (!is_dir("{$targetdir}")):
												mkdir("$targetdir");
											endif;
											if ($_POST['automount']):
												if ($is_running):
													exec("/sbin/mount_nullfs -o {$dir_mode} {$sourcedir} {$targetdir}");
												endif;
											endif;
										endif;
										$savemsg .= gtext("Edited the fstab successfully.");
									else:
										$input_errors[] = gtext("Failed to edit the fstab.");
									endif;
								else:
									$savemsg .= gtext("Directories already exist in the fstab.");
								endif;
							endif;
						endif;
					endif;
				endif;
				break;

			case 'delete':
				// Delete a contained
				if(empty($input_errors)):
					$container = [];
					$container['uuid'] = $_POST['uuid'];
					$container['jailname'] = $_POST['jailname'];
					$confirm_name = $pconfig['confirmname'];
					$item = $container['jailname'];
					$plugin_icon = "{$image_dir}/{$item}_icon.png";

					if(strcmp($confirm_name, $item) !== 0):
						$input_errors[] = gtext("Failed to destroy container, name confirmation is required.");
						break;
					else:
						if ($_POST['nowstop']):
							$cmd = ("/usr/local/bin/bastille destroy -f {$item}");
						else:
							$cmd = ("/usr/local/bin/bastille destroy {$item}");
						endif;
						unset($output,$retval);mwexec2($cmd,$output,$retval);
						if($retval == 0):
							exec("/usr/sbin/sysrc -f {$configfile} -qx {$item}_AUTO_START");
							if(file_exists($plugin_icon)):
								unlink($plugin_icon);
							endif;
							header('Location: bastille_manager_gui.php');
							exit;
						else:
							$input_errors[] = gtext("Failed to destroy container, make sure this container is stopped.");
						endif;
					endif;
				endif;
				break;
			default:
				$input_errors[] = sprintf(gtext("The attribute '%s' is invalid."), 'action');
				break;
		endswitch;
	endif;
endif;
include 'fbegin.inc';
?>
<script type="text/javascript">
//<![CDATA[
$(window).on("load",function() {
	$("#iform").submit(function() { spinner(); });
	$(".spin").click(function() { spinner(); });
});
function enable_change(enable_change) {
	document.iform.name.disabled = !enable_change;
}
function action_change() {
	showElementById('confirmname_tr','hide');
	showElementById('nowstop_tr', 'hide');
	showElementById('source_path_tr', 'hide');
	showElementById('target_path_tr', 'hide');
	showElementById('path_check_tr', 'hide');
	showElementById('advanced_tr', 'hide');
	showElementById('readonly_tr', 'hide');
	showElementById('createdir_tr', 'hide');
	showElementById('automount_tr', 'hide');
	showElementById('jail_release_tr', 'hide');
	showElementById('release_tr','hide');
	showElementById('update_base_tr','hide');
	showElementById('update_jail_tr','hide');
	showElementById('newname_tr', 'hide');
	showElementById('newipaddr_tr', 'hide');
	showElementById('clonestop_tr', 'hide');
	showElementById('auto_boot_tr', 'hide');
	showElementById('no_autoboot_tr', 'hide');
	//showElementById('dateadd_tr','hide');
	var action = document.iform.action.value;
	switch (action) {
		case "backup":
			showElementById('confirmname_tr','hide');
			showElementById('nowstop_tr','hide');
			break;
		case "clone":
			showElementById('newname_tr','show');
			showElementById('newipaddr_tr','show');
			showElementById('clonestop_tr','show');
			break;
		case "update":
			showElementById('confirmname_tr','hide');
			showElementById('nowstop_tr','hide');
			showElementById('update_base_tr','show');
			showElementById('update_jail_tr','show');
			break;
		case "base":
			showElementById('confirmname_tr','hide');
			showElementById('nowstop_tr','hide');
			showElementById('jail_release_tr', 'show');
			showElementById('release_tr','show');
			break;
		case "autoboot":
			showElementById('confirmname_tr','hide');
			showElementById('nowstop_tr','hide');
			showElementById('auto_boot_tr', 'show');
			break;
		case "noauto":
			showElementById('confirmname_tr','hide');
			showElementById('nowstop_tr','hide');
			showElementById('no_autoboot_tr', 'show');
			break;
		case "fstab":
			showElementById('confirmname_tr','hide');
			showElementById('nowstop_tr','hide');
			showElementById('source_path_tr','show');
			showElementById('target_path_tr','show');
			showElementById('path_check_tr','show');
			showElementById('readonly_tr','show');
			showElementById('createdir_tr','show');
			showElementById('automount_tr','show');
			break;
		case "delete":
			showElementById('confirmname_tr','show');
			showElementById('nowstop_tr','show');
			break;
		case "advanced":
			showElementById('confirmname_tr','hide');
			showElementById('nowstop_tr','hide');
			showElementById('advanced_tr','show');
			break;
		default:
			break;
	}
}
//]]>
</script>
<?php
$document = new co_DOMDocument();
$document->
	add_area_tabnav()->
		push()->
		add_tabnav_upper()->
			ins_tabnav_record('bastille_manager_gui.php',gettext('Containers'),gettext('Reload page'),true)->
			ins_tabnav_record('bastille_manager_info.php',gettext('Information'),gettext('Reload page'),true)->
			ins_tabnav_record('bastille_manager_maintenance.php',gettext('Maintenance'),gettext('Reload page'),true);
$document->render();
?>
<form action="bastille_manager_util.php" method="post" name="iform" id="iform"><table id="area_data"><tbody><tr><td id="area_data_frame">
<?php
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
			html_titleline2(gettext('Utilities'));
?>
		</thead>
		<tbody>
<?php
			$b_action = $l_release;
			#$current_release = exec("/usr/sbin/jexec {$pconfig['jailname']} freebsd-version 2>/dev/null");
			unset($disable_base_change);
			$current_release = exec("/usr/bin/grep '\-RELEASE' {$jail_dir}/{$pconfig['jailname']}/fstab | awk '{print $1}' | grep -o '[^/]*$'");
			$is_thickjail = exec("/usr/bin/grep -w '/.*/.bastille' {$jail_dir}/{$pconfig['jailname']}/fstab");
			if (!$current_release):
				$current_release = exec("/usr/bin/grep 'releng' {$jail_dir}/{$pconfig['jailname']}/root/COPYRIGHT | cut -d '/' -f2");
				$disable_base_change = "1";
				if (!$current_release):
					$current_release = "-";
				endif;
			endif;
			$pconfig['source_path'] = "";
			$pconfig['target_path'] = "{$rootfolder}/jails/{$pconfig['jailname']}/root/mnt/";
			html_text2('jailname',gettext('Container name:'),htmlspecialchars($pconfig['jailname']));
			$a_action = [
				'backup' => gettext('Backup'),
				'clone' => gettext('Clone'),
				'update' => gettext('Update'),
				'base' => gettext('Release'),
				'autoboot' => gettext('Autoboot'),
				'noauto' => gettext('Noauto'),
				'fstab' => gettext('Fstab'),
				'delete' => gettext('Destroy'),
				'advanced' => gettext('Advanced'),
			];

			html_combobox2('action',gettext('Action'),$pconfig['action'],$a_action,'',true,false,'action_change()');
			html_inputbox2('confirmname',gettext('Enter name for confirmation'),$pconfig['confirmname'],'',true,30);
			html_checkbox2('nowstop',gettext('Stop container'),!empty($pconfig['nowstop']) ? true : false,gettext('Stop the container if running before deletion.'),'',false);
			html_inputbox2('newname',gettext('Enter a name for the new container'),$pconfig['newname'],'',true,30);
			html_inputbox2('newipaddr',gettext('Enter a IP address for the new container'),$pconfig['newipaddr'],'',true,30);
			html_checkbox2('clonestop',gettext('Stop container'),!empty($pconfig['clonestop']) ? true : false,gettext('Stop the container if running before cloning, mandatory on UFS filesystem.'),'',false);
			html_filechooser("source_path", gtext("Source Data Directory"), $pconfig['source_path'], gtext("Source data directory to be shared, full path here."), $source_path, false, 60);
			html_filechooser("target_path", gtext("Target Data Directory"), $pconfig['target_path'], gtext("Target data directory to be mapped, full path to jail here."), $target_path, false, 60);		
			html_checkbox2("path_check", gettext("Source/Target path check"),!empty($pconfig['path_check']) ? true : false, gettext("If this option is selected no examination of the source/target directory paths will be performed."), "<b><font color='red'>".gettext("Please use this option only if you know what you are doing here!")."</font></b>", false);			
			html_checkbox2('advanced',gettext('Advanced jail configuration Files'),!empty($pconfig['advanced']) ? true : false,gettext('I want to edit the jail files manually, Warning: It is recommended to stop the jail before config edit to prevent issues.'),'',true);
			html_checkbox2('readonly',gettext('Read-Only Mode'),!empty($pconfig['readonly']) ? true : false,gettext('Set target directory in Read-Only mode.'),'',true);
			html_checkbox2('automount',gettext('Auto-mount Nullfs'),!empty($pconfig['automount']) ? true : false,gettext('Auto-mount the nullfs mountpoint if the container is already running.'),'',true);
			html_checkbox2('createdir',gettext('Create Target Directory'),!empty($pconfig['createdir']) ? true : true,gettext('Create target directory if missing (recommended).'),'',true);
			if ($is_thickjail):
				html_checkbox2('update_base',gettext('Base update confirm'),!empty($pconfig['update_base']) ? true : false,gettext('This is a thin container, therefore the base release will be updated, this affects child containers.'),'',true);
			else:
				html_checkbox2('update_jail',gettext('Container update confirm:'),!empty($pconfig['update_jail']) ? true : false,gettext('This is a thick container, therefore the update will be performed within its root, current containers are not affected.'),'',true);
			endif;
			html_text2('jail_release',gettext('Current base release:'),htmlspecialchars($current_release));
			html_text2('auto_boot',gettext('Enable container auto-startup'),htmlspecialchars("This will cause the container to automatically start each time the system restart."));
			html_text2('no_autoboot',gettext('Disable container auto-startup'),htmlspecialchars("This will disable the container automatic startup."));
			if (!$disable_base_change):
				html_combobox2('release',gettext('New base release'),$pconfig['release'],$b_action,gettext("Warning: this will change current base to the selected base on the thin container only, the user is responsible for package updates and/or general incompatibilities issues, or use the command line for native upgrade."),true,false,);
			endif;
			//html_checkbox2('dateadd',gettext('Date'),!empty($pconfig['dateadd']) ? true : false,gettext('Append the date in the following format: ITEM-XXXX-XX-XX-XXXXXX.'),'',false);
?>
		</tbody>
	</table>
	<div id="submit">
		<input name="Submit" type="submit" class="formbtn" value="<?=gtext("Execute");?>" onclick="enable_change(true)" />
		<input name="Cancel" type="submit" class="formbtn" value="<?=gtext("Cancel");?>" />
		<input name="uuid" type="hidden" value="<?=$pconfig['uuid'];?>" />
		<input name="jailname" type="hidden" value="<?=$pconfig['jailname'];?>" />
		<input name="name" type="hidden" value="<?=$pconfig['name'];?>" />
	</div>
	<div id="remarks">
		<?php html_remark("note", gtext("Note"), sprintf(gtext("Some tasks such as backups may render the WebGUI unresponsive until task completes.")));?>
	</div>
<?php
	include 'formend.inc';
?>
</td></tr></tbody></table></form>
<script type="text/javascript">
<!--
enable_change(true);
action_change();
//-->
</script>
<?php
include 'fend.inc';
?>
