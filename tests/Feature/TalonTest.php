<?php

declare(strict_types=1);

use Actengage\Talon\Facades\Talon;
use Actengage\Talon\Talon as TalonService;

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function normalizeHtml(string $html): string
{
    $html = preg_replace('/>\s+</', '><', $html) ?? $html;
    $html = preg_replace('/\s+/', ' ', $html) ?? $html;

    return trim($html);
}

// ---------------------------------------------------------------------------
// Service provider / facade
// ---------------------------------------------------------------------------

it('resolves the Talon singleton from the container', function (): void {
    expect(app(TalonService::class))->toBeInstanceOf(TalonService::class);
});

it('returns the same instance on repeated resolution', function (): void {
    expect(app(TalonService::class))->toBe(app(TalonService::class));
});

it('can be called via the Talon facade', function (): void {
    $html = '<html><body><p>Hello world</p></body></html>';
    $result = Talon::extractFromHtml($html);
    expect($result)->toBeString();
});

// ---------------------------------------------------------------------------
// extractFrom() content-type routing
// ---------------------------------------------------------------------------

it('routes to html extraction when content type is text/html', function (): void {
    $html = '<html><body><p>Reply</p><blockquote><p>Original</p></blockquote></body></html>';
    $result = app(TalonService::class)->extractFrom($html, 'text/html');
    expect($result)->not->toContain('Original');
});

it('routes to plain extraction when content type is text/plain', function (): void {
    $text = "Reply\n\nOn Mon, 1 Jan 2024, John wrote:\n\n> Original";
    $result = app(TalonService::class)->extractFrom($text, 'text/plain');
    expect($result)->not->toContain('Original');
});

it('auto-detects html when no content type is given', function (): void {
    $html = '<html><body><p>Reply</p><blockquote><p>Original</p></blockquote></body></html>';
    $result = app(TalonService::class)->extractFrom($html);
    expect($result)->not->toContain('Original');
});

it('auto-detects plain text when no content type is given', function (): void {
    $text = "Reply\n\nOn Mon, 1 Jan 2024, John wrote:\n\n> Original";
    $result = app(TalonService::class)->extractFrom($text);
    expect($result)->toContain('Reply')
        ->and($result)->not->toContain('Original');
});

it('honors explicit text/plain even when html-like brackets are present', function (): void {
    $text = "Reply\n\nOn Mon, 1 Jan 2024, John <j@x.com> wrote:\n\n> Original";
    $result = app(TalonService::class)->extractFrom($text, 'text/plain');
    expect($result)->not->toContain('Original');
});

it('returns the body unchanged when an exception is thrown internally', function (): void {
    $malformed = str_repeat('<div>', 5000); // Extremely deep nesting
    $result = app(TalonService::class)->extractFrom($malformed);
    expect($result)->toBeString();
});

// ---------------------------------------------------------------------------
// extractFromHtml — Gmail quote
// ---------------------------------------------------------------------------

it('removes a gmail_quote div', function (): void {
    $html = <<<'HTML'
    <html><body>
        <p>Reply text</p>
        <div class="gmail_quote">
            <p>Original message</p>
        </div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Reply text')
        ->and($result)->not->toContain('Original message');
});

it('does not remove a gmail_quote that is a forwarded message', function (): void {
    $html = <<<'HTML'
    <html><body>
        <p>Reply text</p>
        <div class="gmail_quote">---------- Forwarded message ----------</div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Forwarded message');
});

// ---------------------------------------------------------------------------
// extractFromHtml — blockquote
// ---------------------------------------------------------------------------

it('removes the last top-level blockquote', function (): void {
    $html = <<<'HTML'
    <html><body>
        <p>New reply</p>
        <blockquote>
            <p>Quoted text</p>
        </blockquote>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('New reply')
        ->and($result)->not->toContain('Quoted text');
});

it('does not remove a blockquote nested inside another blockquote', function (): void {
    $html = <<<'HTML'
    <html><body>
        <p>Reply</p>
        <blockquote>
            <p>Level 1</p>
            <blockquote><p>Level 2</p></blockquote>
        </blockquote>
    </body></html>
    HTML;

    // The outer blockquote should be removed; level 2 goes with it
    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Reply')
        ->and($result)->not->toContain('Level 1')
        ->and($result)->not->toContain('Level 2');
});

// ---------------------------------------------------------------------------
// extractFromHtml — Zimbra divider
// ---------------------------------------------------------------------------

it('removes a Zimbra hr divider', function (): void {
    $html = <<<'HTML'
    <html><body>
        <p>Reply</p>
        <hr data-marker="__DIVIDER__"/>
        <p>Original</p>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Reply')
        ->and($result)->not->toContain('__DIVIDER__');
});

// ---------------------------------------------------------------------------
// extractFromHtml — ID-based cuts
// ---------------------------------------------------------------------------

it('removes OLK_SRC_BODY_SECTION', function (): void {
    $html = <<<'HTML'
    <html><body>
        <p>Reply</p>
        <div id="OLK_SRC_BODY_SECTION"><p>Outlook original</p></div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Reply')
        ->and($result)->not->toContain('Outlook original');
});

// ---------------------------------------------------------------------------
// extractFromHtml — Microsoft Outlook style-based cut
// ---------------------------------------------------------------------------

it('removes the Outlook 2013 border-top splitter', function (): void {
    $style = 'border:none;border-top:solid #E1E1E1 1.0pt;padding:3.0pt 0cm 0cm 0cm';

    $html = <<<HTML
    <html><body>
        <p>Reply</p>
        <div style="{$style}">
            <p>From: sender@example.com</p>
        </div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Reply')
        ->and($result)->not->toContain('sender@example.com');
});

