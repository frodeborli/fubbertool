<?php

namespace FubberTool\Index;

use FubberTool\DB;
use FubberTool\Output;
use FubberTool\Index\Extractors\ExtractorRegistry;

/**
 * Automatic index update checker
 *
 * Performs smart, incremental background updates:
 * - Runs automatically on command execution (throttled)
 * - Quick detection phase (≤250ms by default)
 * - Prioritizes recently modified files
 * - Scans directories of modified files for new files
 * - Silent operation with optional progress indicators
 */
class AutoUpdateChecker
{
    private DB $db;
    private string $projectRoot;
    private ExtractorRegistry $registry;

    // Configuration (can be overridden via environment variables)
    private int $throttleSeconds;
    private int $detectTimeoutMs;
    private int $recentThresholdSeconds;

    public function __construct(DB $db, string $projectRoot, ?ExtractorRegistry $registry = null)
    {
        $this->db = $db;
        $this->projectRoot = $projectRoot;
        $this->registry = $registry ?? ExtractorRegistry::createDefault();

        // Load configuration from environment or use defaults
        $this->throttleSeconds = (int)getenv('FUBBER_UPDATE_THROTTLE') ?: 60;
        $this->detectTimeoutMs = (int)getenv('FUBBER_DETECT_TIMEOUT') ?: 250;
        $this->recentThresholdSeconds = (int)getenv('FUBBER_RECENT_THRESHOLD') ?: 86400; // 24 hours
    }

