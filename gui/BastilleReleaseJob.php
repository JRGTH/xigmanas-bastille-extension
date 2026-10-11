<?php

declare(strict_types=1);

/**
 * The single background job that downloads or destroys a base release.
 *
 * The job is a detached shell script (bastille_manager_release_job.sh) so it survives the
 * HTTP request. Everything it reports lives in <jobDir>:
 *   job.json  what was started (mode, release, start time)  - written here
 *   job.pid   pid of the script                             - written by the script
 *   job.log   combined output                               - written by the script
 *   job.exit  exit code, present once the script finished   - written by the script
 */
final class BastilleReleaseJob
{
    /** Seconds a freshly started job may take to write its pid before it counts as dead. */
    private const START_GRACE = 5;
    /** Max bytes of log returned per poll. */
    private const READ_CHUNK = 262144;

    /**
     * @param Closure|null $isAlive  fn(int $pid): bool, overridable for tests
     * @param Closure|null $launcher fn(string $command): void, overridable for tests
     */
    public function __construct(
        private readonly string $jobDir,
        private readonly string $scriptPath,
        private readonly ?Closure $isAlive = null,
        private readonly ?Closure $launcher = null,
    ) {
    }

    public function state(): BastilleJobState
    {
        $meta = $this->meta();
        if ($meta === null) {
            return BastilleJobState::None;
        }
        if (is_file($this->path('job.exit'))) {
            return (int) trim((string) file_get_contents($this->path('job.exit'))) === 0
                ? BastilleJobState::Done
                : BastilleJobState::Failed;
        }
        if ($this->isProcessAlive($this->readPid())) {
            return BastilleJobState::Running;
        }
        // The script may not have written its pid yet.
        return time() - (int) ($meta['started'] ?? 0) < self::START_GRACE
            ? BastilleJobState::Running
            : BastilleJobState::Interrupted;
    }

    /** @return array{mode: string, release: string, started: int}|null */
    public function meta(): ?array
    {
        $file = $this->path('job.json');
        if (!is_file($file)) {
            return null;
        }
        try {
            $meta = json_decode((string) file_get_contents($file), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        return is_array($meta) ? $meta : null;
    }

    /**
     * Log text from $offset on.
     *
     * @return array{text: string, offset: int} the new text and the offset to ask next time
     */
    public function readLog(int $offset): array
    {
        $offset = max(0, $offset);
        $file = $this->path('job.log');
        $text = '';
        if (is_file($file) && filesize($file) > $offset && ($handle = fopen($file, 'rb')) !== false) {
            fseek($handle, $offset);
            $text = (string) fread($handle, self::READ_CHUNK);
            fclose($handle);
        }
        return ['text' => $text, 'offset' => $offset + strlen($text)];
    }

    /**
     * Start the job. The caller validates $release and $archives.
     *
     * @param string $archives        e.g. "base lib32"
     * @param string $restoreArchives bastille_bootstrap_archives to put back afterwards
     * @param bool   $resume          pre-download archives resumably (see the script header)
     *
     * @throws RuntimeException when a job is already running or the job dir is not writable
     */
    public function start(
        BastilleJobMode $mode,
        string $release,
        string $bastilleConf,
        string $archives,
        string $restoreArchives,
        bool $resume = true,
    ): void {
        if ($this->state() === BastilleJobState::Running) {
            throw new RuntimeException('Another operation is already running.');
        }
        if (!is_dir($this->jobDir) && !mkdir($this->jobDir, 0755, true) && !is_dir($this->jobDir)) {
            throw new RuntimeException("Cannot create {$this->jobDir}");
        }
        foreach (['job.log', 'job.pid', 'job.exit'] as $file) {
            if (is_file($this->path($file))) {
                unlink($this->path($file));
            }
        }

        file_put_contents($this->path('job.json'), json_encode([
            'mode' => $mode->value,
            'release' => $release,
            'started' => time(),
        ], JSON_THROW_ON_ERROR));

        $command = implode(' ', array_map(escapeshellarg(...), [
            '/bin/sh', $this->scriptPath, $this->jobDir, $mode->value, $release,
            $bastilleConf, $archives, $restoreArchives, $resume ? '1' : '0',
        ]));
        ($this->launcher ?? self::launchDetached(...))($command);
    }

    private function path(string $file): string
    {
        return "{$this->jobDir}/{$file}";
    }

    private function readPid(): int
    {
        $file = $this->path('job.pid');
        return is_file($file) ? (int) trim((string) file_get_contents($file)) : 0;
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if ($this->isAlive !== null) {
            return ($this->isAlive)($pid);
        }
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $output, $code);
        return $code === 0;
    }

    private static function launchDetached(string $command): void
    {
        exec('/usr/bin/nohup ' . $command . ' > /dev/null 2>&1 &');
    }
}