it('removes the Outlook 2010 border-top splitter (B5C4DF color)', function (): void {
    $style = 'border:none;border-top:solid #B5C4DF 1.0pt;padding:3.0pt 0in 0in 0in';

    $html = <<<HTML
    <html><body>
        <p>Reply</p>
        <div style="{$style}">
            <p>Original</p>
        </div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Reply')
        ->and($result)->not->toContain('Original');
});

// ---------------------------------------------------------------------------
// extractFromHtml — From: block cut
// ---------------------------------------------------------------------------

it('removes a div block starting with From:', function (): void {
    $html = <<<'HTML'
    <html><body>
        <p>Reply</p>
        <div>
            <div>From: original@example.com</div>
            <div>Subject: Re: Hello</div>
            <div>Original message body</div>
        </div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Reply')
        ->and($result)->not->toContain('original@example.com');
});

// ---------------------------------------------------------------------------
// extractFromHtml — empty and degenerate inputs
// ---------------------------------------------------------------------------

it('returns empty string when given empty input', function (): void {
    expect(app(TalonService::class)->extractFromHtml(''))->toBe('');
    expect(app(TalonService::class)->extractFromHtml('   '))->toBe('   ');
});

it('returns body unchanged when there is nothing to cut', function (): void {
    $html = '<html><body><p>Just a single message, nothing quoted.</p></body></html>';
    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Just a single message');
});

it('handles malformed HTML gracefully', function (): void {
    $html = '<p>Unclosed paragraph<div>And a div';
    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toBeString();
});

// ---------------------------------------------------------------------------
// extractFromHtml — combined pipeline (appendonsend + divRplyFwdMsg scenario)
// This replicates the original TalonServiceTest fixture.
// ---------------------------------------------------------------------------

it('strips appendonsend and divRplyFwdMsg while keeping the reply', function (): void {
    $html = <<<'HTML'
    <!DOCTYPE html>
    <html>
        <head></head>
        <body>
            <div>This is the reply</div>
            <div id="appendonsend">test</div>
            <div id="divRplyFwdMsg">test</div>
            <hr>
            <div>From: test@test.com</div>
            <div>Subject: test</div>
            <div>This is the original message</div>
        </body>
    </html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect(normalizeHtml($result))->toContain('This is the reply')
        ->and($result)->not->toContain('This is the original message');
});

// ---------------------------------------------------------------------------
// extractFromHtml — inline campaign email template scenario
// Mirrors real emails where "From: Name<br>Subject:..." appears inline.
// Python talon cuts the inline block via cutFromBlock Case 2 while preserving
// the gmail_signature div (which is a sibling, not a child of the cut div).
// ---------------------------------------------------------------------------

it('removes inline From:/Subject: campaign block while preserving the gmail signature', function (): void {
    // Structure from real emails: inline "From: Name<br>Subject:..." with <br>
    // separators, followed by a gmail_signature div at the same level.
    $html = <<<'EOT'
    <html><body>
    <div dir="ltr">
      <div>Hey Team,<br><br>
        I have a new send from SkyTree Book Fairs. Details below:<br><br>
        From: Riley Lee<br>
        Subject: David beat Goliath &mdash; Now it&#39;s your turn<br>
        Subject: There&#39;s David vs. Goliath in schools<br><br>
        Copy is attached.<br><br>
        Thank you.<br>
        --
      </div>
      <div class="gmail_signature" data-smartmail="gmail_signature">
        <div>Justin Kriner</div>
        <div>Development Coordinator</div>
      </div>
    </div>
    </body></html>
    EOT;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Details below')
        ->and($result)->not->toContain('Riley Lee')
        ->and($result)->not->toContain('David beat Goliath')
        ->and($result)->toContain('Justin Kriner');
});

// ---------------------------------------------------------------------------
// extractFromHtml — plain-text bodies with angle-bracket email addresses
// Regression: bodies with no real HTML block tags but with angle brackets from
// email addresses (e.g. <user@example.com>) were mistakenly processed as HTML.
// The extraction pipeline would fire on the angle brackets as "tags", producing
// corrupt output. Bodies with no block-level HTML should be returned unchanged.
// ---------------------------------------------------------------------------

it('returns a plain-text SMTP-header body unchanged when it contains no block-level HTML tags', function (): void {
    // Angle brackets here come from email address syntax, not HTML markup.
    $body = implode("\n", [
        'Delivered-To: user@example.com',
        'From: Sender Name <sender@example.com>',
        'To: Recipient <recipient@example.com>',
        'Subject: Hello world',
        '',
        'This is a plain-text message.',
    ]);

    $result = app(TalonService::class)->extractFromHtml($body);

    expect($result)->toBe($body);
});

it('returns an angle-bracket-heavy body unchanged when it has no block HTML', function (): void {
    // Multiple angle-bracket addresses; the tag count would be <419 if treated
    // as HTML, but there are no div/p/br/span etc. so it should bail out early.
    $body = 'Reply-To: <a@example.com>, <b@example.com>, <c@example.com>'
        ."\nFrom: Team <team@example.com>"
        ."\nSome message content without any HTML markup.";

    $result = app(TalonService::class)->extractFromHtml($body);

    expect($result)->toBe($body);
});

// ---------------------------------------------------------------------------
// extractFromHtml — "Begin forwarded message:" blockquote removal
// Regression: after the main quoted content was stripped by the checkpoint
// algorithm, a bare top-level blockquote containing only the Apple Mail
// forwarded-message label was left behind. Python's second pass removed it;
// PHP must remove it in a single pass.
// ---------------------------------------------------------------------------

