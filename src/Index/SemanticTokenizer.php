<?php

namespace FubberTool\Index;

/**
 * Semantic tokenization for code identifiers
 *
 * Splits identifiers into meaningful tokens for search:
 * - camelCase: getUserById → get User By Id
 * - snake_case: get_user_id → get user id
 * - SCREAMING_CASE: MAX_CONNECTIONS → MAX CONNECTIONS
 * - Preserves original for exact matching
 */
class SemanticTokenizer
{
    /**
     * Tokenize an identifier into searchable parts
     *
     * @param string $identifier The identifier to tokenize
     * @return array{original: string, tokens: array<string>, searchable: string}
     */
    public static function tokenize(string $identifier): array
    {
        // Remove leading/trailing non-alphanumeric
        $identifier = trim($identifier);

        // Split on:
        // - Whitespace
        // - Word boundaries
        // - camelCase transitions (lowercase to uppercase)
        // - Before/after underscores
        // - Before non-word characters
        $pattern = '/\s++|\b|(?<=\p{Ll})(?=\p{Lu})|(?<=_)(?=\w)|(?<=\w)(?=_)|(?=\W)/u';

        $parts = preg_split($pattern, $identifier, -1, PREG_SPLIT_NO_EMPTY);

        // Clean up and deduplicate
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && !in_array($part, ['_', '-', '.', '\\', '/'])) {
                $tokens[] = $part;
            }
        }

        // Remove duplicates while preserving order
        $tokens = array_values(array_unique($tokens));

        // Create searchable string: original + tokens + lowercase versions
        $searchableParts = [$identifier];
        foreach ($tokens as $token) {
            $searchableParts[] = $token;
            // Add lowercase version for case-insensitive search
            $lower = mb_strtolower($token);
            if ($lower !== $token) {
                $searchableParts[] = $lower;
            }
        }

        // Add lowercase of original
        $lowerOriginal = mb_strtolower($identifier);
        if ($lowerOriginal !== $identifier) {
            $searchableParts[] = $lowerOriginal;
        }

        $searchableParts = array_unique($searchableParts);

        return [
            'original' => $identifier,
            'tokens' => $tokens,
            'searchable' => implode(' ', $searchableParts),
        ];
    }

    /**
     * Tokenize a signature or code snippet
     *
     * Extracts and tokenizes all identifiers from code
     *
     * @param string $code Code snippet to tokenize
     * @return string Searchable token string
     */
    public static function tokenizeCode(string $code): string
    {
        // Find all identifier-like sequences
        preg_match_all('/[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*+/u', $code, $matches);

        $allTokens = [];
        foreach ($matches[0] as $identifier) {
            $result = self::tokenize($identifier);
            $allTokens = array_merge($allTokens, [$result['original']], $result['tokens']);
        }

        // Deduplicate
        $allTokens = array_unique($allTokens);

        // Add lowercase versions
        $withLowercase = [];
        foreach ($allTokens as $token) {
            $withLowercase[] = $token;
            $lower = mb_strtolower($token);
            if ($lower !== $token) {
                $withLowercase[] = $lower;
            }
        }

        return implode(' ', array_unique($withLowercase));
    }

    /**
     * Test tokenization with examples
     *
     * @return array Test results
     */
    public static function test(): array
    {
        $tests = [
            'getUserById',
            'get_user_by_id',
            'MAX_CONNECTIONS',
            'App\Models\User',
            '__construct',
            'getElementById',
            'fetchData',
            'UserController',
            'parseHTMLString',
            '$userId',
            'onMouseClick',
        ];

        $results = [];
        foreach ($tests as $test) {
            $results[$test] = self::tokenize($test);
        }

        return $results;
    }
}
