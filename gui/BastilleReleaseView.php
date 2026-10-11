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
            'text' => [
                'downloading' => $this->t('Downloading'),
                'destroying' => $this->t('Destroying'),
                'completed' => $this->t('Completed'),
                'failedDownload' => $this->t('Failed. Press Download again to retry; files already downloaded are reused.'),
                'failedDestroy' => $this->t('Failed.'),
                'interruptedDownload' => $this->t('Interrupted. Press Download again to resume.'),
                'interruptedDestroy' => $this->t('Interrupted.'),
                'connectionLost' => $this->t('Connection lost, retrying...'),
                'notificationTitle' => $this->t('Base Releases'),
                'confirmDestroy' => $this->t('Do you really want to destroy this base release?'),
                'confirmDiscard' => $this->t('Discard this incomplete download?'),
            ],
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
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