it('removes a top-level blockquote whose only content is "Begin forwarded message:"', function (): void {
    // The reply body has already been stripped by the checkpoint algorithm;
    // all that remains is the forwarded-message label in a blockquote.
    $html = <<<'HTML'
    <html><body>
        <p>This is my reply.</p>
        <blockquote type="cite"><div>Begin forwarded message:</div></blockquote>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('This is my reply.')
        ->and($result)->not->toContain('Begin forwarded message:');
});

it('removes a top-level blockquote with trailing colon variant "Begin forwarded message"', function (): void {
    $html = <<<'HTML'
    <html><body>
        <p>Reply content here.</p>
        <blockquote><div>Begin forwarded message</div></blockquote>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Reply content here.')
        ->and($result)->not->toContain('Begin forwarded message');
});

it('returns the original when the only body content is a "Begin forwarded message:" blockquote', function (): void {
    // If the bare label is the ONLY content in the body, removing it would leave
    // an empty body. readableTextEmpty catches this and returns the original.
    $html = <<<'HTML'
    <html><body>
        <blockquote type="cite"><div>Begin forwarded message:</div></blockquote>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    // Nothing to cut — body would be empty after removal, so original is returned.
    expect($result)->toContain('Begin forwarded message:');
});

// ---------------------------------------------------------------------------
// extractFromHtml — <pre> block with embedded SMTP headers (NDR emails)
// Regression: Office 365 NDR emails append the original message's raw SMTP
// headers in a <pre> block. That block contains a "From:" line deep inside a
// single DOMText node. The checkpoint algorithm must detect it as quotation and
// strip the <pre> content, leaving only the delivery-failure notice.
// ---------------------------------------------------------------------------

it('strips a <pre> block whose text content contains an embedded From: SMTP header', function (): void {
    $smtpHeaders = implode("\n", [
        'Authentication-Results: dkim=none (message not signed)',
        'Received: from mail.example.com (192.0.2.1) by mx.example.com',
        ' with Microsoft SMTP Server; Wed, 10 Sep 2025 17:52:01 +0000',
        'From: sender@example.com',
        'To: recipient@example.com',
        'Subject: Test message',
        'Date: Wed, 10 Sep 2025 17:52:00 +0000',
        'Message-ID: <abc123@example.com>',
        'Content-Type: text/plain; charset=utf-8',
    ]);

    $html = <<<HTML
    <html><body>
        <p>Your message couldn't be delivered. The recipient was not found.</p>
        <p>How to fix it: Check the recipient address and try again.</p>
        <p>Original Message Headers</p>
        <pre style="color:gray">{$smtpHeaders}</pre>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain("couldn't be delivered")
        ->and($result)->not->toContain('Authentication-Results')
        ->and($result)->not->toContain('Message-ID');
});

it('strips a <pre> SMTP header block even when followed by encoded-content lines', function (): void {
    $smtpHeaders = implode("\n", [
        'Received: from mail.example.com by mx.example.com',
        'From: newsletter@example.com',
        'To: user@example.com',
        'Subject: Weekly digest',
        'MIME-Version: 1.0',
        'Content-Type: application/ms-tnef; name="winmail.dat"',
        'Content-Transfer-Encoding: base64',
        'ABC123DEF456GHI789JKL012MNO345PQR678STU901VWX234YZ=',
        'ABC123DEF456GHI789JKL012MNO345PQR678STU901VWX234Y2=',
    ]);

    $html = <<<HTML
    <html><body>
        <p>Delivery has failed to these recipients:</p>
        <p>The domain does not exist. Contact the recipient's email admin.</p>
        <pre style="color:gray">{$smtpHeaders}</pre>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Delivery has failed')
        ->and($result)->not->toContain('Received: from')
        ->and($result)->not->toContain('MIME-Version');
});

it('removes inline From:/Subject Line: campaign block while preserving the gmail signature', function (): void {
    // Python cuts any "From: Name" after a <br> regardless of whether field names
    // are standard ("Subject:") or non-standard ("Subject Line:").
    $html = <<<'EOT'
    <html><body>
    <div dir="ltr">
      <div>Hey Team,<br><br>
        Here is a send from Economist Stephen Moore for the Atlas Society. Details below:<br><br>
        From: Stephen Moore<br>
        Subject Line: Make Atlas Shrugged Fiction Again!<br>
        Subject Line: Why young people need to fall in love with America again:<br><br>
        Copy is attached.<br><br>
        Thank you.<br>
        --
      </div>
      <div class="gmail_signature" data-smartmail="gmail_signature">
        <div>Justin Kriner</div>
        <div>Development Coordinator</div>
      </div>
    </div>
    </body></html>
    EOT;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Details below')
        ->and($result)->not->toContain('From: Stephen Moore')
        ->and($result)->not->toContain('Make Atlas Shrugged')
        ->and($result)->toContain('Justin Kriner');
});

// ---------------------------------------------------------------------------
// Real-world patterns from production email sample (10 K messages)
// Each fixture is a minimal reproduction of a structural category observed
// at high frequency in the live dataset.
// ---------------------------------------------------------------------------

// ── Gmail-style nested quote container ──────────────────────────────────────

it('removes the gmail_quote_container div while keeping the reply above it', function (): void {
    // Mirrors the gmail_quote / gmail_quote_container pattern produced by Gmail.
    // The reply sits in a sibling div above the quote container.
    $html = <<<'HTML'
    <html><body>
    <div dir="ltr">
      <div class="gmail_default">Forwarding the top performing emails below, thank you!</div>
    </div>
    <div class="gmail_quote gmail_quote_container">
      <div dir="ltr" class="gmail_attr">On Wed, 19 Mar 2025 at 14:31, Lucy Stanford wrote:<br></div>
      <blockquote class="gmail_quote" style="margin:0 0 0 .8ex;border-left:1px solid #ccc;padding-left:1ex">
        <div>Good afternoon!</div>
        <div>Here are the 4 top-performing emails for APAC from the past few days.</div>
        <div>Let me know if you need anything else.</div>
      </blockquote>
    </div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Forwarding the top performing emails')
        ->and($result)->not->toContain('Good afternoon!')
        ->and($result)->not->toContain('4 top-performing emails');
});

