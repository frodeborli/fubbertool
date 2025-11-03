<?php

namespace FubberTool\Index;

/**
 * Complete code tokenization pipeline
 *
 * Combines:
 * 1. SemanticTokenizer - Splits camelCase/snake_case identifiers
 * 2. TokenNormalizer - Maps operators to searchable words
 */
class CodeTokenizer
{
    /**
     * Tokenize code identifier for FTS5 indexing
     *
     * @param string $identifier Code identifier (e.g., "getUserById", "$userId")
     * @return string Searchable token string
     */
    public static function tokenizeIdentifier(string $identifier): string
    {
        // Step 1: Split identifier using semantic tokenizer
        $semantic = SemanticTokenizer::tokenize($identifier);
        $tokens = $semantic['tokens'];

        // Add original
        array_unshift($tokens, $semantic['original']);

        // Step 2: Normalize each token (handle operators/symbols)
        $normalized = TokenNormalizer::normalize($tokens, false);

        // Step 3: Add lowercase versions for case-insensitive search
        $withLowercase = [];
        foreach ($normalized as $token) {
            $withLowercase[] = $token;
            $lower = mb_strtolower($token);
            if ($lower !== $token && !in_array($lower, ['dollar', 'arrow', 'at'])) {
                // Don't lowercase semantic operator names
                $withLowercase[] = $lower;
            }
        }

        return implode(' ', array_unique($withLowercase));
    }

    /**
     * Tokenize entire code snippet
     *
     * @param string $code Code snippet (signature, expression, etc.)
     * @return string Searchable token string
     */
    public static function tokenizeCode(string $code): string
    {
        // Extract all identifier-like sequences
        preg_match_all('/[a-zA-Z_$@#][a-zA-Z0-9_]*+|->|=>|::|[^\s]+/u', $code, $matches);

        $allTokens = [];
        foreach ($matches[0] as $identifier) {
            $result = self::tokenizeIdentifier($identifier);
            $allTokens[] = $result;
        }

        return implode(' ', array_unique(explode(' ', implode(' ', $allTokens))));
    }

    /**
     * Process entity for indexing
     *
     * Returns both original and searchable versions
     *
     * @param array $entity Entity data from extractor
     * @return array Enhanced entity with searchable fields
     */
    public static function processEntity(array $entity): array
    {
        // Tokenize entity name
        $nameTokens = self::tokenizeIdentifier($entity['entity_name']);

        // Tokenize signature (if present and meaningful)
        $signatureTokens = '';
        if (!empty($entity['signature'])) {
            $signatureTokens = self::tokenizeCode($entity['signature']);
        }

        // Add searchable fields
        $entity['entity_name_searchable'] = $nameTokens;
        $entity['signature_searchable'] = $signatureTokens;

        return $entity;
    }

    /**
     * Prepare search query using same tokenization as indexed data
     *
     * CRITICAL: Must match the tokenization used during indexing
     *
     * @param string $query User's search query
     * @return string Tokenized query for FTS5 MATCH
     */
    public static function prepareSearchQuery(string $query): string
    {
        // If query contains FTS5 operators (* ? ""), preserve them
        if (preg_match('/[*?"()]/', $query)) {
            // Has FTS5 syntax - tokenize parts carefully
            return $query; // For now, pass through - user knows what they're doing
        }

        // Tokenize the query the same way we tokenize indexed data
        return self::tokenizeCode($query);
    }

    /**
     * Test tokenization pipeline
     */
    public static function test(): array
    {
        $testCases = [
            '$userId' => 'PHP variable',
            'getUserById' => 'camelCase method',
            'get_user_by_id' => 'snake_case method',
            '@param string $name' => 'Annotation',
            'public function test(): void' => 'Function signature',
            '$obj->method()' => 'Method call',
            'User::findById($id)' => 'Static call',
            '$arr => $value' => 'Arrow function param',
        ];

        $results = [];
        foreach ($testCases as $input => $description) {
            $results[] = [
                'input' => $input,
                'description' => $description,
                'tokenized' => self::tokenizeCode($input),
            ];
        }

        return $results;
    }
}
