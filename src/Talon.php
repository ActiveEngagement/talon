<?php

declare(strict_types=1);

namespace Actengage\Talon;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Throwable;

class Talon
{
    /**
     * Extract the original (non-quoted) portion of an email body.
     *
     * @param  string|null  $contentType  'text/html', 'text/plain', or null to auto-detect
     */
    public function extractFrom(string $body, ?string $contentType = null): string
    {
        try {
            return match ($contentType ?? $this->detectContentType($body)) {
                'text/plain' => $this->extractFromPlain($body),
                default => $this->extractFromHtml($body),
            };
        } catch (Throwable) {
            return $body;
        }
    }

    /**
     * Detect content type by scanning for HTML block-level tags.
     */
    public function detectContentType(string $body): string
    {
        return preg_match('/<(?:html|body|div|p|br|span|table|td|tr|th|h[1-6]|ul|ol|li)\b/i', $body) === 1
            ? 'text/html'
            : 'text/plain';
    }

    /**
     * Extract from an HTML email body.
     */
    public function extractFromHtml(string $html): string
    {
        // Mirror Python's batch.py which calls extract_from_html twice:
        //   result = extract_from_html(body); extract_from_html(result)
        // The second pass handles cases where the first pass is blocked by the
        // forwarded-message guard (markers starting with [te]*f) but the result
        // still contains quotation content that can be cut on a fresh parse.
        $firstPass = $this->extractFromHtmlOnce($html);

        return $this->extractFromHtmlOnce($firstPass);
    }

    /**
     * Single-pass extraction from an HTML email body.
     */
    public function extractFromHtmlOnce(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $html = str_replace("\r\n", "\n", $html);
        $html = preg_replace('/\<\?xml.+\?\>|\<\!DOCTYPE.+]\>/', '', $html) ?? $html;

        // If the body contains no real HTML block-level tags it is plain text
        // (e.g. SMTP headers with angle-bracket email addresses). Return unchanged
        // so the checkpoint algorithm does not misfire on the angle brackets.
        if ($this->detectContentType($html) === 'text/plain') {
            return $html;
        }

        $doc = $this->parseHtml($html);
        if (! $doc instanceof DOMDocument) {
            return $html;
        }

        $result = $this->extractFromHtmlDocument($doc);

        return $result ?? $html;
    }

    /**
     * Extract from a plain-text email body.
     */
    public function extractFromPlain(string $text): string
    {
        return TextQuotations::extract($text);
    }

    /**
     * Run the full extraction pipeline against a parsed DOMDocument.
     * Returns the stripped HTML string, or null if nothing was cut.
     */
    public function extractFromHtmlDocument(DOMDocument $doc): ?string
    {
        $cutQuotations = HtmlQuotations::cutGmailQuote($doc)
            || HtmlQuotations::cutZimbraQuote($doc)
            || HtmlQuotations::cutBlockquote($doc)
            || HtmlQuotations::cutMicrosoftQuote($doc)
            || HtmlQuotations::cutById($doc)
            || HtmlQuotations::cutFromBlock($doc);

        // Deep-copy before adding checkpoint markers (they mutate the tree)
        $docCopy = clone $doc;

        // Add checkpoint stamps to every text position in the copy
        $checkpointCount = HtmlQuotations::addCheckpoints($docCopy->documentElement ?? $docCopy, 0);
        $quotationCheckpoints = array_fill(0, $checkpointCount, false);

        // Convert the stamped tree to plain text and run text-based detection
        $plainText = $this->treeToText($docCopy);
        $plainText = TextQuotations::preprocess($plainText, "\n", 'text/html');
        $lines = explode("\n", $plainText);

        if (count($lines) > 10000) {
            return null;
        }

        // Collect checkpoint numbers per line
        /** @var list<list<int>> $lineCheckpoints */
        $lineCheckpoints = [];
        foreach ($lines as $line) {
            preg_match_all(Patterns::CHECKPOINT_PATTERN, $line, $matches);
            $lineCheckpoints[] = array_map(
                static fn (string $cp): int => (int) trim($cp, '#!%'),
                $matches[0]
            );
        }

        // Strip checkpoints from lines before running the text algorithm
        $lines = array_map($this->stripCheckpoints(...), $lines);

        $markers = TextQuotations::markLines($lines);
        $returnFlags = [];
        TextQuotations::processMarkedLines($lines, $markers, $returnFlags);
        /** @var array{0: bool, 1: int, 2: int} $returnFlags */
        [$linesWereDeleted, $firstDeleted, $lastDeleted] = $returnFlags;

        if (! $linesWereDeleted && ! $cutQuotations) {
            return null;
        }

        // Build index of every checkpoint that appeared in ANY text line (used to
        // distinguish truly-orphan checkpoints from checkpoints that appeared in
        // non-deleted lines when running deleteQuotationTags).
        $appearedCheckpoints = [];
        foreach ($lineCheckpoints as $lineCheckpointArray) {
            foreach ($lineCheckpointArray as $cpNum) {
                $appearedCheckpoints[$cpNum] = true;
            }
        }

        if ($linesWereDeleted) {
            for ($i = $firstDeleted; $i < $lastDeleted; $i++) {
                foreach ($lineCheckpoints[$i] ?? [] as $cpNum) {
                    $quotationCheckpoints[$cpNum] = true;
                }
            }

            HtmlQuotations::deleteQuotationTags(
                $docCopy->documentElement ?? $docCopy,
                0,
                $quotationCheckpoints,
                $appearedCheckpoints
            );
        }

        // Remove any top-level blockquote whose only visible content is the
        // forwarded-message label. Python's second pass catches these; we handle
        // them in a single pass with this targeted check (IDs 4205, 6035).
        $fwdNodes = HtmlQuotations::xpath($docCopy, '//blockquote[not(ancestor::blockquote)]');
        foreach ($fwdNodes as $fwdNode) {
            $visible = $this->stripCheckpoints($fwdNode->textContent);
            if (preg_match('/^begin forwarded message:?$/i', trim($visible))) {
                $fwdNode->parentNode?->removeChild($fwdNode);
            }
        }

        if ($this->readableTextEmpty($docCopy)) {
            return null;
        }

        return $this->serializeDocument($docCopy);
    }

