<?php

declare(strict_types=1);

/**
 * Partial downloads staged by the release job in <bastille_cachedir>/.resume/<release>/.
 *
 * Each staged archive is <name>.txz plus a <name>.size file holding its full size, written
 * by bastille_manager_release_job.sh, so the GUI can show "83 / 154 MB".
 */
final class BastilleReleaseCache
{
    private const STAGING = '.resume';

    private ?string $cacheDir = null;

    /**
     * @param string       $bastilleConf   path of bastille.conf
     * @param Closure|null $cacheDirReader fn(string $conf): string, overridable for tests
     */
    public function __construct(
        private readonly string $bastilleConf,
        private readonly ?Closure $cacheDirReader = null,
    ) {
    }

    /**
     * bastille_cachedir as bastille itself sees it. bastille.conf is a shell file, so it is
     * sourced rather than parsed: that expands variables like ${bastille_prefix}.
     */
    public function cacheDir(): string
    {
        return $this->cacheDir ??= ($this->cacheDirReader ?? self::readCacheDirFromConf(...))($this->bastilleConf);
    }

    /** @return list<BastillePartialDownload> partial downloads that hold some data */
    public function partials(): array
    {
        $staging = $this->stagingDir();
        if ($staging === null) {
            return [];
        }

        $partials = [];
        foreach (glob("{$staging}/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $release = basename($dir);
            if (!BastilleReleaseName::isFreeBsd($release)) {
                continue;
            }
            $have = array_sum(array_map(filesize(...), glob("{$dir}/*.txz") ?: []));
            if ($have === 0) {
                continue; // empty staging dir, nothing worth resuming
            }
            $total = array_sum(array_map(
                static fn (string $f): int => (int) trim((string) file_get_contents($f)),
                glob("{$dir}/*.size") ?: [],
            ));
            $partials[] = new BastillePartialDownload($release, (int) $have, (int) $total);
        }
        return $partials;
    }

    /** Delete the staged download of one release. False when the name is not acceptable. */
    public function discard(string $release): bool
    {
        if (!BastilleReleaseName::isFreeBsd($release)) {
            return false;
        }
        $staging = $this->stagingDir();
        if ($staging !== null) {
            self::deleteTree("{$staging}/{$release}");
            @rmdir($staging); // only succeeds when empty
        }
        return true;
    }

    /**
     * Delete staged downloads not touched for $days days, except the one of $keepRelease.
     *
     * @return list<string> releases removed
     */
    public function purgeStale(int $days, string $keepRelease, ?int $now = null): array
    {
        $staging = $this->stagingDir();
        if ($staging === null) {
            return [];
        }

        $limit = ($now ?? time()) - $days * 86400;
        $removed = [];
        foreach (glob("{$staging}/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $release = basename($dir);
            if ($release !== $keepRelease && filemtime($dir) < $limit) {
                self::deleteTree($dir);
                $removed[] = $release;
            }
        }
        @rmdir($staging);
        return $removed;
    }

    /** The staging directory, or null when there is no cache or nothing staged. */
    private function stagingDir(): ?string
    {
        $cache = $this->cacheDir();
        $staging = "{$cache}/" . self::STAGING;
        return ($cache !== '' && is_dir($staging)) ? $staging : null;
    }

    private static function readCacheDirFromConf(string $conf): string
    {
        if ($conf === '' || !is_file($conf)) {
            return '';
        }
        $script = '. ' . escapeshellarg($conf) . ' 2>/dev/null; echo "$bastille_cachedir"';
        return trim((string) shell_exec('/bin/sh -c ' . escapeshellarg($script)));
    }

    private static function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
