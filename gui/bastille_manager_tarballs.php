<?php
/*
	bastille_manager_tarballs.php

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

$gt_selection_delete_confirm = gtext('Do you really want to destroy this base release?');
$pgtitle = [gtext("Extensions"), gtext('Bastille'),gtext('Releases')];

// --- BACKGROUND JOB PROCESSING (download / destroy) ---
// The job runs detached from the HTTP request, writing its output to a log file.
// The page only polls that log, so the user can leave and come back at any time.
$job_dir = "{$rootfolder}/log/release_job";
$job_meta = "{$job_dir}/job.json";
$job_log = "{$job_dir}/job.log";
$job_pid = "{$job_dir}/job.pid";
$job_exit = "{$job_dir}/job.exit";

function job_read_meta() {
    global $job_meta;
    $m = is_file($job_meta) ? json_decode((string)file_get_contents($job_meta), true) : null;
    return is_array($m) ? $m : null;
}

// Returns running|done|failed|interrupted|none
function job_state() {
    global $job_pid, $job_exit;
    if (job_read_meta() === null) return 'none';
    if (is_file($job_exit)) return ((int)trim((string)file_get_contents($job_exit)) === 0) ? 'done' : 'failed';
    $pid = is_file($job_pid) ? (int)trim((string)file_get_contents($job_pid)) : 0;
    if ($pid > 0 && (function_exists('posix_kill') ? posix_kill($pid, 0) : exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $o, $rc) !== false && $rc === 0)) return 'running';
    // A freshly started job may not have written its pid yet.
    $m = job_read_meta();
    if (time() - (int)($m['started'] ?? 0) < 5) return 'running';
    return 'interrupted';
}

function job_json($data) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// --- Partial downloads (staged in <cache>/.resume/<release>/) ---
function bastille_cachedir() {
    global $config_path;
    if (empty($config_path) || !is_file($config_path)) return '';
    return trim((string)shell_exec('/bin/sh -c ' . escapeshellarg('. ' . escapeshellarg($config_path) . ' 2>/dev/null; echo "$bastille_cachedir"')));
}

function partial_list() {
    $cache = bastille_cachedir();
    if ($cache === '' || !is_dir("{$cache}/.resume")) return [];
    $out = [];
    foreach (glob("{$cache}/.resume/*", GLOB_ONLYDIR) ?: [] as $dir) {
        $rel = basename($dir);
        if (!preg_match('/^[0-9]+\.[0-9]+-RELEASE$/', $rel)) continue;
        $have = 0;
        $total = 0;
        foreach (glob("{$dir}/*.txz") ?: [] as $f) $have += (int)filesize($f);
        foreach (glob("{$dir}/*.size") ?: [] as $f) $total += (int)trim((string)file_get_contents($f));
        if ($have === 0) continue; // empty staging dir, nothing worth resuming
        $out[] = ['release' => $rel, 'have' => $have, 'total' => $total];
    }
    return $out;
}

function partial_discard($rel) {
    $cache = bastille_cachedir();
    if ($cache === '' || !preg_match('/^[0-9]+\.[0-9]+-RELEASE$/', $rel)) return false;
    $dir = "{$cache}/.resume/{$rel}";
    if (is_dir($dir)) exec('/bin/rm -rf ' . escapeshellarg($dir));
    @rmdir("{$cache}/.resume"); // only succeeds when empty
    return true;
}

function partial_box_html() {
    $html = '';
    foreach (partial_list() as $p) {
        $mb = fn($b) => intdiv((int)$b, 1048576);
        $size = $p['total'] > 0 ? sprintf('%d / %d MB', $mb($p['have']), $mb($p['total'])) : sprintf('%d MB', $mb($p['have']));
        $rel = htmlspecialchars($p['release']);
        $html .= '<div class="partial-row" style="padding:5px;">'
            . '<b>' . gtext('Incomplete download') . ':</b> ' . $rel . ' (' . $size . ') '
            . '<input type="button" class="formbtn partial-btn" value="' . gtext('Resume') . '" onclick="resumePartial(\'' . $rel . '\')" /> '
            . '<input type="button" class="formbtn partial-btn" value="' . gtext('Discard') . '" onclick="discardPartial(\'' . $rel . '\')" />'
            . '</div>';
    }
    return $html;
}

if (isset($_REQUEST['action']) && in_array($_REQUEST['action'], ['start', 'status', 'discard'], true)) {
    // Do not hold the PHP session lock, otherwise the rest of the WebGUI would block.
    session_write_close();

    if ($_REQUEST['action'] === 'status') {
        $state = job_state();
        $meta = job_read_meta();
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $text = '';
        $size = is_file($job_log) ? (int)filesize($job_log) : 0;
        if ($size > $offset && ($fh = fopen($job_log, 'rb'))) {
            fseek($fh, $offset);
            $text = (string)fread($fh, 262144);
            fclose($fh);
        }
        job_json([
            'state' => $state,
            'mode' => $meta['mode'] ?? '',
            'release' => $meta['release'] ?? '',
            'started' => $meta['started'] ?? 0,
            'text' => $text,
            'offset' => $offset + strlen($text),
        ]);
    }

    if ($_REQUEST['action'] === 'discard') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') job_json(['ok' => false, 'error' => 'POST required']);
        if (job_state() === 'running') job_json(['ok' => false, 'error' => gtext('Another operation is already running.')]);
        job_json(['ok' => partial_discard($_POST['release'] ?? '')]);
    }

    // action=start (POST only)
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') job_json(['ok' => false, 'error' => 'POST required']);
    if (job_state() === 'running') job_json(['ok' => false, 'error' => gtext('Another operation is already running.')]);

    $mode = $_POST['mode'] ?? '';
    $release = $_POST['release'] ?? '';
    if (!in_array($mode, ['bootstrap', 'destroy'], true)) job_json(['ok' => false, 'error' => 'Invalid mode']);
    if ($mode === 'bootstrap' && !preg_match('/^[0-9]+\.[0-9]+-RELEASE$/', $release)) job_json(['ok' => false, 'error' => 'Invalid release']);
    if ($mode === 'destroy') {
        if (!preg_match('/^([0-9]+\.[0-9]+-RELEASE|Debian[0-9]{1,2}|Ubuntu_[0-9]{4})$/', $release)) job_json(['ok' => false, 'error' => 'Invalid release']);
        if (!is_dir("{$rootfolder}/releases/{$release}")) job_json(['ok' => false, 'error' => sprintf(gtext('%s base does not exist, nothing to do.'), $release)]);
        if (exec("/usr/bin/grep -wo " . escapeshellarg($release) . " {$jail_dir}/*/fstab 2>/dev/null")) {
            job_json(['ok' => false, 'error' => sprintf(gtext('%s base appears to have containers child.'), $release)]);
        }
    }
    if ($mode === 'bootstrap' && get_state_zfs() == "Invalid ZFS configuration") {
        job_json(['ok' => false, 'error' => gtext("Cannot bootstrap with an invalid ZFS configuration.")]);
    }

    // Housekeeping: drop partial downloads of other releases untouched for 7+ days,
    // and the staged partial of a release that is being destroyed.
    if ($mode === 'bootstrap') {
        $cache = bastille_cachedir();
        if ($cache !== '' && is_dir("{$cache}/.resume")) {
            exec('/usr/bin/find ' . escapeshellarg("{$cache}/.resume") . ' -mindepth 1 -maxdepth 1 -type d -mtime +7 ! -name '
                . escapeshellarg($release) . ' -exec /bin/rm -rf {} + 2>/dev/null');
        }
    } else {
        partial_discard($release);
    }

    if (!is_dir($job_dir)) @mkdir($job_dir, 0755, true);
    foreach ([$job_log, $job_pid, $job_exit, "{$job_dir}/job.sh"] as $f) @unlink($f);

    $rel = escapeshellarg($release);
    if ($mode === 'bootstrap') {
        $archives = trim('base ' . (($_POST['lib32'] ?? '') === 'true' ? 'lib32 ' : '')
            . (($_POST['ports'] ?? '') === 'true' ? 'ports ' : '') . (($_POST['src'] ?? '') === 'true' ? 'src' : ''));
        $sysrc_set = $sysrc_restore = '';
        if (!empty($config_path)) {
            // sysrc adds the quotes itself; never embed them in the value.
            $restore_val = preg_match('/^[A-Za-z0-9 ]+$/', trim($default_distfiles)) ? trim($default_distfiles) : 'base';
            $sysrc_set = '/usr/sbin/sysrc -f ' . escapeshellarg($config_path) . ' ' . escapeshellarg("bastille_bootstrap_archives={$archives}") . " >/dev/null\n";
            $sysrc_restore = '/usr/sbin/sysrc -f ' . escapeshellarg($config_path) . ' ' . escapeshellarg("bastille_bootstrap_archives={$restore_val}") . " >/dev/null\n";
        }
        // Bastille's own fetch does not resume: a partial file stays in the cache and
        // then fails the checksum. So the archives are pre-downloaded here with
        // "fetch -r" (restart at the interrupted byte); bastille then finds them in
        // the cache, verifies the checksum and extracts. Failed or stalled transfers
        // are retried, each attempt continuing from what is already on disk.
        $conf_arg = escapeshellarg($config_path ?: '');
        $body = <<<SH
{$sysrc_set}prefetch() (
    [ -n {$conf_arg} ] && . {$conf_arg} 2>/dev/null
    [ -z "\$bastille_cachedir" ] && return 0
    url="\${bastille_url_freebsd}\$(/usr/bin/uname -m)/\$(/usr/bin/uname -p)/{$release}"
    real="\$bastille_cachedir/{$release}"
    # Staged outside the release cache dir: a failed first bootstrap makes bastille rm -rf it.
    dir="\$bastille_cachedir/.resume/{$release}"
    # First ever bootstrap: no cache dir yet, let bastille create its datasets itself.
    [ -d "\$bastille_cachedir" ] || return 0
    /bin/mkdir -p "\$dir" || return 1
    # Create the per-release cache dir the way bastille does (a ZFS dataset when ZFS is
    # enabled); a plain directory here would stop bastille from creating the dataset.
    if [ ! -d "\$real" ]; then
        case "\$bastille_zfs_enable" in
            [Yy][Ee][Ss]|[Tt][Rr][Uu][Ee]|[Oo][Nn]|1) zfs_on=1;;
            *) zfs_on=0;;
        esac
        if [ \$zfs_on -eq 1 ] && [ -n "\$bastille_zfs_zpool" ]; then
            /sbin/zfs create \$bastille_zfs_options -o mountpoint="\$real" "\$bastille_zfs_zpool/\$bastille_zfs_prefix/cache/{$release}" || return 1
        else
            /bin/mkdir -p "\$real" || return 1
        fi
    fi
    for a in {$archives}; do
        f="\$dir/\$a.txz"
        remote=\$(/usr/bin/fetch -T 30 -s "\$url/\$a.txz" 2>/dev/null)
        case "\$remote" in
            ''|*[!0-9]*)
                # Size unknown (e.g. EOL release on another mirror): let bastille handle it.
                echo "[Size of \$a.txz unknown at \$url, leaving it to bastille (no resume)]"
                continue;;
        esac
        echo "\$remote" > "\$dir/\$a.size"
        have=\$(/usr/bin/stat -f %z "\$f" 2>/dev/null || echo 0)
        [ "\$have" -eq "\$remote" ] && continue
        [ "\$have" -gt "\$remote" ] && /bin/rm -f "\$f" && have=0
        [ "\$have" -gt 0 ] && echo "[Resuming \$a.txz at \$have of \$remote bytes]"
        /usr/bin/fetch -q -r -T 30 -o "\$f" "\$url/\$a.txz" &
        fp=\$!
        # fetch only draws its progress bar on a tty, so report progress from the file size.
        while kill -0 \$fp 2>/dev/null; do
            cur=\$(/usr/bin/stat -f %z "\$f" 2>/dev/null || echo 0)
            printf '\r%s: %d / %d MB (%d%%)    ' "\$a.txz" \$((cur / 1048576)) \$((remote / 1048576)) \$((cur * 100 / remote))
            sleep 1
        done
        wait \$fp
        frc=\$?
        [ \$frc -eq 0 ] || { printf '\n'; return 1; }
        printf '\r%s: %d / %d MB (100%%)    \n' "\$a.txz" \$((remote / 1048576)) \$((remote / 1048576))
    done
    for a in {$archives}; do
        [ -f "\$dir/\$a.txz" ] && { /bin/ln -f "\$dir/\$a.txz" "\$real/\$a.txz" 2>/dev/null || /bin/cp -f "\$dir/\$a.txz" "\$real/\$a.txz"; }
    done
    return 0
)
cleanup() (
    [ -n {$conf_arg} ] && . {$conf_arg} 2>/dev/null
    [ -n "\$bastille_cachedir" ] && /bin/rm -rf "\$bastille_cachedir/.resume/{$release}"
    /bin/rmdir "\$bastille_cachedir/.resume" 2>/dev/null
)
max=10
attempt=1
bfail=0
while :; do
    if prefetch; then
        /bin/echo "Y" | /usr/local/bin/bastille bootstrap {$rel}
        rc=\$?
        # A failure of bastille itself (not the transfer) is rarely transient.
        [ \$rc -ne 0 ] && bfail=\$((bfail + 1))
        [ \$bfail -ge 2 ] && break
    else
        rc=1
    fi
    [ \$rc -eq 0 ] && { cleanup; break; }
    [ \$attempt -ge \$max ] && break
    attempt=\$((attempt + 1))
    echo
    echo "[Attempt failed. Retrying \$attempt/\$max in 10s...]"
    sleep 10
