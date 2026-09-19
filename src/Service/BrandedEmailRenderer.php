<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Builds the HTML half of the emails the site sends, in the portfolio's own
 * style: off-white paper, hard black rules, a mono label above every value.
 *
 * Email rules this follows, and why:
 *  - tables and inline styles only, because Outlook ignores <style> and flex;
 *  - no web font, so the display face falls back to a condensed stack;
 *  - box-shadow is unreliable, so the offset shadow is a dark cell showing
 *    through the padding of the one above it;
 *  - the shell is width:100% capped by max-width, never width="600": the fixed
 *    attribute kept the table from shrinking and pushed a phone into sideways
 *    scrolling;
 *  - every caller-supplied string is escaped here, never by the caller: the
 *    message body is typed by a visitor and must not be able to inject markup.
 */
final class BrandedEmailRenderer
{
    private const PAPER = '#f3eddf';
    private const CARD = '#fffef8';
    private const INK = '#121827';
    private const MUTED = '#596273';
    private const ACCENT = '#dd614c';
    private const DISPLAY = "'Arial Narrow','Avenir Next Condensed',Impact,Haettenschweiler,sans-serif";
    private const BODY = "'IBM Plex Sans',-apple-system,'Segoe UI',Helvetica,Arial,sans-serif";
    private const MONO = "'IBM Plex Mono','SF Mono',Menlo,Consolas,monospace";

    public function __construct(
        private readonly string $brandName,
        private readonly string $brandUrl,
    ) {
    }

    /**
     * @param string $preheader the line inboxes show next to the subject; kept
     *                          out of sight in the body itself
     */
    public function render(string $title, string $preheader, string $bodyHtml): string
    {
        $paper = self::PAPER;
        $card = self::CARD;
        $ink = self::INK;
        $muted = self::MUTED;
        $display = self::DISPLAY;
        $body = self::BODY;
        $mono = self::MONO;

        return <<<HTML
            <!doctype html>
            <html lang="fr">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>{$this->escape($title)}</title>
            </head>
            <body style="margin:0;padding:0;background:{$paper};">
            <div style="display:none;max-height:0;overflow:hidden;opacity:0;">{$this->escape($preheader)}</div>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{$paper};">
            <tr><td align="center" style="padding:28px 12px 40px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">

            <tr><td style="padding-bottom:18px;">
              <span style="font-family:{$display};font-size:30px;line-height:1;letter-spacing:.02em;text-transform:uppercase;color:{$ink};">{$this->escape($this->brandName)}</span>
            </td></tr>

            <tr><td bgcolor="{$ink}" style="background:{$ink};">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr><td style="padding:0 7px 7px 0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{$card}" style="background:{$card};border:3px solid {$ink};">
                <tr><td style="padding:28px 22px;font-family:{$body};font-size:16px;line-height:1.55;color:{$ink};">
                  <h1 style="margin:0 0 20px;font-family:{$display};font-size:38px;line-height:1.02;text-transform:uppercase;color:{$ink};">{$this->escape($title)}</h1>
                  {$bodyHtml}
                </td></tr>
                </table>
              </td></tr>
              </table>
            </td></tr>

            <tr><td style="padding-top:20px;font-family:{$mono};font-size:11px;line-height:1.7;color:{$muted};text-transform:uppercase;letter-spacing:.04em;">
              {$this->escape($this->brandName)} — <a href="{$this->escape($this->brandUrl)}" style="color:{$muted};text-decoration:underline;">{$this->escape($this->hostOnly())}</a>
            </td></tr>

            </table>
            </td></tr>
            </table>
            </body>
            </html>
            HTML;
    }

    public function paragraph(string $text): string
    {
        return sprintf(
            '<p style="margin:0 0 16px;font-family:%s;font-size:16px;line-height:1.55;color:%s;">%s</p>',
            self::BODY,
            self::INK,
            $this->escapeMultiline($text),
        );
    }

    public function subheading(string $text): string
    {
        return sprintf(
            '<p style="margin:26px 0 12px;font-family:%s;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:%s;">%s</p>',
            self::MONO,
            self::MUTED,
            $this->escape($text),
        );
    }

    /**
     * @param array<string, string> $pairs label => value, in reading order
     */
    public function rows(array $pairs): string
    {
        $rows = '';
        foreach ($pairs as $label => $value) {
            $rows .= sprintf(
                '<tr>'
                // A raw answer key and an email address are single unbreakable
                // tokens: without a break they set a minimum the card cannot go
                // under, and a 320px phone scrolls sideways.
                .'<td style="padding:9px 14px 9px 0;border-bottom:1px solid rgba(18,24,39,.14);font-family:%s;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:%s;vertical-align:top;overflow-wrap:break-word;word-break:break-word;">%s</td>'
                .'<td style="padding:9px 0;border-bottom:1px solid rgba(18,24,39,.14);font-family:%s;font-size:15px;line-height:1.5;color:%s;vertical-align:top;overflow-wrap:break-word;word-break:break-word;">%s</td>'
                .'</tr>',
                self::MONO,
                self::MUTED,
                $this->escape((string) $label),
                self::BODY,
                self::INK,
                $this->escapeMultiline($value),
            );
        }

        return sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 4px;">%s</table>',
            $rows,
        );
    }

    /**
     * @param list<string> $items
     */
    public function bullets(array $items, string $empty): string
    {
        $lines = array_values(array_filter($items, static fn ($item): bool => is_string($item) && trim($item) !== ''));
        if ($lines === []) {
            $lines = [$empty];
        }

        $rendered = '';
        foreach ($lines as $line) {
            $rendered .= sprintf(
                '<tr>'
                .'<td width="18" style="padding:4px 0;font-family:%s;font-size:14px;color:%s;vertical-align:top;">&#10003;</td>'
                .'<td style="padding:4px 0;font-family:%s;font-size:15px;line-height:1.5;color:%s;">%s</td>'
                .'</tr>',
                self::MONO,
                self::ACCENT,
                self::BODY,
                self::INK,
                $this->escape($line),
            );
        }

        return sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 4px;">%s</table>',
            $rendered,
        );
    }

    /** The visitor's own words, set apart so they can check them at a glance. */
    public function quote(string $text): string
    {
        return sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">'
            .'<tr><td style="padding:16px 18px;border-left:4px solid %s;background:rgba(18,24,39,.045);'
            .'font-family:%s;font-size:15px;line-height:1.6;color:%s;">%s</td></tr></table>',
            self::ACCENT,
            self::BODY,
            self::INK,
            $this->escapeMultiline($text),
        );
    }

    public function note(string $text): string
    {
        return sprintf(
            '<p style="margin:18px 0 0;padding-top:16px;border-top:2px solid %s;font-family:%s;font-size:12px;line-height:1.6;color:%s;">%s</p>',
            self::INK,
            self::MONO,
            self::MUTED,
            $this->escapeMultiline($text),
        );
    }

    public function signature(string $name): string
    {
        return sprintf(
            '<p style="margin:24px 0 0;font-family:%s;font-size:16px;color:%s;">À très vite,<br><strong>%s</strong></p>',
            self::BODY,
            self::INK,
            $this->escape($name),
        );
    }

    private function hostOnly(): string
    {
        return (string) (parse_url($this->brandUrl, PHP_URL_HOST) ?: $this->brandUrl);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Escapes first, then turns the newlines the plain-text bodies rely on into <br>. */
    private function escapeMultiline(string $value): string
    {
        return nl2br($this->escape($value), false);
    }
}
