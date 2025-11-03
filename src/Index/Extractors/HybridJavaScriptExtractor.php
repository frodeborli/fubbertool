<?php

namespace FubberTool\Index\Extractors;

/**
 * JavaScript/TypeScript extractor using hybrid approach
 *
 * Strategy:
 * 1. Simple regex finds signatures: class Name, function name(), const name =
 * 2. HybridExtractor methods safely extract bodies using brace matching
 * 3. No complex recursive patterns needed - easier to maintain
 */
class HybridJavaScriptExtractor extends HybridExtractor
{
    public function getSupportedExtensions(): array
    {
        return ['js', 'jsx', 'ts', 'tsx', 'mjs', 'cjs'];
    }

    public function getName(): string
    {
        return 'Hybrid JavaScript/TypeScript Extractor';
    }

    public function extract(string $filename, string $content): array
    {
        $entities = [];

        // Extract classes
        $entities = array_merge($entities, $this->extractClasses($content, $filename));

        // Extract functions
        $entities = array_merge($entities, $this->extractFunctions($content, $filename));

        // Extract arrow functions and constants
        $entities = array_merge($entities, $this->extractArrowFunctions($content, $filename));

        return $entities;
    }

    /**
     * Extract class declarations
     */
    private function extractClasses(string $content, string $filename): array
    {
        $entities = [];

        // Simple pattern: finds class signatures
        // Matches: class Name, export class Name, export default class Name
        $pattern = '/(export\s+(?:default\s+)?)?class\s+(\w+)(?:\s+extends\s+[\w.]+)?(?:\s+implements\s+[\w.,\s]+)?/';

        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $i => $match) {
            $fullMatch = $match[0];
            $offset = $match[1];
            $className = $matches[2][$i][0];

            // Use hybrid method to safely extract body
            $block = $this->extractBlock($content, $offset, $offset + strlen($fullMatch));

            if (!$block) {
                continue;
            }

            // Find preceding docblock
            $docblock = $this->findPrecedingDocblock($content, $offset);

            // Calculate line numbers
            $lines = $this->calculateLineNumbers($content, $block['start'], $block['end']);

            $entities[] = [
                'entity_type' => 'class',
                'entity_name' => $className,
                'namespace' => $this->extractModule($content),
                'class_name' => '',
                'signature' => $block['signature'],
                'docblock' => $docblock,
                'body' => $block['body'],
                'line_start' => $lines['start'],
                'line_end' => $lines['end'],
                'language' => 'javascript',
                'visibility' => $this->extractVisibility($fullMatch),
            ];

            // Extract methods from class body
            $entities = array_merge(
                $entities,
                $this->extractMethods($block['body'], $className, $filename)
            );
        }

