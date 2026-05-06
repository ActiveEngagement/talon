<?php

declare(strict_types=1);

namespace Actengage\Talon;

class TextQuotations
{
    private const MAX_LINES = 1000;

    private const SPLITTER_MAX_LINES = 6;

    /**
     * Extract the original (non-quoted) message from plain text.
     */
    public static function extract(string $text): string
    {
        $delimiter = self::getDelimiter($text);
        $text = self::preprocess($text, $delimiter);

        /** @var list<string> $lines */
        $lines = array_slice(explode($delimiter, $text), 0, self::MAX_LINES);
        $markers = self::markLines($lines);
        $lines = self::processMarkedLines($lines, $markers);

        $text = implode($delimiter, $lines);

        return self::postprocess($text);
    }

    /**
     * Mark each line with a single character describing its role.
     *
     * e = empty
     * m = quotation marker line (starts with >)
     * s = splitter (e.g. "On date, person wrote:")
     * f = forwarded message header
     * t = regular text
     *
     * @param  list<string>  $lines
     */
    public static function markLines(array $lines): string
    {
        $count = count($lines);
        $markers = array_fill(0, $count, 'e');
        $i = 0;

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $markers[$i] = 'e';
            } elseif (preg_match(Patterns::QUOT_PATTERN, $line)) {
                $markers[$i] = 'm';
            } elseif (preg_match(Patterns::RE_FWD, trim($line))) {
                $markers[$i] = 'f';
            } else {
                $chunk = implode("\n", array_slice($lines, $i, self::SPLITTER_MAX_LINES));
                $splitter = self::isSplitter($chunk);

                if ($splitter !== null) {
                    $splitterLines = explode("\n", $splitter);
                    $count = count($splitterLines);
                    for ($j = 0; $j < $count; $j++) {
                        $markers[$i + $j] = 's';
                    }
                    $i += $count - 1;
                } else {
                    $markers[$i] = 't';
                }
            }

            $i++;
        }

        return implode('', $markers);
    }

    /**
     * Strip quotation lines from the line array.
     *
     * @param  list<string>  $lines
     * @param  array<int,bool|int>  $returnFlags
     *
     * @param-out  array{0: bool, 1: int, 2: int}  $returnFlags  [wereDeleted, firstDeleted, lastDeleted]
     *
     * @return list<string>
     */
    public static function processMarkedLines(array $lines, string $markers, array &$returnFlags = []): array
    {
        $returnFlags = [false, -1, -1];

        // No splitter and fewer than 3 consecutive quoted lines → treat quoted as text
        if (! str_contains($markers, 's') && ! preg_match('/(me*){3}/', $markers)) {
            $markers = str_replace('m', 't', $markers);
        }

        // Message starts with forwarded block → nothing to cut
        if (preg_match('/^[te]*f/', $markers)) {
            return $lines;
        }

        // Inline reply: quoted text interrupted by normal text — don't cut
        if (preg_match_all('/(?<=m)e*(t[te]*)m/', $markers, $inlineMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($inlineMatches[0] as $match) {
                $pos = $match[1];
                $prevLine = $lines[$pos - 1] ?? '';
                $curLine = ltrim($lines[$pos] ?? '');

                $hasLink = preg_match(Patterns::RE_PARENTHESIS_LINK, $prevLine) ||
                           preg_match(Patterns::RE_PARENTHESIS_LINK, $curLine);

                if (! $hasLink) {
                    return $lines;
                }
            }
        }

        // Splitter followed by text lines — cut from splitter onwards
        if (preg_match('/(se*)+((t|f)+e*)+/', $markers, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
            $returnFlags = [true, $start, count($lines)];

            return array_slice($lines, 0, $start);
        }

        // Quoted-marker region
        if (preg_match(Patterns::RE_QUOTATION, $markers, $m, PREG_OFFSET_CAPTURE) ||
            preg_match(Patterns::RE_EMPTY_QUOTATION, $markers, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[1][1];
            $end = $start + mb_strlen($m[1][0]);
            $returnFlags = [true, $start, $end];

            return array_merge(
                array_slice($lines, 0, $start),
                array_slice($lines, $end)
            );
        }

        return $lines;
    }

    /**
     * Check if a string (potentially multi-line) is a splitter.
     * Returns the matched splitter text, or null.
     */
    public static function isSplitter(string $line): ?string
    {
        $patterns = array_merge(
            [
                Patterns::RE_ORIGINAL_MESSAGE,
                Patterns::RE_ON_DATE_SMB_WROTE,
                Patterns::RE_ON_DATE_WROTE_SMB,
                Patterns::RE_FROM_COLON_OR_DATE_COLON,
                Patterns::RE_ANDROID_WROTE,
                Patterns::RE_POLYMAIL,
            ],
            Patterns::EXTRA_SPLITTER_PATTERNS
        );

        foreach ($patterns as $pattern) {
            // Python's is_splitter() uses re.match(), which anchors at position 0.
            // We replicate that by checking the match starts at offset 0.
            if (! (preg_match($pattern, $line, $m, PREG_OFFSET_CAPTURE) && $m[0][1] === 0)) {
                continue;
            }

            return $m[0][0];
        }

        return null;
    }

    /**
     * Detect the line delimiter used in the message (\r\n or \n).
     *
     * @return non-empty-string
     */
    public static function getDelimiter(string $text): string
    {
        return str_contains($text, "\r\n") ? "\r\n" : "\n";
    }

    /**
     * Preprocess: normalise link brackets and wrap splitters with newlines.
     */
    public static function preprocess(string $text, string $delimiter, string $contentType = 'text/plain'): string
    {
        $text = self::replaceLinkBrackets($text);

        if ($contentType === 'text/plain') {
            return self::wrapSplitterWithNewline($text, $delimiter);
        }

        return $text;
    }

    /**
     * Postprocess: restore link brackets and trim.
     */
    public static function postprocess(string $text): string
    {
        $text = preg_replace(Patterns::RE_NORMALIZED_LINK, '<$1>', $text) ?? $text;

        return trim($text);
    }

    // -------------------------------------------------------------------------

    private static function replaceLinkBrackets(string $text): string
    {
        return preg_replace_callback(
            Patterns::RE_LINK,
            /** @param array<int,string> $m */
            static function (array $m) use ($text): string {
                $pos = mb_strpos($text, $m[0]);
                $newlinePos = mb_strrpos(mb_substr($text, 0, (int) $pos), "\n");
                $charAfterNewline = mb_substr($text, ($newlinePos === false ? 0 : $newlinePos) + 1, 1);

                return $charAfterNewline === '>' ? $m[0] : sprintf('@@%s@@', $m[1]);
            },
            $text
        ) ?? $text;
    }

    private static function wrapSplitterWithNewline(string $text, string $delimiter): string
    {
        return preg_replace_callback(
            Patterns::RE_ON_DATE_SMB_WROTE,
            /** @param array<int,string> $m */
            static function (array $m) use ($text, $delimiter): string {
                $pos = mb_strpos($text, $m[0]);
                if ($pos !== false && $pos > 0 && mb_substr($text, $pos - 1, 1) !== "\n") {
                    return $delimiter.$m[0];
                }

                return $m[0];
            },
            $text
        ) ?? $text;
    }
}
