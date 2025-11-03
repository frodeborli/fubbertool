<?php

namespace FubberTool\Index;

/**
 * Token normalization for FTS5 indexing
 *
 * Handles tokens that FTS5 would strip (punctuation, operators, symbols)
 * Two strategies:
 * 1. Semantic (recommended): Convert to searchable words
 * 2. Hex (fallback): Encode as hex for preservation
 */
class TokenNormalizer
{
    /**
     * Semantic mappings for common code operators/symbols
     */
    private const SEMANTIC_MAP = [
        // PHP operators
        '->' => 'ARROW',
        '=>' => 'FATARROW',
        '::' => 'DOUBLECOLON',
        '++' => 'INCREMENT',
        '--' => 'DECREMENT',
        '===' => 'STRICTEQUAL',
        '!==' => 'STRICTNOTEQUAL',
        '==' => 'EQUAL',
        '!=' => 'NOTEQUAL',

        // Symbols
        '$' => 'DOLLAR',
        '@' => 'AT',
        '#' => 'HASH',
        '&' => 'AMP',
        '|' => 'PIPE',
        '?' => 'QUESTION',
        '!' => 'EXCLAIM',
        '*' => 'STAR',
        '+' => 'PLUS',

        // Brackets (if needed as tokens)
        '(' => 'LPAREN',
        ')' => 'RPAREN',
        '[' => 'LBRACKET',
        ']' => 'RBRACKET',
        '{' => 'LBRACE',
        '}' => 'RBRACE',

        // Annotations
        '@param' => 'AT_param',
        '@return' => 'AT_return',
        '@var' => 'AT_var',
        '@throws' => 'AT_throws',

        // Common patterns
        '...' => 'SPREAD',
        '??' => 'NULLCOALESCE',
        '?->' => 'NULLSAFE',
    ];

    /**
     * Tokens to completely ignore (noise)
     */
    private const IGNORE_TOKENS = [
        ';', ',', '.', ':', '=',  // Common punctuation
        '', ' ', "\t", "\n", "\r", // Whitespace
    ];

    /**
     * Normalize an array of tokens for FTS5 indexing
     *
     * @param array<string> $tokens Raw tokens from splitting
     * @param bool $useHexFallback Whether to hex-encode unrecognized tokens
     * @return array<string> Normalized tokens
     */
    public static function normalize(array $tokens, bool $useHexFallback = false): array
    {
        $normalized = [];

        foreach ($tokens as $token) {
            // Skip empty/whitespace
            if (trim($token) === '') {
                continue;
            }

            // Skip noise tokens
            if (in_array($token, self::IGNORE_TOKENS, true)) {
                continue;
            }

            // Check semantic map first
            if (isset(self::SEMANTIC_MAP[$token])) {
                $normalized[] = self::SEMANTIC_MAP[$token];
                continue;
            }

            // If it's alphanumeric (normal identifier), keep as-is
            if (preg_match('/^[a-zA-Z0-9_]+$/', $token)) {
                $normalized[] = $token;
                continue;
            }

            // Has non-ASCII or special characters
            if (preg_match('/[^\x00-\x7F]/', $token)) {
                if ($useHexFallback) {
                    $normalized[] = self::hexEncode($token);
                } else {
                    // Keep it, let unicode61 handle it
                    $normalized[] = $token;
                }
                continue;
            }

            // Unknown punctuation/operator
            if ($useHexFallback) {
                $normalized[] = self::hexEncode($token);
            }
            // Otherwise drop it (already filtered noise)
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Hex encode a token for FTS5 preservation
     *
     * @param string $token Token to encode
     * @return string Hex-encoded token (e.g., "x03A9" for Ω)
     */
    public static function hexEncode(string $token): string
    {
        // Convert each byte to hex with 'x' prefix
        $hex = '';
        $len = strlen($token);
        for ($i = 0; $i < $len; $i++) {
            $hex .= 'x' . strtoupper(bin2hex($token[$i]));
        }
        return $hex;
    }

    /**
     * Normalize ASCII token (replace non-ASCII punctuation with hex)
     *
     * Keeps Unicode letters and digits, only encodes problematic punctuation
     *
     * @param string $token Token to normalize
     * @return string Normalized token
     */
    public static function normalizeAsciiToken(string $token): string
    {
        // Match any non-ASCII char that is NOT a Unicode letter or digit
        // This preserves: Greek letters (Ω), Chinese characters, etc.
        // But encodes: unusual punctuation, special symbols
        return preg_replace_callback(
            '/[^\x00-\x7F&&[^\p{L}\p{N}_]]/u',
            fn($m) => 'x' . strtoupper(bin2hex($m[0])),
            $token
        );
    }

    /**
     * Check if a token is significant for code search
     *
     * @param string $token Token to check
     * @return bool True if token should be indexed
     */
    public static function isSignificant(string $token): bool
    {
        // Empty or whitespace
        if (trim($token) === '') {
            return false;
        }

        // Noise punctuation
        if (in_array($token, self::IGNORE_TOKENS, true)) {
            return false;
        }

        // Single character that's not alphanumeric and not in semantic map
        if (strlen($token) === 1 && !ctype_alnum($token) && !isset(self::SEMANTIC_MAP[$token])) {
            return false;
        }

        return true;
    }

    /**
     * Get semantic token for an operator (if exists)
     *
     * @param string $token Operator token
     * @return string|null Semantic name or null
     */
    public static function getSemanticToken(string $token): ?string
    {
        return self::SEMANTIC_MAP[$token] ?? null;
    }

    /**
     * Test normalization with examples
     */
    public static function test(): array
    {
        $testCases = [
            // Operators
            ['input' => ['->', 'method'], 'desc' => 'Arrow operator'],
            ['input' => ['=>', 'value'], 'desc' => 'Fat arrow'],
            ['input' => ['$', 'user', 'Id'], 'desc' => 'Variable with $'],
            ['input' => ['@', 'param'], 'desc' => 'Annotation'],

            // Punctuation
            ['input' => ['get', 'User', ';'], 'desc' => 'With semicolon'],
            ['input' => ['array', '(', ')'], 'desc' => 'With parens'],

            // Unicode
            ['input' => ['Ω', 'constant'], 'desc' => 'Greek letter'],
            ['input' => ['hello', '世界'], 'desc' => 'Chinese characters'],

            // Mixed
            ['input' => ['$', 'userId', '->', 'getData', '(', ')'], 'desc' => 'Full expression'],
        ];

        $results = [];
        foreach ($testCases as $test) {
            $results[] = [
                'description' => $test['desc'],
                'input' => $test['input'],
                'semantic' => self::normalize($test['input'], false),
                'with_hex' => self::normalize($test['input'], true),
            ];
        }

        return $results;
    }
}
