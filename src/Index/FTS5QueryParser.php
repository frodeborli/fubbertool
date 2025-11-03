<?php

namespace FubberTool\Index;

/**
 * FTS5 Query Parser
 *
 * Parses FTS5 query syntax and tokenizes only the search terms,
 * preserving operators and structure.
 *
 * Supports:
 * - Binary operators: AND, OR, NOT
 * - NEAR operator: NEAR(term1 term2, distance)
 * - Phrases: "quoted phrases"
 * - Prefix search: term*
 * - Column filters: column:term
 * - Start of column: ^term
 * - Parentheses (nested)
 * - Unary plus: +term (phrase proximity)
 */
class FTS5QueryParser
{
    private string $input;
    private int $pos;
    private int $length;

    /**
     * Parse and tokenize an FTS5 query
     *
     * @param string $query Raw FTS5 query
     * @return string Tokenized query with preserved structure
     */
    public static function parse(string $query): string
    {
        $parser = new self($query);
        return $parser->parseExpression();
    }

    private function __construct(string $query)
    {
        $this->input = $query;
        $this->pos = 0;
        $this->length = strlen($query);
    }

    /**
     * Parse top-level expression
     */
    private function parseExpression(): string
    {
        $result = $this->parseTerm();

        while (true) {
            $this->skipWhitespace();

            // Check for binary operators
            if ($this->tryConsumeKeyword('OR')) {
                $this->skipWhitespace();
                $result .= ' OR ' . $this->parseTerm();
            } elseif ($this->tryConsumeKeyword('AND')) {
                $this->skipWhitespace();
                $result .= ' AND ' . $this->parseTerm();
            } elseif ($this->tryConsumeKeyword('NOT')) {
                $this->skipWhitespace();
                $result .= ' NOT ' . $this->parseTerm();
            } else {
                // Implicit AND (space between terms)
                if ($this->pos < $this->length && $this->peek() !== ')') {
                    $this->skipWhitespace();
                    if ($this->pos < $this->length && $this->peek() !== ')') {
                        $result .= ' ' . $this->parseTerm();
                        continue;
                    }
                }
                break;
            }
        }

        return $result;
    }

    /**
     * Parse a term (highest precedence)
     */
    private function parseTerm(): string
    {
        $this->skipWhitespace();

        // NEAR operator
        if ($this->peekKeyword('NEAR')) {
            return $this->parseNear();
        }

        // Parentheses (grouping)
        if ($this->peek() === '(') {
            $this->advance();
            $expr = $this->parseExpression();
            $this->skipWhitespace();
            $this->consume(')');
            return '(' . $expr . ')';
        }

        // Column filter (e.g., "a:term" or "a:^term")
        if ($this->hasColumnPrefix()) {
            $col = $this->consumeIdentifier();
            $this->consume(':');

            // Check for start-of-column marker after column filter
            if ($this->peek() === '^') {
                $this->advance();
                $this->skipWhitespace();
                $term = $this->parseAtom();
                return $col . ':^' . $term;
            }

            $term = $this->parseAtom();
            return $col . ':' . $term;
        }

        // Start of column marker
        if ($this->peek() === '^') {
            $this->advance();
            $this->skipWhitespace();
            $atom = $this->parseAtom();
            return '^' . $atom;
        }

        // Unary plus (phrase proximity)
        if ($this->peek() === '+') {
            $this->advance();
            $this->skipWhitespace();
            $atom = $this->parseAtom();
            return '+' . $atom;
        }

        // Regular atom
        return $this->parseAtom();
    }

    /**
     * Parse an atom (word or phrase)
     */
    private function parseAtom(): string
    {
        $this->skipWhitespace();

        // Quoted phrase
        if ($this->peek() === '"') {
            return $this->parsePhrase();
        }

        // Bare word
        $word = $this->parseWord();

        // Prefix search operator
        if ($this->peek() === '*') {
            $this->advance();
            return $word . '*';
        }

        return $word;
    }

    /**
     * Parse a quoted phrase and tokenize its content
     */
    private function parsePhrase(): string
    {
        $this->consume('"');

        $content = '';
        while ($this->peek() !== '"' && $this->peek() !== null) {
            if ($this->peek() === '\\' && $this->peekAhead(1) === '"') {
                // Escaped quote
                $content .= '\\"';
                $this->advance();
                $this->advance();
            } else {
                $content .= $this->peek();
                $this->advance();
            }
        }

        $this->consume('"');

        // Tokenize the phrase content (don't auto-quote, we're already in a phrase)
        $tokenized = Tokenizer::tokenize($content, false);
        return '"' . $tokenized . '"';
    }

