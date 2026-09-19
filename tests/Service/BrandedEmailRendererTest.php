<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BrandedEmailRenderer;
use PHPUnit\Framework\TestCase;

final class BrandedEmailRendererTest extends TestCase
{
    public function testThePageCarriesTheBrandAndTheTitle(): void
    {
        $html = $this->renderer()->render('Demande bien reçue', 'Un aperçu', '<p>corps</p>');

        self::assertStringContainsString('<!doctype html>', $html);
        self::assertStringContainsString('Facundo Varas', $html);
        self::assertStringContainsString('<title>Demande bien reçue</title>', $html);
        self::assertStringContainsString('varascundo.com', $html);
        self::assertStringContainsString('<p>corps</p>', $html);
    }

    /**
     * Inboxes show the preheader next to the subject, so it has to be in the
     * markup — but never visible once the message is open.
     */
    public function testThePreheaderIsPresentButHidden(): void
    {
        $html = $this->renderer()->render('Titre', 'Voici le récapitulatif.', '');

        self::assertMatchesRegularExpression(
            '/<div style="display:none[^"]*">Voici le récapitulatif\.<\/div>/',
            $html,
        );
    }

    /**
     * Everything a visitor typed reaches these helpers. Markup in a message must
     * arrive as text in the mailbox, never as tags.
     */
    public function testEveryHelperEscapesWhatTheVisitorTyped(): void
    {
        $renderer = $this->renderer();
        $attack = '<script>alert("x")</script> & "guillemets"';

        $pieces = [
            $renderer->paragraph($attack),
            $renderer->subheading($attack),
            $renderer->rows([$attack => $attack]),
            $renderer->bullets([$attack], 'vide'),
            $renderer->quote($attack),
            $renderer->note($attack),
            $renderer->signature($attack),
            $renderer->render($attack, $attack, ''),
        ];

        foreach ($pieces as $index => $piece) {
            self::assertStringNotContainsString('<script>', $piece, 'fragment '.$index);
            self::assertStringContainsString('&lt;script&gt;', $piece, 'fragment '.$index);
        }
    }

    public function testANewlineInsideAValueBecomesALineBreak(): void
    {
        $html = $this->renderer()->quote("première ligne\nseconde ligne");

        // nl2br inserts the tag and keeps the newline, which is what a mail
        // client needs to render the break and a text-mode reader to keep it.
        self::assertStringContainsString("première ligne<br>\nseconde ligne", $html);
    }

    public function testAnEmptyListFallsBackToTheGivenWording(): void
    {
        $html = $this->renderer()->bullets([], 'Aucune option');

        self::assertStringContainsString('Aucune option', $html);
    }

    /**
     * Outlook drops <style> blocks and external sheets, so nothing may depend on
     * them, and a web font would silently fall back anyway.
     */
    public function testTheStylingStaysInlineAndSelfContained(): void
    {
        $html = $this->renderer()->render('Titre', 'Aperçu', $this->renderer()->paragraph('texte'));

        self::assertStringNotContainsString('<style', $html);
        self::assertStringNotContainsString('<link', $html);
        self::assertStringNotContainsString('@import', $html);
        self::assertStringNotContainsString('fonts.googleapis.com', $html);
    }

    private function renderer(): BrandedEmailRenderer
    {
        return new BrandedEmailRenderer('Facundo Varas', 'https://varascundo.com');
    }
}
