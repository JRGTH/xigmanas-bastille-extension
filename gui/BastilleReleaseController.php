<?php

declare(strict_types=1);

/**
 * Backend of the "Base Releases" page: the start / status / discard endpoints polled by
 * js/modules/release-job.js. It takes plain request data and returns the JSON payload, so it
 * does not touch superglobals, headers or XigmaNAS globals (all injected).
 */
final class BastilleReleaseController
{
    public const ACTIONS = ['start', 'status', 'discard'];

    /** Partial downloads untouched for this many days are deleted when a new download starts. */
    private const STALE_PARTIAL_DAYS = 7;

    /**
     * @param Closure $isZfsInvalid fn(): bool  true when the ZFS configuration is invalid
     * @param Closure $translate    fn(string): string
     */
    public function __construct(
        private readonly BastilleReleaseJob $job,
        private readonly BastilleReleaseCache $cache,
        private readonly string $releasesDir,
        private readonly string $jailsDir,
        private readonly string $bastilleConf,
        private readonly string $defaultArchives,
        private readonly bool $resumeDownloads,
        private readonly Closure $isZfsInvalid,
        private readonly Closure $translate,
    ) {
    }

    public static function handles(string $action): bool
    {
        return in_array($action, self::ACTIONS, true);
    }

    /**
     * @param array<string, mixed> $query GET parameters
     * @param array<string, mixed> $post  POST parameters
     * @return array<string, mixed>
     */
    public function handle(string $action, string $method, array $query, array $post): array
    {
        if ($action === 'status') {
            return $this->status((int) ($query['offset'] ?? 0));
        }
        if ($method !== 'POST') {
            return $this->error('POST required');
        }
        return match ($action) {
            'start' => $this->start($post),
            'discard' => $this->discard($post),
            default => $this->error('Unknown action'),
        };
    }

    /** @return array<string, mixed> */
    private function status(int $offset): array
    {
        $meta = $this->job->meta();
        return [
            'state' => $this->job->state()->value,
            'mode' => $meta['mode'] ?? '',
            'release' => $meta['release'] ?? '',
            'started' => $meta['started'] ?? 0,
        ] + $this->job->readLog($offset);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function start(array $post): array
    {
        if ($this->job->state() === BastilleJobState::Running) {
            return $this->error($this->t('Another operation is already running.'));
        }
        $mode = BastilleJobMode::tryFrom((string) ($post['mode'] ?? ''));
        $release = (string) ($post['release'] ?? '');
        if ($mode === null) {
            return $this->error('Invalid mode');
        }
        if ($mode === BastilleJobMode::Destroy) {
            $release = BastilleReleaseName::installedNameFor($release);
        }

        $error = match ($mode) {
            BastilleJobMode::Bootstrap => $this->checkBootstrap($release),
            BastilleJobMode::Destroy => $this->checkDestroy($release),
        };
        if ($error !== null) {
            return $this->error($error);
        }

        // Housekeeping of staged downloads (see BastilleReleaseCache).
        if ($mode === BastilleJobMode::Bootstrap) {
            // Download a FreeBSD base release.
            $this->cache->purgeStale(self::STALE_PARTIAL_DAYS, $release);
        } else {
            // Delete the FreeBSD base release/directory.
            $this->cache->discard($release);
        }

        try {
            $this->job->start(
                $mode,
                $release,
                $this->bastilleConf,
                $this->archivesFrom($post),
                $this->restoreArchives(),
                // The pre-download knows FreeBSD mirrors only; Linux releases go through bastille.
                $this->resumeDownloads && BastilleReleaseName::isFreeBsd($release),
            );
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function discard(array $post): array
    {
        if ($this->job->state() === BastilleJobState::Running) {
            return $this->error($this->t('Another operation is already running.'));
        }
        return ['ok' => $this->cache->discard((string) ($post['release'] ?? ''))];
    }

    private function checkBootstrap(string $release): ?string
    {
        if (!BastilleReleaseName::isBootstrappable($release)) {
            return 'Invalid release';
        }
        if (($this->isZfsInvalid)()) {
            // Abort bootstrap if invalid ZFS configuration.
            return $this->t('Cannot bootstrap with an invalid ZFS configuration.');
        }
        return null;
    }

    private function checkDestroy(string $release): ?string
    {
        if (!BastilleReleaseName::isInstalledName($release)) {
            return 'Invalid release';
        }
        if (!is_dir("{$this->releasesDir}/{$release}")) {
            // FreeBSD base release check.
            return sprintf($this->t('%s base does not exist, nothing to do.'), $release);
        }
        // Do not delete base releases with containers child.
        if ($this->isUsedByJails($release)) {
            return sprintf($this->t('%s base appears to have containers child.'), $release);
        }
        return null;
    }

    /** Whether any jail fstab mentions the release (same word matching as grep -w). */
    private function isUsedByJails(string $release): bool
    {
        $pattern = '/(?<![A-Za-z0-9_])' . preg_quote($release, '/') . '(?![A-Za-z0-9_])/';
        foreach (glob("{$this->jailsDir}/*/fstab") ?: [] as $fstab) {
            if (preg_match($pattern, (string) file_get_contents($fstab)) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $post */
    private function archivesFrom(array $post): string
    {
        $archives = ['base'];
        foreach (['lib32', 'ports', 'src'] as $optional) {
            if (($post[$optional] ?? '') === 'true') {
                $archives[] = $optional;
            }
        }
        return implode(' ', $archives);
    }

    /** The configured default, unless it is not a plain word list (e.g. a corrupted line). */
    private function restoreArchives(): string
    {
        $default = trim($this->defaultArchives);
        return preg_match('/^[A-Za-z0-9 ]+$/', $default) === 1 ? $default : 'base';
    }

    /** @return array{ok: false, error: string} */
    private function error(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }

    private function t(string $text): string
    {
        return ($this->translate)($text);
    }
}
