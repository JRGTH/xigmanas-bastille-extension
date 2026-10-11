<?php

declare(strict_types=1);

/**
 * HTML and client configuration of the "Base Releases" page.
 */
final class BastilleReleaseView
{
    /** @param Closure $translate fn(string): string */
    public function __construct(private readonly Closure $translate)
    {
    }

    /**
     * The "Incomplete download" rows. The buttons carry data-* attributes that
     * js/modules/release-job.js picks up, so there is no inline JavaScript.
     *
     * @param iterable<BastillePartialDownload> $partials
     */
    public function partialBox(iterable $partials): string
    {
        $html = '';
        foreach ($partials as $partial) {
            $size = $partial->bytesTotal > 0
                ? sprintf('%d / %d MB', $partial->haveMb(), $partial->totalMb())
                : sprintf('%d MB', $partial->haveMb());
            $release = $this->escape($partial->release);

            $html .= '<div class="partial-row" style="padding:5px;">'
                . '<b>' . $this->escape($this->t('Incomplete download')) . ':</b> ' . $release . ' (' . $size . ') '
                . $this->partialButton('resume', $release, $this->t('Resume')) . ' '
                . $this->partialButton('discard', $release, $this->t('Discard'))
                . '</div>';
        }
        return $html;
    }

    /** JSON for window.RELEASE_CONFIG (strings the script cannot translate itself). */
    public function clientConfig(string $endpoint): string
    {
        return json_encode([
            'endpoint' => $endpoint,
            'pollMs' => 1500,
            'text' => $this->notificationTexts() + [
                'downloading' => $this->t('Downloading'),
                'destroying' => $this->t('Destroying'),
                'completed' => $this->t('Completed'),
                'failedDownload' => $this->t('Failed. Press Download again to retry; files already downloaded are reused.'),
                'failedDestroy' => $this->t('Failed.'),
                'interruptedDownload' => $this->t('Interrupted. Press Download again to resume.'),
                'interruptedDestroy' => $this->t('Interrupted.'),
                'connectionLost' => $this->t('Connection lost, retrying...'),
                'confirmDestroy' => $this->t('Do you really want to destroy this base release?'),
                'confirmDiscard' => $this->t('Discard this incomplete download?'),
            ],
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * JSON for window.RELEASE_CONFIG on pages that only watch a release job and notify when it
     * ends (bastille_manager_gui.php): the endpoint and the notification texts, nothing else.
     */
    public function watchConfig(string $endpoint): string
    {
        return json_encode([
            'endpoint' => $endpoint,
            'pollMs' => 3000,
            'text' => $this->notificationTexts(),
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /** @return array<string, string> */
    private function notificationTexts(): array
    {
        return [
            'notificationTitle' => $this->t('Base Releases'),
            'notifyDownloadDone' => $this->t('Download of version %s completed successfully.'),
            'notifyDownloadFailed' => $this->t('Download of version %s failed.'),
            'notifyDownloadInterrupted' => $this->t('Download of version %s was interrupted.'),
            'notifyDestroyDone' => $this->t('Deletion of version %s completed successfully.'),
            'notifyDestroyFailed' => $this->t('Deletion of version %s failed.'),
            'notifyDestroyInterrupted' => $this->t('Deletion of version %s was interrupted.'),
        ];
    }

    private function partialButton(string $action, string $escapedRelease, string $label): string
    {
        return '<input type="button" class="formbtn partial-btn" data-partial-action="' . $action
            . '" data-release="' . $escapedRelease . '" value="' . $this->escape($label) . '" />';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }

    private function t(string $text): string
    {
        return ($this->translate)($text);
    }
}
