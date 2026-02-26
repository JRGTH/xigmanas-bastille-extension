<?php
/*
	bastille_manager_gui.php

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
require_once 'bastille_manager-lib.inc';

$img_path = [
	'add' => 'images/add.png',
	'mod' => 'images/edit.png',
	'del' => 'images/delete.png',
	'loc' => 'images/locked.png',
	'unl' => 'images/unlocked.png',
	'mai' => 'images/maintain.png',
	'inf' => 'images/info.png',
	'ena' => 'images/status_enabled.png',
	'dis' => 'images/status_disabled.png',
	'mup' => 'images/up.png',
	'mdn' => 'images/down.png'
];

// --- START AUTO-REFRESH LOGIC ---
if (isset($_GET['action']) && $_GET['action'] === 'refresh_table') {
    error_reporting(0);
    ini_set('display_errors', 0);
    ob_start();

    // Fetch fresh data
    $jls_list = [];
    if (function_exists('get_jail_infos')) {
        $jls_list = get_jail_infos();
    }

    // Return JSON
    ob_clean();
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    echo json_encode(['success' => true, 'jails' => $jls_list ?: []]);
    exit;
}
// --- END AUTO-REFRESH LOGIC ---

function mwexec_parallel($commands) {
	$processes = [];
	$results = [];

	foreach ($commands as $key => $command) {
		$descriptors = [
			0 => ['pipe', 'r'],  // stdin
			1 => ['pipe', 'w'],  // stdout
			2 => ['pipe', 'w']   // stderr
		];

		$process = proc_open($command, $descriptors, $pipes);

		if (is_resource($process)) {
			stream_set_blocking($pipes[1], false);
			stream_set_blocking($pipes[2], false);

			$processes[$key] = [
				'process' => $process,
				'pipes' => $pipes,
				'command' => $command
			];
		}
	}

	$timeout = 30;
	$start_time = time();

	foreach ($processes as $key => $proc) {
		$elapsed = time() - $start_time;
		if ($elapsed < $timeout) {
			$stdout = stream_get_contents($proc['pipes'][1]);
			$stderr = stream_get_contents($proc['pipes'][2]);

			fclose($proc['pipes'][0]);
			fclose($proc['pipes'][1]);
			fclose($proc['pipes'][2]);

			$return_code = proc_close($proc['process']);

			$results[$key] = [
				'return_code' => $return_code,
				'stdout' => $stdout,
				'stderr' => $stderr
			];
		} else {
			proc_terminate($proc['process']);
			proc_close($proc['process']);

			$results[$key] = [
				'return_code' => -1,
				'stdout' => '',
				'stderr' => 'Command timeout'
			];
		}
	}

	return $results;
}

function mwexec_background($command) {
    $command = $command . ' > /dev/null 2>&1 &';
    exec($command);
}

$sphere_scriptname = basename(__FILE__);
$sphere_scriptname_child = 'bastille_manager_util.php';
$sphere_header = 'Location: '.$sphere_scriptname;
$sphere_header_parent = $sphere_header;
$sphere_array = [];
$sphere_record = [];
$checkbox_member_name = 'checkbox_member_array';
$checkbox_member_array = [];
$checkbox_member_record = [];
$gt_record_add = gtext('Create new jail');
$gt_record_mod = gtext('Utilities');
$gt_selection_start = gtext('Start Selected');
$gt_selection_stop = gtext('Stop Selected');
$gt_selection_restart = gtext('Restart Selected');
$gt_selection_autoboot = gtext('Auto-boot Selected');
$gt_record_conf = gtext('Jail Configuration');
$gt_record_inf = gtext('Information');
$gt_selection_start_confirm = gtext('Do you really want to start selected jail(s)?');
$gt_selection_stop_confirm = gtext('Do you want to stop the selected jail(s)?');
$gt_selection_restart_confirm = gtext('Do you want to restart the selected jail(s)?');
$gt_selection_autoboot_confirm = gtext('Do you want to set auto-boot on selected jail(s)?');

$jls_list = get_jail_infos();
$sphere_array = $jls_list;

if(!initial_install_banner()):
	$errormsg = gtext('Bastille Initial Configuration:')
			. ' '
			. '<a href="' . 'bastille_manager_config.php' . '">'
			. gtext('Please check and configure ZFS support option first.')
			. '</a>'
			. '</br>'
			. gtext('Alternatively to skip this step:')
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

if($_POST):
	if(isset($_POST['apply']) && $_POST['apply']):
		$ret = array('output' => [], 'retval' => 0);
		if(!file_exists($d_sysrebootreqd_path)):
			// Process notifications
		endif;
		$savemsg = get_std_save_message($ret['retval']);
		if($ret['retval'] == 0):
			updatenotify_delete($sphere_notifier);
			header($sphere_header);
			exit;
		endif;
		updatenotify_delete($sphere_notifier);
		$errormsg = implode("\n", $ret['output']);
	endif;

	if(isset($_POST['start_selected_jail']) && $_POST['start_selected_jail']):
		$checkbox_member_array = isset($_POST[$checkbox_member_name]) ? $_POST[$checkbox_member_name] : [];
		$commands = [];

		foreach($checkbox_member_array as $checkbox_member_record):
			if(false !== ($index = array_search_ex($checkbox_member_record, $sphere_array, 'jailname'))):
				if(!isset($sphere_array[$index]['protected'])):
					$commands[] = "/usr/local/bin/bastille start {$checkbox_member_record}";
				endif;
			endif;
		endforeach;

		if (!empty($commands)):
			$results = mwexec_parallel($commands);

			$success_count = 0;
			$fail_count = 0;

			foreach ($results as $result):
				if ($result['return_code'] == 0):
					$success_count++;
				else:
					$fail_count++;
				endif;
			endforeach;

			if (function_exists('invalidate_jail_cache')) {
				invalidate_jail_cache();
			}

			if ($fail_count > 0):
				$errormsg = sprintf(gtext("Started %d jail(s), failed %d jail(s)."), $success_count, $fail_count);
			else:
				$savemsg = sprintf(gtext("%d jail(s) started successfully."), $success_count);
			endif;

			header($sphere_header);
		endif;
	endif;

	if(isset($_POST['stop_selected_jail']) && $_POST['stop_selected_jail']):
		$checkbox_member_array = isset($_POST[$checkbox_member_name]) ? $_POST[$checkbox_member_name] : [];
		$commands = [];

		foreach($checkbox_member_array as $checkbox_member_record):
			if(false !== ($index = array_search_ex($checkbox_member_record, $sphere_array, 'jailname'))):
				if(!isset($sphere_array[$index]['protected'])):
					$commands[] = "/usr/local/bin/bastille stop {$checkbox_member_record}";
				endif;
			endif;
		endforeach;

		if (!empty($commands)):
			$results = mwexec_parallel($commands);

			$success_count = 0;
			$fail_count = 0;

			foreach ($results as $result):
				if ($result['return_code'] == 0):
					$success_count++;
				else:
					$fail_count++;
				endif;
			endforeach;

			if (function_exists('invalidate_jail_cache')) {
				invalidate_jail_cache();
			}

			if ($fail_count > 0):
				$errormsg = sprintf(gtext("Stopped %d jail(s), failed %d jail(s)."), $success_count, $fail_count);
			else:
				$savemsg = sprintf(gtext("%d jail(s) stopped successfully."), $success_count);
			endif;

			header($sphere_header);
		endif;
	endif;

	if(isset($_POST['restart_selected_jail']) && $_POST['restart_selected_jail']):
		$checkbox_member_array = isset($_POST[$checkbox_member_name]) ? $_POST[$checkbox_member_name] : [];
		$commands = [];

		foreach($checkbox_member_array as $checkbox_member_record):
			if(false !== ($index = array_search_ex($checkbox_member_record, $sphere_array, 'jailname'))):
				if(!isset($sphere_array[$index]['protected'])):
					$commands[] = "/usr/local/bin/bastille restart {$checkbox_member_record}";
				endif;
			endif;
		endforeach;

		if (!empty($commands)):
			$results = mwexec_parallel($commands);

			$success_count = 0;
			$fail_count = 0;

			foreach ($results as $result):
				if ($result['return_code'] == 0):
					$success_count++;
				else:
					$fail_count++;
				endif;
			endforeach;

			if (function_exists('invalidate_jail_cache')) {
				invalidate_jail_cache();
			}

			if ($fail_count > 0):
				$errormsg = sprintf(gtext("Restarted %d jail(s), failed %d jail(s)."), $success_count, $fail_count);
			else:
				$savemsg = sprintf(gtext("%d jail(s) restarted successfully."), $success_count);
			endif;

			header($sphere_header);
		endif;
	endif;

	if(isset($_POST['autoboot_selected_jail']) && $_POST['autoboot_selected_jail']):
		$checkbox_member_array = isset($_POST[$checkbox_member_name]) ? $_POST[$checkbox_member_name] : [];
		$commands = [];

		foreach($checkbox_member_array as $checkbox_member_record):
			if(false !== ($index = array_search_ex($checkbox_member_record, $sphere_array, 'jailname'))):
				if(!isset($sphere_array[$index]['protected'])):
					$commands[] = "/usr/local/bin/bastille config {$checkbox_member_record} set boot on";
				endif;
			endif;
		endforeach;

		if (!empty($commands)):
			$results = mwexec_parallel($commands);

			$success_count = 0;
			$fail_count = 0;

			foreach ($results as $result):
				if ($result['return_code'] == 0):
					$success_count++;
				else:
					$fail_count++;
				endif;
			endforeach;

			if (function_exists('invalidate_jail_cache')) {
				invalidate_jail_cache();
			}

			if ($fail_count > 0):
				$errormsg = sprintf(gtext("Set autoboot on %d jail(s), failed %d jail(s)."), $success_count, $fail_count);
			else:
				$savemsg = sprintf(gtext("Autoboot set on %d jail(s) successfully."), $success_count);
			endif;

			header($sphere_header);
		endif;
	endif;
endif;

$pgtitle = [gtext("Extensions"), gtext('Bastille'), gtext('Manager')];
include 'fbegin.inc';
?>
<link rel="stylesheet" type="text/css" href="ext/bastille/css/styles.css?v=<?=time();?>">
<script type="text/javascript">
//<![CDATA[
var currentEvtSource = null; // Global variable to track current SSE connection
var refreshAbortController = null; // Controller to abort fetch requests

$(window).on("load", function() {
	// Init action buttons
	$("#start_selected_jail").click(function () {
        stopAutoRefresh(); // Pause for safety
		return confirm('<?=$gt_selection_start_confirm;?>');
	});
	$("#stop_selected_jail").click(function () {
        stopAutoRefresh();
		return confirm('<?=$gt_selection_stop_confirm;?>');
	});
	$("#restart_selected_jail").click(function () {
        stopAutoRefresh();
		return confirm('<?=$gt_selection_restart_confirm;?>');
	});
	$("#autoboot_selected_jail").click(function () {
        stopAutoRefresh();
    	return confirm('<?=$gt_selection_autoboot_confirm;?>');
    });
    // Disable action buttons.
    disableactionbuttons(true);
    $("#iform").submit(function() { spinner(); });
    $(".spin").click(function() { spinner(); });

	// Attempt to load the previously saved interval
	var savedInterval = localStorage.getItem('bastille_refresh_interval');
    if (savedInterval !== null) {
        $("#refresh-interval").val(savedInterval);
        autoRefresh.interval = parseInt(savedInterval);
    }
	// --- REFRESH INIT
    if (localStorage.getItem('bastille_show_refresh_button') === 'true') {
        $("#refresh-controls").show();
        startAutoRefresh();
    }

    // Force update if web-terminal button is enabled to show it immediately
    if (localStorage.getItem('bastille_show_web_terminal_button') === 'true') {
        updateJailTable();
    }

    $("#refresh-now").click(function() {
        updateJailTable();
    });

    // save interval value in local storage
    $("#refresh-interval").change(function() {
        var val = parseInt($(this).val());
        localStorage.setItem('bastille_refresh_interval', val);
        stopAutoRefresh();
        if (val > 0) {
            autoRefresh.interval = val;
            startAutoRefresh();
        }
    });

    initSimpleResize();

    $(document).on('click', "input[name='<?=$checkbox_member_name;?>[]']", function() {
        controlactionbuttons(this, '<?=$checkbox_member_name;?>[]');
    });
    // Close web-terminal modal
    $("#web-terminal-close").click(function() {
        $("#web-terminal-modal").hide();
        $("#web-terminal-iframe-container").empty(); // Destroy iframe completely
    });

    // Pop-out web-terminal button
    $("#web-terminal-popout").click(function(e) {
        e.preventDefault();
        var jailname = $(this).data('jail');
        if (jailname) {
            // Close modal first
            $("#web-terminal-close").click();
            // Open new tab with direct web-terminal URL
            // We use the same backend script, which will launch a NEW ttyd instance
            window.open('bastille_manager_web_terminal.php?jailname=' + encodeURIComponent(jailname), '_blank');
        }
    });

    //TODO Fullscreen web-terminal button
    $("#web-terminal-fullscreen").click(function() {
        $("#web-terminal-content").toggleClass('fullscreen');
    });

    // Todo Close modals with Escape key
    $(document).keyup(function(e) {
        if (e.key === "Escape") {
            if ($("#web-terminal-modal").is(":visible")) {
                $("#web-terminal-close").click();
            }
        }
    });
});

function disableactionbuttons(ab_disable) {
	$("#start_selected_jail").prop("disabled", ab_disable);
	$("#stop_selected_jail").prop("disabled", ab_disable);
	$("#restart_selected_jail").prop("disabled", ab_disable);
	$("#autoboot_selected_jail").prop("disabled", ab_disable);
}

function controlactionbuttons(ego, triggerbyname) {
    // Use jQuery selector to count checked checkboxes directly
    var $checkedCheckboxes = $("input[name='" + triggerbyname + "']:checked");
    var ab_disable = ($checkedCheckboxes.length === 0); // If no checkboxes are checked, disable buttons
    disableactionbuttons(ab_disable);
}

// --- WebTerminal LOGIC ---
function openWebTerminal(jailname) {
    // Store jailname in the popout button data
    $("#web-terminal-popout").data('jail', jailname);

    // Show loading or something?
    fetch('bastille_manager_web_terminal.php?jailname=' + encodeURIComponent(jailname) + '&format=json')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.text(); // Get raw text to debug
        })
        .then(text => {
            try {
                const data = JSON.parse(text);
                if (data.success) {
                    $("#web-terminal-title").text(jailname);

                    // Create iframe dynamically
                    var $container = $("#web-terminal-iframe-container");
                    $container.empty();
                    var $iframe = $('<iframe>', {
                        id: 'web-terminal-iframe',
                        src: data.url,
                        frameborder: 0
                    });

                    $iframe.on('load', function() {
                        this.contentWindow.focus();
                        $(this).focus();
                    });

                    $container.append($iframe);
                    $("#web-terminal-modal").show();

                } else {
                    alert("Error launching web-terminal: " + data.message);
                }
            } catch (e) {
                console.error("Failed to parse JSON:", text);
                alert("Received invalid response from server. Check server logs for details.");
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert("Failed to connect to web-terminal backend.");
        });
}

// --- AUTO-REFRESH JS ---
var autoRefresh = {
    enabled: true,
    interval: 30000,
    timerId: null,
    lastUpdate: Date.now(),
    isUpdating: false,
    selectedJails: []
};

function updateJailTable() {
    if (autoRefresh.isUpdating) return;
    autoRefresh.isUpdating = true;

    // Activar spinner
    $("#refresh-spinner").show();

    // Abort previous request if any
    if (refreshAbortController) {
        refreshAbortController.abort();
    }
    refreshAbortController = new AbortController();
    const signal = refreshAbortController.signal;

    // Backup of checked checkboxes for persistence
    autoRefresh.selectedJails = [];
    $("input[name='<?=$checkbox_member_name;?>[]']:checked").each(function() {
        autoRefresh.selectedJails.push($(this).val());
    });

    // Timeout for fetch (10 seconds)
    const fetchTimeout = setTimeout(() => {
        if (refreshAbortController) refreshAbortController.abort();
    }, 10000);

    fetch('bastille_manager_gui.php?action=refresh_table', { signal })
        .then(response => response.json())
        .then(data => {
            clearTimeout(fetchTimeout);
            if (data.success) {
                var tbody = $(".area_data_selection tbody");
                tbody.empty();
                data.jails.forEach(function(jail) {
                    var row = $('<tr>');
                    var checkCell = $('<td class="lcelc">');
                    var cb = $('<input type="checkbox">')
                        .attr('name', '<?=$checkbox_member_name;?>[]')
                        .attr('value', jail.jailname)
                        .attr('id', jail.jailname)
                        .prop('checked', autoRefresh.selectedJails.includes(jail.jailname));

                    checkCell.append(cb);
                    row.append(checkCell);

                    // 2. Data Columns
                    row.append($('<td class="lcell">').text(jail.id || '-'));
                    row.append($('<td class="lcell">').text(jail.name || '-'));
                    // Description Column
                    // row.append($('<td class="lcell">').text(jail.description || '-'));
                    row.append($('<td class="lcell">').text(jail.boot || '-'));
                    row.append($('<td class="lcell">').text(jail.prio || '-'));
                    row.append($('<td class="lcell">').text(jail.state || '-'));
                    row.append($('<td class="lcell">').text(jail.type || '-'));
                    row.append($('<td class="lcell">').text(jail.ip || '-'));
                    row.append($('<td class="lcell">').text(jail.ports || '-'));
                    row.append($('<td class="lcell">').text(jail.rel || '-'));
                    row.append($('<td class="lcell">').text(jail.tags || '-'));

                    var statImg = (jail.state === "Up") ? '<?=$img_path['ena'];?>' : '<?=$img_path['dis'];?>';
                    row.append($('<td class="lcell">').append($('<img>').attr('src', statImg)));
                    row.append($('<td class="lcell">').append($('<img>').attr('src', jail.logo)));

                    var tools = $('<td class="lcebld">').html('<table class="area_data_selection_toolbox"><tbody><tr>' +
                        '<td><a href="<?=$sphere_scriptname_child;?>?jailname=' + encodeURIComponent(jail.jailname) + '"><img src="<?=$img_path['mai'];?>" class="spin oneemhigh"></a></td>' +
                        '<td><a href="bastille_manager_jconf.php?jailname=' + encodeURIComponent(jail.jailname) + '"><img src="<?=$g_img['mod'];?>"></a></td>' +
                        '<td><a href="bastille_manager_info.php?uuid=' + encodeURIComponent(jail.jailname) + '"><img src="<?=$g_img['inf'];?>"></a></td>' +
                        '</tr></tbody></table>');

                    // WebTerminal Button Logic (Controlled by LocalStorage)
                    if (localStorage.getItem('bastille_show_web_terminal_button') === 'true') {
                        var webTerminalBtn = '';
                        if (jail.state === "Up") {
                            // Changed to call openWebTerminal() instead of direct link
                            webTerminalBtn = '<a href="#" onclick="openWebTerminal(\'' + jail.jailname + '\'); return false;" title="Web terminal">' +
                                                 '<img src="ext/bastille/images/web-terminal.svg" class="web-terminal-icon" alt="Web Terminal" />' +
                                                 '</a>';
                        } else {
                            webTerminalBtn = '<img src="ext/bastille/images/web-terminal.svg" class="web-terminal-icon web-terminal-icon-disabled" alt="web-terminal" title="Jail is down" />';
                        }
                        tools.find('tr').append($('<td>').html(webTerminalBtn));
                    }

                    row.append(tools);

                    tbody.append(row);
                });
                autoRefresh.lastUpdate = Date.now();

                // Restore button state
                controlactionbuttons(null, '<?=$checkbox_member_name;?>[]');

                // Reapply saved column widths after updating the table
                applySavedColumnWidths();
            }
        })
        .catch(error => {
            if (error.name === 'AbortError') {
                console.log('Fetch aborted');
            } else {
                console.error('Error fetching jail data:', error);
            }
        })
        .finally(() => {
            autoRefresh.isUpdating = false;
            refreshAbortController = null;
            $("#refresh-spinner").hide();
        });
}

function startAutoRefresh() {
    if (autoRefresh.interval > 0) {
        autoRefresh.timerId = setInterval(updateJailTable, autoRefresh.interval);
    }
}

function stopAutoRefresh() {
    if (autoRefresh.timerId) clearInterval(autoRefresh.timerId);
}

// --- STABLE REDIMENSIONING FUNCTION (without %) ---
function initSimpleResize() {
    var $table = $("table.area_data_selection");
    var $cols = $table.find('colgroup col');
    var $headers = $table.find('thead th');

    // 1. Apply saved widths at the beginning
    applySavedColumnWidths();

    // 2. ADD HANDLES
    $headers.each(function(i) {
        if (i >= $headers.length - 1) return; // Ignore the last column
        var $resizer = $('<div class="resizer"></div>');
        $(this).append($resizer);
    });

    // 3. DRAG LOGIC
    var isResizing = false;
    var startX = 0;
    var $currentCol = null;
    var startWidth = 0;

    $table.on('mousedown', '.resizer', function(e) {
        e.preventDefault(); e.stopPropagation();
        stopAutoRefresh();

        // Convert all columns to fixed pixels when starting to drag
        $cols.each(function() {
            var w = $(this).width();
            $(this).css('width', w + 'px');
        });

        var idx = $(this).parent().index();
        $currentCol = $cols.eq(idx);

        isResizing = true;
        startX = e.pageX;
        startWidth = $currentCol.width();
        $(this).addClass('resizing');

        $(document).on('mousemove.rsz', function(e) {
            if (!isResizing) return;
            var diff = e.pageX - startX;
            var newW = startWidth + diff;

            if (newW > 30) {
                $currentCol.css('width', newW + 'px');
            }
        });

        $(document).on('mouseup.rsz', function() {
            if (!isResizing) {
                return;
            }
            isResizing = false;
            $('.resizer').removeClass('resizing');
            $(document).off('mousemove.rsz mouseup.rsz');

            // Save widths after resizing
            saveColumnWidths();

            setTimeout(function() {
                // Only resume if enabled
                if (localStorage.getItem('bastille_show_refresh_button') === 'true') {
                    startAutoRefresh();
                }
            }, 500);
        });
    });
}

function saveColumnWidths() {
    var widths = {};
    var $cols = $("table.area_data_selection colgroup col");
    $cols.each(function(index) {
        // We save the width in pixels.
        widths[index] = $(this).css('width');
    });
    localStorage.setItem('bastille_col_widths', JSON.stringify(widths));
}

function applySavedColumnWidths() {
    var saved = localStorage.getItem('bastille_col_widths');
    if (saved) {
        try {
            var widths = JSON.parse(saved);
            var $cols = $("table.area_data_selection colgroup col");
            $cols.each(function(index) {
                if (widths[index]) {
                    $(this).css('width', widths[index]);
                }
            });
        } catch (e) {
            console.error("Error parsing saved column widths", e);
        }
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
			ins_tabnav_record('bastille_manager_gui.php',gettext('Containers'))->
			ins_tabnav_record('bastille_manager_info.php',gettext('Information'))->
			ins_tabnav_record('bastille_manager_maintenance.php',gettext('Maintenance'));
$document->render();
?>
<form action="bastille_manager_gui.php" method="post" name="iform" id="iform"><table id="area_data"><tbody><tr><td id="area_data_frame">
<?php
	global $sphere_notifier;
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
?>
		</thead>
		<tbody>
<?php
?>
       </tbody>
    </table>

    <div id="refresh-controls" style="text-align: right; display: none; position: relative;">
        <span id="refresh-spinner" style="display: none;"></span>
        <button type="button" id="refresh-now" class="formbtn">Refresh</button>
        <select id="refresh-interval" class="formfld">
            <option value="5000">5s</option>
            <option value="10000">10s</option>
            <option value="30000" selected>30s</option>
            <option value="60000">60s</option>
            <option value="0">Manual</option>
        </select>
    </div>

	<table class="area_data_selection" style="width: 100%; table-layout: fixed; border-collapse: collapse;">
		<colgroup>
			<col style="width:2%">
			<col style="width:3%">
			<col style="width:10%">
            <!-- <col style="width:10%"> Description -->
			<col style="width:4%">
			<col style="width:4%">
			<col style="width:4%">
			<col style="width:4%">
			<col style="width:12%">
			<col style="width:12%">
			<col style="width:7%">
			<col style="width:10%">
			<col style="width:4%">
			<col style="width:4%">
			<col style="width:10%">
		</colgroup>
		<thead>
<?php
			html_separator2();
			html_titleline2(gettext('Overview'), 14);
?>
			<tr>
				<th class="lhelc"><?=gtext('Select');?></th>
				<th class="lhell"><?=gtext('JID');?></th>
				<th class="lhell"><?=gtext('Name');?></th>
                <!-- <th class="lhell"><?=gtext('Description');?></th> -->
				<th class="lhell"><?=gtext('Boot');?></th>
				<th class="lhell"><?=gtext('Prio');?></th>
				<th class="lhell"><?=gtext('State');?></th>
				<th class="lhell"><?=gtext('Type');?></th>
				<th class="lhell"><?=gtext('IP Address');?></th>
				<th class="lhell"><?=gtext('Published Ports');?></th>
				<th class="lhell"><?=gtext('Release');?></th>
				<th class="lhell"><?=gtext('Tags');?></th>
				<th class="lhell"><?=gtext('Active');?></th>
				<th class="lhell"><?=gtext('Template');?></th>
				<th class="lhebl"><?=gtext('Toolbox');?></th>
			</tr>
		</thead>
		<tbody>
<?php
			global $identifier;
			foreach ($sphere_array as $sphere_record):
				$notificationmode = updatenotify_get_mode($sphere_notifier, $identifier);
				$notdirty = (UPDATENOTIFY_MODE_DIRTY != $notificationmode) && (UPDATENOTIFY_MODE_DIRTY_CONFIG != $notificationmode);
				$notprotected = !isset($sphere_record['protected']);
?>
				<tr>
					<td class="lcelc">
<?php
						if ($notdirty && $notprotected):
?>
							<input type="checkbox" name="<?=$checkbox_member_name;?>[]" value="<?=$sphere_record['jailname'];?>" id="<?=$sphere_record['jailname'];?>"/>
<?php
						else:
?>
							<input type="checkbox" name="<?=$checkbox_member_name;?>[]" value="<?=$sphere_record['jailname'];?>" id="<?=$sphere_record['jailname'];?>" disabled="disabled"/>
<?php
						endif;
?>
					</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['id']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['name']);?>&nbsp;</td>
                    <!-- <td class="lcell"><?=htmlspecialchars($sphere_record['description']);?>&nbsp;</td> -->
					<td class="lcell"><?=htmlspecialchars($sphere_record['boot']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['prio']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['state']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['type']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['ip']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['ports']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['rel']);?>&nbsp;</td>
					<td class="lcell"><?=htmlspecialchars($sphere_record['tags']);?>&nbsp;</td>
					<td class="lcell"><img src="<?=$sphere_record['stat'];?>"></td>
					<td class="lcell"><img src="<?=$sphere_record['logo'];?>"></td>
					<td class="lcebld">
						<table class="area_data_selection_toolbox"><tbody><tr>
							<td>
<?php
								if($notdirty && $notprotected):
?>
									<a href="<?=$sphere_scriptname_child;?>?jailname=<?=urlencode($sphere_record['jailname']);?>"><img src="<?=$img_path['mai'];?>" title="<?=$gt_record_mod;?>" alt="<?=$gt_record_mod;?>"  class="spin oneemhigh"/></a>
<?php
								else:
									if ($notprotected):
?>
										<img src="<?=$img_path['del'];?>" title="<?=$gt_record_del;?>" alt="<?=$gt_record_del;?>"/>
<?php
									else:
?>
										<img src="<?=$img_path['loc'];?>" title="<?=$gt_record_loc;?>" alt="<?=$gt_record_loc;?>"/>
<?php
									endif;
								endif;
?>
							</td>
							<td>
								<a href="bastille_manager_jconf.php?jailname=<?=urlencode($sphere_record['jailname']);?>"><img src="<?=$g_img['mod'];?>" title="<?=$gt_record_conf?>" alt="<?=$gt_record_conf?>"/></a>
							</td>
							<td>
								<a href="bastille_manager_info.php?uuid=<?=urlencode($sphere_record['jailname']);?>"><img src="<?=$g_img['inf'];?>" title="<?=$gt_record_inf?>" alt="<?=$gt_record_inf?>"/></a>
							</td>
						</tr></tbody></table>
					</td>
				</tr>
<?php
			endforeach;
?>
		</tbody>
		<tfoot>
			<tr>
				<td class="lcenl" colspan="13"></td>
				<td class="lceadd">
					<a href="bastille_manager_add.php"><img src="<?=$img_path['add'];?>" title="<?=$gt_record_add;?>" border="0" alt="<?=$gt_record_add;?>" class="spin oneemhigh"/></a>
				</td>
			</tr>
		</tfoot>
	</table>
	<div id="submit">
		<input name="start_selected_jail" id="start_selected_jail" type="submit" class="formbtn" value="<?=$gt_selection_start;?>"/>
		<input name="stop_selected_jail" id="stop_selected_jail" type="submit" class="formbtn" value="<?=$gt_selection_stop;?>"/>
		<input name="restart_selected_jail" id="restart_selected_jail" type="submit" class="formbtn" value="<?=$gt_selection_restart;?>"/>
		<input name="autoboot_selected_jail" id="autoboot_selected_jail" type="submit" class="formbtn" value="<?=$gt_selection_autoboot;?>"/>
	</div>

    <div id="web-terminal-modal">
        <div id="web-terminal-content">
            <div id="web-terminal-header">
                <span id="web-terminal-title"></span>
                <img src="ext/bastille/images/info-ssl.svg" class="icon-svg ssl-help-icon"
                         title="SSL Troubleshooting: If this window does not open, use the button on the right to open it in a new tab." />
                <div id="web-terminal-right-buttons">
                    <a href="#" id="web-terminal-fullscreen" class="web-terminal-btn-fullscreen" title="Fullscreen">
                        <img src="ext/bastille/images/fullscreen.svg" class="icon-svg fullscreen-icon-darkbg" alt="Fullscreen" />
                     </a>
                    <a href="#" id="web-terminal-popout" class="web-terminal-btn-open-tab">Open in New Tab</a>
                    <span id="web-terminal-close" style="cursor:pointer; font-weight:bold; font-size:1.3em;">&times;</span>
                </div>
            </div>
            <div id="web-terminal-iframe-container">
                <iframe id="web-terminal-iframe" src="about:blank"></iframe>
            </div>
        </div>
    </div>

<?php
    include 'formend.inc';
?>
</td></tr></tbody></table></form>
<?php
include 'fend.inc';
?>