<?php
/*
	bastille_manager-lib.inc

	Copyright (c) 2019-2026 José Rivera (joserprg@gmail.com).
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

// ===== OPTIMIZATION: Cache Configuration =====
define('JAIL_INFO_CACHE_TIME', 5); // seconds
define('JAIL_INFO_CACHE_FILE', '/tmp/bastille_jail_info_cache.json');
// =============================================

// Initialize some variables.
$prdname = "bastille";
$application = "Bastille Manager";
$restore_name = "restore";
$confdir = "/var/etc/bastille_conf";
$cwdir = exec("/usr/bin/grep 'INSTALL_DIR=' $confdir/conf/bastille_config | /usr/bin/cut -d'\"' -f2");
$rootfolder = $cwdir;
$configfile = "$rootfolder/conf/bastille_config";
$configfile_bastille = "$rootfolder/bastille-dist/usr/local/etc/bastille/bastille.conf";
$versionfile = "$rootfolder/version";
//$date = strftime('%c');                // Previous PHP versions, deprecated as of PHP 8.1.
$date = date('D M d h:i:s Y', time());   // Equivalent date replacement for the previous strftime function.
$logfile = "$rootfolder/log/bastille_ext.log";
$logevent = "$rootfolder/log/bastille_last_event.log";
$backup_path = exec("/usr/bin/grep 'BACKUP_DIR=' $configfile | /usr/bin/cut -d'\"' -f2");
$bastille_config = "$rootfolder/conf/bastille_config";
$config_path = exec("/usr/bin/grep 'BASTILLE_CONFIG=' $configfile | /usr/bin/cut -d'\"' -f2");
$default_distfiles = exec("/usr/bin/grep 'bastille_bootstrap_archives=' $config_path | /usr/bin/cut -d'\"' -f2");
$jail_dir = "{$rootfolder}/jails";
$image_dir = "ext/bastille/images";
$reldir = "{$rootfolder}/releases";
$backup_path_bastille = exec("/usr/sbin/sysrc -f $rootfolder/bastille-dist/usr/local/etc/bastille/bastille.conf -qn bastille_backupsdir");
$zfs_support = exec("/usr/bin/grep 'ZFS_SUPPORT=' $configfile | /usr/bin/cut -d'\"' -f2");
$zfs_activated = exec("/usr/bin/grep 'ZFS_ACTIVATED=' $configfile | /usr/bin/cut -d'\"' -f2");
$tarballversion = "/usr/local/bin/bastille";
$bastille_version_min = exec("grep 'BASTILLE_VERSION=' $tarballversion | cut -d '\"' -f2 | tr -d '.'");
$host_version = exec("/bin/cat /etc/prd.version | tr -d '.'");
$linux_compat_support = exec("/usr/bin/grep 'LINUX_COMPAT_SUPPORT=' $configfile | /usr/bin/cut -d'\"' -f2");
$jail_settings = "settings.conf";

// Ensure the root directory is configured.
if ($rootfolder == ""):
	$input_errors[] = gtext("Extension installed with fault");
else:
// Initialize locales.
	$textdomain = "/usr/local/share/locale";
	$textdomain_bastille = "/usr/local/share/locale-bastille";
	if (!is_link($textdomain_bastille)):
		mwexec("ln -s {$rootfolder}/locale-bastille {$textdomain_bastille}", true);
	endif;
	bindtextdomain("xigmanas", $textdomain_bastille);
endif;
if (is_file("{$rootfolder}/postinit")) unlink("{$rootfolder}/postinit");

// Check releases dir.
function is_dir_empty($reldir) {
	if (!is_readable($reldir)) return NULL;
	return (count(scandir($reldir)) == 2);
}

// Get bastille version
function get_version_bastille() {
	global $tarballversion, $prdname;
	if (is_file("{$tarballversion}")):
		exec("/usr/bin/grep 'BASTILLE_VERSION=' {$tarballversion} | cut -d'=' -f2", $result);
		return ($result[0] ?? '');
	else:
		exec("/usr/local/bin/{$prdname} version | awk 'NR==1'", $result);
		return ($result[0] ?? '');
	endif;
}

// Initial install banner
function initial_install_banner() {
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
	exec("/bin/echo; /bin/ls {$rootfolder}/releases | grep RELEASE 2>/dev/null | /usr/bin/tr -s ' ' '\n'",$relinfo);
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

// ===== CACHE FUNCTIONS =====
function is_cache_valid() {
	if (!file_exists(JAIL_INFO_CACHE_FILE)) {
		return false;
	}
	$cache_age = time() - filemtime(JAIL_INFO_CACHE_FILE);
	return $cache_age < JAIL_INFO_CACHE_TIME;
}

function get_cached_jail_info() {
	if (!is_cache_valid()) {
		return null;
	}
	$cache_data = @file_get_contents(JAIL_INFO_CACHE_FILE);
	if ($cache_data === false) {
		return null;
	}
	return json_decode($cache_data, true);
}

function save_jail_info_cache($data) {
	@file_put_contents(JAIL_INFO_CACHE_FILE, json_encode($data));
}

function invalidate_jail_cache() {
	@unlink(JAIL_INFO_CACHE_FILE);
}

// ===== OPTIMIZED: Get jail infos =====
// Get jail infos - OPTIMIZED VERSION
function get_jail_infos() {
	global $img_path;
	global $image_dir;
	global $configfile;
	global $jail_dir;

	// Try cache first
	$cached = get_cached_jail_info();
	if ($cached !== null) {
		return $cached;
	}

	$result = [];

	if (!is_dir($jail_dir)) {
		return $result;
	}

	// OPTIMIZATION: Get bastille list ONCE and parse all jails
	// Format: JID  Name  Boot  Prio  State  Type  IP_Address  Published_Ports  Release  Tags
	$cmd = '/usr/local/bin/bastille list 2>&1';
	mwexec2($cmd, $rawdata);

	// Build a lookup table from bastille list output
	$jail_data_map = [];
	$header_skipped = false;

	foreach ($rawdata as $line) {
		// Skip header line
		if (!$header_skipped) {
			$header_skipped = true;
			continue;
		}

		// Parse fields: JID Name Boot Prio State Type IP Ports Release Tags
		$fields = preg_split('/\s+/', trim($line), 10);

		if (count($fields) >= 6) {
			$name = $fields[1];
			$jail_data_map[$name] = [
				'jid' => $fields[0],
				'boot' => $fields[2],
				'prio' => $fields[3],
				'state' => $fields[4],
				'type' => $fields[5],
				'ip' => $fields[6] ?? '-',
				'ports' => $fields[7] ?? '-',
				'release' => $fields[8] ?? '-',
				'tags' => $fields[9] ?? '-'
			];
		}
	}

	// Now process each jail from bastille list jail (for jail names)
	$cmd = '/usr/local/bin/bastille list jail 2>&1';
	mwexec2($cmd, $jail_names);

	foreach ($jail_names as $line) {
		$a = preg_split('/\t/', $line);
		$r = [];
		$name = $a[0];

		if (preg_match('/(.*)/', $name, $m)) {
			$r['name'] = $m[1];
		} else {
			$r['name'] = '-';
		}

		$r['jailname'] = $r['name'];
		$item = $r['jailname'];

		// Get data from our lookup table instead of executing bastille list again
		if (isset($jail_data_map[$item])) {
			$jail_data = $jail_data_map[$item];

			$r['id'] = $jail_data['jid'];
			$r['boot'] = $jail_data['boot'];
			$r['prio'] = $jail_data['prio'];
			$r['state'] = $jail_data['state'];
			$r['type'] = $jail_data['type'];
			$r['ip'] = $jail_data['ip'];
			$r['ports'] = $jail_data['ports'];
			$r['rel'] = $jail_data['release'];
			$r['tags'] = $jail_data['tags'];
		} else {
			// Fallback if jail not in bastille list output
			$r['id'] = '-';
			$r['boot'] = '-';
			$r['prio'] = '-';
			$r['state'] = '-';
			$r['type'] = '-';
			$r['ip'] = '-';
			$r['ports'] = '-';
			$r['rel'] = '-';
			$r['tags'] = '-';
		}

		// Set defaults for empty values
		if (!$r['id']) $r['id'] = "-";
		if (!$r['boot']) $r['boot'] = "-";
		if (!$r['prio']) $r['prio'] = "-";
		if (!$r['state']) $r['state'] = "-";
		if (!$r['type']) $r['type'] = "-";
		if (!$r['ip']) $r['ip'] = "-";
		if (!$r['ports']) $r['ports'] = "-";
		if (!$r['rel']) $r['rel'] = "-";
		if (!$r['tags']) $r['tags'] = "-";

		// Display running status icons
		if ($r['state'] == "Up") {
			$r['stat'] = $img_path['ena'];
		} else {
			$r['stat'] = $img_path['dis'];
		}

		// Display custom template icons if available
		$template_icon = "{$jail_dir}/{$item}/plugin_icon.png";
		if (file_exists($template_icon)) {
			if (!file_exists("{$image_dir}/{$item}_icon.png")) {
				@copy("$template_icon", "{$image_dir}/{$item}_icon.png");
			}
			$r['logo'] = "{$image_dir}/{$item}_icon.png";
		} else {
			$template_icon = exec("/usr/bin/grep linsysfs {$jail_dir}/{$item}/fstab 2>/dev/null");
			if ($template_icon) {
				// Display standard Linux icon
				$r['logo'] = "{$image_dir}/linux_icon.png";
			} else {
				// Display standard FreeBSD icon
				$r['logo'] = "{$image_dir}/bsd_icon.png";
			}
		}

		$result[] = $r;
	}

	// Save to cache
	save_jail_info_cache($result);

	return $result;
}
?>