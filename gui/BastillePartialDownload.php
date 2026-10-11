<?php

declare(strict_types=1);

/**
 * An interrupted download staged in <cache>/.resume/<release>.
 *
 * Immutable value object: all properties are readonly, so it can be passed around freely.
 */
final readonly class BastillePartialDownload
{
    private const MB = 1048576;

    public function __construct(
        public string $release,
        public int $bytesHave,
        /** Total bytes of all archives, 0 when unknown. */
        public int $bytesTotal,
    ) {
    }

    public function haveMb(): int
    {
        return intdiv($this->bytesHave, self::MB);
    }

    public function totalMb(): int
    {
        return intdiv($this->bytesTotal, self::MB);
    }
}
