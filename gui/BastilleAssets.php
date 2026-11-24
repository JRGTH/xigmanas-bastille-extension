<?php

declare(strict_types=1);

/**
 * URLs of the extension's own CSS and JavaScript, versioned so that a browser fetches them again
 * when a new release is installed instead of using a stale copy from its cache.
 *
 * The version is the extension version (the "version" file) plus the newest modification time of
 * the files, so a release that forgot to bump the version still changes it.
 *
 * Two things a "?v=" on the <script>/<link> tag cannot reach are handled here:
 *  - ES modules import each other ("./modules/tree.js") without any query: importMapTag() maps
 *    every module to its versioned URL;
 *  - a CSS "@import" cannot carry a query either: the stylesheets are linked one by one.
 */
final class BastilleAssets
{
    /** Where the web server publishes the extension's js/ and css/ directories. */
    private const BASE = '/ext/bastille';

    /** What styles.css used to @import, in the same order, followed by styles.css itself. */
    public const EDITOR_STYLES = [
        'bastille-editor-layout.css',
        'bastille-editor-components.css',
        'bastille-editor-quicksearch.css',
        'bastille-header-refresh.css',
        'bastille-web-terminal-modal.css',
        'bastille-editor-upload-modal.css',
        'bastille-editor-info-right-sidebar.css',
        'bastille-editor-charts.css',
        'bastille-editor-diff-modal.css',
        'styles.css',
    ];

    private ?string $token = null;

    /**
     * @param string $versionFile file with the extension version (e.g. 1.5.12)
     * @param string $guiDir      directory that holds js/ and css/
     */
    public function __construct(
        private readonly string $versionFile,
        private readonly string $guiDir,
    ) {
    }

    /** URL of a file below js/ or css/, e.g. url('js/main.js'). */
    public function url(string $path): string
    {
        return sprintf('%s/%s?v=%s', self::BASE, ltrim($path, '/'), $this->token());
    }

    /**
     * <link> tags for stylesheets below css/, in the given order.
     *
     * @param list<string> $files
     */
    public function styleTags(array $files): string
    {
        $tags = '';
        foreach ($files as $file) {
            $tags .= sprintf(
                '<link rel="stylesheet" type="text/css" href="%s">' . "\n",
                htmlspecialchars($this->url("css/{$file}"), ENT_QUOTES),
            );
        }
        return $tags;
    }

    /** Import map that sends every module of js/modules/ to its versioned URL. Goes before the module scripts. */
    public function importMapTag(): string
    {
        $imports = [];
        foreach (glob("{$this->guiDir}/js/modules/*.js") ?: [] as $file) {
            $path = self::BASE . '/js/modules/' . basename($file);
            $imports[$path] = "{$path}?v={$this->token()}";
        }
        return '<script type="importmap">'
            . json_encode(['imports' => $imports], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
            . "</script>\n";
    }

    private function token(): string
    {
        return $this->token ??= $this->version() . '.' . base_convert((string) $this->newestMtime(), 10, 36);
    }

    private function version(): string
    {
        $version = is_file($this->versionFile) ? trim((string) file_get_contents($this->versionFile)) : '';
        $version = preg_replace('/[^A-Za-z0-9._-]/', '', $version);
        return $version === '' || $version === null ? '0' : $version;
    }

    private function newestMtime(): int
    {
        $newest = 0;
        foreach (['js/*.js', 'js/modules/*.js', 'css/*.css'] as $pattern) {
            foreach (glob("{$this->guiDir}/{$pattern}") ?: [] as $file) {
                $newest = max($newest, (int) filemtime($file));
            }
        }
        return $newest;
    }
}
