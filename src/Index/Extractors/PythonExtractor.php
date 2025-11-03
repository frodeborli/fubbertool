<?php

namespace FubberTool\Index\Extractors;

/**
 * Python extractor using simple line-by-line parsing with indentation awareness
 *
 * Strategy:
 * - Parse line by line, tracking indentation levels
 * - When we see "def " or "class ", everything with greater indentation is the body
 * - Preamble is all lines before the definition (decorators, comments)
 */
class PythonExtractor implements ExtractorInterface
{
    public function getSupportedExtensions(): array
    {
        return ['py'];
    }

    public function getName(): string
    {
        return 'Python Extractor';
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function extract(string $filename, string $content): array
    {
        $lines = explode("\n", $content);
        $entities = [];

        // Extract whole file as module
        $entities[] = [
            'entity_type' => 'file',
            'entity_name' => basename($filename),
            'namespace' => '',
            'class_name' => '',
            'signature' => '',
            'docblock' => $this->extractFileDocstring($lines),
            'body' => $content,
            'line_start' => 1,
            'line_end' => count($lines),
            'language' => 'python',
            'visibility' => '',
        ];

        // Parse line by line
        $i = 0;
        while ($i < count($lines)) {
            $line = $lines[$i];
            $trimmed = ltrim($line);
            $indent = strlen($line) - strlen($trimmed);

            // Skip empty lines and comments
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $i++;
                continue;
            }

            // Class definition
            if (preg_match('/^class\s+(\w+)/', $trimmed)) {
                $classEntities = $this->extractClass($lines, $i);
                foreach ($classEntities as $entity) {
                    $entities[] = $entity;
                }
                // Skip to end of class
                $i = $classEntities[count($classEntities) - 1]['_next_line'];
                continue;
            }

            // Top-level function (not indented)
            if ($indent === 0 && preg_match('/^(async\s+)?def\s+(\w+)/', $trimmed)) {
                $funcEntity = $this->extractFunction($lines, $i, '');
                $entities[] = $funcEntity;
                $i = $funcEntity['_next_line'];
                continue;
            }

            $i++;
        }

        // Clean up internal tracking fields
        foreach ($entities as &$entity) {
            unset($entity['_next_line']);
        }

        return $entities;
    }

    /**
     * Extract file-level docstring (first thing in file if it's a triple-quoted string)
     */
    private function extractFileDocstring(array $lines): string
    {
        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip empty lines and comments
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // Check if first non-empty line is a docstring
            if (preg_match('/^("""|\'\'\')/s', $trimmed)) {
                return $this->extractDocstringAt($lines, array_search($line, $lines));
            }

            // If we hit anything else, no file docstring
            break;
        }

