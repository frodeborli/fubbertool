<?php

namespace FubberTool\Index\Extractors;

/**
 * Extractor for executable scripts without file extensions
 *
 * Indexes any executable file with a shebang as a simple file entry.
 * Handles files like bin/fubber, bin/fubber-php, etc.
 */
class ExecutableScriptExtractor implements ExtractorInterface
{
    public function getSupportedExtensions(): array
    {
        // Special marker for extensionless files
        return [''];
    }

    public function getName(): string
    {
        return 'Executable Script Extractor';
    }

    public function getPriority(): int
    {
        return 90; // Higher than regular extractors
    }

    public function extract(string $filename, string $content): array
    {
        // Check if file is executable
        if (!is_executable($filename)) {
            return [];
        }

        // Check for binary content (quick check for null bytes)
        if (strpos(substr($content, 0, 8192), "\0") !== false) {
            return [];
        }

        // Check for shebang
        if (!str_starts_with($content, '#!')) {
            return [];
        }

        // Detect language from shebang for metadata
        $language = $this->detectLanguageFromShebang($content);

        // Return simple file entry
        $lines = substr_count($content, "\n") + 1;

        return [[
            'entity_type' => 'script',
            'entity_name' => basename($filename),
            'namespace' => '',
            'class_name' => '',
            'signature' => $this->getShebangLine($content),
            'docblock' => '',
            'body' => $content,
            'line_start' => 1,
            'line_end' => $lines,
            'language' => $language ?? 'script',
            'visibility' => '',
        ]];
    }

    /**
     * Get the shebang line as signature
     *
     * @param string $content File content
     * @return string Shebang line
     */
    private function getShebangLine(string $content): string
    {
        $firstLine = strtok($content, "\n");
        return $firstLine !== false ? $firstLine : '';
    }

    /**
     * Detect programming language from shebang line
     *
     * @param string $content File content
     * @return string|null Language name (php, python, bash, etc.) or null if not detected
     */
    private function detectLanguageFromShebang(string $content): ?string
    {
        $firstLine = strtok($content, "\n");
        if ($firstLine === false || !str_starts_with($firstLine, '#!')) {
            return null;
        }

        // Check for common interpreters
        if (preg_match('/php/', $firstLine)) {
            return 'php';
        }
        if (preg_match('/python[0-9]*/', $firstLine)) {
            return 'python';
        }
        if (preg_match('/node|nodejs/', $firstLine)) {
            return 'javascript';
        }
        if (preg_match('/(bash|sh|zsh)$/', $firstLine)) {
            return 'bash';
        }
        if (preg_match('/ruby/', $firstLine)) {
            return 'ruby';
        }
        if (preg_match('/perl/', $firstLine)) {
            return 'perl';
        }

        return null;
    }
}
