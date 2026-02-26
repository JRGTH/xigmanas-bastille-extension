<?php
/*
	bastille_manager_web_terminal.php
    Launches ttyd for a specific jail and returns URL (JSON) or redirects.
    //TODO add alerts instead of h1 errors
*/
declare(strict_types=1);

ini_set('log_errors', 1);
ini_set('error_log', '/tmp/bastille_web_terminal_error.log');
error_reporting(E_ALL);

require_once 'auth.inc';
require_once 'guiconfig.inc';
require_once 'bastille_manager-lib.inc';
require_once 'BastilleWebTerminalLauncher.php';

$jailname = $_GET['jailname'] ?? '';
$format   = $_GET['format'] ?? '';

function respond(bool $success, string $payload, bool $isJson): void {
    if (ob_get_length()) ob_clean();

    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            ($success ? 'url' : 'message') => $payload
        ]);
    } else {
        if ($success) {
            header("Location: " . $payload);
        } else {
            echo "<h1>Error</h1><p>" . htmlspecialchars($payload) . "</p>";
        }
    }
    exit;
}

if (empty($jailname)) {
    respond(false, "Jail name is missing.");
}

try {

    $launcher = new BastilleWebTerminalLauncher($jailname);
    $url = $launcher->launch();

    respond(true, $url, $format === 'json');

} catch (Exception $e) {
   respond(false, "Server Error: " . $e->getMessage(), $format === 'json');
}

?>