<?php

declare(strict_types=1);

use Actengage\Talon\TextQuotations;

// ---------------------------------------------------------------------------
// markLines
// ---------------------------------------------------------------------------

it('marks empty lines as e', function (): void {
    expect(TextQuotations::markLines(['', '   ', "\t"]))->toBe('eee');
});

it('marks > lines as m', function (): void {
    expect(TextQuotations::markLines(['> quoted', '>> deeply quoted']))->toBe('mm');
});

it('marks plain text as t', function (): void {
    expect(TextQuotations::markLines(['Hello world', 'How are you?']))->toBe('tt');
});

it('marks forwarded-message lines as f', function (): void {
    expect(TextQuotations::markLines(['---------- Forwarded message ----------']))->toBe('f');
});

it('marks On-date-wrote lines as s', function (): void {
    $line = 'On Mon, 1 Jan 2024, John <john@example.com> wrote:';
    $markers = TextQuotations::markLines([$line]);
    expect($markers)->toBe('s');
});

it('marks Original Message separator as s', function (): void {
    $markers = TextQuotations::markLines(['-----Original Message-----']);
    expect($markers)->toBe('s');
});

it('marks From:/Date:/Subject: header blocks as s', function (): void {
    $lines = [
        'From: John <john@example.com>',
        'Date: Mon, 1 Jan 2024',
        'Subject: Re: Hello',
        'To: Jane <jane@example.com>',
    ];
    $markers = TextQuotations::markLines($lines);
    expect($markers)->toContain('s');
});

it('produces correct marker string for a typical reply chain', function (): void {
    $lines = [
        'My reply',
        'From: John <john@example.com>',
        '',
        '> Original quoted line',
    ];
    $markers = TextQuotations::markLines($lines);

    expect($markers[0])->toBe('t') // "My reply" = text
        ->and($markers[2])->toBe('e') // empty line
        ->and($markers[3])->toBe('m'); // "> Original" = quoted marker
});

// ---------------------------------------------------------------------------
// isSplitter
// ---------------------------------------------------------------------------

it('recognises On-date-wrote splitter', function (): void {
    expect(TextQuotations::isSplitter('On Mon, Jan 1, 2024, Jane <j@x.com> wrote:'))->not->toBeNull();
});

it('recognises Original Message splitter', function (): void {
    expect(TextQuotations::isSplitter('-----Original Message-----'))->not->toBeNull();
});

it('recognises Reply Message splitter', function (): void {
    expect(TextQuotations::isSplitter('-----Reply Message-----'))->not->toBeNull();
});

it('recognises German Ursprüngliche Nachricht splitter', function (): void {
    expect(TextQuotations::isSplitter('-----Ursprüngliche Nachricht-----'))->not->toBeNull();
});

it('recognises date + email address splitter', function (): void {
    expect(TextQuotations::isSplitter('02.04.2012 14:20 user@example.com'))->not->toBeNull();
});

it('recognises ISO date + GMT splitter', function (): void {
    expect(TextQuotations::isSplitter('2014-10-17 11:28 GMT+03:00 Bob <bob@example.com>:'))->not->toBeNull();
});

it('returns null for a normal line', function (): void {
    expect(TextQuotations::isSplitter('Hello, how are you?'))->toBeNull();
});

it('returns null for a > line', function (): void {
    expect(TextQuotations::isSplitter('> This is quoted'))->toBeNull();
});

it('recognises a From: line + Subject: companion as a splitter', function (): void {
    // RE_FROM_COLON_OR_DATE_COLON requires 2+ consecutive header fields from the
    // From/Date/Subject/To keyword list. "From:" + "Subject:" = 2 fields → splitter.
    $chunk = "From: Riley Lee\nSubject: David beat Goliath\nSubject: There's David vs. Goliath\n";
    expect(TextQuotations::isSplitter($chunk))->not->toBeNull();
});

it('recognises two consecutive Subject: lines as a splitter', function (): void {
    // Python's RE_FROM_COLON_OR_DATE_COLON includes Subject in the keyword list.
    // Two consecutive Subject: lines satisfy the 2+ field requirement → splitter.
    $chunk = "Subject: David beat Goliath\nSubject: There's David vs. Goliath in schools\n";
    expect(TextQuotations::isSplitter($chunk))->not->toBeNull();
});

it('does not treat a single Subject: line as a splitter', function (): void {
    expect(TextQuotations::isSplitter('Subject: Hello World'))->toBeNull();
});

it('does not treat a Subject line containing "plan:" as a splitter (no "An:" false positive)', function (): void {
    // RE_FROM_COLON_OR_DATE_COLON with [^\n]*\n? (optional newline) would backtrack and
    // match "an:" inside "plan:" as keyword "An" (German "To"), giving a false second
    // repetition within the same line. Requiring mandatory \n per field prevents this.
    $line = 'Subject: Senate Republicans have a clear plan: secure the border, support ICE, and protect American families.';
    expect(TextQuotations::isSplitter($line))->toBeNull();
});

