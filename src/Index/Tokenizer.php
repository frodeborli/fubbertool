<?php

namespace FubberTool\Index;

/**
 * Code tokenization for FTS5 indexing
 *
 * Strategy:
 * 1. Split on word boundaries, camelCase, snake_case, punctuation
 * 2. Hex-encode non-word characters with delimiters (_hexvalue_)
 * 3. Apply SAME tokenization to search queries
 *
 * This ensures index/search symmetry.
 */
class Tokenizer
{
    /**
     * Tokenize a document for indexing
     *
     * @param string $document Code to tokenize
     * @param string|null $filename Optional filename for error context
     * @return string Space-separated tokens for FTS5
     */
    public static function tokenize(string $document, ?string $filename = null): string
    {
        return implode(' ', self::tokenizeToArray($document, $filename));
    }

    /**
     * Decode tokenized text back to readable format
     * Reverses the hex encoding: TxxK -> character
     *
     * @param string $tokenized Tokenized text with TxxK patterns
     * @param string|null $markerStart Optional highlight marker start (e.g., 'BeF1234')
     * @param string|null $markerEnd Optional highlight marker end (e.g., 'AfT1234')
     * @return string Decoded readable text
     */
    public static function detokenize(string $tokenized, ?string $markerStart = null, ?string $markerEnd = null): string
    {
        // Keep BEFORE/AFTER markers visible (don't remove them)
        $decoded = $tokenized;

        // Build pattern to handle optional markers around TxxK tokens
        // This handles snippet() wrapping individual tokens like: BeF...T3aKAfT... BeF...T3aKAfT...
        if ($markerStart !== null && $markerEnd !== null) {
            $pre = preg_quote($markerStart, '/');
            $post = preg_quote($markerEnd, '/');

            // Decode TxxK while preserving surrounding markers and consuming spaces
            // Pattern: [space] [markerStart] TxxK [markerEnd] [space]
            // Result: [markerStart] decoded_char [markerEnd] (no spaces)
            $decoded = preg_replace_callback(
                '/ ?(' . $pre . ')?T([0-9a-f]+)K(' . $post . ')? ?/i',
                fn($m) => ($m[1] ?? '') . hex2bin($m[2]) . ($m[3] ?? ''),
                $decoded
            );

            // Collapse marker pairs between consecutive tokens: AfT...BeF... → nothing
            $decoded = preg_replace('/' . $post . $pre . '/i', '', $decoded);
        } else {
            // No markers - just decode TxxK patterns and consume surrounding spaces
            $decoded = preg_replace_callback('/ ?T([0-9a-f]+)K ?/i', fn($m) => hex2bin($m[1]), $decoded);
        }

        // Remove spaces around non-word characters
        $decoded = preg_replace(['/\s+([^\w\s])\s+/', '/\s+([^\w\s])/', '/([^\w\s])\s+/'], ['$1', '$1', '$1'], $decoded);

        // Remove artificial spaces from camelCase splitting: lowercase + space + Uppercase+lowercase
        // e.g., "get User By Id" → "getUserById"
        // Use Unicode character classes to handle non-ASCII: \p{Ll}=lowercase, \p{Lu}=uppercase
        // Apply repeatedly until no more matches (handles consecutive camelCase words)
        while (preg_match('/(\p{Ll}) (\p{Lu}\p{Ll})/u', $decoded)) {
            $decoded = preg_replace('/(\p{Ll}) (\p{Lu}\p{Ll})/u', '$1$2', $decoded);
        }

        return $decoded;
    }