done
{$sysrc_restore}
SH;
    } else {
        // Already confirmed in the browser and checked for child jails above; bastille asks y/n on stdin.
        $body = "/bin/echo y | /usr/local/bin/bastille destroy {$rel}\nrc=\$?\n";
    }

    // The exit code is written from inside the subshell (the script itself must survive the request).
    $script = "#!/bin/sh\necho \$\$ > " . escapeshellarg($job_pid) . "\n"
        . "(\n{$body}\n"
        . "echo\nif [ \$rc -eq 0 ]; then echo '[Completed]'; else echo \"[Failed (exit code \$rc)]\"; fi\n"
        . "echo \$rc > " . escapeshellarg($job_exit) . "\n) < /dev/null >> " . escapeshellarg($job_log) . " 2>&1\n";
    file_put_contents("{$job_dir}/job.sh", $script);
    file_put_contents($job_meta, json_encode(['mode' => $mode, 'release' => $release, 'started' => time()]));
    exec('/usr/bin/nohup /bin/sh ' . escapeshellarg("{$job_dir}/job.sh") . ' > /dev/null 2>&1 &');
    job_json(['ok' => true]);
}

$sphere_array = [];
$sphere_record = [];
$pconfig = [];

function get_rel_list() {
	global $rootfolder;
	global $jail_dir;
	$result = [];
	if (is_dir("{$rootfolder}/releases")):
		$entries = preg_grep('/^[0-9]+\.[0-9]+\-RELEASE|(Debian[0-9]{1,2}$)|(Ubuntu_[0-9]{4}$)/', scandir("{$rootfolder}/releases"));
		foreach($entries as $entry):
		$a = preg_split('/\t/',$entry);
		$r = [];
		$name = $a[0];
		if(preg_match('/^[0-9]+\.[0-9]+\-RELEASE|(Debian[0-9]{1,2}$)|(Ubuntu_[0-9]{4}$)/', $name, $m)):
			$r['name'] = $m[0];
		else:
			$r['name'] = 'unknown';
		endif;
		$r['relname'] = $r['name'];
		$result[] = $r;
		endforeach;
	endif;
	return $result;
}

