<?php

declare(strict_types=1);

namespace Actengage\Talon;

class Patterns
{
    // Checkpoint markers used in the hybrid checkpoint algorithm
    public const CHECKPOINT_PREFIX = '#!%!';

    public const CHECKPOINT_SUFFIX = '!%!#';

    public const CHECKPOINT_PATTERN = '/#!%!\d+!%!#/';

    // ---- Forwarded message ----
    public const RE_FWD = '/^[-]+[ ]*Forwarded message[ ]*[-]+$/im';

    // "On <date>, <person> wrote:" — multi-language
    public const RE_ON_DATE_SMB_WROTE = '/(-*[>]?[ ]?(On|Le|W dniu|Op|Am|Em|På|Den|Vào)[ ].*(,|użytkownik)(.*\n){0,2}.*(wrote|sent|a écrit|napisał|schreef|verzond|geschreven|schrieb|escreveu|skrev|đã viết):?-*)/u';

    // "Op/Am <date> wrote/schrieb <person>:" — Dutch/German variant
    public const RE_ON_DATE_WROTE_SMB = '/(-*[>]?[ ]?(Op|Am)[ ].*(.*\n){0,2}.*(schreef|verzond|geschreven|schrieb)[ ]*.*:)/u';

    // ------ Original Message ------ / ----- Reply Message -----
    public const RE_ORIGINAL_MESSAGE = '/[\s]*[-]+[ ]*(Original Message|Reply Message|Ursprüngliche Nachricht|Antwort Nachricht|Oprindelig meddelelse)[ ]*[-]+/iu';

    // "From:" / "Date:" / "Subject:" / "To:" reply-header splitter block.
    // Mirrors Python talon's RE_FROM_COLON_OR_DATE_COLON exactly:
    // requires 2+ consecutive header-field lines from the From/Date/Subject/To
    // keyword list. A single "From:" line is NOT sufficient — it must be
    // accompanied by at least one more matching field on the next line.
    //
    // [^\n]+\n mirrors Python talon's [^\n$]+\n — mandatory newline after each field.
    // [^\n]*\n? (optional newline) allowed backtracking within a single line, causing
    // false positives where short keywords like "An" (German "To:") matched mid-word
    // (e.g. "plan:" contains "an:"). Requiring \n ensures each field ends at an actual
    // line boundary, matching Python's behavior.
    public const RE_FROM_COLON_OR_DATE_COLON = '/((_+\r?\n)?[\s]*:?[*]?(From|Van|De|Von|Fra|Från|Date|Datum|Envoyé|Skickat|Sendt|Gesendet|Sent|Subject|Betreff|Objet|Emne|Ämne|To|An|Til|À|Till)[\s]?:[^\n]+\n){2,}/ium';

    // ---- John Smith wrote ----
    public const RE_ANDROID_WROTE = '/[\s]*[-]+.*(wrote)[ ]*[-]+/i';

    // Polymail: "On ... \n\n< mailto:... > wrote:"
    public const RE_POLYMAIL = '/On.*\s{2}<\smailto:.*\s> wrote:/i';

    // ">" quotation marker at start of line
    public const QUOT_PATTERN = '/^>+ ?/m';

    // Non-quoted, non-empty line
    public const NO_QUOT_LINE = '/^[^>].*[\S].*/m';

    // Header line detector (contains ": ")
    public const RE_HEADER = '/: /';

    // Link normalisation — <http://...>
    public const RE_LINK = '/<(http:\/\/[^>]*)>/';

    public const RE_NORMALIZED_LINK = '/@@(http:\/\/[^>@]*)@@/';

    // Link in parenthesis — (https://...)
    public const RE_PARENTHESIS_LINK = '/\(https?:\/\//';

    // Quotation region in line-marker strings
    // Markers: e=empty, m=quoted, s=splitter, t=text, f=forwarded
    public const RE_QUOTATION = '/
        (
            (?:s|(?:me*){2,})
            .*
            me*
        )
        [te]*$
    /x';

    public const RE_EMPTY_QUOTATION = '/
        (
            (?:(?:se*)+|(?:me*){2,})
        )
        e*
    /x';

    // Additional splitter patterns (as array of PCRE strings)
    public const EXTRA_SPLITTER_PATTERNS = [
        // date/time + email: "02.04.2012 14:20 user@example.com"
        '/(\d+\/\d+\/\d+|\d+\.\d+\.\d+).*\s\S+@\S+/s',
        // ISO date + GMT: "2014-10-17 11:28 GMT+03:00 Bob <bob@example.com>:"
        '/\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}\s+GMT.*\s\S+@\S+/s',
        // "Thu, 26 Jun 2014 14:00:51 +0400 Bob <bob@example.com>:"
        '/\S{3,10}, \d\d? \S{3,10} 20\d\d,? \d\d?:\d\d(:\d\d)?( \S+){3,6}@\S+:/',
        // Samsung device footer
        '/Sent from Samsung.* \S+@\S+> wrote/i',
    ];

    // HTML block-level and hard-break tags (for tree-to-text conversion)
    public const BLOCK_TAGS = ['div', 'p', 'ul', 'li', 'h1', 'h2', 'h3'];

    public const HARD_BREAK_TAGS = ['br', 'hr', 'tr'];

    // HTML quote IDs to cut unconditionally
    public const QUOTE_IDS = ['OLK_SRC_BODY_SECTION'];

    // Microsoft Outlook border styles (partial — checked via str_contains)
    public const OUTLOOK_BORDER_COLORS = ['#E1E1E1', '#B5C4DF'];
}
