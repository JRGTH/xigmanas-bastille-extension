<?php

declare(strict_types=1);

/**
 * Lifecycle of the (single) release job. The backing value is what the status endpoint returns.
 */
enum BastilleJobState: string
{
    /** No job has been started yet. */
    case None = 'none';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    /** The job died without writing its exit code (killed, reboot...). */
    case Interrupted = 'interrupted';

    public function isFinished(): bool
    {
        return match ($this) {
            self::Done, self::Failed, self::Interrupted => true,
            self::None, self::Running => false,
        };
    }
}