$zfs_status = get_state_zfs();
if($zfs_status == "Invalid ZFS configuration"):
	// Warning if invalid ZFS configuration.
	$input_errors[] = gtext("WARNING: Invalid ZFS configuration detected.");
endif;

$rel_list = get_rel_list();
$sphere_array = $rel_list;

if ($linux_compat_support == "YES"):
	$a_action = [
		'14.4-RELEASE' => gettext('14.4-RELEASE'),
		'14.3-RELEASE' => gettext('14.3-RELEASE'),
		'14.2-RELEASE' => gettext('14.2-RELEASE'),
		'14.1-RELEASE' => gettext('14.1-RELEASE'),
		'14.0-RELEASE' => gettext('14.0-RELEASE'),
		'13.5-RELEASE' => gettext('13.5-RELEASE'),
		'13.4-RELEASE' => gettext('13.4-RELEASE'),
		// Linux base release bootstrap is allowed from command-line.
		//'ubuntu-noble' => gettext('Ubuntu-noble'),
		//'ubuntu-jammy' => gettext('Ubuntu-Jammy'),
		//'ubuntu-focal' => gettext('Ubuntu-Focal'),
		//'ubuntu-bionic' => gettext('Ubuntu-Bionic'),
		//'debian-bookworm' => gettext('Debian-Bookworm'),
		//'debian-bullseye' => gettext('Debian-Bullseye'),
		//'debian-buster' => gettext('Debian-Buster'),
		//'debian-stretch' => gettext('Debian-Stretch'),
	];
