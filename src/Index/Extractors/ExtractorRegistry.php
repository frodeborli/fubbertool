<?php

namespace FubberTool\Index\Extractors;

/**
 * Registry for code extractors
 *
 * Manages extractor discovery, registration, and routing based on file extensions.
 */
class ExtractorRegistry
{
    /** @var ExtractorInterface[] */
    private array $extractors = [];

    /** @var array<string, ExtractorInterface> Cache of extension -> extractor mappings */
    private array $extensionMap = [];

    /**
     * Register an extractor
     *
     * @param ExtractorInterface $extractor
     */
    public function register(ExtractorInterface $extractor): void
    {
        $this->extractors[] = $extractor;

        // Clear cache to rebuild
        $this->extensionMap = [];
    }

    /**
     * Register multiple extractors at once
     *
     * @param ExtractorInterface[] $extractors
     */
    public function registerAll(array $extractors): void
    {
        foreach ($extractors as $extractor) {
            $this->register($extractor);
        }
    }

    /**
     * Get extractor for a file extension
     *
     * If multiple extractors support the same extension, returns the one with highest priority.
     *
     * @param string $extension File extension (without leading dot)
     * @return ExtractorInterface|null
     */
    public function getExtractorForExtension(string $extension): ?ExtractorInterface
    {
        // Check cache
        if (isset($this->extensionMap[$extension])) {
            return $this->extensionMap[$extension];
        }

        // Find all extractors that support this extension
        $candidates = [];
        foreach ($this->extractors as $extractor) {
            if (in_array($extension, $extractor->getSupportedExtensions(), true)) {
                $candidates[] = $extractor;
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // Sort by priority (highest first)
        usort($candidates, fn($a, $b) => $b->getPriority() - $a->getPriority());

        // Cache and return best match
        $this->extensionMap[$extension] = $candidates[0];
        return $candidates[0];
    }

    /**
     * Get extractor for a filename
     *
     * @param string $filename Full path or filename
     * @return ExtractorInterface|null
     */
    public function getExtractorForFile(string $filename): ?ExtractorInterface
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        return $this->getExtractorForExtension(strtolower($extension));
    }

    /**
     * Extract entities from a file
     *
     * Automatically selects appropriate extractor based on file extension.
     *
     * @param string $filename Full path to file
     * @param string|null $content File content (reads from disk if null)
     * @return array Extracted entities
     * @throws \RuntimeException If no extractor found for file type
     */
    public function extractFile(string $filename, ?string $content = null): array
    {
        $extractor = $this->getExtractorForFile($filename);

        if (!$extractor) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            throw new \RuntimeException("No extractor registered for extension: .{$extension}");
        }

        if ($content === null) {
            $content = file_get_contents($filename);
            if ($content === false) {
                throw new \RuntimeException("Failed to read file: {$filename}");
            }
        }

        return $extractor->extract($filename, $content);
    }

    /**
     * Get all registered extractors
     *
     * @return ExtractorInterface[]
     */
    public function getAllExtractors(): array
    {
        return $this->extractors;
    }

    /**
     * Get all supported extensions across all extractors
     *
     * @return string[] Unique list of supported extensions
     */
    public function getSupportedExtensions(): array
    {
        $extensions = [];
        foreach ($this->extractors as $extractor) {
            $extensions = array_merge($extensions, $extractor->getSupportedExtensions());
        }
        return array_unique($extensions);
    }

    /**
     * Create a default registry with all built-in extractors
     *
     * @return self
     */
    public static function createDefault(): self
    {
        $registry = new self();

        // Register extractors
        $registry->register(new PhpExtractor());
        $registry->register(new CssExtractor());
        $registry->register(new MarkdownExtractor());
        $registry->register(new PythonExtractor());

        // Could register fallback extractors with lower priority here
        // $registry->register(new SimplePhpExtractor());

        return $registry;
    }
}
