<?php

declare(strict_types=1);

use Actengage\Talon\HtmlQuotations;

function makeDoc(string $body): DOMDocument
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        mb_convert_encoding($body, 'HTML-ENTITIES', 'UTF-8'),
        LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();

    return $doc;
}

function docText(DOMDocument $doc): string
{
    return preg_replace('/\s+/', ' ', strip_tags($doc->saveHTML() ?: '')) ?? '';
}

// ---------------------------------------------------------------------------
// cutGmailQuote
// ---------------------------------------------------------------------------

it('cuts a div.gmail_quote and returns true', function (): void {
    $doc = makeDoc('<html><body><p>Reply</p><div class="gmail_quote"><p>Old</p></div></body></html>');

    expect(HtmlQuotations::cutGmailQuote($doc))->toBeTrue()
        ->and(docText($doc))->toContain('Reply')
        ->and(docText($doc))->not->toContain('Old');
});

it('does not cut a forwarded gmail_quote', function (): void {
    $doc = makeDoc('<html><body><p>Reply</p><div class="gmail_quote">---------- Forwarded message ----------</div></body></html>');

    expect(HtmlQuotations::cutGmailQuote($doc))->toBeFalse()
        ->and(docText($doc))->toContain('Forwarded message');
});

it('returns false when no gmail_quote is present', function (): void {
    $doc = makeDoc('<html><body><p>Just a reply</p></body></html>');

    expect(HtmlQuotations::cutGmailQuote($doc))->toBeFalse();
});

it('cuts only the first gmail_quote when multiple exist', function (): void {
    $doc = makeDoc(
        '<html><body><p>Reply</p>'
        .'<div class="gmail_quote"><p>Q1</p></div>'
        .'<div class="gmail_quote"><p>Q2</p></div>'
        .'</body></html>'
    );

    expect(HtmlQuotations::cutGmailQuote($doc))->toBeTrue()
        ->and(docText($doc))->not->toContain('Q1');
});

// ---------------------------------------------------------------------------
// cutZimbraQuote
// ---------------------------------------------------------------------------

it('cuts the Zimbra hr divider', function (): void {
    $doc = makeDoc('<html><body><p>Reply</p><hr data-marker="__DIVIDER__"/><p>Original</p></body></html>');

    expect(HtmlQuotations::cutZimbraQuote($doc))->toBeTrue()
        ->and(docText($doc))->toContain('Reply');
});

it('returns false when there is no Zimbra divider', function (): void {
    $doc = makeDoc('<html><body><p>No divider here</p></body></html>');

    expect(HtmlQuotations::cutZimbraQuote($doc))->toBeFalse();
});

// ---------------------------------------------------------------------------
// cutBlockquote
// ---------------------------------------------------------------------------

it('cuts the last top-level blockquote', function (): void {
    $doc = makeDoc('<html><body><p>Reply</p><blockquote><p>Quote</p></blockquote></body></html>');

    expect(HtmlQuotations::cutBlockquote($doc))->toBeTrue()
        ->and(docText($doc))->toContain('Reply')
        ->and(docText($doc))->not->toContain('Quote');
});

it('does not cut a blockquote with class gmail_quote', function (): void {
    $doc = makeDoc('<html><body><p>Reply</p><blockquote class="gmail_quote"><p>Quote</p></blockquote></body></html>');

    expect(HtmlQuotations::cutBlockquote($doc))->toBeFalse();
});

it('returns false when there are no blockquotes', function (): void {
    $doc = makeDoc('<html><body><p>No quotes here</p></body></html>');

    expect(HtmlQuotations::cutBlockquote($doc))->toBeFalse();
});

it('cuts only the last blockquote when multiple exist', function (): void {
    $doc = makeDoc(
        '<html><body><blockquote><p>First</p></blockquote>'
        .'<p>Middle</p>'
        .'<blockquote><p>Last</p></blockquote></body></html>'
    );

    expect(HtmlQuotations::cutBlockquote($doc))->toBeTrue()
        ->and(docText($doc))->toContain('First')
        ->and(docText($doc))->not->toContain('Last');
});