// ---------------------------------------------------------------------------
// processMarkedLines
// ---------------------------------------------------------------------------

it('removes lines after a splitter', function (): void {
    $lines = ['Reply', 'From: j@x.com', 'Sent: Mon', 'Original'];
    $markers = 'tsst';
    $flags = [];

    $result = TextQuotations::processMarkedLines($lines, $markers, $flags);

    expect($result)->toBe(['Reply'])
        ->and($flags[0])->toBeTrue();
});

it('removes a quotation marker block (3+ consecutive quoted lines)', function (): void {
    $lines = ['Reply', '', '> Quoted line', '> Another quoted', '> Third quoted'];
    $markers = 'temmm';
    $flags = [];

    $result = TextQuotations::processMarkedLines($lines, $markers, $flags);

    expect($result)->not->toContain('> Quoted line')
        ->and($flags[0])->toBeTrue();
});

it('returns all lines unchanged when no quotation is detected', function (): void {
    $lines = ['Hello', 'World'];
    $markers = 'tt';
    $flags = [];

    $result = TextQuotations::processMarkedLines($lines, $markers, $flags);

    expect($result)->toBe(['Hello', 'World'])
        ->and($flags[0])->toBeFalse();
});

it('treats m-markers as text when no splitter present and fewer than 3 consecutive', function (): void {
    $lines = ['Reply', '> Single quote'];
    $markers = 'tm';
    $flags = [];

    $result = TextQuotations::processMarkedLines($lines, $markers, $flags);

    expect($result)->toBe(['Reply', '> Single quote']);
});

it('preserves lines when message starts with forwarded block', function (): void {
    $lines = ['Hello', '---------- Forwarded message ----------', 'Body'];
    $markers = 'tft';
    $flags = [];

    $result = TextQuotations::processMarkedLines($lines, $markers, $flags);

    expect($result)->toBe($lines);
});

// ---------------------------------------------------------------------------
// extract (end-to-end plain text)
// ---------------------------------------------------------------------------

it('extracts original from a simple > quoted reply (3+ quoted lines)', function (): void {
    // Talon requires ≥3 consecutive quoted lines when there is no explicit splitter.
    $text = "My reply\n\n> Quoted text\n> More quoted\n> Third quoted";
    $result = TextQuotations::extract($text);

    expect($result)->toContain('My reply')
        ->and($result)->not->toContain('Quoted text');
});

it('extracts original when split by On-date-wrote', function (): void {
    $text = "My reply\n\nOn Mon, 1 Jan 2024, John <j@x.com> wrote:\n\n> Original message";
    $result = TextQuotations::extract($text);

    expect($result)->toContain('My reply')
        ->and($result)->not->toContain('Original message');
});

it('extracts original when split by Original Message separator', function (): void {
    $text = "My reply\n\n-----Original Message-----\nFrom: j@x.com\nOriginal body";
    $result = TextQuotations::extract($text);

    expect($result)->toContain('My reply')
        ->and($result)->not->toContain('Original body');
});

it('extracts original when split by From: header block', function (): void {
    $text = "My reply\n\nFrom: j@x.com\nDate: Mon, 1 Jan 2024\nSubject: Re: Hello\nOriginal body";
    $result = TextQuotations::extract($text);

    expect($result)->toContain('My reply')
        ->and($result)->not->toContain('Original body');
});

it('returns text unchanged when there is nothing to cut', function (): void {
    $text = 'Just a simple message with no quotes.';
    expect(TextQuotations::extract($text))->toBe($text);
});

it('handles \r\n line endings with a splitter', function (): void {
    $text = "Reply\r\n\r\n-----Original Message-----\r\nFrom: j@x.com\r\nOriginal body";
    $result = TextQuotations::extract($text);

    expect($result)->toContain('Reply')
        ->and($result)->not->toContain('Original body');
});

it('does not cut a forwarded message', function (): void {
    $text = "---------- Forwarded message ----------\nFrom: j@x.com\nBody of forward";
    $result = TextQuotations::extract($text);

    expect($result)->toContain('Body of forward');
});

it('handles empty input', function (): void {
    expect(TextQuotations::extract(''))->toBe('');
    expect(TextQuotations::extract('   '))->toBe('');
});

// ---------------------------------------------------------------------------
// Splitter patterns — multilingual
// ---------------------------------------------------------------------------

it('recognises French Le ... a écrit splitter', function (): void {
    $line = 'Le 1 janv. 2024, à 10:00, Jean <j@x.com> a écrit :';
    expect(TextQuotations::isSplitter($line))->not->toBeNull();
});

