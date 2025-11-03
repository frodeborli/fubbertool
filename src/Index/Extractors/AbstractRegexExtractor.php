<?php

namespace FubberTool\Index\Extractors;

/**
 * Base class for regex-based extractors
 *
 * Provides common functionality for loading and executing regex patterns
 * from the extractors/ directory.
 */
abstract class AbstractRegexExtractor implements ExtractorInterface
{
    /**
     * Load a regex pattern from extractors directory
     *
     * @param string $patternName Pattern filename (without .php extension)
     * @return string Regex pattern
     * @throws \RuntimeException If pattern file not found or invalid
     */
    protected function loadPattern(string $patternName): string
    {
        $patternFile = dirname(__DIR__, 3) . "/extractors/{$patternName}.php";

        if (!file_exists($patternFile)) {
            throw new \RuntimeException("Pattern file not found: {$patternFile}");
        }

        $pattern = require $patternFile;

        if (!is_string($pattern)) {
            throw new \RuntimeException("Pattern file must return a string: {$patternFile}");
        }

        return $pattern;
    }

    /**
     * Execute regex pattern and return matches
     *
     * @param string $pattern Regex pattern
     * @param string $content Content to match against
     * @param string|null $filename Optional filename for error reporting
     * @param string|null $patternName Optional pattern identifier (e.g., 'classPattern', 'functionPattern')
     * @return array Array of matches (PREG_SET_ORDER format)
     * @throws \RuntimeException In dev mode when regex fails
     */
    protected function executePattern(string $pattern, string $content, ?string $filename = null, ?string $patternName = null): array
    {
        $matches = [];
        $result = @preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        // If failed, try to recover before giving up
        if ($result === false) {
            $error = preg_last_error_msg();
            $errorCode = preg_last_error();
            $context = $filename ? " in file: $filename" : "";
            $patternInfo = $patternName ? " (pattern: $patternName)" : "";

            $output = $GLOBALS['fubber_output'] ?? null;
            if ($output) {
                $pcreVersion = PCRE_VERSION ?? 'unknown';
                $output->debug(3, "PCRE error code: {code}, message: {msg}, PCRE version: {version}", [
                    'code' => $errorCode,
                    'msg' => $error,
                    'version' => $pcreVersion
                ]);
            }

            // If JIT stack limit exceeded, try again with JIT completely disabled
            if (str_contains($error, 'JIT stack limit')) {
                if ($output) {
                    $output->debug(2, "JIT stack limit hit, retrying with JIT disabled: {file}", [
                        'file' => $filename ?? 'unknown'
                    ]);
                }

                // Disable JIT globally AND inject (*NO_JIT) into pattern
                $oldJit = ini_get('pcre.jit');
                ini_set('pcre.jit', '0');

                // Insert (*NO_JIT) after the opening delimiter
                $delimiter = $pattern[0];
                $noJitPattern = $delimiter . '(*NO_JIT)' . substr($pattern, 1);

                $matches = []; // Reset matches
                $result = @preg_match_all($noJitPattern, $content, $matches, PREG_SET_ORDER);

                // Restore JIT setting
                ini_set('pcre.jit', $oldJit ?: '1');

                if ($result !== false) {
                    // Success without JIT!
                    if ($output) {
                        $output->debug(2, "Successfully processed with JIT disabled");
                    }
                    return $matches;
                }

                // Still failed even without JIT, update error message
                $error = preg_last_error_msg() . " (even with JIT disabled)";
                $errorCode = preg_last_error();
            }

            // If recursion limit or internal error, try with increased limits
            if ($result === false && (str_contains($error, 'Internal error') || str_contains($error, 'recursion limit') || $errorCode === PREG_RECURSION_LIMIT_ERROR || $errorCode === PREG_INTERNAL_ERROR)) {
                if ($output) {
                    $output->debug(2, "PCRE recursion/internal error, trying with increased limits: {file}", [
                        'file' => $filename ?? 'unknown'
                    ]);
                }

                // Temporarily increase limits
                $oldRecursion = ini_get('pcre.recursion_limit');
                $oldBacktrack = ini_get('pcre.backtrack_limit');
                ini_set('pcre.recursion_limit', '1000000'); // 1M
                ini_set('pcre.backtrack_limit', '50000000'); // 50M

                $matches = [];
                $delimiter = $pattern[0];
                $noJitPattern = $delimiter . '(*NO_JIT)' . substr($pattern, 1);
                $result = @preg_match_all($noJitPattern, $content, $matches, PREG_SET_ORDER);

                // Restore limits
                ini_set('pcre.recursion_limit', $oldRecursion ?: '500000');
                ini_set('pcre.backtrack_limit', $oldBacktrack ?: '10000000');

                if ($result !== false) {
                    if ($output) {
                        $output->debug(2, "Successfully processed with increased recursion limits");
                    }
                    return $matches;
                }

                $error = preg_last_error_msg() . " (even with increased limits)";
            }

            // All recovery attempts failed - now handle the error
            // In dev mode, throw exception with detailed info to help debug
            if (getenv('FUBBER_DEV')) {
                throw new \RuntimeException(
                    "Regex error in " . $this->getName() . ": $error$context$patternInfo\n" .
                    "Content length: " . strlen($content) . " bytes\n" .
                    "Pattern length: " . strlen($pattern) . " bytes\n" .
                    "First 200 chars of content:\n" . substr($content, 0, 200) . "\n\n" .
                    "Full pattern:\n" . $pattern . "\n\n" .
                    "To skip this error in production, unset FUBBER_DEV environment variable"
                );
            }

            // Production mode: warn and continue
            $output = $GLOBALS['fubber_output'] ?? null;
            if ($output) {
                $output->warn("Regex error in " . $this->getName() . ": $error$context$patternInfo");
            } else {
                fwrite(STDERR, "Warning: Regex error in " . $this->getName() . ": $error$context$patternInfo\n");
            }
            error_log("Regex error in " . $this->getName() . ": $error$context$patternInfo");
            return [];
        }

        return $matches;
    }

    /**
     * Calculate line numbers from content offset
     *
     * @param string $content Full content
     * @param int $startOffset Character offset of start
     * @param int $endOffset Character offset of end
     * @return array{start: int, end: int} Line numbers (1-indexed)
     */
    protected function getLineNumbers(string $content, int $startOffset, int $endOffset): array
    {
        return [
            'start' => substr_count(substr($content, 0, $startOffset), "\n") + 1,
            'end' => substr_count(substr($content, 0, $endOffset), "\n") + 1,
        ];
    }

    /**
     * Default priority for regex extractors
     *
     * @return int
     */
    public function getPriority(): int
    {
        return 80; // Higher than simple extractors (50)
    }
}
