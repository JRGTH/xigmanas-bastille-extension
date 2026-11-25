<?php

declare(strict_types=1);

/**
 * Color palettes of the web terminal. Each one has a dark and a light variant, so the choice of
 * palette is independent of the dark/light/auto theme.
 *
 * The background of every variant is also used by the modal (the margin around the terminal),
 * see backgrounds().
 */
final class BastilleTerminalPalettes
{
    public const DEFAULT = 'one';

    /** @return array<string, array{label: string, dark: array<string, string>, light: array<string, string>}> */
    public static function all(): array
    {
        return [
            'one' => [
                'label' => 'One',
                'dark' => self::variant('#282c34', '#abb2bf', '#528bff', '#3e4451',
                    ['#282c34', '#e06c75', '#98c379', '#e5c07b', '#61afef', '#c678dd', '#56b6c2', '#abb2bf'],
                    ['#5c6370', '#e06c75', '#98c379', '#e5c07b', '#61afef', '#c678dd', '#56b6c2', '#ffffff']),
                'light' => self::variant('#fafafa', '#383a42', '#526fff', '#dbe4f5',
                    ['#383a42', '#e45649', '#50a14f', '#c18401', '#4078f2', '#a626a4', '#0184bc', '#a0a1a7'],
                    ['#696c77', '#e45649', '#50a14f', '#c18401', '#4078f2', '#a626a4', '#0184bc', '#fafafa']),
            ],
            'nord' => [
                'label' => 'Nord',
                'dark' => self::variant('#2e3440', '#d8dee9', '#d8dee9', '#434c5e',
                    ['#3b4252', '#bf616a', '#a3be8c', '#ebcb8b', '#81a1c1', '#b48ead', '#88c0d0', '#e5e9f0'],
                    ['#4c566a', '#bf616a', '#a3be8c', '#ebcb8b', '#81a1c1', '#b48ead', '#8fbcbb', '#eceff4']),
                'light' => self::variant('#eceff4', '#2e3440', '#2e3440', '#d8dee9',
                    ['#3b4252', '#bf616a', '#5e8c4a', '#b58a2c', '#5e81ac', '#9b6f95', '#3f8f9d', '#a0a8b8'],
                    ['#4c566a', '#bf616a', '#5e8c4a', '#b58a2c', '#5e81ac', '#9b6f95', '#3f8f9d', '#eceff4']),
            ],
            'dracula' => [
                'label' => 'Dracula',
                'dark' => self::variant('#282a36', '#f8f8f2', '#f8f8f2', '#44475a',
                    ['#21222c', '#ff5555', '#50fa7b', '#f1fa8c', '#bd93f9', '#ff79c6', '#8be9fd', '#f8f8f2'],
                    ['#6272a4', '#ff6e6e', '#69ff94', '#ffffa5', '#d6acff', '#ff92df', '#a4ffff', '#ffffff']),
                'light' => self::variant('#fffbeb', '#1f1f1f', '#1f1f1f', '#cfcfde',
                    ['#1f1f1f', '#cb3a2a', '#14710a', '#846e15', '#644ac9', '#a3144d', '#036a96', '#cfcfde'],
                    ['#6c664b', '#d74c3c', '#198d0d', '#9a8218', '#7a63d6', '#b9205d', '#0a7ea8', '#fffbeb']),
            ],
        ];
    }

    /** @return array<string, string> the variant, or the default palette's one when the id is unknown */
    public static function theme(string $id, string $mode): array
    {
        $all = self::all();
        $palette = $all[$id] ?? $all[self::DEFAULT];
        return $mode === 'light' ? $palette['light'] : $palette['dark'];
    }

    /** @return array<string, array{dark: string, light: string}> background of each variant, for the modal */
    public static function backgrounds(): array
    {
        $backgrounds = [];
        foreach (self::all() as $id => $palette) {
            $backgrounds[$id] = ['dark' => $palette['dark']['background'], 'light' => $palette['light']['background']];
        }
        return $backgrounds;
    }

    /**
     * @param list<string> $ansi   black, red, green, yellow, blue, magenta, cyan, white
     * @param list<string> $bright the same eight, bright
     * @return array<string, string>
     */
    private static function variant(string $bg, string $fg, string $cursor, string $selection, array $ansi, array $bright): array
    {
        $names = ['Black', 'Red', 'Green', 'Yellow', 'Blue', 'Magenta', 'Cyan', 'White'];
        $theme = [
            'background' => $bg, 'foreground' => $fg,
            'cursor' => $cursor, 'cursorAccent' => $bg,
            'selectionBackground' => $selection,
        ];
        foreach ($names as $i => $name) {
            $theme[strtolower($name)] = $ansi[$i];
            $theme['bright' . $name] = $bright[$i];
        }
        return $theme;
    }
}