it('preserves the full reply when a gmail_quote is a forwarded message', function (): void {
    $html = <<<'HTML'
    <html><body>
    <div dir="ltr"><div>Please see this forwarded email below.</div></div>
    <div class="gmail_quote">---------- Forwarded message ----------
    From: sender@example.com
    Subject: Important update</div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Please see this forwarded email below.')
        ->and($result)->toContain('Forwarded message');
});

// ── On-date-wrote splitter (text algorithm) ──────────────────────────────────

it('removes the quoted portion after an On-date-wrote splitter in an HTML email', function (): void {
    $html = <<<'HTML'
    <html><body>
    <div>Sounds good, I will coordinate with the team.</div>
    <div>On Mon, 10 Mar 2025 at 09:15, Alice Smith &lt;alice@example.com&gt; wrote:</div>
    <div>Can you send over the latest creative assets by end of week?</div>
    <div>Thanks,</div>
    <div>Alice</div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Sounds good')
        ->and($result)->not->toContain('Can you send over the latest creative assets');
});

// ── Missive-style blockquote-with-cite ───────────────────────────────────────

it('removes a missive-style cite blockquote while keeping the reply above', function (): void {
    // Missive wraps the quoted section in a div.missive_quote > blockquote[type=cite].
    $html = <<<'HTML'
    <html><body>
    <div>
      <div>Happy to help with this one.</div>
    </div>
    <div class="missive_quote">
      <blockquote type="cite">
        <div>Hello — find below the creative details for the upcoming campaign.</div>
        <div>Subject Line: Do you approve of DOGE?</div>
        <div>Please review and confirm.</div>
      </blockquote>
    </div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Happy to help')
        ->and($result)->not->toContain('creative details for the upcoming campaign')
        ->and($result)->not->toContain('Do you approve of DOGE');
});

// ── From: block inside nested divs (real campaign pattern id=708) ─────────────

it('cuts an inline From:/Subject Line: block inside a nested div, keeping the intro', function (): void {
    // Pattern observed in campaign approval emails: the reply intro appears first,
    // then a block starting with "From: Name" and "Subject Line: ..." follows.
    $html = <<<'HTML_WRAP'
    <html><body>
    <div dir="ltr">Hello Whitney — below is a top-performing email trashing Ilhan Omar.
    Let me know if you'd like links and HTML!
      <div>
        <div>Email: Omar Promotion</div>
        <div>From: Dalia For Congress<br>
          Subject Line 1: AOC's BFF Wants A Promotion!<br>
          Subject Line 2: Squad Member ILHAN OMAR wants a promotion<br>
        </div>
      </div>
    </div>
    </body></html>
    HTML_WRAP;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Hello Whitney')
        ->and($result)->not->toContain('Dalia For Congress')
        ->and($result)->not->toContain("AOC's BFF");
});

it('cuts a multi-sender From:/Subject Line: block while preserving the reply', function (): void {
    $html = <<<'HTML'
    <html><body>
    <div dir="ltr">
      <div>Here are the top performers for Vivek. Let me know if this works for you.</div>
      <div><br></div>
      <div>From: Vivek Ramaswamy<br>
        Subject line: Big Announcement<br>
        Subject line: It's happening<br>
        Subject line: The biggest influence on 2024<br>
      </div>
    </div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('top performers for Vivek')
        ->and($result)->not->toContain('Vivek Ramaswamy')
        ->and($result)->not->toContain('Big Announcement');
});

// ── Quoted-line (>) removal via text algorithm ────────────────────────────────

it('removes three or more consecutive > quoted lines', function (): void {
    // The text algorithm requires ≥3 consecutive quoted lines to fire without a splitter.
    $html = <<<'HTML'
    <html><body>
    <div>Thanks, that looks correct.</div>
    <div>&gt; Please review the following items:</div>
    <div>&gt; 1. Budget allocation for Q2</div>
    <div>&gt; 2. Campaign schedule for March</div>
    <div>&gt; 3. Creative approval deadline</div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Thanks, that looks correct.')
        ->and($result)->not->toContain('Budget allocation for Q2');
});

// ── Original-Message splitter ─────────────────────────────────────────────────

it('removes content after a -----Original Message----- splitter', function (): void {
    $html = <<<'HTML'
    <html><body>
    <div>Got it — I will follow up with the client today.</div>
    <div>-----Original Message-----</div>
    <div>From: Manager &lt;manager@example.com&gt;</div>
    <div>Sent: Tuesday, March 18, 2025 2:00 PM</div>
    <div>Please make sure to follow up with the client about the outstanding invoice.</div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('I will follow up')
        ->and($result)->not->toContain('outstanding invoice');
});

// ── Large emails (>419 tags) are now fully processed ──────────────────────────

it('processes a large email with more than 419 tags instead of bailing out early', function (): void {
    // The old 419-tag limit was a workaround for algorithm bugs. With those bugs
    // fixed, large emails go through the full pipeline.
    $reply = str_repeat('<div>Reply line goes here.</div>', 220);
    $html = "<html><body>{$reply}<blockquote><p>Original quoted content</p></blockquote></body></html>";

    expect(substr_count($html, '<'))->toBeGreaterThan(419);

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Reply line goes here.')
        ->and($result)->not->toContain('Original quoted content');
});

