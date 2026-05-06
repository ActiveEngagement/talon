# Talon (PHP)

Extract the original message from an email reply chain. A PHP port of [mailgun/talon](https://github.com/mailgun/talon), validated against ~42K real-world Python talon outputs at 99.66% parity.

```php
use Actengage\Talon\Facades\Talon;

$reply = Talon::extractFrom($emailBody);                     // auto-detects html vs plain
$reply = Talon::extractFrom($emailBody, 'text/html');        // or be explicit
$reply = Talon::extractFrom($plainText,  'text/plain');
```

## Installation

```bash
composer require actengage/talon
```

Requires PHP 8.1+, `ext-dom`, `ext-mbstring`. Auto-registers in Laravel via package discovery.

## Usage

### Facade

```php
use Actengage\Talon\Facades\Talon;

Talon::extractFrom($body);                    // auto-detect
Talon::extractFrom($body, 'text/html');
Talon::extractFrom($body, 'text/plain');
```

When `$contentType` is `null` (the default), the body is scanned for HTML block-level tags (`<html>`, `<body>`, `<div>`, `<p>`, `<br>`, `<table>`, `<li>`, etc.). If any are present the input is treated as HTML; otherwise as plain text. Pass an explicit content type to override.

### Service

```php
use Actengage\Talon\Talon;

(new Talon())->extractFromHtml($html);
(new Talon())->extractFromPlain($text);
```

### Lower-level API

For direct access to the text-mode primitives:

```php
use Actengage\Talon\TextQuotations;

TextQuotations::extract($text);
TextQuotations::isSplitter($line);            // returns the matched splitter or null
TextQuotations::markLines($lines);            // returns marker string: e/m/s/t/f
TextQuotations::processMarkedLines($lines, $markers, $flags);
```

## What it handles

| HTML | Plain text |
|------|------------|
| Gmail, Outlook 2003–2013, Zimbra, Windows Mail | `>` quotation blocks (≥3 consecutive) |
| Top-level `<blockquote>` | `On <date>, <person> wrote:` in 9 languages |
| `From:` / `Date:` header blocks (text and tail) | `-----Original Message-----` and variants |
| Known quote-container IDs | Multi-line splitters (≤6 lines) |
| Two-pass for nested forwarded guards | Inline replies preserved; forwarded messages skipped |

UTF-8 throughout (`mb_*` for offsets); both `\n` and `\r\n` delimiters.

## Behaviour & limits

- Plain-text extraction caps at the first 1,000 lines (`TextQuotations::MAX_LINES`).
- HTML extraction returns the original unchanged when `treeToText()` produces more than 10,000 lines (large marketing emails are passed through, since they rarely contain reply chains).
- The HTML pipeline runs twice (`Talon::extractFromHtml` calls `extractFromHtmlOnce` twice) to mirror Python `talon.batch`. The second pass catches forwarded-message guards that block the first.
- Inputs without recognisable HTML block-level tags are returned as-is to avoid misfires on plain-text bodies that happen to contain `<email@...>` brackets.

## Python parity

This port is intentionally close to mailgun/talon. The implementation preserves:

- lxml-style `el.text` / `el.tail` traversal in `Talon::walkForText`
- Exact XPath strings for Outlook splitter detection
- `mg:tail()`-equivalent matching in `cutFromBlock` Case 2 via `following-sibling::node()[1][self::text()]`
- Mandatory-newline `[^\n]+\n` per header field in `RE_FROM_COLON_OR_DATE_COLON` (PCRE backtracking equivalent of Python's `[^\n$]+\n`)
- Splitter pattern check order and regex flags
- Checkpoint stamping order (append, not prepend) so markers land on the last line of multi-line text blocks

Validated against the full Active Engagement mailbox dataset (41,977 messages):

| Metric        | Count  | %      |
|---------------|-------:|-------:|
| Compared      | 41,977 | 100%   |
| Matches       | 41,835 | 99.66% |
| Mismatches    |    142 |  0.34% |

Remaining mismatches are all pre-existing data artefacts (stored Python results computed against a previous version of the body, or invalid-UTF-8 sequences that PHP/libxml2 and Python/lxml decode differently).

## Development

145 Pest tests covering the cutters, splitter detection, marker logic, multilingual splitters, multi-line splitters, inline replies, forwarded-message guards, and end-to-end extraction. The codebase is held to **PHPStan level max** (with Larastan extensions), formatted with **Laravel Pint**, and refactor-checked with **Rector**.

```bash
composer test          # pest
composer lint          # pint
composer stan          # phpstan max
composer rector:check  # rector dry-run
composer check         # all of the above
```

CI runs all four checks in parallel on every push and PR (`.github/workflows/ci.yml`), with composer / vendor / phpstan / rector caches keyed off `composer.lock` and the workflow concurrency-grouped per ref.

## Releases

Versioning is managed with [Changesets](https://github.com/changesets/changesets). Add a changeset whenever you make a user-facing change:

```bash
pnpm changeset
```

On push to `main`, `.github/workflows/release.yml` opens a "Version Packages" PR. Merging that PR bumps the version, writes `CHANGELOG.md`, and tags the release. The package is `private: true` in `package.json` — no npm publish happens.

## Public API

These methods form the stability surface — anything else is internal.

- `Talon::extractFrom(string $body, ?string $contentType = null): string`
- `Talon::detectContentType(string $body): string`
- `Talon::extractFromHtml(string $html): string`
- `Talon::extractFromPlain(string $text): string`
- `TextQuotations::extract(string $text): string`
- `TextQuotations::isSplitter(string $line): ?string`
- `TextQuotations::markLines(array $lines): string`
- `TextQuotations::processMarkedLines(array $lines, string $markers, array &$flags = []): array`

The `HtmlQuotations::cut*` methods and checkpoint helpers are public for testing but should be considered internal.

## License

MIT. Originally derived from [mailgun/talon](https://github.com/mailgun/talon) (Apache 2.0).