    /**
     * Base tokenization - splits and hex-encodes non-word characters
     *
     * @param string $document Code to tokenize
     * @param string|null $filename Optional filename for error context
     * @return array<string> Array of tokens
     */
    private static function tokenizeToArray(string $document, ?string $filename = null): array
    {
        $normalize = function(string $s) {
            // Normalize double quotes to single quotes before hex encoding
            // This makes " and ' searchable as the same token and avoids FTS5 syntax conflicts
            $s = str_replace('"', "'", $s);

            return preg_replace_callback(
                // Match any character before a non-word character
                '/(?=\W)./u',
                fn($m) => 'T' . bin2hex($m[0]) . 'K',
                $s
            );
        };

        // Split on:
        // - \s++ : whitespace (possessive)
        // - \b : word boundaries
        // - (?<=\p{Ll})(?=\p{Lu}) : camelCase (lowercase to uppercase)
        // - (?<=_)(?=\w) : after underscore
        // - (?<=\w)(?=_) : before underscore
        // - (?=\W) : before non-word character
        $parts = @preg_split('/\s++|\b|(?<=\p{Ll})(?=\p{Lu})|(?<=_)(?=\w)|(?<=\w)(?=_)|(?=\W)/xum', $document);

        // Handle regex errors (e.g., JIT stack limit, malformed UTF-8)
        if ($parts === false) {
            $error = preg_last_error_msg();
            $fileContext = $filename ? " in file: $filename" : "";

            // If JIT stack limit exceeded, try again with JIT completely disabled
            if (str_contains($error, 'JIT stack limit')) {
                $output = $GLOBALS['fubber_output'] ?? null;
                if ($output) {
                    $output->debug(2, "Tokenizer JIT stack limit hit, retrying with JIT disabled: {file}", [
                        'file' => $filename ?? 'unknown'
                    ]);
                }

                // Disable JIT globally AND inject (*NO_JIT) into pattern
                $oldJit = ini_get('pcre.jit');
                ini_set('pcre.jit', '0');

                // Use (*NO_JIT) directive in pattern (already has delimiter)
                $parts = @preg_split('/(*NO_JIT)\s++|\b|(?<=\p{Ll})(?=\p{Lu})|(?<=_)(?=\w)|(?<=\w)(?=_)|(?=\W)/xum', $document);

                // Restore JIT setting
                ini_set('pcre.jit', $oldJit ?: '1');

                if ($parts !== false) {
                    // Success without JIT!
                    if ($output) {
                        $output->debug(2, "Tokenizer successfully processed with JIT disabled");
                    }
                    // Continue with normal processing
                } else {
                    $error = preg_last_error_msg(); // Get new error
                }
            }

            // If it's a UTF-8 error, try to fix the encoding and retry
            if ($parts === false && (str_contains($error, 'UTF-8') || str_contains($error, 'Malformed'))) {
                $convertedDoc = self::convertToUtf8($document, $filename);
                if ($convertedDoc !== null) {
                    // Retry tokenization with converted document
                    $parts = @preg_split('/\s++|\b|(?<=\p{Ll})(?=\p{Lu})|(?<=_)(?=\w)|(?<=\w)(?=_)|(?=\W)/xum', $convertedDoc);
                    if ($parts !== false) {
                        // Success after conversion!
                        $document = $convertedDoc;
                    }
                }
            }

            // If still failed, handle the error
            if ($parts === false) {
                // In dev mode, throw exception to help debug issues
                if (getenv('FUBBER_DEV')) {
                    throw new \RuntimeException(
                        "Tokenizer regex failed: $error$fileContext\n" .
                        "Document length: " . strlen($document) . " bytes\n" .
                        "First 100 chars: " . substr($document, 0, 100)
                    );
                }

                // Use global Output if available
                $output = $GLOBALS['fubber_output'] ?? null;
                if ($output) {
                    $output->warn("Tokenizer regex failed: $error$fileContext (falling back to simple splitting)");
                } else {
                    fwrite(STDERR, "Warning: Tokenizer regex failed: $error$fileContext (falling back to simple splitting)\n");
                }

                // Fall back to simple whitespace splitting
                $parts = preg_split('/\s+/', $document);
                if ($parts === false) {
                    // Last resort: return empty array
                    return [];
                }
            }
        }

        $result = [];
        foreach ($parts as $part) {
            if ($part !== '' && $part !== '_') {
                $result[] = $normalize($part);
            }
        }

        return $result;
    }