else:
	$a_action = [
		'14.4-RELEASE' => gettext('14.4-RELEASE'),
		'14.3-RELEASE' => gettext('14.3-RELEASE'),
		'14.2-RELEASE' => gettext('14.2-RELEASE'),
		'14.1-RELEASE' => gettext('14.1-RELEASE'),
		'14.0-RELEASE' => gettext('14.0-RELEASE'),
		'13.5-RELEASE' => gettext('13.5-RELEASE'),
		'13.4-RELEASE' => gettext('13.4-RELEASE'),
	];
endif;

if($_POST):
	unset($input_errors);
	unset($errormsg);
	unset($savemsg);
	$pconfig = $_POST;
	if(isset($_POST['Cancel']) && $_POST['Cancel']):
		header('Location: bastille_manager_gui.php');
		exit;
	endif;

	if (isset($_POST['Download']) && $_POST['Download']):
		$lib32 = "";
		$ports = "";
		$src = "";
		$get_release = $pconfig['release_item'];
		$check_release = ("{$rootfolder}/releases/{$get_release}");
		$cmd = sprintf('/bin/echo "Y" | /usr/local/bin/bastille bootstrap %1$s > %2$s 2>&1', $get_release, $logevent);
		$base_mandatory = "base";
		$zfs_status = get_state_zfs();

		//unset($lib32,$ports,$src);
		if (isset($_POST['lib32'])):
			$lib32 = "lib32";
		endif;
		if (isset($_POST['ports'])):
			$ports = "ports";
		endif;
		if (isset($_POST['src'])):
			$src = "src";
		endif;
		$opt_tarballs = "$lib32 $ports $src";

		// Download a FreeBSD base release.
		if ($_POST['Download']):
			if($zfs_status == "Invalid ZFS configuration"):
				// Abort bootstrap if invalid ZFS configuration.
				$input_errors[] = gtext("Cannot bootstrap with an invalid ZFS configuration.");
			else:
				$savemsg = "";
				$errormsg = "";
				if ($opt_tarballs):
					if ($config_path):
						// Override default distfiles once.
						exec("/usr/sbin/sysrc -f {$config_path} bastille_bootstrap_archives=\"$base_mandatory $opt_tarballs\"");
					endif;
				endif;
				$return_val = 0;
				$output = [];
				exec($cmd,$output,$return_val);
                if($return_val == 0):
                      $ausgabe = "";
                      if (file_exists($logevent)) {
                          $ausgabe = file_get_contents($logevent);
                      }
                      $ausgabe = preg_replace('/\e[[][A-Za-z0-9];?[0-9]*m?/', '', $ausgabe);
                      $ausgabe = trim($ausgabe);
                      if (!empty($ausgabe)) {
                          $savemsg .= str_replace("\n", "<br />", htmlspecialchars($ausgabe)) . "<br />";
                      } else {
                          $savemsg .= sprintf(gtext('%s Bootstrap process completed successfully.'), $get_release) . "<br />";
                      }
                      // Set back default distfiles.
                      exec("/usr/sbin/sysrc -f {$config_path} bastille_bootstrap_archives=\"$default_distfiles\"");
                else:
                      $errormsg .= sprintf(gtext('%s Failed to download and/or extract release base.'),$get_release);
                endif;

			endif;
		endif;
	endif;

	if (isset($_POST['Destroy']) && $_POST['Destroy']):
		if ($_POST['Destroy']):
			$get_release = $pconfig['release_item'];
			if($get_release == 'ubuntu-bionic'):
				$get_release = "Ubuntu_1804";
			elseif($get_release == 'ubuntu-focal'):
				$get_release = "Ubuntu_2004";
			elseif($get_release == 'ubuntu-jammy'):
				$get_release = "Ubuntu_2204";
			elseif($get_release == 'debian-stretch'):
				$get_release = "Debian9";
			elseif($get_release == 'debian-buster'):
				$get_release = "Debian10";
			elseif($get_release == 'debian-bullseye'):
				$get_release = "Debian11";
			elseif($get_release == 'debian-bookworm'):
				$get_release = "Debian12";
			endif;

			$check_release = ("{$rootfolder}/releases/{$get_release}");
			$check_used = exec("/usr/bin/grep -wo {$get_release} {$jail_dir}/*/fstab 2>/dev/null");

			$cmd = ("/usr/local/bin/bastille destroy {$get_release}");

			if (!file_exists($check_release)):
				// FreeBSD base release check.
				$savemsg .= sprintf(gtext('%s base does not exist, nothing to do.'),$get_release);
			else:
				// Do not delete base releases with containers child.
				if ($check_used):
					$errormsg .= sprintf(gtext('%s base appears to have containers child.'),$get_release);
				else:
					// Delete the FreeBSD base release/directory.
					if ($_POST['Destroy']):
						unset($output,$retval);mwexec2($cmd,$output,$retval);
						if($retval == 0):
							//$savemsg .= sprintf(gtext('%s base deleted successfully.'),$get_release);
							header('Location: bastille_manager_tarballs.php');
						else:
							$errormsg .= sprintf(gtext('%s failed to delete.'),$get_release);
						endif;
					endif;
				endif;
			endif;
		endif;
	endif;