it('returns a large email unchanged when it contains no quotation to strip', function (): void {
    // No quotation markers → nothing to cut → original preserved (via normal path, not early exit).
    $repeated = str_repeat('<div>Newsletter content line.</div>', 220);
    $html = "<html><body>{$repeated}</body></html>";

    expect(substr_count($html, '<'))->toBeGreaterThan(419);

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Newsletter content line.');
});

// ── Standalone reply with no quotation (nothing to cut) ───────────────────────

it('keeps a campaign-style standalone message fully intact when there is nothing to cut', function (): void {
    // Mirrors the "Hello! Targeted Victory needs your help" pattern that appears
    // frequently in the dataset — short single-message emails with project details
    // but no reply chain.
    $html = <<<'HTML'
    <html><body>
    <div>Hello! Targeted Victory needs your help with a new project.
    Please see below for project details and files for NRCC.</div>
    <div>Send Date(s): 3/21/2025 - 3/31/2025</div>
    <div>Client: NRCC</div>
    <div>Budget: $25,000</div>
    <div>Please confirm receipt and reach out with any questions.</div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Targeted Victory needs your help')
        ->and($result)->toContain('NRCC')
        ->and($result)->toContain('25,000');
});

// ── Zimbra hr divider with content after it ───────────────────────────────────

it('removes content after a Zimbra __DIVIDER__ hr', function (): void {
    $html = <<<'HTML'
    <html><body>
    <div>Here is the approved test for VFG. Thanks!</div>
    <hr data-marker="__DIVIDER__"/>
    <div>From: Nick Dordeski</div>
    <div>Subject: TEST - Red wall in CO</div>
    <div>Here is the VFG test for today.</div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Here is the approved test for VFG')
        ->and($result)->not->toContain('Red wall in CO');
});

// ── Out-of-office / auto-reply (single short message, preserved intact) ────────

it('preserves an out-of-office auto-reply body unchanged', function (): void {
    // Simple out-of-office message — no quotation markers, should be returned intact.
    $html = <<<'HTML'
    <html><body>
    <div>Thanks for reaching out! I will be out of the office with limited
    availability until Tuesday June 24th.</div>
    <div>If you need an immediate response, please contact my colleague at
    backup@example.com.</div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('out of the office')
        ->and($result)->toContain('backup@example.com');
});

// ── Reply with email signature preserved ─────────────────────────────────────

it('strips the inline campaign block but keeps the signature div that follows', function (): void {
    // Reproduces the real pattern from production id=2: inline "From: Name<br>Subject:..."
    // with a gmail_signature div at the same nesting level that must be preserved.
    $html = <<<'MARKUP'
    <html><body>
    <div dir="ltr">
      <div>Hey Team,<br><br>
        I have a new send from SkyTree Book Fairs about beating the Scholastic Goliath.
        Details below:<br><br>
        From: Riley Lee<br>
        Subject: David beat Goliath &mdash; Now it&#39;s your turn<br>
        Subject: There&#39;s David vs. Goliath in schools<br><br>
        Artwork and copy is attached.<br><br>
        Thank you.<br>--
      </div>
      <div class="gmail_signature" data-smartmail="gmail_signature">
        <div>Justin Kriner</div>
        <div>Development Coordinator</div>
        <div>Optimize Consulting</div>
      </div>
    </div>
    </body></html>
    MARKUP;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Details below')
        ->and($result)->not->toContain('Riley Lee')
        ->and($result)->not->toContain('David beat Goliath')
        ->and($result)->toContain('Justin Kriner')
        ->and($result)->toContain('Optimize Consulting');
});

// ---------------------------------------------------------------------------
// Multi-level reply chains
// ---------------------------------------------------------------------------

it('strips the quoted chain from a two-level reply', function (): void {
    // Outer reply → inner reply → original. The checkpoint algorithm should cut
    // everything from the first blockquote down.
    $html = <<<'HTML'
    <html><body>
    <div>My response to the response.</div>
    <blockquote>
      <div>First reply text here.</div>
      <blockquote>
        <div>Original message content.</div>
      </blockquote>
    </blockquote>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('My response to the response.')
        ->and($result)->not->toContain('First reply text here.')
        ->and($result)->not->toContain('Original message content.');
});

it('strips the gmail_quote chain in a deeply nested thread', function (): void {
    $html = <<<'HTML'
    <html><body>
    <div dir="ltr">Approved — please proceed.</div>
    <div class="gmail_quote">
      <div>On Fri, 20 Mar 2025, Alice wrote:</div>
      <blockquote class="gmail_quote">
        <div>Can you approve the attached?</div>
        <div class="gmail_quote">
          <div>On Thu, 19 Mar 2025, Bob wrote:</div>
          <blockquote class="gmail_quote">
            <div>Please review and approve.</div>
          </blockquote>
        </div>
      </blockquote>
    </div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Approved')
        ->and($result)->not->toContain('Can you approve the attached')
        ->and($result)->not->toContain('Please review and approve');
});

// ---------------------------------------------------------------------------
// Apple Mail full forwarded message structure
// ---------------------------------------------------------------------------

