<?php

declare(strict_types=1);

/**
 * Validation of release names coming from the browser. Anything that reaches a shell
 * command or a file path must pass one of these first.
 */
final class BastilleReleaseName
{
    private const FREEBSD = '/^[0-9]+\.[0-9]+-RELEASE$/D';
    private const INSTALLED = '/^([0-9]+\.[0-9]+-RELEASE|Debian[0-9]{1,2}|Ubuntu_[0-9]{4})$/D';

    private function __construct()
    {
    }

    /** A FreeBSD release that can be bootstrapped, e.g. "14.4-RELEASE". */
    public static function isBootstrappable(string $name): bool
    {
        return preg_match(self::FREEBSD, $name) === 1;
    }

    /** Any base release that can exist under releases/ (FreeBSD, Debian, Ubuntu). */
    public static function isInstalledName(string $name): bool
    {
        return preg_match(self::INSTALLED, $name) === 1;
    }
}