it('recognises Dutch Op ... schreef splitter', function (): void {
    $line = 'Op 1 jan. 2024, om 10:00 heeft Jan <j@x.com> het volgende geschreven:';
    expect(TextQuotations::isSplitter($line))->not->toBeNull();
});

it('recognises German Am ... schrieb splitter', function (): void {
    $line = 'Am 1. Jan. 2024 um 10:00 schrieb Hans <h@x.com>:';
    expect(TextQuotations::isSplitter($line))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Delimiter detection
// ---------------------------------------------------------------------------

it('detects crlf delimiter', function (): void {
    expect(TextQuotations::getDelimiter("Hello\r\nWorld"))->toBe("\r\n");
});

it('defaults to lf delimiter', function (): void {
    expect(TextQuotations::getDelimiter("Hello\nWorld"))->toBe("\n");
});

// ---------------------------------------------------------------------------
// Inline reply detection — quoted markers between text blocks (do not cut)
// ---------------------------------------------------------------------------

it('does not cut when quoted markers are interleaved with text (inline reply)', function (): void {
    // m...t...m pattern: talon must NOT cut inline replies because doing so would
    // destroy the conversation structure.
    $lines = ['> First question', 'Answer to it', '> Second question', 'Answer to that'];
    $markers = 'mtmt';
    $flags = [];

    $result = TextQuotations::processMarkedLines($lines, $markers, $flags);

    expect($result)->toBe($lines)
        ->and($flags[0])->toBeFalse();
});

it('does not cut when a single quoted line appears with no splitter and fewer than 3 consecutive', function (): void {
    $lines = ['Reply', '> One quoted line'];
    $markers = 'tm';
    $flags = [];

    $result = TextQuotations::processMarkedLines($lines, $markers, $flags);

    expect($result)->toBe($lines);
});

// ---------------------------------------------------------------------------
// Splitter spanning multiple lines (SPLITTER_MAX_LINES window)
// ---------------------------------------------------------------------------

it('recognises a two-line On-date-wrote splitter', function (): void {
    // When "On <date>," and "wrote:" are on separate lines, isSplitter must see them
    // together (up to SPLITTER_MAX_LINES = 6).
    $chunk = "On Wednesday, April 2, 2025 at 3:45 PM,\nAlice Smith <alice@example.com> wrote:";
    expect(TextQuotations::isSplitter($chunk))->not->toBeNull();
});

it('recognises a three-line On-date-wrote splitter', function (): void {
    $chunk = "On Wednesday, April 2, 2025 at 3:45 PM,\nAlice Smith\n<alice@example.com> wrote:";
    expect(TextQuotations::isSplitter($chunk))->not->toBeNull();
});

it('extracts reply from a message where the date-wrote splitter spans two lines', function (): void {
    $text = implode("\n", [
        'Confirmed, the files are on the way.',
        '',
        'On Wednesday, April 2, 2025 at 3:45 PM,',
        'Alice Smith <alice@example.com> wrote:',
        '',
        '> Please send the files today.',
    ]);

    $result = TextQuotations::extract($text);

    expect($result)->toContain('files are on the way')
        ->and($result)->not->toContain('Please send the files today');
});

// ---------------------------------------------------------------------------
// Samsung device footer splitter
// ---------------------------------------------------------------------------

it('recognises a Samsung Sent-from splitter', function (): void {
    // Pattern requires email address with a trailing > (angle-bracket format).
    $chunk = 'Sent from Samsung Galaxy S24 <user@example.com> wrote';
    expect(TextQuotations::isSplitter($chunk))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// processMarkedLines — splitter followed immediately by empty lines then text
// ---------------------------------------------------------------------------

it('cuts content after splitter even when separated by blank lines', function (): void {
    $lines = ['Reply', '', 'From: j@x.com', '', 'Original body'];
    $markers = 'teset';
    $flags = [];

    $result = TextQuotations::processMarkedLines($lines, $markers, $flags);

    expect($result)->not->toContain('Original body')
        ->and($flags[0])->toBeTrue();
});

// ---------------------------------------------------------------------------
// extract — edge cases
// ---------------------------------------------------------------------------

it('extracts correctly when reply and quoted section share the same paragraph', function (): void {
    // On-date-wrote appears immediately after reply text with no blank line.
    $text = "Got it.On Mon, 1 Jan 2024, John <j@x.com> wrote:\n\n> Original";
    $result = TextQuotations::extract($text);

    expect($result)->toContain('Got it.')
        ->and($result)->not->toContain('Original');
});

it('preserves unicode multibyte content through extraction', function (): void {
    $text = "Заявка принята.\n\nOn Mon, 1 Jan 2024, John <j@x.com> wrote:\n\n> Пожалуйста, подтвердите.";
    $result = TextQuotations::extract($text);

    expect($result)->toContain('Заявка принята.')
        ->and($result)->not->toContain('Пожалуйста');
});