it('strips full Apple Mail forwarded message content, keeping only the reply', function (): void {
    // Mirrors production ID 4205: reply body above a blockquote[type=cite] containing
    // the full Apple Mail forwarded structure (div.Begin forwarded message + nested header divs).
    $html = <<<'HTML'
    <html><body>
    <div>10 DLC info attached.</div>
    <div>K. Gibson<br>List Manager<br>ActiveEngagement</div>
    <blockquote type="cite">
      <div>Begin forwarded message:</div>
      <div>
        <b>From:</b> Sender Name &lt;sender@example.com&gt;<br>
        <b>Date:</b> September 10, 2025 at 1:00 PM CDT<br>
        <b>To:</b> recipient@example.com<br>
        <b>Subject:</b> Re: 10 DLC Registration
      </div>
      <div>Here is the forwarded body content with campaign details.</div>
    </blockquote>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('10 DLC info attached.')
        ->and($result)->toContain('K. Gibson')
        ->and($result)->not->toContain('Here is the forwarded body content')
        ->and($result)->not->toContain('Begin forwarded message:');
});

it('keeps only the signature when the reply section is just a sig above a forwarded blockquote', function (): void {
    // Mirrors production ID 6035: no prose reply, just a signature block above
    // a forwarded blockquote. The signature must be preserved.
    $html = <<<'HTML'
    <html><body>
    <div>K. Gibson<br>List Manager<br>ActiveEngagement<br>www.actengage.com</div>
    <blockquote type="cite">
      <div>Begin forwarded message:</div>
      <div>
        <b>From:</b> Newsletter &lt;newsletter@example.com&gt;<br>
        <b>Subject:</b> Weekly digest
      </div>
      <div>Forwarded email body content goes here.</div>
    </blockquote>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('K. Gibson')
        ->and($result)->toContain('ActiveEngagement')
        ->and($result)->not->toContain('Forwarded email body content')
        ->and($result)->not->toContain('Begin forwarded message:');
});

// ---------------------------------------------------------------------------
// Inline reply detection — should NOT strip quoted content
// ---------------------------------------------------------------------------

it('does not cut when quoted markers appear between text blocks (inline reply)', function (): void {
    // Inline interleaved reply: m...t...m pattern — Python and talon both preserve
    // these since cutting would destroy the meaning of the exchange.
    $html = <<<'HTML'
    <html><body>
    <div>&gt; Can you confirm the budget?</div>
    <div>Yes, the budget is confirmed at $10,000.</div>
    <div>&gt; And the timeline?</div>
    <div>We are targeting end of Q2.</div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('budget is confirmed')
        ->and($result)->toContain('targeting end of Q2');
});

// ---------------------------------------------------------------------------
// readableTextEmpty safety net
// ---------------------------------------------------------------------------

it('returns the original when cutting would leave an empty body', function (): void {
    // The only meaningful content is inside a blockquote. Cutting it would leave
    // nothing. readableTextEmpty catches this and returns the original.
    $html = <<<'HTML'
    <html><body>
    <blockquote>
      <p>This is the only content in the email.</p>
    </blockquote>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('This is the only content in the email.');
});

it('returns the original when a From: block is the sole body content', function (): void {
    // cutFromBlock would strip everything, making the body empty.
    // readableTextEmpty restores the original.
    $html = <<<'HTML'
    <html><body>
    <div>
      <div>From: Campaign Team &lt;team@example.com&gt;</div>
      <div>Subject: Weekly Newsletter</div>
      <div>All the campaign content is here.</div>
    </div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Campaign Team')
        ->and($result)->toContain('All the campaign content is here.');
});

// ---------------------------------------------------------------------------
// Outlook 2003 MsoNormal splitter
// ---------------------------------------------------------------------------

it('removes the Outlook 2003 MsoNormal hr splitter and everything after it', function (): void {
    $html = <<<'HTML'
    <html><body>
    <p>Reply goes here.</p>
    <div>
      <div class="MsoNormal" align="center">
        <font><span><hr size="3" width="100%" align="center"></span></font>
      </div>
      <div>Original message content from Outlook 2003.</div>
    </div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Reply goes here.')
        ->and($result)->not->toContain('Original message content from Outlook 2003.');
});

// ---------------------------------------------------------------------------
// Unicode content
// ---------------------------------------------------------------------------

it('handles unicode content in reply and quoted sections', function (): void {
    $html = <<<'HTML'
    <html><body>
    <div>Hei! Jeg har sett gjennom materialet. Alt ser bra ut. 👍</div>
    <blockquote>
      <div>Vennligst se vedlagte filer for kampanjedetaljer.</div>
    </blockquote>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Alt ser bra ut')
        ->and($result)->not->toContain('Vennligst se vedlagte');
});

// ---------------------------------------------------------------------------
// On-date-wrote splitter in HTML context
// ---------------------------------------------------------------------------

it('strips content after a multi-line On-date-wrote splitter in HTML', function (): void {
    $html = <<<'HTML'
    <html><body>
    <div>Looks good to me!</div>
    <div>On Wednesday, April 2, 2025 at 3:45 PM,
    Alice Smith &lt;alice@example.com&gt; wrote:</div>
    <div>Please review the attached creative brief.</div>
    <div>Thanks, Alice</div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Looks good to me')
        ->and($result)->not->toContain('Please review the attached creative brief');
});

it('strips content after a Sent-from-mobile footer splitter', function (): void {
    $html = <<<'HTML'
    <html><body>
    <div>Will do — sending files now.</div>
    <div>Sent from my iPhone</div>
    <div>On Apr 2, 2025, at 3:45 PM, Bob &lt;bob@example.com&gt; wrote:</div>
    <div>Can you send the files today?</div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('sending files now')
        ->and($result)->not->toContain('Can you send the files today');
});

// ---------------------------------------------------------------------------
// extractFromPlain end-to-end
// ---------------------------------------------------------------------------

it('extracts plain text reply from a multi-level quoted chain', function (): void {
    $text = implode("\n", [
        'This is my latest response.',
        '',
        'On Wed, 2 Apr 2025, Alice wrote:',
        '> On Tue, 1 Apr 2025, Bob wrote:',
        '>> The original message is here.',
        '> Alice replied to it.',
        '',
        'And I am replying to Alice.',
    ]);

    $result = app(TalonService::class)->extractFromPlain($text);

    expect($result)->toContain('This is my latest response.')
        ->and($result)->not->toContain('The original message is here.');
});

