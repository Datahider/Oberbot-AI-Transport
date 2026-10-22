<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use FastVolt\Helper\Markdown;

final class TelegramTextRenderer
{
    public function renderFinal(string $markdown): string
    {
        return $this->markdownToTelegramHtml($markdown);
    }

    public function renderCommentary(string $markdown): string
    {
        return '<i>' . $this->markdownToTelegramHtml($markdown) . '</i>';
    }

    public function renderTranscript(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return '<blockquote expandable>'
            . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</blockquote>';
    }

    private function markdownToTelegramHtml(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        $this->loadBrokenFastVoltDependencies();

        if (!class_exists(Markdown::class)) {
            return htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $instance = Markdown::new()->setContent($markdown);
        $html = method_exists($instance, 'getHtml')
            ? $instance->getHtml()
            : $instance->toHtml();

        return trim($this->replaceUnsupported((string) $html));
    }

    private function loadBrokenFastVoltDependencies(): void
    {
        if (class_exists(\FastVolt\Helper\Libs\Markdown\Process\ParseMarkdown::class)) {
            return;
        }

        $path = dirname(__DIR__) . '/vendor/fastvolt/markdown/src/libs/Markdown/Process/ParseMarkdown.php';
        if (is_file($path)) {
            require_once $path;
        }
    }

    private function replaceUnsupported(string $html): string
    {
        $html = (string) preg_replace('/(<li\b[^>]*>)\s*\[ \]\s+/u', '$1🔲 ', $html);
        $html = (string) preg_replace('/(<li\b[^>]*>)\s*\[[xX]\]\s+/u', '$1✅ ', $html);
        $html = $this->processOrderedLists($html);
        $html = $this->processTables($html);

        $html = str_replace('<p>', "\n", $html);
        $html = str_replace('</p>', '', $html);
        $html = str_replace('<ul>', '', $html);
        $html = str_replace('</ul>', '', $html);
        $html = (string) preg_replace('/<ol\b[^>]*>/i', '', $html);
        $html = str_replace('</ol>', '', $html);
        $html = (string) preg_replace('/(?<!\n)<li\b[^>]*>/i', "\n<li>", $html);
        $html = (string) preg_replace('/<li\b[^>]*>(?=(?:🔲|✅) )/iu', '', $html);
        $html = (string) preg_replace('/<li\b[^>]*>/i', '• ', $html);
        $html = str_replace('</li>', '', $html);
        $html = str_replace('<p>', "\n", $html);
        $html = str_replace('</p>', '', $html);

        $html = str_replace('<br>', '', $html);
        $html = str_replace('<br />', '', $html);
        $html = str_replace('<hr>', '──────────', $html);
        $html = str_replace('<hr />', '──────────', $html);

        $html = (string) preg_replace('/<blockquote>\s*/', '<blockquote expandable>', $html);
        $html = (string) preg_replace_callback(
            '/<h1>(.*?)<\/h1>/i',
            fn (array $m): string => '<b>' . $this->uppercaseHtmlText($m[1]) . '</b>',
            $html
        );
        $html = (string) preg_replace_callback(
            '/<h2>(.*?)<\/h2>/i',
            fn (array $m): string => '<b>' . $this->uppercaseHtmlText($m[1]) . '</b>',
            $html
        );
        $html = (string) preg_replace('/<h3>(.*?)<\/h3>/i', '<b>$1</b>', $html);
        $html = (string) preg_replace('/<h4>(.*?)<\/h4>/i', '<b>$1</b>', $html);
        $html = (string) preg_replace('/<h5>(.*?)<\/h5>/i', '<u>$1</u>', $html);
        $html = (string) preg_replace('/<h6>(.*?)<\/h6>/i', '<u>$1</u>', $html);

        return ltrim($html);
    }

    private function uppercaseHtmlText(string $html): string
    {
        $parts = preg_split('/(<[^>]*>|&[#a-zA-Z0-9]+;)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new \RuntimeException('Failed to split heading HTML');
        }

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = mb_strtoupper($part);
            }
        }

        return implode('', $parts);
    }

    private function processTables(string $html): string
    {
        return (string) preg_replace_callback('/<table\b[^>]*>(.*?)<\/table>/is', static function (array $tableMatches): string {
            $rows = [];

            preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $tableMatches[1] ?? '', $rowMatches);

            foreach ($rowMatches[1] ?? [] as $rowHtml) {
                preg_match_all('/<(td|th)\b[^>]*>(.*?)<\/\1>/is', $rowHtml, $cellMatches);
                $cells = array_map(
                    static function (string $cellHtml): string {
                        $text = strip_tags($cellHtml);
                        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
                    },
                    $cellMatches[2] ?? []
                );

                $cells = array_values(array_filter($cells, static fn (string $cell): bool => $cell !== ''));
                if ($cells !== []) {
                    $rows[] = implode(' | ', $cells);
                }
            }

            if ($rows === []) {
                return '';
            }

            return "\n" . implode("\n", $rows) . "\n";
        }, $html);
    }

    private function processOrderedLists(string $html): string
    {
        return (string) preg_replace_callback('/<ol\b([^>]*)>(.*?)<\/ol>/is', static function (array $matches): string {
            $attributes = $matches[1] ?? '';
            $index = 1;

            if (preg_match('/\bstart\s*=\s*["\']?(\d+)["\']?/i', $attributes, $startMatches) === 1) {
                $index = max(1, (int) $startMatches[1]);
            }

            $items = (string) preg_replace('/<\/li>\s+<li\b/i', '</li><li', trim($matches[2]));

            return (string) preg_replace_callback('/<li\b[^>]*>(.*?)<\/li>/is', static function (array $itemMatches) use (&$index): string {
                $line = $index . '. ' . trim($itemMatches[1]);
                $index++;

                return $line . "\n";
            }, $items);
        }, $html);
    }
}
