<?php

declare(strict_types=1);

/**
 * Validation of release names coming from the browser. Anything that reaches a shell
 * command or a file path must pass one of these first.
 */
final class BastilleReleaseName
{
    private const FREEBSD = '/^[0-9]+\.[0-9]+-RELEASE$/D';
    /** Linux base releases as listed (commented out) in the release combobox, e.g. "ubuntu-noble". */
    private const LINUX_SELECTION = '/^(ubuntu|debian)-[a-z]+$/D';
    private const INSTALLED = '/^([0-9]+\.[0-9]+-RELEASE|Debian[0-9]{1,2}|Ubuntu_[0-9]{4})$/D';

    /** Combobox value of a Linux release => name of its directory under releases/. */
    private const LINUX_DIRECTORIES = [
        'ubuntu-bionic' => 'Ubuntu_1804',
        'ubuntu-focal' => 'Ubuntu_2004',
        'ubuntu-jammy' => 'Ubuntu_2204',
        'debian-stretch' => 'Debian9',
        'debian-buster' => 'Debian10',
        'debian-bullseye' => 'Debian11',
        'debian-bookworm' => 'Debian12',
    ];

    private function __construct()
    {
    }

    /** A FreeBSD release, e.g. "14.4-RELEASE". Only these can be pre-downloaded. */
    public static function isFreeBsd(string $name): bool
    {
        return preg_match(self::FREEBSD, $name) === 1;
    }

    /** Anything the combobox can offer for bootstrap: a FreeBSD or a Linux release. */
    public static function isBootstrappable(string $name): bool
    {
        return self::isFreeBsd($name) || preg_match(self::LINUX_SELECTION, $name) === 1;
    }

    /** Any base release that can exist under releases/ (FreeBSD, Debian, Ubuntu). */
    public static function isInstalledName(string $name): bool
    {
        return preg_match(self::INSTALLED, $name) === 1;
    }

    /** The directory under releases/ for a combobox value ("ubuntu-bionic" => "Ubuntu_1804"). */
    public static function installedNameFor(string $selection): string
    {
        return self::LINUX_DIRECTORIES[$selection] ?? $selection;
    }
}