it('preserves a forwarded plain-text message intact', function (): void {
    $text = implode("\n", [
        '---------- Forwarded message ----------',
        'From: sender@example.com',
        'Subject: Important update',
        '',
        'Please review the attached materials.',
    ]);

    $result = app(TalonService::class)->extractFromPlain($text);

    expect($result)->toContain('Please review the attached materials.');
});

// ---------------------------------------------------------------------------
// HTML comments — stripped cleanly, do not affect quotation detection
// ---------------------------------------------------------------------------

it('handles emails that contain HTML comments without corrupting output', function (): void {
    $html = <<<'HTML'
    <html><body>
    <!-- Tracking pixel removed -->
    <div>Thanks for the update!</div>
    <!-- Original message follows -->
    <blockquote>
      <div>Please review the attached proposal.</div>
    </blockquote>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Thanks for the update!')
        ->and($result)->not->toContain('Please review the attached proposal');
});

// ---------------------------------------------------------------------------
// readableTextEmpty — single-character visible content (ID 20073 regression)
// ---------------------------------------------------------------------------

it('returns original when body visible text is a single emoji after cutting blockquote (ID 20073)', function (): void {
    // Python's _readable_text_empty uses a checkpoint-FREE copy, so "👍" (len=1)
    // is not included in visible text, body is considered empty, original returned.
    // PHP was using the checkpoint-stamped copy so "👍" + marker had len > 1 and
    // the stripped version was returned instead of the original.
    $html = <<<'HTML'
    <html><body><p>👍</p><blockquote><p>Original quoted content</p></blockquote></body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    // Body visible content is just "👍" (len=1) — readableTextEmpty should be true,
    // so the pipeline returns the original unmodified HTML.
    expect($result)->toContain('Original quoted content');
});

it('does not cut when only whitespace remains after removing blockquote', function (): void {
    $html = '<html><body>   <blockquote><p>Only quoted</p></blockquote></body></html>';
    $result = app(TalonService::class)->extractFromHtml($html);
    expect($result)->toContain('Only quoted');
});

// ---------------------------------------------------------------------------
// Second-pass extraction — forwarded message followed by From: splitter (ID 20632)
// ---------------------------------------------------------------------------

it('cuts From: content that appears after a forwarded block on the second pass (ID 20632)', function (): void {
    // Python's batch.py runs extract_from_html twice. On the first pass the
    // forwarded-message guard ([te]*f pattern) prevents cutting. On the second pass
    // the forwarded header is gone and the From: splitter is detected and cut.
    $html = <<<'HTML'
    <html><body>
    <div>Thank you for the message.</div>
    <div>---------- Forwarded message ----------</div>
    <div>From: Michael Whatley &lt;mike@example.com&gt;</div>
    <div>Subject: Re: Project update</div>
    <div>Original forwarded body content here.</div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    // After two passes the From: block and content after it should be removed.
    expect($result)->toContain('Thank you for the message.')
        ->and($result)->not->toContain('Original forwarded body content here.');
});

// ---------------------------------------------------------------------------
// ProtonMail forwarded block — RE_FWD must match even when treeToText places
// "------- Forwarded Message -------" and "From:" on the same line (no $ anchor)
// ---------------------------------------------------------------------------

// gmail_quote with forwarded message text inside gmail_attr child (ID 25897 regression)
// Python's cut_gmail_quote checks el.text (direct text before first child), which is
// None/whitespace here → cuts. PHP was checking full textContent including child text.

it('cuts a gmail_quote_container where forwarded message text is inside a gmail_attr child div', function (): void {
    // Real Gmail forwarded message structure: the "Forwarded message" text is inside
    // the gmail_attr child, NOT as direct text of the gmail_quote div. Python's
    // cut_gmail_quote sees el.text = None → cuts. PHP must do the same.
    $html = <<<'HTML'
    <html><body>
    <div dir="ltr"><div>FYI — see the forward below.</div></div>
    <div class="gmail_quote gmail_quote_container">
      <div dir="ltr" class="gmail_attr">---------- Forwarded message ---------<br>
      From: Alice Smith &lt;alice@example.com&gt;<br>
      Subject: Project update<br></div>
      <blockquote class="gmail_quote" style="margin:0 0 0 .8ex">
        <div>This is the forwarded content.</div>
      </blockquote>
    </div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('FYI')
        ->and($result)->not->toContain('This is the forwarded content.');
});

it('does not cut a gmail_quote when the forwarded message text is direct text of the div', function (): void {
    // When "---------- Forwarded message ----------" is the direct text of the
    // gmail_quote element (not inside a child), Python preserves it — PHP should too.
    $html = <<<'HTML'
    <html><body>
    <div>Please see forwarded email.</div>
    <div class="gmail_quote">---------- Forwarded message ----------
    From: sender@example.com
    Subject: Important update</div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Please see forwarded email.')
        ->and($result)->toContain('Forwarded message');
});

it('preserves ProtonMail forwarded message headers including Date/Subject/To lines', function (): void {
    // ProtonMail renders: "------- Forwarded Message -------<br>From: ...<br>Date: ..."
    // treeToText puts "Forwarded Message -------" and "From:" on the same line.
    // RE_FWD without trailing $ still matches → forwarded guard fires → nothing cut.
    $html = <<<'HTML'
    <html><body>
    <div>DP test send</div>
    <div>
      <div class="protonmail_quote">------- Forwarded Message -------<br>
      From: Susan M. Michael &lt;team@example.com&gt;<br>
      Date: On Thursday, October 23rd, 2025 at 3:57 PM<br>
      Subject: TEST - As Israel beings to heal<br>
      To: Conservative Vision &lt;team@d-ploy.it&gt;<br></div>
    </div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('DP test send')
        ->and($result)->toContain('Date: On Thursday')
        ->and($result)->toContain('Subject: TEST')
        ->and($result)->toContain('To: Conservative Vision');
});

