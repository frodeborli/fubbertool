<?php

namespace FubberTool\Index\Extractors;

/**
 * Base class for hybrid extractors (regex + manual parsing)
 *
 * Strategy:
 * 1. Use simple regex to find entity signatures (class, function, etc.)
 * 2. Use match-braces pattern to safely extract body content
 * 3. Avoids false matches in strings/comments
 *
 * Benefits:
 * - More maintainable than complex recursive patterns
 * - Reuses common logic across languages
 * - Easier to debug and extend
 */
abstract class HybridExtractor extends AbstractRegexExtractor
{
    private ?string $matchPattern = null;

    /**
     * Get the universal match-braces pattern
     *
     * This pattern can safely skip over:
     * - Comments (single-line and multi-line)
     * - Quoted strings with escapes
     * - Nested braces/parens/brackets
     */
    protected function getMatchPattern(): string
    {
        if ($this->matchPattern === null) {
            $this->matchPattern = $this->loadPattern('match-braces');
        }
        return $this->matchPattern;
    }

    /**
     * Find the closing brace for an opening brace at given position
     *
     * Uses the match-braces pattern to safely navigate content.
     *
     * @param string $content Full content
     * @param int $openPos Position of opening brace '{'
     * @return int Position of matching closing brace '}'
     */
    protected function findMatchingBrace(string $content, int $openPos): int
    {
        $depth = 0;
        $len = strlen($content);
        $inString = false;
        $stringChar = '';
        $inComment = false;
        $commentType = '';

        for ($i = $openPos; $i < $len; $i++) {
            $char = $content[$i];
            $nextChar = $i + 1 < $len ? $content[$i + 1] : '';

            // Handle comment start
            if (!$inString && !$inComment) {
                if ($char === '/' && $nextChar === '/') {
                    $inComment = true;
                    $commentType = '//';
                    $i++; // Skip next char
                    continue;
                }
                if ($char === '/' && $nextChar === '*') {
                    $inComment = true;
                    $commentType = '/*';
                    $i++; // Skip next char
                    continue;
                }
                if ($char === '#') {
                    $inComment = true;
                    $commentType = '#';
                    continue;
                }
            }

            // Handle comment end
            if ($inComment) {
                if ($commentType === '//' || $commentType === '#') {
                    if ($char === "\n" || $char === "\r") {
                        $inComment = false;
                    }
                } elseif ($commentType === '/*') {
                    if ($char === '*' && $nextChar === '/') {
                        $inComment = false;
                        $i++; // Skip next char
                    }
                }
                continue;
            }

            // Handle string start/end
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
                continue;
            }

            if ($inString) {
                // Handle escape sequences
                if ($char === '\\') {
                    $i++; // Skip next char
                    continue;
                }
                // Handle string end
                if ($char === $stringChar) {
                    $inString = false;
                }
                continue;
            }

            // Count braces (only when not in string or comment)
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $len - 1;
    }

    /**
     * Extract a block starting from a signature match
     *
     * @param string $content Full content
     * @param int $signatureStart Offset where signature starts
     * @param int $signatureEnd Offset where signature ends
     * @return array{signature: string, body: string, start: int, end: int}|null
     */
    protected function extractBlock(string $content, int $signatureStart, int $signatureEnd): ?array
    {
        // Find the opening brace after the signature
        $openBracePos = $this->findNextNonWhitespace($content, $signatureEnd, '{');

        if ($openBracePos === null) {
            return null;
        }

        // Find matching closing brace
        $closeBracePos = $this->findMatchingBrace($content, $openBracePos);

        $signature = substr($content, $signatureStart, $signatureEnd - $signatureStart);
        $body = substr($content, $openBracePos, $closeBracePos - $openBracePos + 1);

        return [
            'signature' => trim($signature),
            'body' => $body,
            'start' => $signatureStart,
            'end' => $closeBracePos,
        ];
    }

    /**
     * Find next occurrence of a character, skipping whitespace
     *
     * @param string $content Content to search
     * @param int $startPos Position to start from
     * @param string $char Character to find
     * @return int|null Position of character, or null if not found
     */
    protected function findNextNonWhitespace(string $content, int $startPos, string $char): ?int
    {
        $len = strlen($content);
        for ($i = $startPos; $i < $len; $i++) {
            $c = $content[$i];
            if ($c === $char) {
                return $i;
            }
            if (!ctype_space($c)) {
                return null; // Found non-whitespace that's not our target
            }
        }
        return null;
    }

    /**
     * Find preceding docblock for a signature
     *
     * Scans backward from signature position to find docblock comment
     *
     * @param string $content Full content
     * @param int $signaturePos Position where signature starts
     * @return string Docblock content (empty if none found)
     */
    protected function findPrecedingDocblock(string $content, int $signaturePos): string
    {
        // Scan backward to find potential docblock
        $searchStart = max(0, $signaturePos - 5000); // Search up to 5KB back
        $searchContent = substr($content, $searchStart, $signaturePos - $searchStart);

        // Find last /** ... */ before signature
        if (preg_match('/\/\*\*([^*]|\*(?!\/))*\*\/\s*$/s', $searchContent, $match)) {
            return trim($match[0]);
        }

        return '';
    }

    /**
     * Extract entity name from signature
     *
     * @param string $signature Signature string
     * @param string $pattern Regex pattern to extract name
     * @return string|null Entity name or null if not found
     */
    protected function extractName(string $signature, string $pattern): ?string
    {
        if (preg_match($pattern, $signature, $match)) {
            return $match[1];
        }
        return null;
    }

    /**
     * Calculate line numbers for a range
     *
     * @param string $content Full content
     * @param int $start Start position
     * @param int $end End position
     * @return array{start: int, end: int}
     */
    protected function calculateLineNumbers(string $content, int $start, int $end): array
    {
        return [
            'start' => substr_count(substr($content, 0, $start), "\n") + 1,
            'end' => substr_count(substr($content, 0, $end), "\n") + 1,
        ];
    }
}