        return '';
    }

    /**
     * Extract a class and all its methods
     * Returns array of entities (class + methods)
     */
    private function extractClass(array $lines, int $startLine): array
    {
        $line = $lines[$startLine];
        $trimmed = ltrim($line);
        $baseIndent = strlen($line) - strlen($trimmed);

        // Parse class signature
        if (!preg_match('/^class\s+(\w+)/', $trimmed, $match)) {
            return [];
        }

        $className = $match[1];
        $signature = rtrim($trimmed, ':');

        // Find preamble (decorators, comments before class)
        $preamble = $this->extractPreamble($lines, $startLine);

        // Find docstring (first line after class definition if it's triple-quoted)
        $docstring = $this->extractDocstringAt($lines, $startLine + 1);

        // Find end of class (next line with same or less indentation)
        $endLine = $this->findBlockEnd($lines, $startLine, $baseIndent);

        // Extract class body
        $body = [];
        for ($i = $startLine; $i <= $endLine; $i++) {
            $body[] = $lines[$i];
        }

        $classEntity = [
            'entity_type' => 'class',
            'entity_name' => $className,
            'namespace' => '',
            'class_name' => '',
            'signature' => $signature,
            'docblock' => $preamble . ($docstring ? "\n" . $docstring : ''),
            'body' => implode("\n", $body),
            'line_start' => $startLine + 1,
            'line_end' => $endLine + 1,
            'language' => 'python',
            'visibility' => '',
            '_next_line' => $endLine + 1,
        ];

        $entities = [$classEntity];

        // Extract methods within the class
        $i = $startLine + 1;
        while ($i <= $endLine) {
            $line = $lines[$i];
            $trimmed = ltrim($line);
            $indent = strlen($line) - strlen($trimmed);

            // Skip empty and comments
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $i++;
                continue;
            }

            // Method definition (must be indented more than class)
            if ($indent > $baseIndent && preg_match('/^(async\s+)?def\s+(\w+)/', $trimmed)) {
                $method = $this->extractFunction($lines, $i, $className);
                $entities[] = $method;
                $i = $method['_next_line'];
                continue;
            }

            $i++;
        }

        return $entities;
    }

    /**
     * Extract a function or method
     */
    private function extractFunction(array $lines, int $startLine, string $className): array
    {
        $line = $lines[$startLine];
        $trimmed = ltrim($line);
        $baseIndent = strlen($line) - strlen($trimmed);

        // Parse function signature
        if (!preg_match('/^(async\s+)?def\s+(\w+)/', $trimmed, $match)) {
            return [];
        }

        $functionName = $match[2];
        $signature = rtrim($trimmed, ':');

        // Determine visibility from naming convention
        $visibility = 'public';
        if (str_starts_with($functionName, '__') && !str_ends_with($functionName, '__')) {
            $visibility = 'private';
        } elseif (str_starts_with($functionName, '_')) {
            $visibility = 'protected';
        }

        // Find preamble
        $preamble = $this->extractPreamble($lines, $startLine);

        // Find docstring
        $docstring = $this->extractDocstringAt($lines, $startLine + 1);

        // Find end of function
        $endLine = $this->findBlockEnd($lines, $startLine, $baseIndent);

        // Extract body
        $body = [];
        for ($i = $startLine; $i <= $endLine; $i++) {
            $body[] = $lines[$i];
        }

        return [
            'entity_type' => $className ? 'method' : 'function',
            'entity_name' => $functionName,
            'namespace' => '',
            'class_name' => $className,
            'signature' => $signature,
            'docblock' => $preamble . ($docstring ? "\n" . $docstring : ''),
            'body' => implode("\n", $body),
            'line_start' => $startLine + 1,
            'line_end' => $endLine + 1,
            'language' => 'python',
            'visibility' => $visibility,
            '_next_line' => $endLine + 1,
        ];
    }

    /**
     * Extract preamble (decorators and comments before a definition)
     */
    private function extractPreamble(array $lines, int $defLine): string
    {
        $preamble = [];
        $i = $defLine - 1;

        // Walk backwards collecting decorators and comments
        while ($i >= 0) {
            $line = $lines[$i];
            $trimmed = ltrim($line);

            // Empty line stops preamble
            if ($trimmed === '') {
                break;
            }

            // Collect decorators (@) and comments (#)
            if (str_starts_with($trimmed, '@') || str_starts_with($trimmed, '#')) {
                array_unshift($preamble, $line);
                $i--;
                continue;
            }

            // Anything else stops preamble
            break;
        }

        return implode("\n", $preamble);
    }

    /**
     * Extract docstring at a specific line (if present)
     */
    private function extractDocstringAt(array $lines, int $lineIndex): string
    {
        if ($lineIndex >= count($lines)) {
            return '';
        }

        $line = ltrim($lines[$lineIndex]);

        // Must start with triple quotes
        if (!preg_match('/^("""|\'\'\')/s', $line, $match)) {
            return '';
        }

        $quote = $match[1];
        $docLines = [];

        // Check if single-line docstring (starts and ends on same line)
        $afterQuote = substr($line, 3);
        if (str_contains($afterQuote, $quote)) {
            // Single line: """content"""
            $content = substr($afterQuote, 0, strpos($afterQuote, $quote));
            return trim($content);
        }

        // Multi-line docstring
        $i = $lineIndex;
        $foundClosing = false;

        while ($i < count($lines)) {
            $currentLine = $lines[$i];

            // First line: skip opening quotes
            if ($i === $lineIndex) {
                $docLines[] = substr($currentLine, strpos($currentLine, $quote) + 3);
            } else {
                // Check if this line has closing quotes
                if (str_contains($currentLine, $quote)) {
                    // Add content before closing quotes
                    $docLines[] = substr($currentLine, 0, strpos($currentLine, $quote));
                    $foundClosing = true;
                    break;
                } else {
                    $docLines[] = $currentLine;
                }
            }

            $i++;

            // Safety: don't scan more than 100 lines for docstring
            if ($i - $lineIndex > 100) {
                break;
            }
        }

        return trim(implode("\n", $docLines));
    }

    /**
     * Find end of indented block
     * Returns the last line that belongs to this block
     */
    private function findBlockEnd(array $lines, int $startLine, int $baseIndent): int
    {
        $i = $startLine + 1;

        while ($i < count($lines)) {
            $line = $lines[$i];
            $trimmed = ltrim($line);

            // Empty lines and comments don't affect indentation tracking
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $i++;
                continue;
            }

            $indent = strlen($line) - strlen($trimmed);

            // If we've dedented to base level or less, we've found the end
            if ($indent <= $baseIndent) {
                return $i - 1;
            }

            $i++;
        }

        // Reached end of file
        return count($lines) - 1;
    }
}
