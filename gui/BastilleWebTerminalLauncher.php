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

    /** One Dark. Its background must match the one in the modal (gui.php, TERM_BG). */
    private const array DARK_THEME = [
        'background' => '#282c34', 'foreground' => '#abb2bf',
        'cursor' => '#528bff', 'cursorAccent' => '#282c34',
        'selectionBackground' => '#3e4451',
        'black' => '#282c34', 'red' => '#e06c75', 'green' => '#98c379', 'yellow' => '#e5c07b',
        'blue' => '#61afef', 'magenta' => '#c678dd', 'cyan' => '#56b6c2', 'white' => '#abb2bf',
        'brightBlack' => '#5c6370', 'brightRed' => '#e06c75', 'brightGreen' => '#98c379',
        'brightYellow' => '#e5c07b', 'brightBlue' => '#61afef', 'brightMagenta' => '#c678dd',
        'brightCyan' => '#56b6c2', 'brightWhite' => '#ffffff',
    ];

    /** One Light, the counterpart of the dark palette. */
    private const array LIGHT_THEME = [
        'background' => '#fafafa', 'foreground' => '#383a42',
        'cursor' => '#526fff', 'cursorAccent' => '#fafafa',
        'selectionBackground' => '#dbe4f5',
        'black' => '#383a42', 'red' => '#e45649', 'green' => '#50a14f', 'yellow' => '#c18401',
        'blue' => '#4078f2', 'magenta' => '#a626a4', 'cyan' => '#0184bc', 'white' => '#a0a1a7',
        'brightBlack' => '#696c77', 'brightRed' => '#e45649', 'brightGreen' => '#50a14f',
        'brightYellow' => '#c18401', 'brightBlue' => '#4078f2', 'brightMagenta' => '#a626a4',
        'brightCyan' => '#0184bc', 'brightWhite' => '#fafafa',
    ];

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
            $this->spawn($this->indexFile());
        }

        return $this->generateUrl();
    }

    /**
     * What is added to ttyd's page (before </head>):
     *  - a dark colour scheme, so the browser draws dark native scrollbars (ttyd 1.7 uses xterm.js 5,
     *    whose scrollbar is the native one and cannot be themed with -t). The thumb is invisible
     *    until the mouse moves or the terminal scrolls ("sb" class on <html>) and fades in and out
     *    (a transition on scrollbar-color);
     *  - a script that applies the theme and font size the modal asks for, through postMessage, on
     *    the running terminal (window.term), so the session is not interrupted.
     */
    private function indexInject(): string
    {
        $themes = json_encode(['dark' => self::DARK_THEME, 'light' => self::LIGHT_THEME], JSON_THROW_ON_ERROR);

        // scrollbar-color is animated by Chrome (121+) and Firefox; the ::-webkit-scrollbar-thumb is not.
        $style = ':root{color-scheme:dark}'
            . '.xterm-viewport{scrollbar-width:thin;scrollbar-color:transparent transparent;transition:scrollbar-color .5s ease}'
            . 'html.sb .xterm-viewport{scrollbar-color:#6b7385 transparent}'
            . 'html.light.sb .xterm-viewport{scrollbar-color:#a9a9a9 transparent}';

        $script = '(function(){var T=' . $themes . ',t,h=document.documentElement,tries=0;'
            . 'function s(){h.classList.add("sb");clearTimeout(t);t=setTimeout(function(){h.classList.remove("sb")},1200)}'
            . '["mousemove","wheel","scroll"].forEach(function(e){addEventListener(e,s,{passive:true,capture:true})});'
            // Tell the modal when the terminal exists, so it can send the saved preferences.
            . 'function ready(){if(window.term&&window.term.options){parent.postMessage({type:"bastille-term-ready"},"*")}'
            . 'else if(tries++<100){setTimeout(ready,100)}}ready();'
            // Ctrl + mouse wheel changes the font size (instead of zooming the page); the modal saves it.
            . 'addEventListener("wheel",function(e){if(!e.ctrlKey||!window.term||!e.deltaY){return}e.preventDefault();'
            . 'var n=term.options.fontSize+(e.deltaY<0?1:-1);if(n<10||n>24){return}'
            . 'term.options.fontSize=n;try{term.fit()}catch(x){}parent.postMessage({type:"bastille-term-font",fontSize:n},"*")},{passive:false});'
            . 'addEventListener("message",function(e){var d=e.data;'
            . 'if(e.source!==parent||!d||d.type!=="bastille-term"||!window.term){return}'
            . 'var n=parseInt(d.fontSize,10);if(n>=10&&n<=28){term.options.fontSize=n}'
            . 'if(T[d.theme]){term.options.theme=T[d.theme];h.style.colorScheme=d.theme;'
            . 'h.classList.toggle("light",d.theme==="light");document.body.style.backgroundColor=T[d.theme].background}'
            . 'try{term.fit()}catch(x){}})})()';

        return '<style>' . $style . '</style><script>' . $script . '</script>';
    }

    /** Named after a hash of what is injected: changing it makes the page be built again. */
    private function indexFile(): string
    {
        return '/tmp/bastille_ttyd_index_' . hash('crc32b', $this->indexInject()) . '.html';
    }

    /** The custom index when it exists and is not older than the ttyd binary, else null. */
    private function currentIndex(): ?string
    {
        if (!is_file($this->indexFile())) {
            return null;
        }
        return filemtime($this->indexFile()) >= filemtime(self::TTYD_BIN) ? $this->indexFile() : null;
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
        foreach (glob('/tmp/bastille_ttyd_index*.html') ?: [] as $stale) {
            unlink($stale);
        }
        $custom = substr_replace($page, $this->indexInject(), $end, 0);
        $tmp = $this->indexFile() . '.' . getmypid();
        if (file_put_contents($tmp, $custom) === false) {
            return false;
        }
        return rename($tmp, $this->indexFile());
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
     * xterm.js options passed to ttyd (-t key=value; values are JSON). It starts dark; the
     * modal then applies the user's choice of theme and font size (see indexInject()).
     */
    private function terminalOptions(): string
    {
        $options = [
            'cursorBlink' => true,
            'cursorStyle' => 'bar',
            'fontSize' => 15,
            'lineHeight' => 1.2,
            'fontFamily' => 'JetBrains Mono, Fira Code, DejaVu Sans Mono, Menlo, Consolas, monospace',
            'theme' => self::DARK_THEME,
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
