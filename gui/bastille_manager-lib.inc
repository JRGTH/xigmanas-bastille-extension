<?php
/*
	bastille_manager-lib.inc

	Copyright (c) 2019-2020 José Rivera (joserprg@gmail.com).
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

require_once 'super_fun.inc';
require_once 'globals.inc';
require_once 'array.inc';
require_once 'system.inc';

// Initialize some variables.
// TODO: Some infos can be gathered with-
// internal PHP functions rather than external shell commands.

//$rootfolder = dirname($config['rc']['postinit']['cmd'][$i]);
$application = "Bastille Manager";
$restore_name = "restore";
$confdir = "/var/etc/bastille_conf";
$cwdir = exec("/usr/bin/grep 'INSTALL_DIR=' $confdir/conf/bastille_config | /usr/bin/cut -d'\"' -f2");
$rootfolder = $cwdir;
$configfile = "$rootfolder/conf/bastille_config";
$versionfile = "$rootfolder/version";
$date = strftime('%c');
$logfile = "$rootfolder/log/bastille_ext.log";
$logevent = "$rootfolder/log/bastille_last_event.log";
$backup_path = exec("/usr/bin/grep 'BACKUP_DIR=' $configfile | /usr/bin/cut -d'\"' -f2");
$bastille_config = "$rootfolder/conf/bastille_config";
$config_path = exec("/usr/bin/grep 'BASTILLE_CONFIG=' $configfile | /usr/bin/cut -d'\"' -f2");
$default_distfiles = exec("/usr/bin/grep 'bastille_bootstrap_archives=' $config_path | /usr/bin/cut -d'\"' -f2");
$jail_dir = "{$rootfolder}/jails";
$image_dir = "ext/bastille/images";
$options_support = exec("/usr/local/bin/bastille create | grep -wo '\[option\]'");
$reldir = "{$rootfolder}/releases";
$backup_path_bastille = exec("/usr/sbin/sysrc -f $rootfolder/bastille-dist/usr/local/etc/bastille/bastille.conf -qn bastille_backupsdir");
$zfs_support = exec("/usr/bin/grep 'ZFS_SUPPORT=' $configfile | /usr/bin/cut -d'\"' -f2");
$zfs_activated = exec("/usr/bin/grep 'ZFS_ACTIVATED=' $configfile | /usr/bin/cut -d'\"' -f2");

// Ensure the root directory is configured.
if ($rootfolder == "")
	$input_errors[] = gtext("Extension installed with fault");
else {
// Initialize locales.
	$textdomain = "/usr/local/share/locale";
	$textdomain_bastille = "/usr/local/share/locale-bastille";
	if (!is_link($textdomain_bastille)) { mwexec("ln -s {$rootfolder}/locale-bastille {$textdomain_bastille}", true); }
	bindtextdomain("xigmanas", $textdomain_bastille);
}
if (is_file("{$rootfolder}/postinit")) unlink("{$rootfolder}/postinit");

// Check releases dir.
function is_dir_empty($reldir) {
	if (!is_readable($reldir)) return NULL; 
	return (count(scandir($reldir)) == 2);
}

// Initial install banner
function initial_install_banner() {
	// Never display this if bastille is already bootstraped/activated.
	global $rootfolder;
	global $zfs_activated;
	$is_activated = "";
	$is_bootstrapped = "";

	// Check activation
	if ($zfs_activated == "YES"):
		return $is_activated = "YES";
	elseif ($zfs_activated == "NO"):
		return $is_activated = "NO";
	else:
		// Check for bootstrap directories.
		exec("/bin/echo ' cache jails logs releases templates ' | /usr/bin/tr -s ' ' '\n'", $bastille_dirs);
		array_shift($bastille_dirs);
		foreach($bastille_dirs as $dir):
			if(is_dir("$rootfolder/$dir")):
			return $is_bootstrapped = "YES";
			break;
			endif;
		endforeach;	
	endif;
}

// Check for sane ZFS settings.
function get_state_zfs() {
	global $zfs_support;
	if ($zfs_support == "YES"):
		return $getinfo['zfs'] = gettext("Enabled");
	elseif ($zfs_support == "AVA"):
		return $getinfo['zfs'] = gettext("Available but not enabled");
	elseif ($zfs_support == "ERR"):
		return $getinfo['zfs'] = gettext("Invalid ZFS configuration");
	else:
		return $getinfo['zfs'] = gettext("Disabled");
	endif;
}

// Get all base releases list.
function get_all_release_list() {
	global $rootfolder;
	global $g;
	exec("/bin/echo; /bin/ls {$rootfolder}/releases 2>/dev/null | /usr/bin/tr -s ' ' '\n'",$relinfo);
	array_shift($relinfo);
	$rellist = [];
	foreach($relinfo as $rel):
		$arel = preg_split("/\s+/",ltrim($rel));
		$relname = chop($arel[0]);
		if(substr($relname,-1) === '*'):
			$relname = substr($relname,0,strlen($relname) - 1);
		endif;
		$rellist[$relname] = [];
	endforeach;
	return $rellist;
}

// Get all interface list.
function get_all_interface_list() {
	global $g;
	exec("/bin/echo; /sbin/ifconfig -l | /usr/bin/tr -s ' ' '\n'; /bin/echo 'Config'",$linkinfo);
	array_shift($linkinfo);
	$iflist = [];
	foreach($linkinfo as $link):
		$alink = preg_split("/\s+/",ltrim($link));
		$ifname = chop($alink[0]);
		if(substr($ifname,-1) === '*'):
			$ifname = substr($ifname,0,strlen($ifname) - 1);
		endif;
		$iflist[$ifname] = [];
	endforeach;
	return $iflist;
}

//	list base releases
$a_release = get_all_release_list();
$l_release = [];
foreach($a_release as $k_release => $release):
	$l_release[$k_release] = $k_release;
endforeach;

//	list of configured interfaces
$a_interface = get_all_interface_list();
$l_interfaces = [];
foreach($a_interface as $k_interface => $ifinfo):
	$l_interfaces[$k_interface] = $k_interface;
endforeach;

// Get jail infos.
function get_jail_infos() {
	global $img_path;
	global $image_dir;
	global $configfile;
	global $jail_dir;
	$result = [];
	if(is_dir($jail_dir)):
		$cmd = '/usr/local/bin/bastille list jail 2>&1';
	else:
		$cmd = ":";
	endif;
	mwexec2($cmd,$rawdata);
	foreach($rawdata as $line):
		$a = preg_split('/\t/',$line);
		$r = [];
		$name = $a[0];
		if(preg_match('/(.*)/', $name, $m)):
			$r['name'] = $m[1];
		else:
			$r['name'] = '-';
		endif;
		$r['jailname'] = $r['name'];

		// Set the JID on the running jails.
		$item = $r['jailname'];
		$r['id'] = exec("/usr/sbin/jls | /usr/bin/awk '/{$item}\ /{print $1}'");
		if (!$r['id']):
			$r['id'] = "-";
		endif;
		// Set the IPv4 on the running jails.
		//$r['ip'] = exec("/usr/sbin/jls | /usr/bin/grep {$item} | /usr/bin/awk '{print $2}'");
		$r['ip'] = exec("/usr/bin/grep -w 'ip4.addr' {$jail_dir}/{$item}/jail.conf | /usr/bin/awk '{print $3}' | /usr/bin/tr -d ';'");
		if (!$r['ip']):
			$r['ip'] = exec("/usr/bin/grep -w 'ip6.addr' {$jail_dir}/{$item}/jail.conf | /usr/bin/awk '{print $3}' | /usr/bin/tr -d ';'");
		endif;
		// Try to get ip from vnet config.
		if(!$r['ip']):
			$r['ip'] = exec("/usr/local/bin/bastille cmd {$item} cat /etc/rc.conf | /usr/bin/grep 'ifconfig_vnet0=' | cut -d'\"' -f2 | sed 's/inet //'");
		endif;
		if (!$r['ip']):
			$r['ip'] = "-";
		endif;
		// Display release.
		$r['rel'] = exec("/usr/sbin/jexec {$item} freebsd-version 2>/dev/null");
		if (!$r['rel']):
			$r['rel'] = "-";
		endif;
		// Display interfaces.
		$r['nic'] = exec("/usr/bin/grep -w 'interface' {$jail_dir}/{$item}/jail.conf | /usr/bin/awk '{print $3}' | /usr/bin/tr -d ';'");
		if (!$r['nic']):
			$r['nic'] = "-";
		endif;
		// Display path.
		$r['path'] = exec("/usr/bin/grep -w 'path' {$jail_dir}/{$item}/jail.conf | /usr/bin/awk '{print $3}' | /usr/bin/tr -d ';'");
		if (!$r['path']):
			$r['path'] = "-";
		endif;
		// Display auto-start settings.
		$jail_autostart = exec("/usr/sbin/sysrc -qn -f {$configfile} {$item}_AUTO_START");
		if ($jail_autostart == 'YES') {
			$r['boot'] = $img_path['ena'];
		} elseif ($jail_autostart == 'NO') {
			$r['boot'] = $img_path['dis'];
		} else {
			$r['boot'] = $img_path['dis'];
		}
		// Display running status icons.
		$jail_running = exec("/usr/sbin/jls name | /usr/bin/awk '/^{$item}\$/'");
		if ($jail_running):
			$r['stat'] = $img_path['ena'];
		else:
			$r['stat'] = $img_path['dis'];
		endif;
		// Display custom template icons if available.
		$template_icon = "{$jail_dir}/{$item}/plugin_icon.png";
		if(file_exists($template_icon)):
			if(!file_exists("{$image_dir}/{$item}_icon.png")):
				copy("$template_icon", "{$image_dir}/{$item}_icon.png");
			endif;
			$r['logo'] = "{$image_dir}/{$item}_icon.png";
		else:
			// Display standard FreeBSD icon.
			$r['logo'] = "{$image_dir}/bsd_icon.png";
		endif;

		$result[] = $r;
	endforeach;
	return $result;
}
?>
