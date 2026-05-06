<?php

declare(strict_types=1);

namespace Actengage\Talon;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMText;
use DOMXPath;

class HtmlQuotations
{
    /**
     * Remove div.gmail_quote (unless it is a forwarded message).
     */
    public static function cutGmailQuote(DOMDocument $doc): bool
    {
        $nodes = self::xpath($doc, "//div[contains(concat(' ', normalize-space(@class), ' '), ' gmail_quote ')]");

        foreach ($nodes as $node) {
            // Mirror Python's check: gmail_quote[0].text (lxml el.text = direct text
            // before first child element). Full textContent includes child text and would
            // incorrectly match RE_FWD for "Forwarded message" inside a gmail_attr child.
            $directText = $node->firstChild instanceof DOMText
                ? ($node->firstChild->nodeValue ?? '')
                : '';
            if (preg_match(Patterns::RE_FWD, trim($directText))) {
                continue;
            }
            $node->parentNode?->removeChild($node);

            return true;
        }

        return false;
    }

    /**
     * Remove the Zimbra hr[data-marker="__DIVIDER__"] and everything after it.
     */
    public static function cutZimbraQuote(DOMDocument $doc): bool
    {
        $nodes = self::xpath($doc, '//hr[@data-marker="__DIVIDER__"]');

        if ($nodes->length === 0) {
            return false;
        }

        /** @var DOMElement $divider */
        $divider = $nodes->item(0);
        $divider->parentNode?->removeChild($divider);

        return true;
    }

    /**
     * Remove the last non-nested blockquote that is not .gmail_quote.
     */
    public static function cutBlockquote(DOMDocument $doc): bool
    {
        $nodes = self::xpath(
            $doc,
            '(//blockquote)'
            .'[not(contains(concat(" ", normalize-space(@class), " "), " gmail_quote "))'
            .' and not(ancestor::blockquote)]'
            .'[last()]'
        );

        if ($nodes->length === 0) {
            return false;
        }

        /** @var DOMElement $quote */
        $quote = $nodes->item(0);
        $quote->parentNode?->removeChild($quote);

        return true;
    }

    /**
     * Remove the Microsoft Outlook splitter block and all siblings after it.
     *
     * Handles Outlook 2003/2007/2010/2013 and Windows Mail styles.
     */
    public static function cutMicrosoftQuote(DOMDocument $doc): bool
    {
        $splitter = self::findOutlookSplitter($doc);

        if (! $splitter instanceof DOMElement) {
            return false;
        }

        $parent = $splitter->parentNode;
        if ($parent === null) {
            return false;
        }

        // Remove all siblings after the splitter, then the splitter itself
        while (($next = $splitter->nextSibling) !== null) {
            $parent->removeChild($next);
        }
        $parent->removeChild($splitter);

        return true;
    }

    /**
     * Remove elements by the IDs listed in Patterns::QUOTE_IDS.
     */
    public static function cutById(DOMDocument $doc): bool
    {
        $found = false;

        foreach (Patterns::QUOTE_IDS as $id) {
            $nodes = self::xpath($doc, sprintf('//*[@id="%s"]', $id));

            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
                $found = true;
            }
        }

