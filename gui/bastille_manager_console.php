<?php
/*
	bastille_manager_console.php

    Launches ttyd for a specific jail and returns URL (JSON) or redirects.
*/

ini_set('log_errors', 1);
ini_set('error_log', '/tmp/bastille_console_error.log');
error_reporting(E_ALL);

require_once 'auth.inc';
require_once 'guiconfig.inc';
require_once 'bastille_manager-lib.inc';

function is_json_request() {
    return (isset($_GET['format']) && $_GET['format'] === 'json') ||
           (is_ajax());
}

function send_json_response($success, $message_or_url) {
    // Clear any previous output
    if (ob_get_length()) ob_clean();

    if (is_json_request()) {
        header('Content-Type: application/json');
        $key = $success ? 'url' : 'message';
        echo json_encode(['success' => $success, $key => $message_or_url]);
    } else {
        if ($success) {
            header("Location: " . $message_or_url);
        } else {
            die($message_or_url);
        }
    }
    exit;
}

$jailname = $_GET['jailname'] ?? '';

if (empty($jailname)) {
    send_json_response(false, 'No jail specified.');
}

$jailname = escapeshellcmd($jailname);

exec("pkill -f 'ttyd -p' > /dev/null 2>&1");

$port = 7681;
$max_port = 7700;
$found = false;

while ($port <= $max_port) {
    $connection = @fsockopen('127.0.0.1', $port);
    if (is_resource($connection)) {
        fclose($connection);
        $port++; // Port is taken
    } else {
        $found = true;
        break; // Port is free
    }
}

if (!$found) {
    send_json_response(false, 'No free ports available for console (range 7681-7700).');
}

$ttyd_bin = "/usr/local/bin/ttyd";
$bastille_bin = "/usr/local/bin/bastille";

if (!file_exists($ttyd_bin)) {
    send_json_response(false, "Error: ttyd binary not found at $ttyd_bin. Please install it on the host.");
}

$cmd = "nohup $ttyd_bin -p {$port} -W -o $bastille_bin console {$jailname} > /dev/null 2>&1 &";

exec($cmd);

// Wait a bit for ttyd to start
usleep(500000); // 0.5s

// Verify if the process started
$pid_check_cmd = "pgrep -f 'ttyd -p {$port}'";
$pid = exec($pid_check_cmd);

if (empty($pid)) {
    // Try to capture stderr if it failed
    $debug_cmd = "$ttyd_bin -p {$port} -W -o $bastille_bin console {$jailname} 2>&1";
    exec($debug_cmd, $output, $retval);
    $error_msg = implode("\n", $output);
    send_json_response(false, "Failed to start ttyd process. Output: " . $error_msg);
}

$host = $_SERVER['HTTP_HOST'];
$host_parts = explode(':', $host);
$hostname = $host_parts[0];
$url = "http://{$hostname}:{$port}";

send_json_response(true, $url);
?>