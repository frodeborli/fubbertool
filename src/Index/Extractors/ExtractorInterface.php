<?php

namespace FubberTool\Index\Extractors;

/**
 * Interface for code extractors
 *
 * Extractors parse source files and return structured entity data
 * for indexing into the FTS5 database.
 */
interface ExtractorInterface
{
    /**
     * Get supported file extensions (without leading dot)
     *
     * @return string[] List of extensions this extractor handles
     *
     * Example: ['php', 'phtml']
     */
    public function getSupportedExtensions(): array;

    /**
     * Extract entities from source code
     *
     * @param string $filename Full path to file (for reference/debugging)
     * @param string $content File content to parse
     * @return array<array{
     *   entity_type: string,
     *   entity_name: string,
     *   namespace: string,
     *   class_name: string,
     *   signature: string,
     *   docblock: string,
     *   body: string,
     *   line_start: int,
     *   line_end: int,
     *   language: string,
     *   visibility: string
     * }> Array of extracted entities
     */
    public function extract(string $filename, string $content): array;

    /**
     * Get extractor name (for debugging/logging)
     *
     * @return string Human-readable name
     */
    public function getName(): string;

    /**
     * Get extractor priority (higher = preferred when multiple extractors support same extension)
     *
     * @return int Priority value (0-100, default 50)
     */
    public function getPriority(): int;
}
