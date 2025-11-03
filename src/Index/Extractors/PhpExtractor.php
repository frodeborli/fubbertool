<?php

namespace FubberTool\Index\Extractors;

/**
 * PHP extractor using advanced recursive regex patterns
 *
 * Uses sophisticated DEFINE-based patterns for accurate parsing:
 * - PhpNamespaces.php: Extract namespace declarations
 * - PhpClasses.php: Extract classes, interfaces, traits, enums
 * - PhpFunctions.php: Extract functions and methods
 */
class PhpExtractor extends AbstractRegexExtractor
{
    private ?string $namespacePattern = null;
    private ?string $classPattern = null;
    private ?string $functionPattern = null;

    public function getSupportedExtensions(): array
    {
        return ['php', 'phtml'];
    }

    public function getName(): string
    {
        return 'PHP Extractor';
    }

    public function extract(string $filename, string $content): array
    {
        // Lazy load patterns
        if ($this->namespacePattern === null) {
            $this->namespacePattern = $this->loadPattern('PhpNamespaces');
            $this->classPattern = $this->loadPattern('PhpClasses');
            $this->functionPattern = $this->loadPattern('PhpFunctions');
        }

        $entities = [];

        // Extract whole file as file entity
        $entities[] = $this->extractFileEntity($filename, $content);

        // Extract namespaces first
        $namespaces = $this->executePattern($this->namespacePattern, $content, $filename, 'namespacePattern');

        if (empty($namespaces)) {
            // No namespace - treat entire file as global namespace
            $classEntities = $this->extractClasses($content, '', $filename);
            $entities = array_merge($entities, $classEntities);

            // Extract standalone functions (not inside classes)
            $contentWithoutClasses = $this->removeClassBodies($content, $filename);
            $entities = array_merge(
                $entities,
                $this->extractFunctions($contentWithoutClasses, '', '', $filename)
            );
        } else {
            // Process each namespace
            foreach ($namespaces as $ns) {
                $nsName = $this->extractNamespaceName($ns['namespace']);
                $nsContent = $ns[0]; // Full namespace block

                // Extract classes within this namespace
                $classEntities = $this->extractClasses($nsContent, $nsName, $filename);
                $entities = array_merge($entities, $classEntities);

                // Extract standalone functions (not inside classes)
                $contentWithoutClasses = $this->removeClassBodies($nsContent, $filename);
                $entities = array_merge(
                    $entities,
                    $this->extractFunctions($contentWithoutClasses, $nsName, '', $filename)
                );
            }
        }

        return $entities;
    }

    /**
     * Extract whole file as file entity
     */
    private function extractFileEntity(string $filename, string $content): array
    {
        $lines = substr_count($content, "\n") + 1;

        return [
            'entity_type' => 'file',
            'entity_name' => basename($filename),
            'namespace' => '',
            'class_name' => '',
            'signature' => '',
            'docblock' => '',
            'body' => $content,
            'line_start' => 1,
            'line_end' => $lines,
            'language' => 'php',
            'visibility' => '',
        ];
    }

    /**
     * Extract namespace name from namespace declaration
     */
    private function extractNamespaceName(string $namespaceDecl): string
    {
        if (preg_match('/namespace\s+([a-z0-9_\\\\]+)/i', $namespaceDecl, $match)) {
            return trim($match[1], '\\');
        }
        return '';
    }

    /**
     * Remove class bodies from content to avoid re-extracting methods as functions
     */
    private function removeClassBodies(string $content, string $filename): string
    {
        $matches = $this->executePattern($this->classPattern, $content, $filename, 'classPattern');

        // Remove class bodies in reverse order to maintain offsets
        $matches = array_reverse($matches);

        foreach ($matches as $match) {
            $offset = strpos($content, $match[0]);
            if ($offset !== false) {
                // Replace the entire class match with empty space
                $content = substr_replace($content, str_repeat(' ', strlen($match[0])), $offset, strlen($match[0]));
            }
        }

        return $content;
    }

    /**
     * Extract classes from content
     */
    private function extractClasses(string $content, string $namespace, string $filename): array
    {
        $matches = $this->executePattern($this->classPattern, $content, $filename, 'classPattern');
        $entities = [];

        foreach ($matches as $match) {
            $signature = trim($match['signature']);
            $preamble = $match['preamble'] ?? '';
            $body = $match['body'] ?? '';

            // Extract class type and name
            if (!preg_match('/(class|interface|trait|enum)\s+([a-z0-9_]+)/i', $signature, $typeMatch)) {
                continue;
            }

            $entityType = strtolower($typeMatch[1]);
            $entityName = $typeMatch[2];

            // Calculate line numbers from the full match
            $startOffset = strpos($content, $match[0]);
            $endOffset = $startOffset + strlen($match[0]);
            $lines = $this->getLineNumbers($content, $startOffset, $endOffset);

            $entities[] = [
                'entity_type' => $entityType,
                'entity_name' => $entityName,
                'namespace' => $namespace,
                'class_name' => '',
                'signature' => preg_replace('/\s+/', ' ', $signature),
                'docblock' => trim($preamble),
                'body' => $body,
                'line_start' => $lines['start'],
                'line_end' => $lines['end'],
                'language' => 'php',
                'visibility' => '',
            ];

            // Extract methods from class body
            $entities = array_merge(
                $entities,
                $this->extractFunctions($body, $namespace, $entityName, $filename)
            );
        }

        return $entities;
    }

    /**
     * Extract functions/methods from content
     */
    private function extractFunctions(string $content, string $namespace, string $className, string $filename): array
    {
        $matches = $this->executePattern($this->functionPattern, $content, $filename, 'functionPattern');
        $entities = [];

        foreach ($matches as $match) {
            $signature = trim($match['signature']);
            $preamble = $match['preamble'] ?? '';
            $body = $match['body'] ?? '';

            // Extract function name
            if (!preg_match('/function\s+([a-z0-9_]+)/i', $signature, $funcMatch)) {
                continue;
            }

            $functionName = $funcMatch[1];
            $entityType = $className ? 'method' : 'function';

            // Extract visibility if present
            $visibility = '';
            if (preg_match('/\b(public|protected|private)\b/', $signature, $visMatch)) {
                $visibility = $visMatch[1];
            }

            // Calculate line numbers
            $startOffset = strpos($content, $match[0]);
            $endOffset = $startOffset + strlen($match[0]);
            $lines = $this->getLineNumbers($content, $startOffset, $endOffset);

            $entities[] = [
                'entity_type' => $entityType,
                'entity_name' => $functionName,
                'namespace' => $namespace,
                'class_name' => $className,
                'signature' => preg_replace('/\s+/', ' ', $signature),
                'docblock' => trim($preamble),
                'body' => $body,
                'line_start' => $lines['start'],
                'line_end' => $lines['end'],
                'language' => 'php',
                'visibility' => $visibility,
            ];
        }

        return $entities;
    }
}
