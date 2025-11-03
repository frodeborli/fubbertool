<?php

namespace FubberTool\Index\Extractors;

/**
 * CSS extractor using advanced recursive regex patterns
 *
 * Extracts CSS rules (selectors + declaration blocks) including:
 * - Regular rules (.class, #id, element)
 * - At-rules (@media, @keyframes, etc.)
 * - Nested rules
 */
class CssExtractor extends AbstractRegexExtractor
{
    private ?string $cssPattern = null;

    public function getSupportedExtensions(): array
    {
        return ['css', 'scss', 'sass', 'less'];
    }

    public function getName(): string
    {
        return 'CSS Extractor';
    }

    public function extract(string $filename, string $content): array
    {
        // Lazy load pattern
        if ($this->cssPattern === null) {
            $this->cssPattern = $this->loadPattern('Css');
        }

        $matches = $this->executePattern($this->cssPattern, $content, $filename, 'cssPattern');
        $entities = [];

        foreach ($matches as $match) {
            $selector = trim($match['selector']);
            $body = $match['body'] ?? '';

            // Determine entity type
            $entityType = 'css-rule';
            if (str_starts_with($selector, '@media')) {
                $entityType = 'css-media-query';
            } elseif (str_starts_with($selector, '@keyframes')) {
                $entityType = 'css-keyframes';
            } elseif (str_starts_with($selector, '@')) {
                $entityType = 'css-at-rule';
            }

            // Calculate line numbers
            $startOffset = strpos($content, $match[0]);
            $endOffset = $startOffset + strlen($match[0]);
            $lines = $this->getLineNumbers($content, $startOffset, $endOffset);

            $entities[] = [
                'entity_type' => $entityType,
                'entity_name' => $selector,
                'namespace' => '',
                'class_name' => '',
                'signature' => $selector,
                'docblock' => '',
                'body' => $body,
                'line_start' => $lines['start'],
                'line_end' => $lines['end'],
                'language' => 'css',
                'visibility' => '',
            ];
        }

        // Always emit at least the file entity so it's searchable
        if (empty($entities)) {
            $lineCount = substr_count($content, "\n") + 1;
            $entities[] = [
                'entity_type' => 'file',
                'entity_name' => basename($filename),
                'namespace' => '',
                'class_name' => '',
                'signature' => '',
                'docblock' => '',
                'body' => $content,
                'line_start' => 1,
                'line_end' => $lineCount,
                'language' => 'css',
                'visibility' => '',
            ];
        }

        return $entities;
    }
}
