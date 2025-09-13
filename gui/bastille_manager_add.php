<?php
/*
	bastille_manager_add.php

	Copyright (c) 2019-2025 Jose Rivera (joserprg@gmail.com).
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

$pgtitle = array(gtext("Extensions"), "Bastille", "Create");

$pconfig = [];

if(!(isset($pconfig['jailname']))):
	$pconfig['jailname'] = 'jail1';
endif;
if(!(isset($pconfig['ipaddress']))):
	$pconfig['ipaddress'] = '';
endif;

if(!get_all_release_list()):
	$errormsg = gtext('No base releases extracted yet.')
			. ' '
			. '<a href="' . 'bastille_manager_tarballs.php' . '">'
			. gtext('Please download a base release first.')
			. '</a>';
		$prerequisites_ok = false;
endif;

$zfs_status = get_state_zfs();
if($zfs_status == "Invalid ZFS configuration"):
	// Warning if invalid ZFS configuration.
	$input_errors[] = gtext("WARNING: Invalid ZFS configuration detected.");
endif;

if($_POST):
	global $jail_dir;
	global $configfile;
	unset($input_errors);
	$pconfig = $_POST;
	if(isset($_POST['Cancel']) && $_POST['Cancel']):
		header('Location: bastille_manager_gui.php');
		exit;
	endif;
	if(isset($_POST['Create']) && $_POST['Create']):
		$zfs_status = get_state_zfs();
		if($zfs_status == "Invalid ZFS configuration"):
			// Abort jail creation if invalid ZFS configuration.
			$input_errors[] = gtext("Cannot create jail with an invalid ZFS configuration.");
		else:

		$jname = $pconfig['jailname'];
		$ipaddr = $pconfig['ipaddress'];
		$release = $pconfig['release'];
		$resolv_conf = "{$jail_dir}/{$jname}/root/etc/resolv.conf";
		$resolv_host = "/var/etc/resolv.conf";
		$options = "";
		if ($_POST['interface'] == 'Config'):
			$interface = "";
		else:
			$interface = $pconfig['interface'];
		endif;

		if($release == 'Ubuntu_1804'):
			$release = "ubuntu-bionic";
		elseif($release == 'Ubuntu_2004'):
			$release = "ubuntu-focal";
		elseif($release == 'Ubuntu_2204'):
			$release = "ubuntu-jammy";
		elseif($release == 'Debian9'):
			$release = "debian-stretch";
		elseif($release == 'Debian10'):
			$release = "debian-buster";
		elseif($release == 'Debian12'):
			$release = "debian-bookworm";
		endif;

		if(isset($_POST['thickjail']) && isset($_POST['vnetjail'])):
			$options = "-T -V";
		elseif(isset($_POST['thickjail']) && isset($_POST['bridgejail'])):
			$options = "-T -B";
		elseif(isset($_POST['thickjail'])):
			$options = "-T";
		elseif(isset($_POST['vnetjail'])):
			$options = "-V";
		elseif(isset($_POST['bridgejail'])):
			$options = "-B";
		elseif(isset($_POST['linuxjail'])):
			$options = "-L";
		endif;

		if(isset($_POST['emptyjail'])):
			// Just create an empty container with minimal jail.conf.
			$cmd = ("/usr/local/bin/bastille create -E {$jname}");
		else:
			if (isset($_POST['autostart'])):
				$cmd = ("/usr/local/bin/bastille create {$options} {$jname} {$release} {$ipaddr} {$interface}");
			else:
				$cmd = ("/usr/local/bin/bastille create --no-boot {$options} {$jname} {$release} {$ipaddr} {$interface}");
			endif;
		endif;

		if ($_POST['Create']):
			if(get_all_release_list()):
				unset($output,$retval);mwexec2($cmd,$output,$retval);
				if($retval == 0):
					//if (isset($_POST['autostart'])):
					//	exec("/usr/sbin/sysrc -f {$configfile} {$jname}_AUTO_START=\"YES\"");
					//endif;
					if(is_link($resolv_conf)):
						if(unlink($resolv_conf)):
							//exec("/usr/local/bin/bastille cp $jname $resolv_host etc");
							copy($resolv_host, $resolv_conf);
						endif;
					endif;
					header('Location: bastille_manager_gui.php');
					exit;
				else:
					$errormsg .= gtext("Failed to create container.");
				endif;
			else:
				$errormsg .= gtext(" <<< Failed to create container.");
			endif;
		endif;

		endif;
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
function emptyjail_change() {
	switch(document.iform.emptyjail.checked) {
		case false:
			showElementById('ipaddress_tr','show');
			showElementById('interface_tr', 'show');
			showElementById('release_tr', 'show');
			showElementById('thickjail_tr', 'show');
			showElementById('vnetjail_tr', 'show');
			showElementById('bridgejail_tr', 'show');
			//showElementById('nowstart_tr', 'show');
			showElementById('autostart_tr', 'show');
			showElementById('linuxjail_tr', 'show');
		break;
		case true:
			showElementById('ipaddress_tr','hide');
			showElementById('interface_tr', 'hide');
			showElementById('release_tr', 'hide');
			showElementById('thickjail_tr', 'hide');
			showElementById('vnetjail_tr', 'hide');
			showElementById('bridgejail_tr', 'hide');
			//showElementById('nowstart_tr', 'hide');
			showElementById('autostart_tr', 'hide');
			showElementById('linuxjail_tr', 'hide');
		break;
	}
}

function linuxjail_change() {
	switch(document.iform.linuxjail.checked) {
		case false:
			showElementById('ipaddress_tr','show');
			showElementById('interface_tr', 'show');
			showElementById('release_tr', 'show');
			showElementById('thickjail_tr', 'show');
			showElementById('vnetjail_tr', 'show');
			showElementById('bridgejail_tr', 'show');
			//showElementById('nowstart_tr', 'show');
			showElementById('autostart_tr', 'show');
			showElementById('linuxjail_tr', 'show');
			showElementById('emptyjail_tr', 'show');
		break;
		case true:
			showElementById('ipaddress_tr','show');
			showElementById('interface_tr', 'show');
			showElementById('release_tr', 'show');
			showElementById('thickjail_tr', 'hide');
			showElementById('vnetjail_tr', 'hide');
			showElementById('bridgejail_tr', 'hide');
			//showElementById('nowstart_tr', 'show');
			showElementById('autostart_tr', 'show');
			showElementById('emptyjail_tr', 'hide');
		break;
	}
}

function vnetjail_change() {
	switch(document.iform.vnetjail.checked) {
		case false:
			showElementById('ipaddress_tr','show');
			showElementById('interface_tr', 'show');
			showElementById('release_tr', 'show');
			showElementById('thickjail_tr', 'show');
			showElementById('vnetjail_tr', 'show');
			showElementById('bridgejail_tr', 'show');
			//showElementById('nowstart_tr', 'show');
			showElementById('autostart_tr', 'show');
			showElementById('linuxjail_tr', 'show');
		break;
		case true:
			showElementById('ipaddress_tr','show');
			showElementById('interface_tr', 'show');
			showElementById('release_tr', 'show');
			showElementById('thickjail_tr', 'show');
			showElementById('vnetjail_tr', 'show');
			showElementById('bridgejail_tr', 'hide');
			//showElementById('nowstart_tr', 'show');
			showElementById('autostart_tr', 'show');
			showElementById('linuxjail_tr', 'show');
		break;
	}
}

function bridgejail_change() {
	switch(document.iform.bridgejail.checked) {
		case false:
			showElementById('ipaddress_tr','show');
			showElementById('interface_tr', 'show');
			showElementById('release_tr', 'show');
			showElementById('thickjail_tr', 'show');
			showElementById('vnetjail_tr', 'show');
			showElementById('bridgejail_tr', 'show');
			//showElementById('nowstart_tr', 'show');
			showElementById('autostart_tr', 'show');
			showElementById('linuxjail_tr', 'show');
		break;
		case true:
			showElementById('ipaddress_tr','show');
			showElementById('interface_tr', 'show');
			showElementById('release_tr', 'show');
			showElementById('thickjail_tr', 'show');
			showElementById('vnetjail_tr', 'hide');
			showElementById('bridgejail_tr', 'show');
			//showElementById('nowstart_tr', 'show');
			showElementById('autostart_tr', 'show');
			showElementById('linuxjail_tr', 'show');
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
<form action="bastille_manager_add.php" method="post" name="iform" id="iform"><table id="area_data"><tbody><tr><td id="area_data_frame">
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
	if(file_exists($d_sysrebootreqd_path)):
		print_info_box(get_std_save_message(0));
	endif;
?>
	<table class="area_data_settings">
		<colgroup>
			<col class="area_data_settings_col_tag">
			<col class="area_data_settings_col_data">
		</colgroup>
		<thead>
<?php
			html_titleline2(gettext('Create new Container'));
?>
		</thead>
		<tbody>
<?php
			html_inputbox2('jailname',gettext('Friendly name'),$pconfig['jailname'],'',true,20);
			html_inputbox2('ipaddress',gettext('IP Address'),$pconfig['ipaddress'],'',true,20);
			$a_action = $l_interfaces;
			$b_action = $l_release;
			html_combobox2('interface',gettext('Network interface'),!empty($pconfig['interface']),$a_action,'',true,false);
			html_combobox2('release',gettext('Base release'),!empty($pconfig['release']),$b_action,'',true,false);
			if($bastille_version_min > "0700000000"):
				html_checkbox2('thickjail',gettext('Create a thick container'),!empty($pconfig['thickjail']) ? true : false,gettext('These containers consume more space, but are self contained.'),'',false);
				if($host_version > "12100"):
					html_checkbox2('vnetjail',gettext('Enable VNET(VIMAGE)'),!empty($pconfig['vnetjail']) ? true : false,gettext('VNET-enabled containers are attached to a virtual bridge interface for connectivity(Only supported on 13.x and above).'),'',false,false,'vnetjail_change()');
					html_checkbox2('bridgejail',gettext('Enable Bridge VNET(VIMAGE)'),!empty($pconfig['bridgejail']) ? true : false,gettext('Bridge VNET-enabled containers are attached to a specified, already existing external bridge(Only supported on 13.x and above).'),'',false,false,'bridgejail_change()');
				endif;
				html_checkbox2('emptyjail',gettext('Create an empty container'),!empty($pconfig['emptyjail']) ? true : false,gettext('This are ideal for custom builds, experimenting with unsupported RELEASES or Linux jails.'),'',false,false,'emptyjail_change()');
				if($linux_compat_support == "YES"):
					//html_checkbox2('linuxjail',gettext('Create a Linux container'),!empty($pconfig['linuxjail']) ? true : false,gettext('This will create a Linux container, this is highly experimental and for testing purposes.'),'',false,false,'linuxjail_change()');
				endif;
			endif;
			//html_checkbox2('nowstart',gettext('Start after creation'),!empty($pconfig['nowstart']) ? true : false,gettext('Start the container after creation(May be overridden by later bastille releases).'),'',false);
			html_checkbox2('autostart',gettext('Auto start on boot'),!empty($pconfig['autostart']) ? true : false,gettext('Automatically start the container at boot time.'),'',false);
?>
		</tbody>
	</table>
	<div id="submit">
		<input name="Create" type="submit" class="formbtn" value="<?=gtext('Create');?>"/>
		<input name="Cancel" type="submit" class="formbtn" value="<?=gtext('Cancel');?>" />
	</div>
<?php
	include 'formend.inc';
?>
</td></tr></tbody></table></form>
<script type="text/javascript">
<!--
emptyjail_change();
linuxjail_change();
//-->
</script>
<?php
include 'fend.inc';
?>