// ---------------------------------------------------------------------------
// cutMicrosoftQuote
// ---------------------------------------------------------------------------

it('cuts the Outlook 2013 border-top splitter (E1E1E1)', function (): void {
    $style = 'border:none;border-top:solid #E1E1E1 1.0pt;padding:3.0pt 0cm 0cm 0cm';
    $doc = makeDoc(
        "<html><body><p>Reply</p><div style=\"{$style}\"><p>Original</p></div></body></html>"
    );

    expect(HtmlQuotations::cutMicrosoftQuote($doc))->toBeTrue()
        ->and(docText($doc))->toContain('Reply')
        ->and(docText($doc))->not->toContain('Original');
});

it('cuts the Outlook 2010 border-top splitter (B5C4DF)', function (): void {
    $style = 'border:none;border-top:solid #B5C4DF 1.0pt;padding:3.0pt 0in 0in 0in';
    $doc = makeDoc(
        "<html><body><p>Reply</p><div style=\"{$style}\"><p>Original</p></div></body></html>"
    );

    expect(HtmlQuotations::cutMicrosoftQuote($doc))->toBeTrue()
        ->and(docText($doc))->not->toContain('Original');
});

it('returns false when no Microsoft splitter is present', function (): void {
    $doc = makeDoc('<html><body><p>Just a reply</p></body></html>');

    expect(HtmlQuotations::cutMicrosoftQuote($doc))->toBeFalse();
});

// ---------------------------------------------------------------------------
// cutById
// ---------------------------------------------------------------------------

it('removes the OLK_SRC_BODY_SECTION element', function (): void {
    $doc = makeDoc('<html><body><p>Reply</p><div id="OLK_SRC_BODY_SECTION"><p>Original</p></div></body></html>');

    expect(HtmlQuotations::cutById($doc))->toBeTrue()
        ->and(docText($doc))->toContain('Reply')
        ->and(docText($doc))->not->toContain('Original');
});

it('returns false when no matching ID element exists', function (): void {
    $doc = makeDoc('<html><body><p>Nothing here</p></body></html>');

    expect(HtmlQuotations::cutById($doc))->toBeFalse();
});

// ---------------------------------------------------------------------------
// cutFromBlock
// ---------------------------------------------------------------------------

it('removes a div block whose text starts with From:', function (): void {
    $doc = makeDoc(
        '<html><body>'
        .'<p>Reply</p>'
        .'<div><div>From: sender@example.com</div><div>Subject: Hello</div></div>'
        .'</body></html>'
    );

    expect(HtmlQuotations::cutFromBlock($doc))->toBeTrue()
        ->and(docText($doc))->toContain('Reply')
        ->and(docText($doc))->not->toContain('sender@example.com');
});

it('removes the Date: div and later siblings, leaving earlier From: for the checkpoint algorithm', function (): void {
    // Python's cut_from_block picks block[-1] (the last matching element — the inner
    // Date: div here, not the From: div before it) and removes it plus its siblings.
    // The From: div comes before Date:, so cut_from_block alone leaves it; the
    // checkpoint/text algorithm then removes it in the full extractFromHtml pipeline.
    $doc = makeDoc(
        '<html><body>'
        .'<p>Reply</p>'
        .'<div>'
        .'<div>From: sender@example.com</div>'
        .'<div>Date: Mon, 1 Jan 2024</div>'
        .'<div>Subject: Re: Hello</div>'
        .'</div>'
        .'</body></html>'
    );

    expect(HtmlQuotations::cutFromBlock($doc))->toBeTrue()
        ->and(docText($doc))->toContain('Reply')
        ->and(docText($doc))->not->toContain('Date:')
        ->and(docText($doc))->not->toContain('Subject:');
});

