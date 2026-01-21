<?php
/*
	bastille_manager_info.php

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

$zfs_status = get_state_zfs();
if($zfs_status == "Invalid ZFS configuration"):
	// Warning if invalid ZFS configuration.
	$input_errors[] = gtext("WARNING: Invalid ZFS configuration detected.");
endif;

function jls_get_jail_list(string $entity_name = NULL) {
	if(isset($entity_name)):
		$cmd = "/usr/sbin/jls -v -j $entity_name 2>&1";
	else:
		$cmd = '/usr/sbin/jls -v 2>&1';
	endif;
	unset($output);
	mwexec2($cmd,$output);
	return implode(PHP_EOL,$output);
}

function jls_get_all(string $entity_name = NULL) {
	if(isset($entity_name)):
		$cmd = "/usr/sbin/jls -qn -j $entity_name | tr -s \" \" \"\n\" 2>&1";
	else:
		$cmd = ':';
	endif;
	unset($a_names);
	mwexec2($cmd,$a_names);
	if(is_array($a_names) && count($a_names) > 0):
		$names = implode(' ',array_map('escapeshellarg',$a_names));
		unset($output);
		mwexec2($cmd,$output);
	else:
		$output = [gtext('Parameters information available for individual selection only.')];
	endif;
	return implode(PHP_EOL,$output);
}

$entity_name = NULL;
if(isset($_GET['uuid']) && is_string($_GET['uuid'])):
	$entity_name = sprintf('%s',$_GET['uuid']);
endif;
$pgtitle = [gtext("Extensions"), gtext('Bastille'),gtext('Information')];
include 'fbegin.inc';
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

<?php
	if(!empty($errormsg)):
		print_error_box($errormsg);
	endif;
	if(!empty($input_errors)):
		print_input_errors($input_errors);
	endif;
	if(file_exists($d_sysrebootreqd_path)):
		print_info_box(get_std_save_message(0));
	endif;
?>

<table id="area_data"><tbody><tr><td id="area_data_frame">
	<table class="area_data_settings">
		<colgroup>
			<col class="area_data_settings_col_tag">
			<col class="area_data_settings_col_data">
		</colgroup>
		<thead>
<?php
			html_titleline2(gettext('Container Summary'));
?>
		</thead>
		<tbody>
			<tr>
				<td class="celltag"><?=gtext('Summary');?></td>
				<td class="celldata">
					<pre><span id="jls_jail_list"><?=jls_get_jail_list($entity_name);?></span></pre>
				</td>
			</tr>
		</tbody>
		<tfoot>
<?php
			html_separator2();
?>
		</tfoot>
	</table>
	<table class="area_data_settings">
		<colgroup>
			<col class="area_data_settings_col_tag">
			<col class="area_data_settings_col_data">
		</colgroup>
		<thead>
<?php
			html_titleline2(gettext('Container Parameters'));
?>
		</thead>
		<tbody>
			<tr>
				<td class="celltag"><?=gtext('Parameters');?></td>
				<td class="celldata">
					<pre><span id="jls_get_info"><?=jls_get_all($entity_name);?></span></pre>
				</td>
			</tr>
		<tbody>
	</table>
<tbody>
</td></tr></tbody></table>
<?php
include 'fend.inc';
?>