        return $entities;
    }

    /**
     * Extract function declarations
     */
    private function extractFunctions(string $content, string $filename): array
    {
        $entities = [];

        // Pattern: function declarations
        // Matches: function name(), export function name(), async function name()
        $pattern = '/(export\s+)?(?:async\s+)?function\s+(\w+)\s*\([^)]*\)/';

        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $i => $match) {
            $fullMatch = $match[0];
            $offset = $match[1];
            $functionName = $matches[2][$i][0];

            $block = $this->extractBlock($content, $offset, $offset + strlen($fullMatch));

            if (!$block) {
                continue;
            }

            $docblock = $this->findPrecedingDocblock($content, $offset);
            $lines = $this->calculateLineNumbers($content, $block['start'], $block['end']);

            $entities[] = [
                'entity_type' => 'function',
                'entity_name' => $functionName,
                'namespace' => $this->extractModule($content),
                'class_name' => '',
                'signature' => $block['signature'],
                'docblock' => $docblock,
                'body' => $block['body'],
                'line_start' => $lines['start'],
                'line_end' => $lines['end'],
                'language' => 'javascript',
                'visibility' => $this->extractVisibility($fullMatch),
            ];
        }

        return $entities;
    }

    /**
     * Extract arrow functions and const declarations
     */
    private function extractArrowFunctions(string $content, string $filename): array
    {
        $entities = [];

        // Pattern: const/let/var with arrow functions
        // Matches: const name = () => {}, export const name = async () => {}
        $pattern = '/(export\s+)?(?:const|let|var)\s+(\w+)\s*=\s*(?:async\s+)?\([^)]*\)\s*=>/';

        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $i => $match) {
            $fullMatch = $match[0];
            $offset = $match[1];
            $name = $matches[2][$i][0];

            // Find the body (might be expression or block)
            $arrowPos = strpos($content, '=>', $offset);
            if ($arrowPos === false) {
                continue;
            }

            $bodyStart = $this->findNextNonWhitespace($content, $arrowPos + 2, '{');

            if ($bodyStart !== null) {
                // Block body
                $bodyEnd = $this->findMatchingBrace($content, $bodyStart);
                $body = substr($content, $bodyStart, $bodyEnd - $bodyStart + 1);
            } else {
                // Expression body - find semicolon or newline
                $bodyStart = $arrowPos + 2;
                $bodyEnd = $this->findExpressionEnd($content, $bodyStart);
                $body = trim(substr($content, $bodyStart, $bodyEnd - $bodyStart));
            }

            $docblock = $this->findPrecedingDocblock($content, $offset);
            $lines = $this->calculateLineNumbers($content, $offset, $bodyEnd);

            $entities[] = [
                'entity_type' => 'arrow-function',
                'entity_name' => $name,
                'namespace' => $this->extractModule($content),
                'class_name' => '',
                'signature' => trim($fullMatch),
                'docblock' => $docblock,
                'body' => $body,
                'line_start' => $lines['start'],
                'line_end' => $lines['end'],
                'language' => 'javascript',
                'visibility' => $this->extractVisibility($fullMatch),
            ];
        }

        return $entities;
    }

    /**
     * Extract methods from a class body
     */
    private function extractMethods(string $classBody, string $className, string $filename): array
    {
        $entities = [];

        // Pattern: method declarations inside class
        // Matches: methodName(), async methodName(), static methodName()
        $pattern = '/(?:async\s+)?(?:static\s+)?(?:get\s+|set\s+)?(\w+)\s*\([^)]*\)/';

        preg_match_all($pattern, $classBody, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $i => $match) {
            $fullMatch = $match[0];
            $offset = $match[1];
            $methodName = $matches[1][$i][0];

            // Skip constructor (already captured as class)
            if ($methodName === 'constructor') {
                continue;
            }

            $block = $this->extractBlock($classBody, $offset, $offset + strlen($fullMatch));

            if (!$block) {
                continue;
            }

            $docblock = $this->findPrecedingDocblock($classBody, $offset);
            $lines = $this->calculateLineNumbers($classBody, $block['start'], $block['end']);

            $entities[] = [
                'entity_type' => 'method',
                'entity_name' => $methodName,
                'namespace' => '',
                'class_name' => $className,
                'signature' => $block['signature'],
                'docblock' => $docblock,
                'body' => $block['body'],
                'line_start' => $lines['start'],
                'line_end' => $lines['end'],
                'language' => 'javascript',
                'visibility' => $this->extractMethodVisibility($fullMatch),
            ];
        }

        return $entities;
    }

    /**
     * Extract module/import path from file content
     */
    private function extractModule(string $content): string
    {
        // Try to find module.exports or export statements
        // This is simplified - could be more sophisticated
        return '';
    }

    /**
     * Extract visibility from signature
     */
    private function extractVisibility(string $signature): string
    {
        if (strpos($signature, 'export') !== false) {
            return 'public';
        }
        return 'private';
    }

    /**
     * Extract method visibility
     */
    private function extractMethodVisibility(string $signature): string
    {
        if (preg_match('/\bprivate\b|\#/', $signature)) {
            return 'private';
        }
        if (preg_match('/\bprotected\b/', $signature)) {
            return 'protected';
        }
        return 'public';
    }

    /**
     * Find the end of an expression (for arrow function bodies)
     */
    private function findExpressionEnd(string $content, int $start): int
    {
        $depth = 0;
        $len = strlen($content);

        for ($i = $start; $i < $len; $i++) {
            $char = $content[$i];

            // Track brace depth
            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
                if ($depth < 0) {
                    return $i; // Hit closing brace of enclosing context
                }
            } elseif ($depth === 0 && ($char === ';' || $char === ',' || $char === "\n")) {
                return $i;
            }
        }

        return $len - 1;
    }
}
