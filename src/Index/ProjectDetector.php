<?php

namespace FubberTool\Index;

use FubberTool\DB;

/**
 * Detects and registers project root directories
 *
 * Workflow:
 * 1. Check if current path is within a registered project
 * 2. If not, scan upward for project markers
 * 3. If markers found, prompt user to select (if multiple) or confirm
 * 4. Register selected root in project_roots table
 */
class ProjectDetector
{
    /**
     * Markers that indicate a project root, in priority order
     * These are ONLY used for making suggestions - not for actual project root detection
     */
    private const MARKERS = [
        '.git' => 'Git repository',
        'composer.lock' => 'PHP project (Composer)',
        'composer.json' => 'PHP project (Composer)',
        'package-lock.json' => 'Node.js project (npm)',
        'package.json' => 'Node.js project',
        'yarn.lock' => 'Node.js project (Yarn)',
        'pnpm-lock.yaml' => 'Node.js project (pnpm)',
        'requirements.txt' => 'Python project',
        'Pipfile' => 'Python project (Pipenv)',
        'pyproject.toml' => 'Python project',
        'Cargo.toml' => 'Rust project',
        'Cargo.lock' => 'Rust project',
        'go.mod' => 'Go project',
        'Gemfile' => 'Ruby project',
        'mix.exs' => 'Elixir project',
    ];

    /**
     * Detect project root from current working directory
     *
     * @param DB $db Database connection
     * @param string|null $startPath Starting path (defaults to getcwd())
     * @param bool $interactive Whether to prompt user for selection
     * @return string Absolute path to project root
     * @throws \RuntimeException If no project root found
     */
    public static function detect(DB $db, ?string $startPath = null, bool $interactive = true): string
    {
        $startPath = $startPath ?? getcwd();
        $startPath = realpath($startPath);

        if (!$startPath) {
            throw new \RuntimeException("Invalid starting path");
        }

        // Step 1: Check if we're inside a registered project
        $registeredRoot = self::findRegisteredProjectContaining($db, $startPath);
        if ($registeredRoot) {
            // Update last_accessed
            Schema::registerProjectRoot($db, $registeredRoot);

            // Run automatic background update check (if throttle allows)
            self::runAutoUpdateCheck($db, $registeredRoot);

            return $registeredRoot;
        }

        // Step 2: No registered project found - show helpful suggestions
        self::showProjectRootSuggestions($startPath);
        exit(1);
    }

    /**
     * Detect registered project root only (doesn't show suggestions or exit)
     *
     * @param DB $db Database connection
     * @param string $startPath Starting path
     * @return string Absolute path to project root
     * @throws \RuntimeException If no registered project root found
     */
    public static function detectRegistered(DB $db, string $startPath): string
    {
        $startPath = realpath($startPath) ?: $startPath;

        // Check if we're inside a registered project
        $registeredRoot = self::findRegisteredProjectContaining($db, $startPath);

        if ($registeredRoot) {
            return $registeredRoot;
        }

        throw new \RuntimeException("No registered project root found");
    }

    /**
     * Check if current path is within a registered project
     */
    private static function findRegisteredProjectContaining(DB $db, string $path): ?string
    {
        $projects = Schema::getProjectRoots($db);

        // Sort by length descending to match most specific path first
        usort($projects, fn($a, $b) => strlen($b['project_root']) - strlen($a['project_root']));

        foreach ($projects as $project) {
            $projectRoot = $project['project_root'];
            // Check if path is within or equals project root
            if ($path === $projectRoot || str_starts_with($path, $projectRoot . '/')) {
                return $projectRoot;
            }
        }

        return null;
    }

    /**
     * Get all known projects from database
     *
     * @return array<array{project_root: string, project_name: string, last_indexed: ?int}>
     */
    public static function getAllProjects(DB $db): array
    {
        return Schema::getProjectRoots($db);
    }

    /**
     * List all registered projects (for CLI)
     */
    public static function listProjects(DB $db): void
    {
        $projects = self::getAllProjects($db);

        if (empty($projects)) {
            echo "No projects registered yet.\n";
            echo "Run 'fubber index' in a project directory to register and index it.\n";
            return;
        }

        echo "Registered projects:\n\n";

        foreach ($projects as $project) {
            echo "  {$project['project_name']}\n";
            echo "    Path: {$project['project_root']}\n";

            if ($project['last_indexed']) {
                $time = date('Y-m-d H:i:s', $project['last_indexed']);
                echo "    Last indexed: {$time}\n";
            } else {
                echo "    Last indexed: Never\n";
            }

            echo "\n";
        }
    }

