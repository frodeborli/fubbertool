<?php

namespace FubberTool\Index\Extractors;

/**
 * Markdown extractor using simple line-based parsing
 *
 * Extracts markdown headings and their associated content
 */
class MarkdownExtractor implements ExtractorInterface
{
    public function getSupportedExtensions(): array
    {
        return ['md', 'markdown', 'mkd', 'mdwn'];
    }

    public function getName(): string
    {
        return 'Markdown Extractor';
    }

    public function getPriority(): int
    {
        return 80;
    }

    public function extract(string $filename, string $content): array
    {
        $entities = [];

        // First entity: the full file
        $entities[] = [
            'entity_type' => 'file',
            'entity_name' => basename($filename),
            'namespace' => '',
            'class_name' => '',
            'signature' => '',
            'docblock' => '',
            'body' => $content,
            'line_start' => 1,
            'line_end' => substr_count($content, "\n") + 1,
            'language' => 'md',
            'visibility' => '',
        ];

        // Extract H1 headings and their bodies
        $lines = explode("\n", $content);
        $totalLines = count($lines);

        for ($i = 0; $i < $totalLines; $i++) {
            $line = $lines[$i];

            // Match H1 headings only (single # followed by space)
            if (preg_match('/^#\s+(.+)$/', $line, $match)) {
                $headingText = trim($match[1]);

                // Collect body: everything until the next H1
                $bodyLines = [];
                $j = $i + 1;
                while ($j < $totalLines) {
                    // Stop if we hit another H1
                    if (preg_match('/^#\s+/', $lines[$j])) {
                        break;
                    }
                    $bodyLines[] = $lines[$j];
                    $j++;
                }

                $body = implode("\n", $bodyLines);

                $entities[] = [
                    'entity_type' => 'md-heading-1',
                    'entity_name' => $headingText,
                    'namespace' => '',
                    'class_name' => '',
                    'signature' => $line,
                    'docblock' => '',
                    'body' => trim($body),
                    'line_start' => $i + 1,
                    'line_end' => $j,
                    'language' => 'md',
                    'visibility' => '',
                ];
            }
        }

        return $entities;
    }
}
