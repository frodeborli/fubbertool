<?php

namespace FubberTool\Index;

/**
 * Discovers files to index within a project
 *
 * Recursively scans directories
 */
class FileDiscovery
{
    /**
     * Cache for compiled regex patterns per directory
     */
    private static array $patternCache = [];

    /**
     * Current project root path
     */
    private static ?string $projectRoot = null;

    /**
     * File extensions to index by category
     */
    private const EXTENSIONS = [
        'php' => ['php'],
        'css' => ['css', 'scss', 'sass', 'less'],
        'js' => ['js', 'jsx', 'ts', 'tsx', 'mjs'],
        'md' => ['md', 'markdown'],
        'html' => ['html', 'htm'],
        'python' => ['py'],
        'ruby' => ['rb'],
        'go' => ['go'],
        'rust' => ['rs'],
    ];

    /**
     * Directories to always skip
     *
     * Note: All dot-directories (.*) are filtered by global pattern
     */
    private const SKIP_DIRS = [
        'node_modules',
        'vendor',
        '__pycache__',
        'dist',
        'build',
        'coverage',
    ];

    /**
     * Discover all indexable files in project
     *
     * @param string $projectRoot Project root path
     * @param callable|null $progressCallback Optional callback called after each file found: fn(int $count, string $path)
     * @return array<string, array{path: string, language: string}> Map of absolute path => file info
     */
    public static function discover(string $projectRoot, ?callable $progressCallback = null): array
    {
        // Initialize project root and clear pattern cache
        self::$projectRoot = $projectRoot;
        self::$patternCache = [];

        $files = [];

        self::scanDirectory($projectRoot, $projectRoot, $files, $progressCallback);

        return $files;
    }

    /**
     * Recursively scan directory for files
     */
    private static function scanDirectory(
        string $projectRoot,
        string $dir,
        array &$files,
        ?callable $progressCallback = null
    ): void {
        try {
            $iterator = new \DirectoryIterator($dir);
        } catch (\UnexpectedValueException $e) {
            // Permission denied or directory doesn't exist - skip it
            return;
        }

        foreach ($iterator as $item) {
            if ($item->isDot()) {
                continue;
            }

            $path = $item->getPathname();

            if ($item->isDir()) {
                // Check if path can be visited
                if (!self::canVisit($item, $projectRoot)) {
                    continue;
                }

                // Recurse into subdirectory
                self::scanDirectory($projectRoot, $path, $files, $progressCallback);
            } elseif ($item->isFile()) {
                // Check if path can be visited
                if (!self::canVisit($item, $projectRoot)) {
                    continue;
                }

                $isExecutable = $item->isExecutable();
                $language = self::detectLanguage($item->getFilename(), $isExecutable);
                if ($language) {
                    $files[$path] = [
                        'path' => $path,
                        'language' => $language,
                    ];

                    // Call progress callback if provided
                    if ($progressCallback) {
                        $progressCallback(count($files), $path);
                    }
                }
            }
        }
    }

    /**
     * Detect file language from extension or executable script
     *
     * @param string $filename Filename or path
     * @param bool $isExecutable Whether the file is executable
     * @return string|null Language category or null if not supported
     */
    private static function detectLanguage(string $filename, bool $isExecutable = false): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // If no extension and file is executable, mark as extensionless script
        if (empty($extension) && $isExecutable) {
            return 'script';
        }

        return self::getLanguageForExtension($extension);
    }

    /**
     * Get language category for a file extension
     *
     * @param string $extension File extension (without dot)
     * @return string|null Language category or null if not supported
     */
    public static function getLanguageForExtension(string $extension): ?string
    {
        $extension = strtolower($extension);

        // Empty extension for extensionless executable scripts
        if ($extension === '') {
            return 'script';
        }

        foreach (self::EXTENSIONS as $language => $extensions) {
            if (in_array($extension, $extensions)) {
                return $language;
            }
        }

        return null;
    }

    /**
     * Determine if a path can be visited/traversed
     *
     * @param \SplFileInfo $fileInfo The file/directory to check
     * @param string $projectRoot Project root path
     * @return bool True if the path can be visited, false otherwise
     */
    public static function canVisit(\SplFileInfo $fileInfo, string $projectRoot): bool
    {
        $fullPath = $fileInfo->getPathname();
        $parentDir = $fileInfo->getPath();

        $partialPattern = self::canVisitGetIllegalPatterns($parentDir);

        if ($partialPattern === '') {
            return true;
        }

        $regex = '/' . $partialPattern . '/';

        // If matches, path is BLOCKED
        return !preg_match($regex, $fullPath);
    }

    /**
     * Get compiled regex pattern for illegal paths under a directory
     *
     * Recursively builds pattern by inheriting from parent directories.
     * Global patterns match anywhere, local patterns are anchored to directory.
     *
     * @param string $directory Directory to get patterns for
     * @return string Partial regex pattern (without delimiters)
     */
    private static function canVisitGetIllegalPatterns(string $directory): string
    {
        // Check cache first
        if (isset(self::$patternCache[$directory])) {
            return self::$patternCache[$directory];
        }

        $patterns = [];

        if ($directory === self::$projectRoot) {
            // Global pattern: all dot-directories (e.g., .git, .venv, .idea, .cache)
            $patterns[] = '\/\.[^\/]+\/';

            // Global patterns - unanchored, match anywhere in path
            $skipDirsPatterns = array_map(
                fn($dir) => '\/' . preg_quote($dir, '/') . '\/',
                self::SKIP_DIRS
            );
            $patterns[] = implode('|', $skipDirsPatterns);
        } else {
            // Recursively get parent patterns
            $parentDir = dirname($directory);
            $parentPattern = self::canVisitGetIllegalPatterns($parentDir);
            if ($parentPattern !== '') {
                $patterns[] = $parentPattern;
            }
        }

        // TODO: Load local .gitignore patterns if exists
        // $gitignorePath = $directory . '/.gitignore';
        // if (file_exists($gitignorePath)) {
        //     $localPatterns = self::loadGitignorePatterns($gitignorePath, $directory);
        //     if ($localPatterns !== '') {
        //         $patterns[] = $localPatterns;
        //     }
        // }

        // Combine all patterns
        $result = implode('|', array_filter($patterns));

        // Cache and return
        self::$patternCache[$directory] = $result;
        return $result;
    }

}