it('does not cut after br-tail From: when the parent element direct text is a forwarded-message header', function (): void {
    // cutFromBlock Case 2: when "From:" appears as tail text after a <br>,
    // Python checks block.getparent().text (direct text before first child) against RE_FWD.
    // PHP was wrongly using $parent->textContent (full content including "From:" etc.)
    // which never matches RE_FWD. This ensures the direct-text guard fires correctly.
    $html = <<<'HTML'
    <html><body>
    <div>FYI below.</div>
    <div>------- Forwarded Message -------<br>
    From: Alice &lt;alice@example.com&gt;<br>
    Date: Mon, 1 Jan 2024<br>
    Subject: Hello<br></div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('FYI below.')
        ->and($result)->toContain('Forwarded Message')
        ->and($result)->toContain('From: Alice');
});

it('preserves br-separated header block that starts with forwarded message line via treeToText newline fix', function (): void {
    // When <br> has tail content, Python's html_tree_to_text emits \n BEFORE the content
    // (because br is in _HARDBREAKS and el_text has length > 1). This places the forwarded
    // marker on its own line, giving it an 'f' marker and triggering the forwarded guard.
    // PHP's fix: add \n before HARD_BREAK_TAGS with content (not just after).
    $html = <<<'HTML'
    <html><body>
    <div>Please review the forwarded email.</div>
    <div>---------- Forwarded message ----------<br>
    From: Bob &lt;bob@example.com&gt;<br>
    Subject: Important<br>
    Body content here.</div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Please review the forwarded email.')
        ->and($result)->toContain('Forwarded message')
        ->and($result)->toContain('From: Bob');
});

it('preserves all reply lines when each line is in its own gmail_quote div', function (): void {
    // When an email client wraps each reply line in a separate <div class="gmail_quote">,
    // PHP's second pass must NOT remove those divs — they contain the actual reply text.
    // Previously, <div class="gmail_quote"><br></div> separators (checkpoint-only content)
    // were incorrectly considered "in quotation" by deleteQuotationTags because the
    // orphan-checkpoint guard was over-aggressive.
    $html = <<<'HTML'
    <html><body>
    <div dir="ltr">
      <div class="gmail_quote">Team</div>
      <div class="gmail_quote"><br></div>
      <div class="gmail_quote">Test for TPUSA for Approval</div>
      <div class="gmail_quote"><br></div>
      <div class="gmail_quote">Thanks</div>
      <div class="gmail_quote"><br></div>
      <div class="gmail_quote">Nina
        <div>-------- Original Message --------
          <table><tr><th>Subject:</th><td>Test email</td></tr>
          <tr><th>Date:</th><td>2025-10-24</td></tr>
          <tr><th>From:</th><td>sender@example.com</td></tr></table>
        </div>
      </div>
    </div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Test for TPUSA for Approval')
        ->and($result)->toContain('Thanks')
        ->and($result)->toContain('Nina')
        ->and($result)->not->toContain('sender@example.com');
});

it('preserves reply content when From: appears as creative brief label after a spacer div', function (): void {
    // cutFromBlock Case 2 was using following-sibling::text()[1] which matched the
    // "Team,...Details below:" div (whose first following *text* sibling is "From: John...")
    // even though an element sibling (spacer div) sits between them. Python uses mg:tail()
    // which only matches the element whose IMMEDIATE next sibling is the "From:" text node.
    // The fix: use following-sibling::node()[1][self::text()] so only the spacer div matches,
    // not the content div earlier in the tree.
    $html = <<<'HTML'
    <html><body>
    <div dir="ltr"><div><div>
      <div>Team,<br><br>I've got a send. Details below:</div>
      <div><br></div>
      From: John Papola, Content Creator<br>
      <b>Subject Line</b>: Are the kids alright?<br>
      <b>Subject Line</b>: What about the children?<br>
    </div></div></div>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Team,')
        ->and($result)->toContain('Details below:')
        ->and($result)->not->toContain('Are the kids alright?');
});

it('does not cut email containing a creative brief with Subject: and plan: on the same line', function (): void {
    // RE_FROM_COLON_OR_DATE_COLON used [^\n]*\n? (optional newline) which allowed the
    // regex to backtrack and match "an:" inside "plan:" as the German keyword "An" (To:),
    // treating the Subject line as a 2-field splitter. Requires mandatory \n per field.
    $html = <<<'HTML'
    <html><body>
    <p>Team, I&#39;ve got a send. Details below:</p>
    <p><strong>Sender Name</strong>: John Thune</p>
    <p><strong>Subject</strong>: Senate Republicans have a clear plan: secure the border, support ICE, and protect American families.</p>
    <p><strong>Subject Alternatives:</strong></p>
    <ul>
      <li>Senate Republicans have a clear plan</li>
      <li>Senate Republicans have a plan: secure the border, support ICE, and protect American families.</li>
    </ul>
    <p><strong>Preview Text</strong>: Now is the time to stand with ICE, DHS, and law enforcement.</p>
    </body></html>
    HTML;

    $result = app(TalonService::class)->extractFromHtml($html);

    expect($result)->toContain('Senate Republicans have a clear plan')
        ->and($result)->toContain('Preview Text');
});
