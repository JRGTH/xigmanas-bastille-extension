<?php
/*
	bastille_manager_editor.php

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

$savetopath = "{$rootfolder}/jails/";
if (isset($_POST['savetopath']))  {
	$savetopath = htmlspecialchars($_POST['savetopath']);
}
if(isset($_POST['submit'])) {
	switch($_POST['submit']) {
		case 'edit':
			if(preg_match('/\S/', $savetopath)) {
				if(file_exists($savetopath) && is_file($savetopath)) {
					$content = file_get_contents($savetopath);
					$edit_area = "";
					if (stristr($savetopath, ".php") == true) $language = "php";
					else if (stristr($savetopath, ".inc") == true) $language = "php";
					else if (stristr($savetopath, ".sh") == true) $language = "core";
					else if (stristr($savetopath, ".xml") == true) $language = "xml";
					else if (stristr($savetopath, ".js") == true) $language = "js";
					else if (stristr($savetopath, ".css") == true) $language = "css";
				} else {
					$savemsg = sprintf('%s %s', gtext('File not found'), $savetopath);
					$content = '';
					$savetopath = '';			
				}
			}
			break;
		case 'save':
			if(preg_match('/\S/', $savetopath)) {
				conf_mount_rw();
				$content = preg_replace("/\r/","",$_POST['code']) ;
				file_put_contents($savetopath, $content);
				$edit_area = "";
				$savemsg = sprintf('%s %s', gtext('Saved file to'), $savetopath);
				if ($savetopath === "{$g['cf_conf_path']}/config.xml") {
					unlink_if_exists("{$g['tmp_path']}/config.cache");
				}
				conf_mount_ro();
			}
			break;
		case 'bastille':
			// Return to Bastille index.
			header('Location: bastille_manager_gui.php');
			break;
	}
}

if(isset($_POST['rows']) && !empty($_POST['rows'])) {
	$rows = $_POST['rows'];
} else {
	$rows = 30;
}
if(isset($_POST['cols']) && !empty($_POST['cols'])) {
	$cols = $_POST['cols'];
} else {
	$cols = 66;
}
$pgtitle = [gtext('Bastille'), gtext('File Editor')];
include 'fbegin.inc';
?>
<script type="text/javascript">
//<![CDATA[
$(window).on("load", function() {
<?php	// Init spinner onsubmit()?>
	$("#iform").submit(function() { spinner(); });
});
//]]>
</script>
<table id="area_data"><tbody><tr><td id="area_data_frame"><form action="bastille_manager_editor.php" method="post" name="iform" id="iform">
<?php
	if(!empty($savemsg)):
		print_info_box($savemsg);
	endif;
?>
	<table class="area_data_settings">
		<colgroup>
			<col style="width:100%">
		</colgroup>
		<thead>
<?php
			html_titleline2(gettext('File Editor'),1);
			html_separator2(1);
?>
			<tr>
				<td>
					<span class="label"><?=gtext('File Path');?></span>
					<input size="42" id="savetopath" name="savetopath" value="<?=$savetopath;?>" />
					<input name="browse" type="button" class="formbtn" id="Browse" onclick='ifield = form.savetopath; filechooser = window.open("filechooser.php?p="+encodeURIComponent(ifield.value), "filechooser", "scrollbars=yes,toolbar=no,menubar=no,statusbar=no,width=550,height=300"); filechooser.ifield = ifield; window.ifield = ifield;' value="..." />
					<button name="submit" type="submit" class="formbtn" id="Edit" value="edit"><?=gtext('Edit');?></button>
					<button name="submit" type="submit" class="formbtn" id="Save" value="save"><?=gtext('Save');?></button>
					<button name="submit" type="submit" class="formbtn" id="Return" value="bastille"><?=gtext('Return to Bastille');?></button>
					<hr noshade="noshade" />					
				</td>
			</tr>
<?php
			html_separator2(1);
?>
		</thead>
		<tbody>
			<tr>
				<td valign="top" class="label">
					<div style="background: #eeeeee;" id="textareaitem">
<?php
						//	NOTE: The opening *and* the closing textarea tag must be on the same line.
?>
						<textarea style="width:100%; margin:0;" class="<?=$language;?>:showcolumns" rows="<?=$rows;?>" cols="<?=$cols;?>" name="code"><?=htmlspecialchars(!empty($content) ? $content : '');?></textarea>
					</div>
				</td>
			</tr>
		</tbody>
	</table>
<?php
include 'formend.inc';
?>
</form></td></tr></tbody></table>
<script type="text/javascript">
//<![CDATA[
  // Set focus.
  document.forms[0].savetopath.focus();

//]]>
</script>
<?php
include 'fend.inc';