it('cuts a standalone Date: block with preceding reply content', function (): void {
    // Python's cut_from_block cuts any Date:/From: block that has preceding body content,
    // with no requirement for companion header fields.
    $doc = makeDoc(
        '<html><body>'
        .'<p>Reply</p>'
        .'<div><div>Date: Mon, 1 Jan 2024</div><div>Original body</div></div>'
        .'</body></html>'
    );

    expect(HtmlQuotations::cutFromBlock($doc))->toBeTrue()
        ->and(docText($doc))->toContain('Reply')
        ->and(docText($doc))->not->toContain('Original body');
});

it('returns false when there is no From:/Date: block', function (): void {
    $doc = makeDoc('<html><body><p>Just a reply with no headers</p></body></html>');

    expect(HtmlQuotations::cutFromBlock($doc))->toBeFalse();
});

it('cuts an email template From: field when there is preceding reply content', function (): void {
    // Python talon cuts any From: block with preceding body content (no companion-header guard).
    $doc = makeDoc(
        '<html><body>'
        .'<p>Hey Team, Here is a send from the Atlas Society. Details below:</p>'
        .'<div>'
        .'<div>From: Jennifer Grossman (JAG)</div>'
        .'<div>Subject Line: I just have one question…</div>'
        .'<div>HTML and copy is attached.</div>'
        .'</div>'
        .'</body></html>'
    );

    expect(HtmlQuotations::cutFromBlock($doc))->toBeTrue()
        ->and(docText($doc))->toContain('Atlas Society')
        ->and(docText($doc))->not->toContain('Jennifer Grossman');
});

it('cuts a From: block even when it is the first content (mirrors Python cut_from_block)', function (): void {
    // Python's cut_from_block cuts the From: div and all siblings — including Campaign
    // content — leaving body empty. The readableTextEmpty guard in extractFromHtmlDocument
    // then restores the original. Testing cut_from_block in isolation shows the cut.
    $doc = makeDoc(
        '<html><body>'
        .'<div>From: Newsletter Team</div>'
        .'<div>Campaign content goes here</div>'
        .'</body></html>'
    );

    expect(HtmlQuotations::cutFromBlock($doc))->toBeTrue()
        ->and(docText($doc))->not->toContain('Campaign content goes here');
});

// Case 2: "From:" appearing as a text node after a <br> (inline with the body text)

it('cuts inline From: after a <br> when followed by standard header fields (Case 2)', function (): void {
    // Structure mirrors real emails where campaign details appear inline:
    // "Details below:<br><br>From: Riley Lee<br>Subject: David beat Goliath..."
    $doc = makeDoc(
        '<html><body>'
        .'<div>Details below:<br><br>From: Riley Lee<br>'
        .'Subject: David beat Goliath<br>Subject: There\'s David vs. Goliath<br>'
        .'HTML and copy is attached.<br>Thank you.<br>--</div>'
        .'<div class="gmail_signature">Justin Kriner</div>'
        .'</body></html>'
    );

    expect(HtmlQuotations::cutFromBlock($doc))->toBeTrue()
        ->and(docText($doc))->toContain('Details below')
        ->and(docText($doc))->not->toContain('Riley Lee')
        ->and(docText($doc))->toContain('Justin Kriner');
});

it('cuts inline From: after a <br> even when subject uses non-standard "Subject Line:" label (Case 2)', function (): void {
    // Python cuts "From: Name" after a <br> regardless of whether the field names
    // are standard. Case 2 has no regex guard — it fires on any "From:/Date:" tail.
    $doc = makeDoc(
        '<html><body>'
        .'<div>Hey Team,<br>Details below:<br><br>From: Stephen Moore<br>'
        .'Subject Line: Make Atlas Shrugged Fiction Again!<br>'
        .'Subject Line: Why young people need to fall in love with America<br>'
        .'HTML and copy is attached.<br>--</div>'
        .'<div class="gmail_signature">Justin Kriner</div>'
        .'</body></html>'
    );

    expect(HtmlQuotations::cutFromBlock($doc))->toBeTrue()
        ->and(docText($doc))->toContain('Details below')
        ->and(docText($doc))->not->toContain('Stephen Moore')
        ->and(docText($doc))->toContain('Justin Kriner');
});

