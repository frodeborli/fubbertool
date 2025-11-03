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
     * Reverses the hex encoding: _XX_ -> character
     *
     * @param string $tokenized Tokenized text with _XX_ patterns
     * @return string Decoded readable text
     */
    public static function detokenize(string $tokenized): string
    {
        // Keep BEFORE/AFTER markers visible (don't remove them)
        $decoded = $tokenized;

        // Decode TxxK patterns
        $decoded = preg_replace_callback('/T([0-9a-f]{2})K/i', fn($m) => hex2bin($m[1]), $decoded);

        // Remove spaces around non-word characters
        return preg_replace(['/\s+([^\w\s])\s+/', '/\s+([^\w\s])/', '/([^\w\s])\s+/'], ['$1', '$1', '$1'], $decoded);
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
            if ($part === '^' || $part === ':' || $part === '(' || $part === ')' ||
                $part === '+' || $part === 'AND' || $part === 'OR' || $part === 'NOT' ||
                $part === 'NEAR') {
                $res[] = $part;
            } elseif (trim($part) === '') {
                $res[] = ' ';
            } else {
                // Tokenize search term using same logic as indexing
                $t = self::tokenizeToArray($part, null); // No filename context for query tokenization
                for ($i = 0; $i < count($t); $i++) {
                    if ($i > 0) {
                        $res[] = ' + ';  // Concatenation operator (adjacency)
                    }
                    $res[] = $t[$i];
                }
            }
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