endif;

include 'fbegin.inc';
?>

<script type="text/javascript">
const JOB_URL = 'bastille_manager_tarballs.php';
let jobOffset = 0, jobRaw = '', jobTimer = null, jobPolling = false, sawRunning = false;
let lastOut = null, logNode = null;

function el(id) { return document.getElementById(id); }

function renderLog() {
    // Strip ANSI colors and emulate carriage returns (fetch progress lines overwrite themselves).
    const clean = jobRaw.replace(/\x1b\[[0-9;]*[A-Za-z]/g, '');
    let out = '';
    for (const ch of clean) {
        if (ch === '\r') {
            const nl = out.lastIndexOf('\n');
            out = nl !== -1 ? out.substring(0, nl + 1) : '';
        } else out += ch;
    }
    if (out === lastOut) return; // nothing visible changed: do not touch the DOM (avoids flicker)
    lastOut = out;
    const area = el('log-area');
    const atBottom = area.scrollHeight - area.scrollTop - area.clientHeight < 30;
    // Reuse one text node instead of rebuilding the content on every update.
    if (!logNode) { area.textContent = ''; logNode = document.createTextNode(''); area.appendChild(logNode); }
    logNode.data = out;
    if (atBottom) area.scrollTop = area.scrollHeight;
}

function setBusy(busy) {
    el('btn-download').disabled = el('btn-destroy').disabled = busy;
    document.querySelectorAll('.partial-btn').forEach(b => b.disabled = busy);
}

function setStatus(text) { const e = el('job-status'); if (e.textContent !== text) e.textContent = text; }

async function pollJob() {
    if (jobPolling) return;
    jobPolling = true;
    try {
        const r = await fetch(JOB_URL + '?action=status&offset=' + jobOffset, { cache: 'no-store' });
        const d = await r.json();
        if (d.state === 'none') { stopPolling(); setBusy(false); return; }
        el('log-container').style.display = 'block';
        const label = d.mode === 'bootstrap' ? '<?=gtext("Downloading");?>' : '<?=gtext("Destroying");?>';
        if (d.text) { jobRaw += d.text; jobOffset = d.offset; renderLog(); }
        if (d.state === 'running') {
            sawRunning = true;
            setBusy(true);
            setStatus(label + ' ' + d.release + ' ...');
        } else {
            stopPolling();
            setBusy(false);
            const isDl = d.mode === 'bootstrap';
            const msgs = {
                done: '<?=gtext("Completed");?>',
                failed: isDl ? '<?=gtext("Failed. Press Download again to retry; files already downloaded are reused.");?>' : '<?=gtext("Failed.");?>',
                interrupted: isDl ? '<?=gtext("Interrupted. Press Download again to resume.");?>' : '<?=gtext("Interrupted.");?>'
            };
            setStatus(d.release + ': ' + msgs[d.state]);
            if (sawRunning) { sawRunning = false; refreshInstalled(); }
        }
    } catch (e) {
        // Network hiccup: the job keeps running on the server, keep polling.
        setStatus('<?=gtext("Connection lost, retrying...");?>');
    } finally {
        jobPolling = false;
    }
}

// Refresh only the "Installed Base" list (no full page reload, so the log does not flicker).
async function refreshInstalled() {
    try {
        const r = await fetch(JOB_URL, { cache: 'no-store' });
        const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
        const newHead = doc.querySelector('table.area_data_settings thead');
        const oldHead = document.querySelector('table.area_data_settings thead');
        if (newHead && oldHead) oldHead.innerHTML = newHead.innerHTML;
        const newTok = doc.querySelector('input[name="authtoken"]');
        const oldTok = document.querySelector('input[name="authtoken"]');
        if (newTok && oldTok) oldTok.value = newTok.value;
        const newBox = doc.getElementById('partial-box');
        const oldBox = el('partial-box');
        if (newBox && oldBox) oldBox.innerHTML = newBox.innerHTML;
    } catch (e) { /* the list refreshes on the next page load */ }
}

function startPolling() {
    if (!jobTimer) jobTimer = setInterval(pollJob, 1500);
    pollJob();
}
function stopPolling() { clearInterval(jobTimer); jobTimer = null; }

async function runBastilleAction(mode) {
    const release = document.getElementsByName('release_item')[0].value;
    if (!release) return;
    if (mode === 'destroy' && !confirm('<?=$gt_selection_delete_confirm;?>')) return;

    setBusy(true);
    const body = new FormData();
    const authToken = document.querySelector('input[name="authtoken"]')?.value || '';
    if (authToken) body.append('authtoken', authToken);
    body.append('action', 'start');
    body.append('mode', mode);
    body.append('release', release);
    body.append('lib32', document.getElementsByName('lib32')[0].checked);
    body.append('ports', document.getElementsByName('ports')[0].checked);
    body.append('src', document.getElementsByName('src')[0].checked);
    try {
        const r = await fetch(JOB_URL, { method: 'POST', body: body });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json();
        if (!d.ok) { alert(d.error); setBusy(false); return; }
        jobOffset = 0; jobRaw = '';
        sawRunning = true; // finished jobs started here refresh the page, even if they end between polls
        el('log-container').style.display = 'block';
        renderLog();
        startPolling();
    } catch (e) {
        alert(e);
        setBusy(false);
    }
}

function resumePartial(release) {
    document.getElementsByName('release_item')[0].value = release;
    runBastilleAction('bootstrap');
}

async function discardPartial(release) {
    if (!confirm('<?=gtext("Discard this incomplete download?");?>')) return;
    const body = new FormData();
    body.append('authtoken', document.querySelector('input[name="authtoken"]')?.value || '');
    body.append('action', 'discard');
    body.append('release', release);
    try {
        const r = await fetch(JOB_URL, { method: 'POST', body: body });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json();
        if (!d.ok) alert(d.error || 'Error');
    } catch (e) { alert(e); }
    refreshInstalled();
}

// On page load, re-attach to a running job or show the result of the last one.
document.addEventListener('DOMContentLoaded', startPolling);
</script>

<?php
$document = new co_DOMDocument();
$document->
	add_area_tabnav()->
		push()->
		add_tabnav_upper()->
			ins_tabnav_record('bastille_manager_gui.php',gettext('Containers'),gettext('Reload page'),true)->
			ins_tabnav_record('bastille_manager_info.php',gettext('Information'),gettext('Reload page'),true)->
			ins_tabnav_record('bastille_manager_maintenance.php',gettext('Maintenance'),gettext('Reload page'),true)->
		pop()->add_tabnav_lower()->
			ins_tabnav_record('bastille_manager_config.php',gettext('Bastille Configuration'),gettext('Reload page'),true)->
			ins_tabnav_record('bastille_manager_tarballs.php',gettext('Base Releases'),gettext('Reload page'),true);
$document->render();
?>

<form action="bastille_manager_tarballs.php" method="post" name="iform" id="iform">
    <table id="area_data"><tbody><tr><td id="area_data_frame">

        <div id="log-container" style="display:none; margin-bottom:15px;">
            <?php
                print_info_box('<div id="job-status" style="font-weight:bold; padding:5px;"></div><div id="log-area" style="text-align: left; white-space: pre-wrap; font-family: monospace; font-size: 11px; padding: 5px; max-height: 400px; overflow-y: auto;"></div>');
            ?>
        </div>

        <table class="area_data_settings">
           <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
           <thead>
              <?php
              if (!empty($sphere_array)):
                 html_titleline2(gettext('FreeBSD/Linux Base Release Installed'));
                 foreach ($sphere_array as $sphere_record):
                    html_text2('releases', gettext('Installed Base:'), htmlspecialchars($sphere_record['relname']));
                 endforeach;
              endif;
              html_separator();
              html_titleline2(gettext('FreeBSD Base Release Download'));
              ?>
           </thead>
           <tbody>
              <?php
              html_combobox2('release_item', gettext('Select Base Release'), '', $a_action, '', true, false);
              html_titleline2(gettext('Optional Distfiles (Overrides config, has no effect on Linux Releases)'));
              html_checkbox2('lib32', gettext('32-bit Compatibility'), false, 'lib32.txz', '', false);
              html_checkbox2('ports', gettext('Ports tree'), false, 'ports.txz', '', false);
              html_checkbox2('src', gettext('System source tree'), false, 'src.txz', '', false);
              ?>
           </tbody>
        </table>

        <div id="submit">
           <input name="Download" id="btn-download" type="button" class="formbtn" value="<?=gtext("Download");?>" onclick="runBastilleAction('bootstrap')" />
           <input name="Destroy" id="btn-destroy" type="button" class="formbtn" value="<?=gtext("Destroy");?>" onclick="runBastilleAction('destroy')" />
           <input name="Cancel" type="submit" class="formbtn" value="<?=gtext("Cancel");?>" />
        </div>

        <div id="partial-box" style="margin-top:10px;"><?=partial_box_html();?></div>

    </td></tr></tbody></table>
    <?php include 'formend.inc'; ?>
</form>

<?php include 'fend.inc'; ?>