<?php
/*
	bastille_manager_add.php

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

$pgtitle = array(gtext("Extensions"), "Bastille", "Create");

if(!$pconfig['jailname']):
	$pconfig['jailname'] = 'jail1';
endif;
if(!$pconfig['ipaddress']):
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

if($_POST):
	global $configfile;
	unset($input_errors);
	$pconfig = $_POST;
	if(isset($_POST['Cancel']) && $_POST['Cancel']):
		header('Location: bastille_manager_gui.php');
		exit;
	endif;
	if(isset($_POST['Create']) && $_POST['Create']):
		$jname = $pconfig['jailname'];
		$ipaddr = $pconfig['ipaddress'];
		$release = $pconfig['release'];
		if ($_POST['interface'] == 'Config'):
			$interface = "";
		else:
			$interface = $pconfig['interface'];
		endif;

		if($_POST['thickjail']):
			$thick_jail = "-T";
		else:
			$thick_jail = "";
		endif;

		if ($_POST['nowstart']):
			$cmd = ("/usr/local/bin/bastille create {$thick_jail} {$jname} {$release} {$ipaddr} {$interface} && /usr/local/bin/bastille start {$jname}");
		else:
			$cmd = ("/usr/local/bin/bastille create {$thick_jail} {$jname} {$release} {$ipaddr} {$interface}");
		endif;

		if ($_POST['Create']):
			if(get_all_release_list()):
				unset($output,$retval);mwexec2($cmd,$output,$retval);
				if($retval == 0):
					if ($_POST['autostart']):
						exec("/usr/sbin/sysrc -f {$configfile} {$jname}_AUTO_START=\"YES\"");
					endif;
					//$savemsg .= gtext("Boot Environment created and activated successfully.");
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

include 'fbegin.inc';
?>
<script type="text/javascript">
//<![CDATA[
$(window).on("load",function() {
	$("#iform").submit(function() { spinner(); });
	$(".spin").click(function() { spinner(); });
});
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
			html_inputbox2('ipaddress',gettext('IPv4 Address'),$pconfig['ipaddress'],'',true,20);
			$a_action = $l_interfaces;
			$b_action = $l_release;
			html_combobox2('interface',gettext('Network interface'),$pconfig['interface'],$a_action,'',true,false,'action_change()');
			html_combobox2('release',gettext('Base release'),$pconfig['release'],$b_action,'',true,false,'action_change()');
			if($thick_jail):
				html_checkbox2('thickjail',gettext('Create a thick container'),!empty($pconfig['thickjail']) ? true : false,gettext('These containers consume more space, but are self contained.'),'',false);
			endif;
			html_checkbox2('nowstart',gettext('Start after creation'),!empty($pconfig['nowstart']) ? true : false,gettext('Start the container after creation.'),'',false);
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
<?php
include 'fend.inc';
?>