it('does not cut a forwarded From: block in Case 2 when parent textContent matches RE_FWD', function (): void {
    // The RE_FWD guard in Case 2 fires when the parent element's textContent contains
    // "---------- Forwarded message ----------" on its own line (literal \n, not <br>).
    $doc = makeDoc(
        '<html><body>'
        ."<div>---------- Forwarded message ----------\n<br>"
        .'From: sender@example.com<br>Subject: Hello</div>'
        .'</body></html>'
    );

    expect(HtmlQuotations::cutFromBlock($doc))->toBeFalse()
        ->and(docText($doc))->toContain('sender@example.com');
});

it('returns false when From: element is the deepest match but has no div ancestor', function (): void {
    // From: text is directly in <body> with no wrapping div — no div ancestor found,
    // Python returns False, so should PHP.
    $doc = makeDoc('<html><body>From: nobody@example.com Subject: Test</body></html>');

    expect(HtmlQuotations::cutFromBlock($doc))->toBeFalse();
});

// ---------------------------------------------------------------------------
// cutMicrosoftQuote — Windows Mail / padding-top style
// ---------------------------------------------------------------------------

it('cuts the Windows Mail padding-top border-top splitter', function (): void {
    $style = 'padding-top: 5px; border-top-color: rgb(229, 229, 229); border-top-width: 1px; border-top-style: solid;';
    $doc = makeDoc(
        "<html><body><p>Reply</p><div style=\"{$style}\"><p>Original</p></div></body></html>"
    );

    expect(HtmlQuotations::cutMicrosoftQuote($doc))->toBeTrue()
        ->and(docText($doc))->toContain('Reply')
        ->and(docText($doc))->not->toContain('Original');
});

// ---------------------------------------------------------------------------
// cutById — multiple IDs
// ---------------------------------------------------------------------------

it('removes all elements matching known quote IDs in a single pass', function (): void {
    // OLK_SRC_BODY_SECTION is the only ID currently in QUOTE_IDS, but verify
    // that multiple matching elements are all removed.
    $doc = makeDoc(
        '<html><body>'
        .'<p>Reply</p>'
        .'<div id="OLK_SRC_BODY_SECTION"><p>First original</p></div>'
        .'<div id="OLK_SRC_BODY_SECTION"><p>Second original</p></div>'
        .'</body></html>'
    );

    expect(HtmlQuotations::cutById($doc))->toBeTrue()
        ->and(docText($doc))->toContain('Reply')
        ->and(docText($doc))->not->toContain('First original')
        ->and(docText($doc))->not->toContain('Second original');
});

// ---------------------------------------------------------------------------
// addCheckpoints — multi-line DOMText (pre block)
// ---------------------------------------------------------------------------

it('stamps checkpoints at the END of multi-line text so the checkpoint falls after splitter lines', function (): void {
    // A <pre> whose text contains "From:" on a late line — the checkpoint on the
    // DOMElement must be appended (not prepended) so it lands AFTER the From: line.
    $doc = makeDoc(
        '<html><body>'
        ."<pre>Line 1\nLine 2\nFrom: sender@example.com\nLine 4</pre>"
        .'</body></html>'
    );

    $counter = HtmlQuotations::addCheckpoints($doc->documentElement ?? $doc, 0);

    // The pre element's firstChild DOMText should have the checkpoint appended at the end.
    $preNodes = HtmlQuotations::xpath($doc, '//pre');
    $pre = $preNodes->item(0);

    expect($pre)->toBeInstanceOf(DOMElement::class);
    // The serialised pre text should end with a checkpoint marker, not start with one.
    $text = $pre instanceof DOMElement && $pre->firstChild instanceof DOMText
        ? ($pre->firstChild->nodeValue ?? '')
        : '';
    expect($text)->toEndWith('!%!#') // checkpoint suffix
        ->and($counter)->toBeGreaterThan(0);
});