    /**
     * Prepare a search query (applies same tokenization as indexing)
     *
     * CRITICAL: Must use same tokenization as indexed data
     *
     * Preserves FTS5 syntax:
     * - AND, OR, NOT operators
     * - Phrases: "quoted phrases"
     * - Prefix search: term*
     * - Column filters: column:term
     * - Start of column: ^term
     * - Parentheses (nested)
     * - Concatenation: term + term
     *
     * @param string $query User's search query with FTS5 operators
     * @return string Tokenized query for FTS5 MATCH
     */
    public static function prepareQuery(string $query): string
    {
        // Split on: whitespace, FTS5 operators, quotes
        // Captures: escaped chars, operators individually, or sequences of other chars
        preg_match_all('/\s+|[()"+^:]|(\\\\[\\\\()"+^:]|[^\s()"+:])+/uxs', $query, $matches);
        $parts = [];

        foreach ($matches[0] as $word) {
            if (trim($word) === '') {
                $parts[] = ' ';
            } elseif ($word === 'AND' || $word === 'OR' || $word === 'NOT' || $word === 'NEAR') {
                $parts[] = $word;
            } else {
                // Handle escaped characters
                $parts[] = preg_replace_callback('/\\\\./us', function($m) {
                    if (preg_match('/\\\\[\\\\()"+^:]/us', $m[0])) {
                        return substr($m[0], 1);
                    }
                    return $m[0];
                }, $word);
            }
        }

        $res = [];
        $inQuotes = false;
        $quoteBuffer = [];
        $previousWasWhitespace = true; // Start as true so we don't add '+' before first token

        foreach ($parts as $part) {
            // Handle quote delimiter
            if ($part === '"') {
                if ($inQuotes) {
                    // Closing quote - tokenize accumulated buffer and wrap in quotes
                    $content = implode('', $quoteBuffer);
                    $t = self::tokenizeToArray($content, null); // No filename context for query tokenization
                    $res[] = '"' . implode(' + ', $t) . '"';
                    $quoteBuffer = [];
                    $inQuotes = false;
                } else {
                    // Opening quote - just set the flag, don't add to result
                    $inQuotes = true;
                }
                continue;
            }

            // Inside quotes - accumulate everything (including spaces and operators)
            if ($inQuotes) {
                $quoteBuffer[] = $part;
                continue;
            }

            // Outside quotes - handle FTS5 operators
            // Special handling for ':' - only treat as operator if it follows a valid column name
            if ($part === ':') {
                $isColumnFilter = false;
                if (!empty($res)) {
                    // Get last non-whitespace part
                    $lastPart = '';
                    for ($i = count($res) - 1; $i >= 0; $i--) {
                        if (trim($res[$i]) !== '') {
                            $lastPart = trim($res[$i]);
                            break;
                        }
                    }
                    $validColumns = ['preamble', 'signature', 'body', 'namespace', 'ext', 'path'];
                    $isColumnFilter = in_array(strtolower($lastPart), $validColumns, true);
                }

                if ($isColumnFilter) {
                    // This is a column filter - pass through as FTS5 operator
                    $res[] = $part;
                    // Treat ':' like whitespace - next token should NOT have '+' prefix
                    $previousWasWhitespace = true;
                    continue; // Skip to next part
                }
                // Otherwise, fall through to tokenize it like regular code
            } elseif ($part === '^' || $part === '(' || $part === ')' ||
                      $part === '+' || $part === 'AND' || $part === 'OR' ||
                      $part === 'NOT' || $part === 'NEAR') {
                $res[] = $part;
                $previousWasWhitespace = false;
                continue;
            } elseif (trim($part) === '') {
                $res[] = ' ';
                $previousWasWhitespace = true;
                continue;
            }

            // Tokenize search term using same logic as indexing
            // This handles both regular words and ':' (when not a column filter)
            $t = self::tokenizeToArray($part, null);

            // Add '+' before first token only if there was no whitespace before this part
            for ($i = 0; $i < count($t); $i++) {
                if ($i > 0) {
                    // Always add '+' between tokens within the same part
                    $res[] = ' + ';
                } elseif (!$previousWasWhitespace) {
                    // Add '+' before first token only if no whitespace before this part
                    $res[] = ' + ';
                }
                $res[] = $t[$i];
            }

            $previousWasWhitespace = false;
        }

        return implode('', $res);
    }

    /**
     * Attempt to convert document to UTF-8 from various encodings
     * Only called when we already know UTF-8 parsing failed
     *
     * @param string $document Original document (known to be non-UTF-8)
     * @param string|null $filename Filename for logging
     * @return string|null Converted document or null if conversion failed
     */
    private static function convertToUtf8(string $document, ?string $filename = null): ?string
    {
        // Access global Output instance if available
        $output = $GLOBALS['fubber_output'] ?? null;

        // Try to detect encoding (excluding UTF-8 since we already know it's not valid UTF-8)
        $detectedEncoding = mb_detect_encoding($document, ['ISO-8859-1', 'Windows-1252', 'ASCII'], true);

        if ($detectedEncoding) {
            $converted = @mb_convert_encoding($document, 'UTF-8', $detectedEncoding);
            if ($converted !== false && $converted !== '') {
                if ($output) {
                    $output->debug(1, "Converted encoding from {from} to UTF-8: {file}", [
                        'from' => $detectedEncoding,
                        'file' => $filename ?? 'unknown'
                    ]);
                }
                return $converted;
            }
        }

        // Fallback: Try common encodings explicitly
        $encodings = ['ISO-8859-1', 'Windows-1252', 'ISO-8859-15', 'CP1252'];

        foreach ($encodings as $encoding) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $document);
            if ($converted !== false && $converted !== '') {
                if ($output) {
                    $output->debug(1, "Converted encoding from {from} to UTF-8 (fallback): {file}", [
                        'from' => $encoding,
                        'file' => $filename ?? 'unknown'
                    ]);
                }
                return $converted;
            }
        }

        // Last resort: Strip invalid UTF-8 bytes by re-encoding
        $converted = @mb_convert_encoding($document, 'UTF-8', 'UTF-8');
        if ($converted !== false && $converted !== '') {
            if ($output) {
                $output->debug(1, "Cleaned invalid UTF-8 bytes: {file}", [
                    'file' => $filename ?? 'unknown'
                ]);
            }
            return $converted;
        }

        // Always show failures (even without Output instance)
        if ($output) {
            $output->warn("Failed to convert encoding to UTF-8: " . ($filename ?? 'unknown'));
        } else {
            fwrite(STDERR, "Warning: Failed to convert encoding to UTF-8: " . ($filename ?? 'unknown') . "\n");
        }
        return null;
    }
}