        return $found;
    }

    /**
     * Remove elements whose text content starts with "From:" or "Date:".
     *
     * Mirrors Python talon's cut_from_block exactly:
     * Case 1 — finds the deepest element whose text content starts with
     *   "From:"/"Date:", walks up to the nearest <div> ancestor, then removes
     *   that div and all its following siblings at that level. Skips only when
     *   the found div is the sole child of <body> (Python's parent_div_is_all_content
     *   guard). When the result would be an empty body, the caller's
     *   readableTextEmpty check restores the original.
     * Case 2 — handles "From:"/"Date:" that appears as tail text (text node
     *   following an element such as <br>) when Case 1 finds no element match.
     */
    public static function cutFromBlock(DOMDocument $doc): bool
    {
        // Case 1: From:/Date: is the text content of an element.
        $blocks = self::xpath($doc,
            "//*[starts-with(normalize-space(.), 'From:')]|".
            "//*[starts-with(normalize-space(.), 'Date:')]"
        );

        if ($blocks->length > 0) {
            // Python takes block[-1]: last match = deepest/most-specific element.
            /** @var DOMElement $block */
            $block = $blocks->item($blocks->length - 1);

            // Walk up to the nearest <div> ancestor (Python: walk until tag == 'div').
            $parentDiv = null;
            $current = $block;
            while ($current !== null) {
                if ($current instanceof DOMElement && strtolower($current->nodeName) === 'div') {
                    $parentDiv = $current;
                    break;
                }
                $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null;
            }

            if ($parentDiv instanceof DOMElement) {
                $parent = $parentDiv->parentNode;

                // Mirror Python's parent_div_is_all_content check: skip only when
                // the found div is the sole child of <body>. In every other case
                // we cut and rely on readableTextEmpty to veto if the result is empty.
                $parentDivIsAllContent = (
                    $parent instanceof DOMElement &&
                    strtolower($parent->nodeName) === 'body' &&
                    $parent->childNodes->length === 1
                );

                if (! $parentDivIsAllContent && $parent !== null) {
                    // Remove parent_div and all its following siblings (Python's loop).
                    $node = $parentDiv;
                    $next = $node->nextSibling;
                    while ($next !== null) {
                        $parent->removeChild($node);
                        $node = $next;
                        $next = $node->nextSibling;
                    }
                    $parent->removeChild($node);

                    return true;
                }
            }

            // No div ancestor found — Python returns False and skips Case 2.
            return false;
        }

        // Case 2: From:/Date: text is in the tail of a preceding element
        // (text node that is a sibling of an element, e.g. after <br>).
        // Only runs when Case 1 found no element match at all.
        //
        // Python talon uses mg:tail() — a custom XPath function that returns only the
        // text IMMEDIATELY after an element's closing tag (lxml's el.tail). We mirror
        // that with following-sibling::node()[1][self::text()], which requires the very
        // next sibling node to be a text node. This prevents matching elements like
        // "Team,...Details below:" whose first following text sibling is "From:..." but
        // whose immediate next sibling is another element (the spacer div/br).
        $tails = self::xpath($doc,
            "//*[starts-with(normalize-space(following-sibling::node()[1][self::text()]), 'From:')]|".
            "//*[starts-with(normalize-space(following-sibling::node()[1][self::text()]), 'Date:')]"
        );

        if ($tails->length > 0) {
            /** @var DOMElement $block */
            $block = $tails->item(0);
            $parent = $block->parentNode;

            if ($parent !== null) {
                // Mirror Python: check block.getparent().text (direct text before first child),
                // not the full textContent. Full textContent includes "From:" etc. which breaks RE_FWD.
                $parentDirectText = $parent->firstChild instanceof DOMText
                    ? trim($parent->firstChild->nodeValue ?? '')
                    : '';
                if (preg_match(Patterns::RE_FWD, $parentDirectText)) {
                    return false;
                }

                while (($next = $block->nextSibling) !== null) {
                    $parent->removeChild($next);
                }
                $parent->removeChild($block);

                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Checkpoint algorithm helpers
    // -------------------------------------------------------------------------

    /**
     * Recursively stamp checkpoint tokens into every text node.
     * Returns the next checkpoint counter.
     */
    public static function addCheckpoints(DOMNode $node, int $counter): int
    {
        if ($node instanceof DOMText) {
            $node->nodeValue = $node->nodeValue.Patterns::CHECKPOINT_PREFIX.$counter.Patterns::CHECKPOINT_SUFFIX;
            $counter++;

            return $counter;
        }

        if ($node instanceof DOMElement && $node->ownerDocument instanceof DOMDocument) {
            // Append checkpoint to end of element's own text node (matching Python's
            // `el.text = el.text + CHECKPOINT` behaviour). Appending means the checkpoint
            // lands on the LAST line of multi-line text blocks, so it falls inside the
            // quotation range when content like a <pre> SMTP-header block contains a
            // downstream "From:" splitter. Prepending placed the checkpoint on the FIRST
            // line, which was before the splitter and therefore never in the quotation range.
            if ($node->firstChild instanceof DOMText) {
                $node->firstChild->nodeValue =
                    $node->firstChild->nodeValue.
                    Patterns::CHECKPOINT_PREFIX.$counter.Patterns::CHECKPOINT_SUFFIX;
            } else {
                $textNode = $node->ownerDocument->createTextNode(
                    Patterns::CHECKPOINT_PREFIX.$counter.Patterns::CHECKPOINT_SUFFIX
                );
                $node->insertBefore($textNode, $node->firstChild);
            }
            $counter++;

            foreach (iterator_to_array($node->childNodes) as $child) {
                $counter = self::addCheckpoints($child, $counter);
            }

            // Stamp after last child
            $textNode = $node->ownerDocument->createTextNode(
                Patterns::CHECKPOINT_PREFIX.$counter.Patterns::CHECKPOINT_SUFFIX
            );
            $node->appendChild($textNode);
            $counter++;
        }

        return $counter;
    }

    /**
     * Delete nodes whose checkpoints are all marked as quotation.
     * Returns whether this node was entirely in the quotation.
     *
     * @param  array<int,bool>  $quotationCheckpoints  Checkpoint numbers that fall in the deleted region.
     * @param  array<int,bool>  $appearedCheckpoints  Checkpoint numbers that appeared in ANY text line.
     *                                                Used to distinguish truly orphan checkpoints (never
     *                                                in any line) from checkpoints that appeared in
     *                                                non-deleted lines.
     * @return array{0: int, 1: bool}
     */
    public static function deleteQuotationTags(DOMNode $node, int $counter, array $quotationCheckpoints, array $appearedCheckpoints = []): array
    {
        // [$counter, $nodeInQuotation]
        $nodeInQuotation = true;

        if ($node instanceof DOMText) {
            preg_match_all(Patterns::CHECKPOINT_PATTERN, (string) $node->nodeValue, $matches);
            foreach ($matches[0] as $cp) {
                $cpNum = (int) trim($cp, '#!%');
                if (! ($quotationCheckpoints[$cpNum] ?? false)) {
                    $nodeInQuotation = false;
                }
                $counter++;
            }
            if ($nodeInQuotation) {
                $node->nodeValue = '';
            }

            return [$counter, $nodeInQuotation];
        }

        if (! ($node instanceof DOMElement)) {
            return [$counter, false];
        }

        $quotationChildren = [];
        foreach (iterator_to_array($node->childNodes) as $child) {
            [$counter, $childInQuotation] = self::deleteQuotationTags($child, $counter, $quotationCheckpoints, $appearedCheckpoints);
            if ($childInQuotation) {
                $quotationChildren[] = $child;
            } elseif ($child instanceof DOMText) {
                // A DOMText containing only checkpoint markers (no visible content)
                // is a structural placeholder. It may hold an "orphan" checkpoint —
                // one that never appeared in any text line and is therefore never marked
                // as quotation. Truly orphan checkpoints should not prevent the parent
                // from being recognised as fully in-quotation.
                //
                // However, a checkpoint that DID appear in a text line but is NOT in
                // the deleted range means this node is genuinely outside the quotation.
                // That case must set nodeInQuotation = false, or void elements like <br>
                // between non-quoted lines would be incorrectly deleted.
                $visible = preg_replace(Patterns::CHECKPOINT_PATTERN, '', (string) $child->nodeValue) ?? $child->nodeValue;
                if (trim((string) $visible) === '') {
                    preg_match_all(Patterns::CHECKPOINT_PATTERN, (string) $child->nodeValue, $cpMatches);
                    $isOrphan = true;
                    foreach ($cpMatches[0] as $cp) {
                        if ($appearedCheckpoints[(int) trim($cp, '#!%')] ?? false) {
                            $isOrphan = false;
                            break;
                        }
                    }
                    if ($isOrphan) {
                        $quotationChildren[] = $child;
                    } else {
                        $nodeInQuotation = false;
                    }
                } else {
                    $nodeInQuotation = false;
                }
            } else {
                $nodeInQuotation = false;
            }
        }

        if ($nodeInQuotation) {
            return [$counter, true];
        }

        foreach ($quotationChildren as $child) {
            $node->removeChild($child);
        }

        return [$counter, false];
    }

    // -------------------------------------------------------------------------

    /**
     * @return DOMNodeList<DOMNode>
     */
    public static function xpath(DOMDocument $doc, string $query): DOMNodeList
    {
        $xpath = new DOMXPath($doc);
        $result = $xpath->query($query);

        /** @var DOMNodeList<DOMNode> */
        return $result instanceof DOMNodeList ? $result : new DOMNodeList;
    }

    /**
     * Find the Outlook splitter div using exact style-attribute matching.
     *
     * Mirrors Python talon's cut_microsoft_quote which uses XPath @style='...'
     * (exact equality) — styles with extra spaces do NOT match.
     */
    private static function findOutlookSplitter(DOMDocument $doc): ?DOMElement
    {
        // Outlook 2007/2010/2013 — exact style strings (no spaces around semicolons)
        $splitter = self::xpath($doc,
            "//div[@style='border:none;border-top:solid #B5C4DF 1.0pt;padding:3.0pt 0cm 0cm 0cm']|"
            ."//div[@style='border:none;border-top:solid #B5C4DF 1.0pt;padding:3.0pt 0in 0in 0in']|"
            ."//div[@style='border:none;border-top:solid #E1E1E1 1.0pt;padding:3.0pt 0cm 0cm 0cm']|"
            ."//div[@style='border:none;border-top:solid #E1E1E1 1.0pt;padding:3.0pt 0in 0in 0in']|"
            ."//div[@style='padding-top: 5px; border-top-color: rgb(229, 229, 229); border-top-width: 1px; border-top-style: solid;']"
        );

        if ($splitter->length > 0) {
            /** @var DOMElement $node */
            $node = $splitter->item(0);

            // Outlook 2010: the splitter might be the first child — use parent
            $parent = $node->parentNode;
            if ($parent instanceof DOMElement && $parent->firstChild === $node) {
                return $parent;
            }

            return $node;
        }

        // Outlook 2003 — hr inside MsoNormal div
        $nodes = self::xpath(
            $doc,
            '//div/div[@class="MsoNormal" and @align="center"]'
            .'/font/span'
            .'/hr[@size="3" and @width="100%" and @align="center"]'
        );

        if ($nodes->length > 0) {
            // Walk up: hr → span → font → div[@class=MsoNormal] → div
            $node = $nodes->item(0);
            for ($i = 0; $i < 4 && $node !== null; $i++) {
                $node = $node->parentNode;
            }

            return $node instanceof DOMElement ? $node : null;
        }

        return null;
    }
}
