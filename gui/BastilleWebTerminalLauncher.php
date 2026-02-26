<?php
declare(strict_types=1);

/**
 * Class for managing the lifecycle of the ttyd process in XigmaNAS.
 */
class BastilleWebTerminalLauncher {

    private const int PORT_START = 7681;
    private const int PORT_END = 7700;
    private const string BASTILLE_BIN = '/usr/local/bin/bastille';
    private const string TTYD_BIN = '/usr/local/bin/ttyd';

    /** * Default XigmaNAS WebGUI certificate paths.
     * These are generated dynamically by the system when SSL is enabled.
     */
    private const string SSL_CERT = '/var/etc/webguicert.pem';
    private const string SSL_KEY = '/var/etc/webguipriv.pem';

    private int $port;
    private string $protocol = 'http://';

    public function __construct(private readonly string $jailName) {
        $this->determineProtocol();
    }

    /**
     * Detects if the current session is running over HTTPS.
     * Switches to HTTPS only if certificates are physically present on the system.
     */
    private function determineProtocol(): void {
        $isHttps = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') == 443;
        if ($isHttps && file_exists(self::SSL_CERT) && file_exists(self::SSL_KEY)) {
            $this->protocol = 'https://';
        }
    }

    /**
     * Searches for an available port within the defined range (7681-7700).
     * Uses a fast socket check to verify port availability.
     */
    public function acquireFreePort(): int {
        for ($p = self::PORT_START; $p <= self::PORT_END; $p++) {
            $fp = @fsockopen('127.0.0.1', $p, $errno, $errstr, 0.1);
            if (!$fp) {
                return $this->port = $p;
            }
            fclose($fp);
        }
        throw new RuntimeException("No free ports available in range " . self::PORT_START . "-" . self::PORT_END);
    }

    public function launch(): string {
        if (!file_exists(self::TTYD_BIN)) {
            throw new RuntimeException("Binary ttyd not found at " . self::TTYD_BIN);
        }

        if (!isset($this->port)) {
            $this->acquireFreePort();
        }

        /**
         * Proactive cleanup
         */
        exec("pkill -f 'ttyd .* console {$this->jailName}' > /dev/null 2>&1");

        /**
         * Mozilla firefox ? fixme
         */
        $sslArgs = "";
        if ($this->protocol === 'https://') {
            $sslArgs = sprintf("--ssl --ssl-cert %s --ssl-key %s", self::SSL_CERT, self::SSL_KEY);
        }

        /**
         * Command execution:
         * - nohup: keeps the process running after PHP exits.
         * - --check-origin=false: allows the iframe to connect from the WebGUI domain.
         * - -W: enables write access (writable terminal).
         */
        $cmd = sprintf(
            "nohup %s %s --check-origin=false -p %d -W %s console %s > /dev/null 2>&1 &",
            self::TTYD_BIN,
            $sslArgs,
            $this->port,
            self::BASTILLE_BIN,
            escapeshellarg($this->jailName)
        );

        exec($cmd);

        usleep(500000);

        return $this->generateUrl();
    }

    private function generateUrl(): string {
        $host = explode(':', $_SERVER['HTTP_HOST'])[0];
        return "{$this->protocol}{$host}:{$this->port}";
    }
}