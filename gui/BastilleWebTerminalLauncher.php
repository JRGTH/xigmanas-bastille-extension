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

    /** ttyd's own page plus our <style>, built from the installed ttyd (see buildIndex). */
    private const string INDEX_FILE = '/tmp/bastille_ttyd_index.html';

    /**
     * Added to ttyd's page. A dark colour scheme makes the browser draw dark native scrollbars
     * (ttyd 1.7 uses xterm.js 5, whose scrollbar is the native one and cannot be themed with -t).
     */
    private const string INDEX_STYLE = '<style>:root{color-scheme:dark}'
        . '.xterm-viewport{scrollbar-width:thin;scrollbar-color:#4b5263 #282c34}</style>';

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

        $index = $this->currentIndex();
        $this->spawn($index);

        if ($index === null && $this->buildIndex()) {
            // First time with this ttyd: restart it with the page that has our style.
            $this->spawn(self::INDEX_FILE);
        }

        return $this->generateUrl();
    }

    /** The custom index when it exists and is not older than the ttyd binary, else null. */
    private function currentIndex(): ?string
    {
        if (!is_file(self::INDEX_FILE)) {
            return null;
        }
        return filemtime(self::INDEX_FILE) >= filemtime(self::TTYD_BIN) ? self::INDEX_FILE : null;
    }

    /**
     * Take the page the running ttyd serves, add our style and save it for "ttyd -I".
     * Built from the installed ttyd, so a ttyd upgrade never mixes with an old client.
     * Any failure just leaves ttyd with its own page (light scrollbar), nothing breaks.
     */
    private function buildIndex(): bool
    {
        $context = stream_context_create([
            'http' => ['timeout' => 3],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $page = @file_get_contents("{$this->protocol}127.0.0.1:" . self::TTYD_PORT . '/', false, $context);
        if (!is_string($page)) {
            return false;
        }
        $end = strpos($page, '</head>');
        if ($end === false) {
            return false;
        }
        $custom = substr_replace($page, self::INDEX_STYLE, $end, 0);
        $tmp = self::INDEX_FILE . '.' . getmypid();
        if (file_put_contents($tmp, $custom) === false) {
            return false;
        }
        return rename($tmp, self::INDEX_FILE);
    }

    /** Stop the previous ttyd and start one for this jail; waits until its port answers. */
    private function spawn(?string $indexFile): void
    {
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

        $terminalOptions = $this->terminalOptions();
        $indexArg = $indexFile === null ? '' : '-I ' . escapeshellarg($indexFile);

        /**
        * Command execution details:
        * --------------------------
        * - nohup: Detaches the process from the parent PHP thread so it keeps running.
        * - --ssl: Integrated with XigmaNAS certs to prevent Mixed Content issues.
        * - -t (terminal-options): Configures the xterm.js frontend (theme, font, blinking bar cursor).
        * - -I: Custom index page (ttyd's own plus our style), when it has been built.
        * - --check-origin=false: Necessary for cross-port WebSocket communication within the iframe.
        * - -p %d: Dynamic port assignment to allow multiple concurrent jail consoles.
        * - -W: Enables write access; otherwise, the terminal would be read-only.
        * - bastille console: The target binary that enters the specific jail's shell.
        * - > /dev/null 2>&1 &: Completely silences output and pushes the process to the background.
        */
        $cmd = sprintf(
            "nohup %s %s %s %s --check-origin=false -p %d -W %s console %s > /dev/null 2>&1 &",
            self::TTYD_BIN,
            $sslArgs,
            $terminalOptions,
            $indexArg,
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
    }

    /**
     * xterm.js options passed to ttyd (-t key=value; values are JSON). Always a dark palette
     * (One Dark); its background must match #web-terminal-iframe-container in
     * css/bastille-web-terminal-modal.css, which draws the margin around the terminal.
     */
    private function terminalOptions(): string
    {
        $theme = [
            'background' => '#282c34', 'foreground' => '#abb2bf',
            'cursor' => '#528bff', 'cursorAccent' => '#282c34',
            'selectionBackground' => '#3e4451',
            'black' => '#282c34', 'red' => '#e06c75', 'green' => '#98c379', 'yellow' => '#e5c07b',
            'blue' => '#61afef', 'magenta' => '#c678dd', 'cyan' => '#56b6c2', 'white' => '#abb2bf',
            'brightBlack' => '#5c6370', 'brightRed' => '#e06c75', 'brightGreen' => '#98c379',
            'brightYellow' => '#e5c07b', 'brightBlue' => '#61afef', 'brightMagenta' => '#c678dd',
            'brightCyan' => '#56b6c2', 'brightWhite' => '#ffffff',
            // Dark scrollbar (only honoured by the xterm.js versions that know these keys).
            'scrollbarSliderBackground' => 'rgba(121,121,121,0.4)',
            'scrollbarSliderHoverBackground' => 'rgba(100,100,100,0.7)',
            'scrollbarSliderActiveBackground' => 'rgba(191,191,191,0.4)',
        ];
        $options = [
            'cursorBlink' => true,
            'cursorStyle' => 'bar',
            'fontSize' => 15,
            'lineHeight' => 1.2,
            'fontFamily' => 'JetBrains Mono, Fira Code, DejaVu Sans Mono, Menlo, Consolas, monospace',
            'theme' => $theme,
        ];

        $args = [];
        foreach ($options as $key => $value) {
            $args[] = '-t ' . escapeshellarg($key . '=' . json_encode($value, JSON_UNESCAPED_SLASHES));
        }
        return implode(' ', $args);
    }

    private function generateUrl(): string
    {
        $host = explode(':', $_SERVER['HTTP_HOST'])[0];
        return "{$this->protocol}{$host}:" . self::TTYD_PORT;
    }
}