    /**
     * Check if auto-update should run (respects throttle)
     */
    public function shouldCheck(): bool
    {
        // Check if auto-updates are disabled
        if (getenv('FUBBER_AUTO_UPDATE') === 'false' || getenv('FUBBER_AUTO_UPDATE') === '0') {
            return false;
        }

        // Get last update check timestamp
        $stmt = $this->db->prepare("
            SELECT last_update_check
            FROM project_roots
            WHERE project_root = ?
        ");
        $stmt->execute([$this->projectRoot]);
        $lastCheck = $stmt->fetchColumn();

        // If never checked or throttle period has passed, allow check
        if ($lastCheck === false || $lastCheck === null) {
            return true;
        }

        $elapsed = time() - $lastCheck;
        return $elapsed >= $this->throttleSeconds;
    }

    /**
     * Run automatic background update
     */
    public function runBackgroundUpdate(): void
    {
        // Quick detection phase
        $filesToIndex = $this->detectChanges();

        if (empty($filesToIndex)) {
            // Update check timestamp even if no changes
            $this->updateCheckTimestamp();
            return;
        }

        // Update check timestamp
        $this->updateCheckTimestamp();

        // Index changed/new files
        $this->indexFiles($filesToIndex);
    }

    /**
     * Detect files that need indexing (respects timeout)
     *
     * @return array Array with keys: 'files' => files to index, 'modifiedDirs' => dirs to scan
     */
    private function detectChanges(): array
    {
        $startTime = microtime(true);
        $timeoutSeconds = $this->detectTimeoutMs / 1000.0;

        $filesToIndex = [];
        $checkedFiles = [];
        $modifiedFilePaths = [];  // Track paths of modified files

        // Initialize FileDiscovery for canVisit() checks
        $reflection = new \ReflectionClass(FileDiscovery::class);
        $projectRootProperty = $reflection->getProperty('projectRoot');
        $projectRootProperty->setAccessible(true);
        $projectRootProperty->setValue(null, $this->projectRoot);

        $patternCacheProperty = $reflection->getProperty('patternCache');
        $patternCacheProperty->setAccessible(true);
        $patternCacheProperty->setValue(null, []);

        // Phase 1: Check recently modified files (last 24 hours)
        $recentThreshold = time() - $this->recentThresholdSeconds;
        $recentFiles = $this->db->query("
            SELECT filename, filetime, language
            FROM file_metadata
            WHERE project_root = ?
              AND verified_time >= ?
            ORDER BY verified_time DESC
        ", [$this->projectRoot, $recentThreshold]);

        foreach ($recentFiles as $row) {
            // Check timeout
            if ((microtime(true) - $startTime) >= $timeoutSeconds) {
                break;
            }

            $filepath = $row['filename'];
            $storedMtime = (int)$row['filetime'];

            // Check if file exists and is allowed
            $fileInfo = new \SplFileInfo($filepath);
            if (!file_exists($filepath) || !FileDiscovery::canVisit($fileInfo, $this->projectRoot)) {
                // File was deleted or is now forbidden - purge from database
                $this->purgeFile($filepath);
                continue;
            }

            $currentMtime = @filemtime($filepath);
            if ($currentMtime === false) {
                continue;
            }

            $checkedFiles[$filepath] = true;

            // File has been modified
            if ($currentMtime > $storedMtime) {
                $modifiedFilePaths[] = $filepath;
                $filesToIndex[] = [
                    'path' => $filepath,
                    'language' => $row['language'],
                ];
            }

            // Update verified_time (we checked this file)
            $this->updateVerifiedTime($filepath);
        }

        // Phase 2: Check older files if time budget allows
        $olderFiles = $this->db->query("
            SELECT filename, filetime, language
            FROM file_metadata
            WHERE project_root = ?
              AND verified_time < ?
            ORDER BY verified_time ASC
            LIMIT 50
        ", [$this->projectRoot, $recentThreshold]);

        foreach ($olderFiles as $row) {
            // Check timeout
            if ((microtime(true) - $startTime) >= $timeoutSeconds) {
                break;
            }

            $filepath = $row['filename'];

            // Skip if already checked
            if (isset($checkedFiles[$filepath])) {
                continue;
            }

            $storedMtime = (int)$row['filetime'];

            // Check if file exists and is allowed
            $fileInfo = new \SplFileInfo($filepath);
            if (!file_exists($filepath) || !FileDiscovery::canVisit($fileInfo, $this->projectRoot)) {
                // File was deleted or is now forbidden - purge from database
                $this->purgeFile($filepath);
                continue;
            }

            $currentMtime = @filemtime($filepath);
            if ($currentMtime === false) {
                continue;
            }

            $checkedFiles[$filepath] = true;

            // File has been modified
            if ($currentMtime > $storedMtime) {
                $modifiedFilePaths[] = $filepath;
                $filesToIndex[] = [
                    'path' => $filepath,
                    'language' => $row['language'],
                ];
            }

            // Update verified_time
            $this->updateVerifiedTime($filepath);
        }

        // Phase 3: Scan directories containing modified files for new files
        if (!empty($modifiedFilePaths) && (microtime(true) - $startTime) < $timeoutSeconds) {
            // Get ALL known files from database (not just checked files)
            $allKnownFiles = $this->getAllKnownFiles();
            $newFiles = $this->scanDirectoriesForNewFiles($modifiedFilePaths, $allKnownFiles);
            $filesToIndex = array_merge($filesToIndex, $newFiles);
        }

        return $filesToIndex;
    }

    /**
     * Get all known files from database
     */
    private function getAllKnownFiles(): array
    {
        $filenames = $this->db->queryColumn("
            SELECT filename
            FROM file_metadata
            WHERE project_root = ?
        ", [$this->projectRoot]);

        $knownFiles = [];
        foreach ($filenames as $filename) {
            $knownFiles[$filename] = true;
        }

        return $knownFiles;
    }

    /**
     * Scan directories of modified files for new files
     */
    private function scanDirectoriesForNewFiles(array $modifiedFiles, array $knownFiles): array
    {
        $directoriesToScan = $this->getDirectoriesToScan($modifiedFiles);
        $newFiles = [];

        foreach ($directoriesToScan as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            // Scan directory (non-recursive)
            $files = @scandir($dir);
            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $fullPath = $dir . '/' . $file;

                // Only check files, not directories
                if (!is_file($fullPath)) {
                    continue;
                }

                // Skip if already known
                if (isset($knownFiles[$fullPath])) {
                    continue;
                }

                // Check if we have an extractor for this file type
                $extension = pathinfo($fullPath, PATHINFO_EXTENSION);
                if (!$this->registry->getExtractorForExtension($extension)) {
                    continue;
                }

                // Determine language from extension
                $language = FileDiscovery::getLanguageForExtension($extension);

                $newFiles[] = [
                    'path' => $fullPath,
                    'language' => $language,
                ];
            }
        }

        return $newFiles;
    }

    /**
     * Get directories to scan based on modified files
     * Includes parent directory and grandparent directory
     */
    private function getDirectoriesToScan(array $modifiedFiles): array
    {
        $dirsToScan = [];

        foreach ($modifiedFiles as $filepath) {
            $dir = dirname($filepath);
            $parentDir = dirname($dir);

            $dirsToScan[$dir] = true;

            // Only add grandparent if it's still within project root
            if (str_starts_with($parentDir . '/', $this->projectRoot . '/') || $parentDir === $this->projectRoot) {
                $dirsToScan[$parentDir] = true;
            }
        }

        return array_keys($dirsToScan);
    }

    /**
     * Index a list of files
     */
    private function indexFiles(array $filesToIndex): void
    {
        // Don't show progress for small updates
        if (count($filesToIndex) < 10) {
            $this->indexFilesSilently($filesToIndex);
            return;
        }

        // Show progress bar for larger updates
        $output = new Output(STDOUT);
        $progress = $output->progressBar(count($filesToIndex), 'Auto-updating index...');

        $indexer = new Indexer($this->db, $this->projectRoot, $this->registry);
        $this->db->beginTransaction();

        foreach ($filesToIndex as $fileInfo) {
            // Delete old entries first
            Schema::deleteFile($this->db, $fileInfo['path']);

            // Index the file
            $indexer->indexFile($fileInfo['path'], $fileInfo['language'], false, null);

            $progress->advance();
        }

        $this->db->commit();
        $progress->finish();

        // Update last_indexed timestamp
        Schema::updateLastIndexed($this->db, $this->projectRoot);
    }

    /**
     * Index files silently (no progress indicator)
     */
    private function indexFilesSilently(array $filesToIndex): void
    {
        $indexer = new Indexer($this->db, $this->projectRoot, $this->registry);
        $this->db->beginTransaction();

        foreach ($filesToIndex as $fileInfo) {
            // Delete old entries first
            Schema::deleteFile($this->db, $fileInfo['path']);

            // Index the file
            $indexer->indexFile($fileInfo['path'], $fileInfo['language'], false, null);
        }

        $this->db->commit();

        // Update last_indexed timestamp
        Schema::updateLastIndexed($this->db, $this->projectRoot);
    }

    /**
     * Update the last_update_check timestamp for the project
     */
    private function updateCheckTimestamp(): void
    {
        $stmt = $this->db->prepare("
            UPDATE project_roots
            SET last_update_check = ?
            WHERE project_root = ?
        ");
        $stmt->execute([time(), $this->projectRoot]);
    }

    /**
     * Update verified_time for a file (marks it as checked)
     */
    private function updateVerifiedTime(string $filename): void
    {
        $stmt = $this->db->prepare("
            UPDATE file_metadata
            SET verified_time = ?
            WHERE filename = ?
        ");
        $stmt->execute([time(), $filename]);
    }

    /**
     * Purge a file from the database
     *
     * Removes file from code_entities (triggers handle FTS5 cleanup)
     * and file_metadata. Takes ~0.5-1ms per file.
     */
    private function purgeFile(string $filename): void
    {
        Output::debug(2, "Purging file from database: {file}", ['file' => $filename]);

        // Delete from code_entities first (triggers will clean FTS5 via AFTER DELETE)
        $stmt = $this->db->prepare("DELETE FROM code_entities WHERE filename = ?");
        $stmt->execute([$filename]);

        // Delete from file_metadata
        $stmt = $this->db->prepare("DELETE FROM file_metadata WHERE filename = ?");
        $stmt->execute([$filename]);
    }
}
