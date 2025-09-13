<?php
/*
    bastille_manager_config.php

	Copyright (c) 2019-2025 Jose Rivera (joserprg@gmail.com).
    All rights reserved.

    Copyright (c) 2018 Andreas Schmidhuber
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

$configAddon = "{$bastille_config}";

$textdomain = "/usr/local/share/locale";
$textdomain_bastille = "/usr/local/share/locale-bastille";
if (!is_link($textdomain_bastille)) { mwexec("ln -s {$rootfolder}/locale-bastille {$textdomain_bastille}", true); }
bindtextdomain("xigmanas", $textdomain_bastille);

$pgtitle = array(gtext("Extensions"), gtext("Bastille"), gtext("Configuration"));

$wSpace = "&nbsp;&nbsp;";
$wSpaceEqual = "&nbsp;&nbsp;=&nbsp;&nbsp;";
$paramNameSize = 30;	//length of parameter name input field, default for parameter value input field is '80'

if(!initial_install_banner()):
	$errormsg = gtext('Bastille Initial Configuration.')
			. ' '
			. '</br>'
			. gtext('Please check and configure the following entries: "BASTILLE_ZFS_ENABLE" and "BASTILLE_ZFS_ZPOOL".')
			.'</br>'
			. gtext('After configuring or skip ZFS option:')
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

function htmlInput($name, $title, $value="", $size=80) {
	$result = "<input name='{$name}' size='{$size}' title='{$title}' placeholder='{$title}' value='{$value}' />";
	return $result;
}

function htmlButton($name, $text, $value="", $title="", $confirm="", $buttonImage="") {
	$onClick = ($confirm == "") ? "" : "onclick='return confirm(\"{$confirm}\")'";
	switch ($buttonImage) {
		case "save": $buttonImage = "<img src='images/status_enabled.png' height='10' width='10'>"; break;
		default: $buttonImage = "";
	}
	$result = "<button name='{$name}' type='submit' class='formbtn' title='{$title}' value='{$value}' {$onClick}>{$buttonImage}{$text}</button>";
	return $result;
}

function parseConfigFile($configFile) {
	global $section;
	$fileArray = file($configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);	// load config file content to array
	$configArray = array();
	foreach($fileArray as $line) {							// create array from config
		$line = trim($line);								// remove leading/trailing space
		if ($line[0] == "#") continue;						// skip if comment line
		if ($line[0] == "[") {								// add as section
			$configArray[$line] = [];
			$section = $line;								// remember section name for params
		} else {											// process params
			$parameter = explode("=", $line, 2);			// seperate key and value, (Split at the first occurrence only)
			$key = trim($parameter[0]);						// set key
			$val = explode("#", trim($parameter[1]));		// get value, remove trailing comments
			$value = $val[0];								// set value
			$configArray[$section][$key] = $value;			// add param to section
		}
	}
	return $configArray;
}

function saveConfigFile($configFile, $configArray, $hashTag="", $prettyPrint=true) {
	//$printTab = ($prettyPrint) ? "\t" : "";		// Print leading tab.
	//$printSpace = ($prettyPrint) ? " " : "";		// Print spaces.
	$printTab = ($prettyPrint) ? "" : "";			// Do not print leading tab.
	$printSpace = ($prettyPrint) ? "" : "";			// Do not print spaces.

	$cFile = fopen($configFile, "w");
	foreach($configArray as $key => $line) {									// traverse array, key = section
		if (is_array($line)) {
			if ($key != '') fwrite($cFile, $key.PHP_EOL);						// write section if not "['']" => NO section
			foreach($line as $pName => $pValue) fwrite($cFile, $printTab.$pName.$printSpace."=".$printSpace.$pValue.PHP_EOL);	// "\t".$pName	= add TAB for output formatting
			fwrite($cFile, PHP_EOL);
		} else fwrite($cFile, $key.$printSpace."=".$printSpace.$line.PHP_EOL);
	}	// end foreach
	fclose($cFile);
	if (!empty($hashTag)) header("Location:#{$hashTag}");
}

// load addon config - use selected config from Bastille tab or alternative if exist
$configAddonArray = parseConfigFile($configAddon);										// read addon config file
if (empty($configAddonArray['']['ALTERNATIVE_CONFIG'])) $configFile = str_replace('"', "", $configAddonArray['']['BASTILLE_CONFIG']);	// get Bastille config file path and name
else $configFile = str_replace('"', "", $configAddonArray['']['ALTERNATIVE_CONFIG']);	// get Bastille config file path and name

// load Bastille config
if (!is_file($configFile)) $input_errors[] = sprintf(gtext("%s not found!"), gettext("Configuration File")." {$configFile}");
else {
	$configArray = parseConfigFile($configFile);										// parse Bastille config file
	//$savemsg = gtext("Loaded config file").": <b>".basename($configFile)."</b>";
}

if ($_POST) {
	unset($input_errors);

	if (isset($_POST['saveParam']) && $_POST['saveParam']) {					// saveParam s/n/v
		$buttonTag = explode("#", $_POST['saveParam']);							// buttonTag[0] = section, buttonTag[1] = paramName
		$hashTag = str_replace(["[", "]", ".", "#"], "", $buttonTag[0]);		// create destination to jump to after post 
		$nameTag = str_replace(["[", "]", ".", "#"], "", $_POST['saveParam']);	// nameTag = <input title='$nameTag + addParam' ... />
		$configArray[$buttonTag[0]][$buttonTag[1]] = $_POST[$nameTag];			// save param to section
		#$savemsg .= "saveParam s/n/v: ".$_POST['saveParam']." ".$nameTag." ".$_POST[$nameTag];
	}
	if (empty($input_errors) && !isset($_POST['loadConfig'])) saveConfigFile($configFile, $configArray, $hashTag);

	# Run bastille-init to update config.
	exec("bastille-init");
}

bindtextdomain("xigmanas", $textdomain);
include("fbegin.inc");
bindtextdomain("xigmanas", $textdomain_bastille);
?>
<form action="bastille_manager_config.php" method="post" name="iform" id="iform" onsubmit="spinner()">
	<table width="100%" border="0" cellpadding="0" cellspacing="0">
	<tr><td class="tabnavtbl">
		<ul id="tabnav">
			<li class="tabinact"><a href="bastille_manager_gui.php"><span><?=gettext("Bastille");?></span></a></li>
			<li class="tabact"><a href="bastille_manager_config.php"><span><?=gettext("Configuration");?></span></a></li>
		</ul>
	</td></tr>
	<tr><td class="tabcont">
		<table width="100%" border="0" cellpadding="6" cellspacing="0">
				<?php if(!empty($errormsg)): print_error_box($errormsg); endif; ?>
			<?php																			// create table from configuration
				echo "<tr><td colspan='2' style='padding-left:0px; padding-right:0px;'>";
					if (!empty($input_errors)) print_input_errors($input_errors);
					if (!empty($savemsg)) print_info_box($savemsg);
				echo "</td></tr>";		
				// loop through configuration
				$firstSection = true;														// prevent first html_separator in loop
				if (is_array($configArray) && !empty($configArray))	
					foreach($configArray as $key => $line) {								// traverse array, key = section
						$nameTag = str_replace(["[", "]", "."], "", $key);					// create tag for post jump address and config changes
						if (is_array($line)) {
							if ($firstSection === true) $firstSection = false;
							else html_separator();
							html_titleline(gtext("Variable Name").": ".$key, 2, $nameTag);	// section title bar
							foreach($line as $pName => $pValue)								// traverse params within section, pName = param name, pValue = param value
								html_text($pName, $pName,									// create param entry
								htmlInput($nameTag.$pName, gtext("Parameter Value"), $pValue).$wSpace.
								htmlButton("saveParam", "", $key."#".$pName, gtext("Save"), "", "save").$wSpace. "",
								);
						}
						echo "<tr><td style='padding-left:0px;'>";
						echo "</td></tr>";
					}
				echo "<tr><td colspan='2' style='padding-left:0px;'>";
					html_remark("noteAddSection", gtext("Note"), gtext("Please be careful, as no validation will be performed on your input!"));
				echo "</td></tr>";
			?>
		</table><?php include("formend.inc");?>
	</td></tr>
	</table>
</form>
<?php include("fend.inc");?>