    // -------------------------------------------------------------------------

    /**
     * Convert an HTML tree to plain text, preserving structure with newlines.
     */
    public function treeToText(DOMDocument $doc): string
    {
        // Remove <style> and comment nodes
        foreach (iterator_to_array(HtmlQuotations::xpath($doc, '//style')) as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach (iterator_to_array(HtmlQuotations::xpath($doc, '//comment()')) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $text = '';
        $this->walkForText($doc->documentElement ?? $doc, $text);
        $text = preg_replace('/\n{2,10}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function walkForText(DOMNode $node, string &$text): void
    {
        // Only visit element nodes — text nodes are handled as el.text/el.tail
        // when their parent/sibling element is visited (mirrors Python lxml iteration).
        if (! ($node instanceof DOMElement)) {
            return;
        }

        $tag = strtolower($node->nodeName);

        // Mirror Python's html_tree_to_text:
        //   el_text = (el.text or '') + (el.tail or '')
        // el.text = text before first child element  → PHP: firstChild if DOMText
        // el.tail = text after closing tag in parent → PHP: nextSibling if DOMText
        $ownText = $node->firstChild instanceof DOMText ? ($node->firstChild->nodeValue ?? '') : '';
        $tailText = $node->nextSibling instanceof DOMText ? ($node->nextSibling->nodeValue ?? '') : '';
        $elText = $ownText.$tailText;

        if (mb_strlen($elText) > 1) {
            // Mirror Python: \n before content for BOTH block tags AND hard-break tags.
            // Python: `if el.tag in _BLOCKTAGS + _HARDBREAKS: text += "\n"`
            if (in_array($tag, Patterns::BLOCK_TAGS, true) || in_array($tag, Patterns::HARD_BREAK_TAGS, true)) {
                $text .= "\n";
            }

            if ($tag === 'li') {
                $text .= '  * ';
            }

            $text .= trim($elText).' ';

            $elTextVisible = $this->stripCheckpoints($elText);
            if ($node->hasAttribute('href') && trim($elTextVisible) !== '') {
                $href = $node->getAttribute('href');
                if ($href !== '') {
                    $text .= sprintf('(%s) ', $href);
                }
            }
        } elseif (in_array($tag, Patterns::HARD_BREAK_TAGS, true) && ! str_ends_with($text, "\n")) {
            // Only add \n after for hard-break tags when there was no content (mirrors Python).
            $text .= "\n";
        }

        foreach ($node->childNodes as $child) {
            $this->walkForText($child, $text);
        }
    }

    private function readableTextEmpty(DOMDocument $doc): bool
    {
        // Strip checkpoints from the serialised HTML first, then re-parse into
        // a clean DOM and run treeToText on that. This mirrors Python's behaviour
        // where _readable_text_empty receives html_tree_copy (the checkpoint-FREE
        // original copy), so single-character nodes like "👍" (len=1) are correctly
        // excluded from the visible-text check and the body is treated as empty.
        $html = $this->stripCheckpoints($doc->saveHTML() ?: '');

        $clean = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $clean->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return trim($this->treeToText($clean)) === '';
    }

    private function parseHtml(string $html): ?DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;
        $doc->preserveWhiteSpace = true;

        libxml_use_internal_errors(true);

        $success = $doc->loadHTML(
            '<?xml encoding="utf-8" ?>'.$html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();

        return $success ? $doc : null;
    }

    private function serializeDocument(DOMDocument $doc): string
    {
        $html = $doc->saveHTML() ?: '';

        // Remove checkpoint markers that may remain as orphan text
        return $this->stripCheckpoints($html);
    }

    private function stripCheckpoints(string $text): string
    {
        return preg_replace(Patterns::CHECKPOINT_PATTERN, '', $text) ?? $text;
    }
}
