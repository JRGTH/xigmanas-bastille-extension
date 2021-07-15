<?php
/*
	bastille_manager_maintenance.php

	Copyright (c) 2019-2020 José Rivera (joserprg@gmail.com).
    All rights reserved.

	Copyright (c) 2016 Andreas Schmidhuber
	All rights reserved.

	Portions of XigmaNAS (http://www.nas4free.org).
	Copyright (c) 2012-2016 The NAS4Free Project <info@nas4free.org>.
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

require("auth.inc");
require("guiconfig.inc");
require_once("bastille_manager-lib.inc");

$pgtitle = array(gtext("Extensions"), "Bastille", "Maintenance");

if(!initial_install_banner()):
	$errormsg = gtext('Bastille Initial Configuration.')
			. ' '
			. '<a href="' . 'bastille_manager_config.php' . '">'
			. gtext('Please check and configure ZFS support option first.')
			. '</a>'
			. '</br>'
			. gtext('Or click the "Save" button to store the default configuration.');
		$prerequisites_ok = false;
endif;

// For legacy product versions.
$return_val = mwexec("/bin/cat /etc/prd.version | cut -d'.' -f1 | /usr/bin/grep '10'", true);
if ($return_val == 0) {
	if (is_array($config['rc']['postinit'] ) && is_array( $config['rc']['postinit']['cmd'] ) ) {
		for ($i = 0; $i < count($config['rc']['postinit']['cmd']);) { if (preg_match('/bastille-init/', $config['rc']['postinit']['cmd'][$i])) break; ++$i; }
	}
}

// Set default backup directory.
if (1 == mwexec("/bin/cat {$configfile} | /usr/bin/grep 'BACKUP_DIR='")) {
	if (is_file("{$configfile}")) exec("/usr/sbin/sysrc -f {$configfile} BACKUP_DIR={$rootfolder}/backups");
}
$backup_path = exec("/bin/cat {$configfile} | /usr/bin/grep 'BACKUP_DIR=' | cut -d'\"' -f2");

if ($_POST) {
	global $zfs_activated;
	global $backup_path_bastille;
	global $configfile_bastille;
	if(isset($_POST['upgrade']) && $_POST['upgrade']):
		$cmd = sprintf('%1$s/bastille-init -u > %2$s',$rootfolder,$logevent);
		$return_val = 0;
		$output = [];
		exec($cmd,$output,$return_val);
		if($return_val == 0):
			ob_start();
			include("{$logevent}");
			$ausgabe = ob_get_contents();
			ob_end_clean(); 
			$savemsg .= str_replace("\n", "<br />", $ausgabe)."<br />";
		else:
			$input_errors[] = gtext('An error has occurred during upgrade process.');
			$cmd = sprintf('echo %s: %s An error has occurred during upgrade process. >> %s',$date,$application,$logfile);
			exec($cmd);
		endif;
	endif;

	// Remove only extension related files during cleanup.
	if (isset($_POST['uninstall']) && $_POST['uninstall']) {
		if(isset($_POST['delete_confirm']) && $_POST['delete_confirm']):
			bindtextdomain("xigmanas", $textdomain);
			if (is_link($textdomain_bastille)) mwexec("rm -f {$textdomain_bastille}", true);
			if (is_dir($confdir)) mwexec("rm -Rf {$confdir}", true);
			mwexec("rm /usr/local/www/bastille_manager_gui.php && rm -R /usr/local/www/ext/bastille", true);
			mwexec("{$rootfolder}/usr/local/sbin/bastille-init -t", true);		
			$uninstall_cmd = "echo 'y' | /usr/local/sbin/bastille-init -U";
			mwexec($uninstall_cmd, true);
			if (is_link("/usr/local/share/{$prdname}")) mwexec("rm /usr/local/share/{$prdname}", true);
			if (is_link("/var/cache/pkg")) mwexec("rm /var/cache/pkg", true);
			if (is_link("/var/db/pkg")) mwexec("rm /var/db/pkg && mkdir /var/db/pkg", true);

			// Remove start postinit cmd in later product versions.
			if (is_array($config['rc']) && is_array($config['rc']['param'])) {
				$postinit_cmd = "{$rootfolder}/bastille-init";
				$value = $postinit_cmd;
				$sphere_array = &$config['rc']['param'];
				$updateconfigfile = false;
				if (false !== ($index = array_search_ex($value, $sphere_array, 'value'))) {
					unset($sphere_array[$index]);
					$updateconfigfile = true;
				}
				if ($updateconfigfile) {
					write_config();
					$updateconfigfile = false;
				}
			}
			// Remove stop postinit cmd in later product versions.
			if (is_array($config['rc']) && is_array($config['rc']['param'])) {
				$shutdown_cmd = "{$rootfolder}/bastille-init -p";
				$value = $shutdown_cmd;
				$sphere_array = &$config['rc']['param'];
				$updateconfigfile = false;
				if (false !== ($index = array_search_ex($value, $sphere_array, 'value'))) {
					unset($sphere_array[$index]);
					$updateconfigfile = true;
				}
				if ($updateconfigfile) {
					write_config();
					$updateconfigfile = false;
				}
			}
			header("Location:index.php");
		else:
			$input_errors[] = gtext('Confirmation is required for extension removal.');
		endif;
	}

	if (isset($_POST['save']) && $_POST['save']) {
		// Ensure to have NO whitespace & trailing slash.
		$backup_path = rtrim(trim($_POST['backup_path']),'/');
		if ("{$backup_path}" == "") {
			$backup_path = "{$rootfolder}/backups";
			}
		if (!is_file($backup_path)) {
			if($backup_path_bastille !== $backup_path):
				// Update bastille config if required.
				$cmd = "/usr/sbin/sysrc -f {$configfile_bastille} bastille_backupsdir={$backup_path}";
				unset($retval);mwexec($cmd,$retval);
				if ($retval == 0) {
					$savemsg .= gtext("Bastille config updated successfully.");
					exec("echo '{$date}: {$application}: Bastille config updated successfully' >> {$logfile}");
					}
				else {
					$input_errors[] = gtext("Failed to update Bastille config.");
					exec("echo '{$date}: {$application}: Failed to update Bastille config' >> {$logfile}");
					}
			endif;
			// Update extension config.
			$cmd = "/usr/sbin/sysrc -f {$configfile} BACKUP_DIR={$backup_path}";
			unset($retval);mwexec($cmd,$retval);
			if ($retval == 0) {
				$savemsg .= gtext("Extension settings saved successfully.");
				exec("echo '{$date}: {$application}: Extension settings saved successfully' >> {$logfile}");
				}
			else {
				$input_errors[] = gtext("Failed to save extension settings.");
				exec("echo '{$date}: {$application}: Failed to save extension settings' >> {$logfile}");
				}
			}
		else {
			$input_errors[] = gtext("Failed to save extension settings.");
			exec("echo '{$date}: {$application}: Failed to save extension settings' >> {$logfile}");
			}
		// ZFS activation support.
		if ($_POST['zfs_activate']):
			$zfs_status = get_state_zfs();
			if($zfs_status == "Available but not enabled" || $zfs_status == "Enabled"):
				if(!$zfs_activated):
					$cmd = sprintf('/bin/echo Y | %1$s/bastille-init -Z > %2$s',$rootfolder,$logevent);
					$return_val = 0;
					$output = [];
					exec($cmd,$output,$return_val);
					if($return_val == 0):
						unset ($errormsg);
						ob_start();
						include("{$logevent}");
						$ausgabe = ob_get_contents();
						ob_end_clean(); 
						$savemsg .= str_replace("\n", "<br />", $ausgabe)."<br />";
						exec("/usr/sbin/sysrc -f {$configfile} ZFS_ACTIVATED=\"YES\"");
					else:
						$input_errors[] = gtext('An error has occurred during ZFS activation process.');
						$cmd = sprintf('echo %s: %s An error has occurred during ZFS activation process. >> %s',$date,$application,$logfile);
						exec($cmd);
					endif;
				endif;
			else:
				$input_errors[] = gtext("Cannot activate ZFS with either invalid or disabled configurations.");
			endif;
		else:
			$zfs_status = get_state_zfs();
			if($zfs_status == "Available but not enabled"):
				$input_errors[] = gtext("Cannot skip ZFS activation with current config, either disable ZFS option in the config or just Activate ZFS support.");
			elseif($zfs_status == "Invalid ZFS configuration"):
				$input_errors[] = gtext("Cannot skip ZFS activation with an invalid configuration.");
			elseif($zfs_status == "Enabled"):
				exec("/usr/sbin/sysrc -f {$configfile} ZFS_ACTIVATED=\"YES\"");
			elseif(!$zfs_activated):
				exec("/usr/sbin/sysrc -f {$configfile} ZFS_ACTIVATED=\"NO\"");
				$savemsg .= gtext("ZFS activation option has been skipped.");
			endif;
		endif;
		# Run bastille-init to update config.
		exec("bastille-init");
	}

		if (isset($_POST['restore']) && $_POST['restore']) {
		// Ensure to have NO whitespace & trailing slash.
		$backup_file = rtrim(trim($_POST['backup_path']),'/');
		$filename_trim = exec("echo {$backup_file} | awk '{print $1}' | grep -o '[^/]*$'");
		$jailname_trim = exec("echo {$backup_file} | awk '{print $1}' | grep -o '[^/]*$' | cut -d '_' -f1");

		if ("{$backup_file}" == "") {
			$input_errors[] = gtext("Error: backup file undefined.");
			}

		if (is_dir("{$jail_dir}/{$jailname_trim}")):
			$input_errors[] = gtext("Container directory/dataset already exist.");
		else:
		if (is_file($backup_file)) {
			$cmd = ("/usr/local/bin/bastille import '{$filename_trim}'");
			unset($output,$retval);mwexec2($cmd,$output,$retval);
			if ($retval == 0) {
				$savemsg .= gtext("Container restored successfully.");
				exec("echo '{$date}: {$application}: Container restored successfully from {$filename_trim}' >> {$logfile}");
				}
			else {
				$input_errors[] = gtext("Failed to restore container.");
				exec("echo '{$date}: {$application}: Failed to restore container from {$filename_trim}' >> {$logfile}");
				}
			}
		else {
			$input_errors[] = gtext("Failed to restore container, file not found.");
			exec("echo '{$date}: {$application}: Failed to restore container, file {$filename_trim} not found' >> {$logfile}");
			}
		endif;
	}
}

function get_version_ext() {
	global $versionfile;
	exec("/bin/cat {$versionfile}", $result);
	return ($result[0]);
}

if (is_ajax()) {
	$getinfo['bastille'] = get_version_bastille();
	$getinfo['ext'] = get_version_ext();
	render_ajax($getinfo);
}

bindtextdomain("xigmanas", $textdomain);
include("fbegin.inc");
bindtextdomain("xigmanas", $textdomain_bastille);
?>
<script type="text/javascript">//<![CDATA[
$(document).ready(function(){
	var gui = new GUI;
	gui.recall(0, 2000, 'bastille-gui.php', null, function(data) {
		$('#getinfo').html(data.info);
		$('#getinfo_bastille').html(data.bastille);
		$('#getinfo_ext').html(data.ext);
	});
});
//]]>
</script>
<!-- The Spinner Elements -->
<script src="js/spin.min.js"></script>
<!-- use: onsubmit="spinner()" within the form tag -->
<script type="text/javascript">
<!--
}
//-->
</script>
<form action="bastille_manager_maintenance.php" method="post" name="iform" id="iform" onsubmit="spinner()">
	<table width="100%" border="0" cellpadding="0" cellspacing="0">
		<tr><td class="tabnavtbl">
    		<ul id="tabnav">
    			<li class="tabinact"><a href="bastille_manager_gui.php"><span><?=gettext("Containers");?></span></a></li>
				<li class="tabact"><a href="bastille_manager_info.php"><span><?=gettext("Information");?></span></a></li>
    			<li class="tabact"><a href="bastille_manager_maintenance.php"><span><?=gettext("Maintenance");?></span></a></li>
    		</ul>
    	</td></tr>
		<tr><td class="tabnavtbl">
		<ul id="tabnav2">
			<li class="tabact"><a href="bastille_manager_config.php"><span><?=gettext("Bastille Configuration");?></span></a></li>
			<li class="tabact"><a href="bastille_manager_tarballs.php"><span><?=gettext("Base Releases");?></span></a></li>
		</ul>
		</td></tr>
		<tr><td class="tabcont">
			<table width="100%" border="0" cellpadding="6" cellspacing="0">
				<?php
				if(!empty($errormsg)): print_error_box($errormsg); endif;
				if(!empty($savemsg)): print_info_box($savemsg); endif;
				if(!empty($input_errors)): print_input_errors($input_errors); endif;
				if(file_exists($d_sysrebootreqd_path)): print_info_box(get_std_save_message(0)); endif;
				?>
				<?php html_titleline(gtext("Bastille"));?>
				<?php html_text("installation_directory", gtext("Installation directory"), sprintf(gtext("The extension is installed in %s"), $rootfolder));?>
				<tr>
					<td class="vncellt"><?=gtext("Bastille version");?></td>
					<td class="vtable"><span name="getinfo_bastille" id="getinfo_bastille"><?=get_version_bastille()?></span></td>
				</tr>
				<tr>
					<td class="vncellt"><?=gtext("Extension version");?></td>
					<td class="vtable"><span name="getinfo_ext" id="getinfo_ext"><?=get_version_ext()?></span></td>
				</tr>
				<tr>
					<td class="vncellt"><?=gtext("ZFS support");?></td>
					<td class="vtable"><span name="getinfo_zfs" id="getinfo_zfs"><?=get_state_zfs()?></span></td>
				</tr>
					<?php
						if(!initial_install_banner()):
							html_checkbox2('zfs_activate',gtext('ZFS support activation'),'' ? true : false,gtext('Check this to activate ZFS support or leave unchecked to dismiss (requires ZFS support to be available), this is a one time option and this row will disappear after clicking Save button.'),'',false);
						endif;
					?>
					<?php html_filechooser("backup_path", gtext("Backup directory"), $backup_path, gtext("Directory to store containers backup archives, use as file chooser for restoring from file."), $backup_path, true, 60);?>
			</table>
			<div id="submit">
				<input id="save" name="save" type="submit" class="formbtn" title="<?=gtext("Save settings");?>" value="<?=gtext("Save");?>"/>
				<input name="upgrade" type="submit" class="formbtn" title="<?=gtext("Upgrade Extension and Bastille Packages");?>" value="<?=gtext("Upgrade");?>" />
				<input name="restore" type="submit" class="formbtn" title="<?=gtext("Restore a container");?>" value="<?=gtext("Restore");?>" />
			</div>
			<div id="remarks">
				<?php html_remark("note", gtext("Info"), sprintf(gtext("For general information visit the following link(s):")));?>
				<div id="enumeration"><ul><li><a href="http://bastillebsd.org/" target="_blank" ><?=gtext("Bastille helps you quickly create and manage FreeBSD Jails.")?></a></li></ul></div>
			</div>
			<table width="100%" border="0" cellpadding="6" cellspacing="0">
				<?php html_separator();?>
				<?php html_titleline(gtext("Uninstall"));?>
				<?php html_checkbox2('delete_confirm',gtext('Uninstall confirm'),'' ? true : false,gtext('Check to confirm extension uninstall. Note: Jail related content will be preserved by default.'),'',false);?>
				<?php html_separator();?>
			</table>
			<div id="submit1">
				<input name="uninstall" type="submit" class="formbtn" title="<?=gtext("Uninstall Extension");?>" value="<?=gtext("Uninstall");?>" onclick="return confirm('<?=gtext("Bastille Extension and packages will be completely removed, Bastille containers and child directories will not be touched, really to proceed?");?>')" />
			</div>
		</td></tr>
	</table>
	<?php include("formend.inc");?>
</form>
<script type="text/javascript">
<!--
enable_change(false);
//-->
</script>
<?php include("fend.inc");?>
