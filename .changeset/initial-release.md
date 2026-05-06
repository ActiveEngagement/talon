---
"@actengage/talon": minor
---

Initial release.

A PHP port of [mailgun/talon](https://github.com/mailgun/talon) for extracting the original (non-quoted) portion of an email reply chain.

- HTML cutters: Gmail, Outlook 2003–2013, Zimbra, Windows Mail, generic blockquote, `From:`/`Date:` header blocks, quote-container IDs.
- Plain-text cutters: `>` quote blocks, `On <date>, <person> wrote:` in 9 languages, `-----Original Message-----`, multi-line splitters, inline-reply preservation, forwarded-message guard.
- Auto-detects `text/html` vs `text/plain` when no content type is given.
- Validated against ~42K production emails at 99.66% Python-talon parity.
- PHPStan level max, Laravel Pint, Rector clean. 145 Pest tests.