    /**
     * Parse a bare word and tokenize it
     */
    private function parseWord(): string
    {
        $word = '';

        // Consume word characters (including operators like $, @, ->, etc.)
        // Exclude FTS5 special characters and delimiters
        while ($this->peek() !== null &&
               !ctype_space($this->peek()) &&
               !in_array($this->peek(), ['(', ')', '"', '*', ':', '^', '+', ','], true)) {
            $word .= $this->peek();
            $this->advance();
        }

        if (empty($word)) {
            throw new \RuntimeException("Expected word at position {$this->pos}");
        }

        // Tokenize the word (auto-quote multi-token results to keep them together)
        return Tokenizer::tokenize($word, true);
    }

    /**
     * Parse NEAR operator
     * Format: NEAR(term1 term2 ..., distance)
     */
    private function parseNear(): string
    {
        $this->consumeKeyword('NEAR');
        $this->skipWhitespace();
        $this->consume('(');

        $terms = [];
        $distance = null;

        // Parse terms until we hit comma or closing paren
        while (true) {
            $this->skipWhitespace();

            // Check for closing paren
            if ($this->peek() === ')') {
                break;
            }

            // Check for comma (distance parameter follows)
            if ($this->peek() === ',') {
                $this->advance(); // consume comma
                $this->skipWhitespace();

                // Parse distance number (don't tokenize it!)
                $distanceStr = '';
                while ($this->peek() !== null && ctype_digit($this->peek())) {
                    $distanceStr .= $this->peek();
                    $this->advance();
                }

                $distance = $distanceStr;
                $this->skipWhitespace();
                break; // After distance, we expect closing paren
            }

            // Parse next term
            $terms[] = $this->parseAtom();
        }

        $this->consume(')');

        if ($distance !== null) {
            return 'NEAR(' . implode(' ', $terms) . ', ' . $distance . ')';
        } else {
            return 'NEAR(' . implode(' ', $terms) . ')';
        }
    }

    // ========================================================================
    // Helper methods
    // ========================================================================

    /**
     * Peek at current character
     */
    private function peek(): ?string
    {
        if ($this->pos >= $this->length) {
            return null;
        }
        return $this->input[$this->pos];
    }

    /**
     * Peek ahead N characters
     */
    private function peekAhead(int $n): ?string
    {
        $pos = $this->pos + $n;
        if ($pos >= $this->length) {
            return null;
        }
        return $this->input[$pos];
    }

    /**
     * Advance position by 1
     */
    private function advance(): void
    {
        $this->pos++;
    }

    /**
     * Consume expected character or throw exception
     */
    private function consume(string $expected): void
    {
        if ($this->peek() !== $expected) {
            throw new \RuntimeException(
                "Expected '$expected' at position {$this->pos}, got '" .
                ($this->peek() ?? 'EOF') . "'"
            );
        }
        $this->advance();
    }

    /**
     * Skip whitespace
     */
    private function skipWhitespace(): void
    {
        while ($this->peek() !== null && ctype_space($this->peek())) {
            $this->advance();
        }
    }

    /**
     * Peek at upcoming keyword (case-insensitive)
     */
    private function peekKeyword(string $keyword): bool
    {
        $saved = $this->pos;
        $this->skipWhitespace();

        $len = strlen($keyword);
        $match = true;

        for ($i = 0; $i < $len; $i++) {
            if ($this->peek() === null ||
                strtoupper($this->peek()) !== strtoupper($keyword[$i])) {
                $match = false;
                break;
            }
            $this->advance();
        }

        // Must be followed by non-word character
        if ($match && $this->peek() !== null && ctype_alnum($this->peek())) {
            $match = false;
        }

        $this->pos = $saved;
        return $match;
    }

    /**
     * Try to consume a keyword (case-insensitive)
     */
    private function tryConsumeKeyword(string $keyword): bool
    {
        if ($this->peekKeyword($keyword)) {
            $this->skipWhitespace();
            for ($i = 0; $i < strlen($keyword); $i++) {
                $this->advance();
            }
            return true;
        }
        return false;
    }

    /**
     * Consume keyword or throw exception
     */
    private function consumeKeyword(string $keyword): void
    {
        if (!$this->tryConsumeKeyword($keyword)) {
            throw new \RuntimeException(
                "Expected keyword '$keyword' at position {$this->pos}"
            );
        }
    }

    /**
     * Check if there's a column prefix ahead (identifier:)
     */
    private function hasColumnPrefix(): bool
    {
        $saved = $this->pos;

        // Consume identifier characters
        while ($this->peek() !== null &&
               (ctype_alnum($this->peek()) || $this->peek() === '_')) {
            $this->advance();
        }

        $hasColon = $this->peek() === ':';
        $this->pos = $saved;

        return $hasColon;
    }

    /**
     * Consume an identifier (for column names)
     */
    private function consumeIdentifier(): string
    {
        $id = '';

        while ($this->peek() !== null &&
               (ctype_alnum($this->peek()) || $this->peek() === '_')) {
            $id .= $this->peek();
            $this->advance();
        }

        if (empty($id)) {
            throw new \RuntimeException("Expected identifier at position {$this->pos}");
        }

        return $id;
    }
}