    /**
     * Show helpful suggestions for creating a project root
     */
    private static function showProjectRootSuggestions(string $startPath): void
    {
        // Find potential project root locations
        $suggestions = self::findProjectRootSuggestions($startPath);

        fwrite(STDERR, "No project root detected. How to create project roots:\n\n");

        // Show detected locations first
        foreach ($suggestions as $suggestion) {
            fwrite(STDERR, "  fubber init {$suggestion['path']}    # {$suggestion['reason']}\n");
        }

        // Always show current directory option
        fwrite(STDERR, "  fubber init .    # Create project root in current directory\n");
        fwrite(STDERR, "\n");
    }

    /**
     * Find project markers starting from a path
     *
     * @return array<array{path: string, marker: string, description: string}>
     */
    public static function findProjectMarkers(string $path): array
    {
        $candidates = [];
        $current = $path;
        $previous = null;
        $homeDir = getenv('HOME');

        // Determine stop point: $HOME if we're inside it, otherwise /
        $stopAt = '/';
        if ($homeDir && str_starts_with($path . '/', $homeDir . '/')) {
            $stopAt = $homeDir;
        }

        while ($current !== $previous) {
            foreach (self::MARKERS as $marker => $description) {
                $markerPath = $current . '/' . $marker;
                if (file_exists($markerPath)) {
                    // Check if we already have this path
                    $alreadyAdded = false;
                    foreach ($candidates as $candidate) {
                        if ($candidate['path'] === $current) {
                            $alreadyAdded = true;
                            break;
                        }
                    }

                    if (!$alreadyAdded) {
                        $candidates[] = [
                            'path' => $current,
                            'marker' => $marker,
                            'description' => $description,
                        ];
                    }
                }
            }

            // Stop at the boundary
            if ($current === $stopAt) {
                break;
            }

            $previous = $current;
            $current = dirname($current);
        }

        return $candidates;
    }

    /**
     * Find potential project root locations based on partial markers
     */
    public static function findProjectRootSuggestions(string $startPath): array
    {
        $suggestions = [];
        $dirMarkers = []; // Track which markers are found in each directory
        $current = $startPath;
        $previous = null;
        $homeDir = getenv('HOME');

        while ($current !== $previous) {
            // Check for any files that might indicate a project
            $potentialMarkers = [
                '.git',
                'composer.json',
                'composer.lock',
                'package.json',
                'package-lock.json',
                'yarn.lock',
                'pnpm-lock.yaml',
                'pyproject.toml',
                'requirements.txt',
                'Pipfile',
                'Cargo.toml',
                'Cargo.lock',
                'go.mod',
                'Gemfile',
                'mix.exs',
            ];

            $foundMarkers = [];
            foreach ($potentialMarkers as $marker) {
                if (file_exists($current . '/' . $marker)) {
                    $foundMarkers[] = $marker;
                }
            }

            // If we found markers in this directory, add it as a suggestion
            if (!empty($foundMarkers)) {
                $reason = 'found ' . implode(', ', $foundMarkers);
                $suggestions[] = [
                    'path' => $current,
                    'reason' => $reason,
                ];
            }

            // Add home directory as a candidate if we encounter it (only if no markers found)
            if ($homeDir && $current === $homeDir && empty($foundMarkers)) {
                $suggestions[] = [
                    'path' => $homeDir,
                    'reason' => 'your home directory',
                ];
            }

            $previous = $current;
            $current = dirname($current);

            // Stop at root
            if ($current === '/') {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * Run automatic background update check
     *
     * @param DB $db Database connection
     * @param string $projectRoot Project root path
     */
    private static function runAutoUpdateCheck(DB $db, string $projectRoot): void
    {
        try {
            $checker = new AutoUpdateChecker($db, $projectRoot);

            // Check if update should run (respects throttle)
            if (!$checker->shouldCheck()) {
                return;
            }

            // Run the update in background
            $checker->runBackgroundUpdate();
        } catch (\Exception $e) {
            // Log auto-update errors if verbose mode enabled
            if (getenv('FUBBER_UPDATE_VERBOSE')) {
                error_log("Auto-update check failed: " . $e->getMessage());
                error_log($e->getTraceAsString());
            }
        }
    }
}
