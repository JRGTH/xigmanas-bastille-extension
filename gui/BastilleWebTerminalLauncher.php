<?php

declare(strict_types=1);

/**
 * Class for managing the lifecycle of the ttyd process in XigmaNAS.
 */
class BastilleWebTerminalLauncher
{
    private const int TTYD_PORT = 7681;
    private const string BASTILLE_BIN = '/usr/local/bin/bastille';
    private const string TTYD_BIN = '/usr/local/bin/ttyd';

    /** * Default XigmaNAS WebGUI certificate paths.
     * These are generated dynamically by the system when SSL is enabled.
     */
    private const string SSL_CERT = '/var/etc/webguicert.pem';
    private const string SSL_KEY = '/var/etc/webguipriv.pem';

    private string $protocol = 'http://';

    public function __construct(private readonly string $jailName)
    {
        $this->determineProtocol();
    }

    /**
     * Detects if the current session is running over HTTPS.
     * Switches to HTTPS only if certificates are physically present on the system.
     */
    private function determineProtocol(): void
    {
        $isHttps = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') == 443;
        if ($isHttps && file_exists(self::SSL_CERT) && file_exists(self::SSL_KEY)) {
            $this->protocol = 'https://';
        }
    }

    public function launch(): string
    {
        if (!file_exists(self::TTYD_BIN)) {
            throw new RuntimeException("Binary ttyd not found at " . self::TTYD_BIN);
        }

        /**
         * Proactive cleanup
         */
        exec("pkill -f 'ttyd .* console' > /dev/null 2>&1");

        // We give the OS a fraction of a second to completely free up the port.
        usleep(200000);

        /**
         * Mozilla firefox ssl
         */
        $sslArgs = "";
        if ($this->protocol === 'https://') {
            $sslArgs = sprintf("--ssl --ssl-cert %s --ssl-key %s", self::SSL_CERT, self::SSL_KEY);
        }

        $terminalOptions = "-t cursorBlink=true -t cursorStyle=bar";

        /**
        * Command execution details:
        * --------------------------
        * - nohup: Detaches the process from the parent PHP thread so it keeps running.
        * - --ssl: Integrated with XigmaNAS certs to prevent Mixed Content issues.
        * - -t (terminal-options): Configures the xterm.js frontend (blinking bar cursor).
        * - --check-origin=false: Necessary for cross-port WebSocket communication within the iframe.
        * - -p %d: Dynamic port assignment to allow multiple concurrent jail consoles.
        * - -W: Enables write access; otherwise, the terminal would be read-only.
        * - bastille console: The target binary that enters the specific jail's shell.
        * - > /dev/null 2>&1 &: Completely silences output and pushes the process to the background.
        */
        $cmd = sprintf(
            "nohup %s %s %s --check-origin=false -p %d -W %s console %s > /dev/null 2>&1 &",
            self::TTYD_BIN,
            $sslArgs,
            $terminalOptions,
            self::TTYD_PORT,
            self::BASTILLE_BIN,
            escapeshellarg($this->jailName)
        );

        exec($cmd);

        $maxRetries = 15;
        for ($i = 0; $i < $maxRetries; $i++) {
            // Intentamos conectar al puerto local con 0.1s de timeout
            $fp = @fsockopen('127.0.0.1', self::TTYD_PORT, $errno, $errstr, 0.1);
            if ($fp) {
                fclose($fp);
                break; // ¡El puerto está abierto, ttyd está listo!
            }
            usleep(100000); // Esperamos 100ms antes de reintentar
        }

        return $this->generateUrl();
    }

    private function generateUrl(): string
    {
        $host = explode(':', $_SERVER['HTTP_HOST'])[0];
        return "{$this->protocol}{$host}:" . self::TTYD_PORT;
    }
}
