<?php

namespace FubberTool\Index;

use FubberTool\DB;
use FubberTool\Index\Extractors\ExtractorRegistry;
use FubberTool\Index\Tokenizer;
use FubberTool\Output;

/**
 * Main indexing orchestrator
 *
 * Coordinates file discovery, extraction, and database insertion
 * using the ExtractorRegistry for modular, extensible extraction.
 *
 * Tokenizes all indexed content using the custom Tokenizer for optimal
 * code search (camelCase splitting, operator encoding, etc.)
 */
class Indexer
{
    private DB $db;
    private string $projectRoot;
    private ExtractorRegistry $registry;

    public function __construct(DB $db, string $projectRoot, ?ExtractorRegistry $registry = null)
    {
        $this->db = $db;
        $this->projectRoot = realpath($projectRoot);
        $this->registry = $registry ?? ExtractorRegistry::createDefault();
    }

    /**
     * Index entire project
     *
     * @param bool $verbose Print progress information
     */
    public function indexProject(bool $verbose = false): void
    {
        $startTime = microtime(true);
        $output = $GLOBALS['fubber_output'];

        if ($verbose) {
            $output->writeln("Indexing project: {$this->projectRoot}");
            $output->writeln("Supported extensions: " . implode(', ', $this->registry->getSupportedExtensions()));
            $output->writeln();
        }

        // Discover all files
        $output->debug(1, "Discovering files in project...");
        $files = FileDiscovery::discover($this->projectRoot);
        $totalFiles = count($files);

        if ($verbose) {
            $output->writeln("Found $totalFiles files to index");
        }

        // Delete existing entries for this project
        $output->debug(1, "Deleting existing project entries...");
        Schema::deleteProject($this->db, $this->projectRoot);
        $output->debug(1, "Deletion complete");

        // Begin transaction for better performance
        $output->debug(1, "Beginning transaction...");
        $this->db->beginTransaction();

        $indexed = 0;
        $entities = 0;
        $skipped = 0;
        $processedCount = 0;

        // Create progress bar
        $progress = $output->progressBar($totalFiles, 'Indexing files...');

        foreach ($files as $fileInfo) {
            $output->debug(3, "Processing file: {path}", ['path' => $fileInfo['path']]);

            // Check if we have an extractor for this file type
            // Use actual file extension, not language category
            $extension = pathinfo($fileInfo['path'], PATHINFO_EXTENSION);
            $extractor = $this->registry->getExtractorForExtension($extension);

            if (!$extractor) {
                $output->debug(3, "  No extractor for extension: {ext}", ['ext' => $extension]);
                $skipped++;
                $processedCount++;
                // Only update progress every 7 files to reduce overhead (prime number for better visual distribution)
                if ($processedCount % 7 === 0) {
                    $progress->advance(7);
                }
                continue;
            }

            $output->debug(3, "  Using extractor: {extractor}", ['extractor' => get_class($extractor)]);
            $count = $this->indexFile($fileInfo['path'], $fileInfo['language'], $verbose);
            if ($count > 0) {
                $indexed++;
                $entities += $count;
                $output->debug(3, "  Indexed {count} entities", ['count' => $count]);
            } else {
                $output->debug(3, "  No entities extracted");
            }

            $processedCount++;
            // Only update progress every 7 files to reduce overhead (prime number for better visual distribution)
            if ($processedCount % 7 === 0) {
                $progress->advance(7);
            }

            // In verbose mode, show details every 100 files
            if ($verbose && $indexed % 100 === 0) {
                $output->writeln("Indexed $indexed files ($entities entities)...");
            }
        }

        // Final progress update to ensure we reach 100%
        $progress->advance($totalFiles % 7);

        $progress->finish();

        // Commit transaction
        $this->db->commit();

        // Update last_indexed timestamp
        Schema::updateLastIndexed($this->db, $this->projectRoot);

        $elapsed = round(microtime(true) - $startTime, 2);

        if ($verbose) {
            $output->writeln();
            $output->writeln("Indexing complete:");
            $output->writeln("  Files indexed: $indexed");
            if ($skipped > 0) {
                $output->writeln("  Files skipped: $skipped");
            }
            $output->writeln("  Entities extracted: $entities");
            $output->writeln("  Time: {$elapsed}s");
            if ($elapsed > 0) {
                $output->writeln("  Rate: " . round($indexed / $elapsed, 2) . " files/sec");
            }
        } else {
            // Show final summary
            $output->writeln("Indexed $indexed files ($entities entities) in {$elapsed}s");
        }
    }

    /**
     * Index a single file
     *
     * @param string $filename Full path to file
     * @param string $language Language/file type
     * @param bool $verbose Print progress
     * @return int Number of entities extracted
     */
    public function indexFile(string $filename, string $language, bool $verbose = false): int
    {
        $output = $GLOBALS['fubber_output'];

        // Get relative path for cleaner debug output
        $relativePath = substr($filename, strlen($this->projectRoot) + 1);
        $output->debug(3, "Indexing file: {path}", ['path' => $relativePath]);

        // Read file content
        $output->debug(3, "  Reading file content...");
        $content = @file_get_contents($filename);
        if ($content === false) {
            $output->debug(3, "  Failed to read file: {path}", ['path' => $relativePath]);
            if ($verbose) {
                error_log("Failed to read file: $filename");
            }
            return 0;
        }
        $output->debug(3, "  File size: {size} bytes", ['size' => strlen($content)]);

        // Extract entities using registry
        $output->debug(3, "  Extracting entities...");
        try {
            $entities = $this->registry->extractFile($filename, $content);
        } catch (\RuntimeException $e) {
            $output->debug(3, "  Extraction failed: {error}", ['error' => $e->getMessage()]);

            // In dev mode, re-throw to stop immediately so we can fix the issue
            if (getenv('FUBBER_DEV')) {
                throw new \RuntimeException(
                    "Extraction failed for file: $filename\n" .
                    "Error: " . $e->getMessage() . "\n" .
                    "Stack trace:\n" . $e->getTraceAsString()
                );
            }

            // Production mode: log and continue
            if ($verbose) {
                error_log("Extraction failed for $filename: " . $e->getMessage());
            }
            return 0;
        }
        $output->debug(3, "  Extracted {count} entities", ['count' => count($entities)]);

        // Sanity check: extractors should always return at least one entity (the file itself)
        if (empty($entities)) {
            $output->debug(1, "WARNING: Extractor returned 0 entities for {path} - this should not happen!", [
                'path' => $relativePath
            ]);
            return 0;
        }

        // Get file metadata
        $output->debug(3, "  Getting file metadata...");
        $filetime = filemtime($filename);
        $fileHash = md5_file($filename);

        // Insert file metadata
        $output->debug(3, "  Inserting file metadata...");
        $this->db->execute("
            INSERT OR REPLACE INTO file_metadata
            (filename, project_root, filetime, verified_time, file_hash, entry_count, language)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ", [
            $filename,
            $this->projectRoot,
            $filetime,
            time(),
            $fileHash,
            count($entities),
            $language,
        ]);

        // Calculate file extension and relative path
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $relativePath = substr($filename, strlen($this->projectRoot) + 1);

        // Insert entities into real table (FTS5 index will be synced after)
        $output->debug(3, "  Preparing to tokenize and insert {count} entities...", ['count' => count($entities)]);
        $stmt = $this->db->prepare("
            INSERT INTO code_entities
            (preamble, signature, body, namespace, ext, path,
             preamble_raw, signature_raw,
             type, filename, line_start, line_end)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($entities as $entity) {
            $output->debug(3, "    Tokenizing entity: {type} at line {line}", [
                'type' => $entity['entity_type'],
                'line' => $entity['line_start']
            ]);

            // Tokenize searchable fields using custom tokenizer
            // Limit body size to prevent tokenization freeze on huge content
            $body = $entity['body'] ?? '';
            if (strlen($body) > 100000) {
                $output->debug(3, "      Body truncated from {original} to 100000 chars", [
                    'original' => strlen($entity['body'])
                ]);
                $body = substr($body, 0, 100000);
            }

            $output->debug(3, "      Tokenizing preamble ({size} chars)", [
                'size' => strlen($entity['docblock'] ?? '')
            ]);
            $preambleTokens = Tokenizer::tokenize($entity['docblock'] ?? '', $filename);

            $output->debug(3, "      Tokenizing signature ({size} chars)", [
                'size' => strlen($entity['signature'] ?? '')
            ]);
            $signatureTokens = Tokenizer::tokenize($entity['signature'] ?? '', $filename);

            $output->debug(3, "      Tokenizing body ({size} chars)", [
                'size' => strlen($body)
            ]);
            $bodyTokens = Tokenizer::tokenize($body, $filename);

            $output->debug(3, "      Tokenizing namespace ({size} chars)", [
                'size' => strlen($entity['namespace'] ?? '')
            ]);
            $namespaceTokens = Tokenizer::tokenize($entity['namespace'] ?? '', $filename);

            $output->debug(3, "      Tokenizing extension");
            $extTokens = Tokenizer::tokenize($ext, $filename);

            $output->debug(3, "      Tokenizing path");
            $pathTokens = Tokenizer::tokenize($relativePath, $filename);

            $output->debug(3, "      Inserting into database (trigger will sync FTS5)...");
            $stmt->execute([
                // Searchable (tokenized)
                $preambleTokens,
                $signatureTokens,
                $bodyTokens,
                $namespaceTokens,
                $extTokens,
                $pathTokens,

                // Original (for display)
                $entity['docblock'] ?? '',
                $entity['signature'] ?? '',

                // Metadata
                $entity['entity_type'],
                $filename,
                $entity['line_start'],
                $entity['line_end'],
            ]);

            $output->debug(3, "      Entity inserted successfully");
        }

        $output->debug(3, "  All entities tokenized and inserted");

        if ($verbose && $output) {
            $relPath = substr($filename, strlen($this->projectRoot) + 1);
            $output->writeln("  $relPath: " . count($entities) . " entities");
        }

        return count($entities);
    }

    /**
     * Reindex a single file (delete old entries first)
     */
    public function reindexFile(string $filename, string $language, bool $verbose = false): int
    {
        // Delete existing entries for this file
        Schema::deleteFile($this->db, $filename);

        // Index the file
        return $this->indexFile($filename, $language, $verbose);
    }

    /**
     * Incrementally update project index (only reindex changed files)
     *
     * @param bool $verbose Print progress information
     */
    public function updateProject(bool $verbose = false): void
    {
        $startTime = microtime(true);
        $output = $GLOBALS['fubber_output'];

        if ($verbose) {
            $output->writeln("Updating index for project: {$this->projectRoot}");
            $output->writeln();
        }

        // Phase 1: Scan project to find files that need updating
        $spinner = $output->spinner('Scanning project for changes...');

        $filesToIndex = [];
        $scanned = 0;

        // Get existing file metadata
        $rows = $this->db->query("
            SELECT filename, filetime
            FROM file_metadata
            WHERE project_root = ?
        ", [$this->projectRoot]);
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['filename']] = (int)$row['filetime'];
        }

        // Discover all files with progress updates
        $files = FileDiscovery::discover($this->projectRoot, function($count, $path) use ($spinner) {
            // Update spinner every 100 files to avoid overhead
            if ($count % 100 === 0) {
                $spinner->setMessage("Scanning project... ($count files found)");
            }
        });

        foreach ($files as $fileInfo) {
            $scanned++;

            // Update spinner every 10 files
            if ($scanned % 10 === 0) {
                $spinner->setMessage("Scanning project... ($scanned files scanned)");
                $spinner->tick();
            }

            $filepath = $fileInfo['path'];
            $currentMtime = @filemtime($filepath);

            if ($currentMtime === false) {
                continue; // File disappeared
            }

            // Check if we have an extractor for this file type
            $extension = pathinfo($filepath, PATHINFO_EXTENSION);
            $extractor = $this->registry->getExtractorForExtension($extension);
            if (!$extractor) {
                continue; // No extractor available
            }

            // Check if file needs indexing
            if (!isset($indexed[$filepath]) || $indexed[$filepath] < $currentMtime) {
                $filesToIndex[] = $fileInfo;
            }
        }

        // Check for deleted files
        $deletedFiles = [];
        foreach ($indexed as $filepath => $mtime) {
            if (!file_exists($filepath)) {
                $deletedFiles[] = $filepath;
            }
        }

        $spinner->finish();

        // If nothing to do, exit early
        if (empty($filesToIndex) && empty($deletedFiles)) {
            $output->writeln("Index is up to date.");
            return;
        }

        if ($verbose) {
            $output->writeln("Found " . count($filesToIndex) . " files to index");
            if (!empty($deletedFiles)) {
                $output->writeln("Found " . count($deletedFiles) . " deleted files to remove");
            }
            $output->writeln();
        }

        // Phase 2: Process the queue
        $this->db->beginTransaction();

        // Remove deleted files
        if (!empty($deletedFiles)) {
            foreach ($deletedFiles as $filepath) {
                Schema::deleteFile($this->db, $filepath);
            }
        }

        // Index changed/new files
        $indexed = 0;
        $entities = 0;

        if (!empty($filesToIndex)) {
            // Batch delete all files that need re-indexing (much faster than individual deletes)
            $output->debug(2, "Batch deleting {count} files from index...", ['count' => count($filesToIndex)]);
            $filePaths = array_map(fn($f) => $f['path'], $filesToIndex);
            Schema::deleteFiles($this->db, $filePaths);
            $output->debug(2, "Batch delete complete");

            $progress = $output->progressBar(count($filesToIndex), 'Indexing files...');
            $totalToIndex = count($filesToIndex);
            $processedCount = 0;

            foreach ($filesToIndex as $fileInfo) {
                // Index the file (old entries already deleted above)
                $count = $this->indexFile($fileInfo['path'], $fileInfo['language'], $verbose);
                if ($count > 0) {
                    $indexed++;
                    $entities += $count;
                }

                $processedCount++;
                // Only update progress every 7 files to reduce overhead (prime number for better visual distribution)
                if ($processedCount % 7 === 0) {
                    $progress->advance(7);
                }

                // In verbose mode, show details every 100 files
                if ($verbose && $indexed % 100 === 0) {
                    $output->writeln("Indexed $indexed files ($entities entities)...");
                }
            }

            // Final progress update to ensure we reach 100%
            $progress->advance($totalToIndex % 7);
            $progress->finish();
        }

        // Commit transaction
        $this->db->commit();

        // Update last_indexed timestamp
        Schema::updateLastIndexed($this->db, $this->projectRoot);

        $elapsed = round(microtime(true) - $startTime, 2);

        if ($verbose) {
            $output->writeln();
            $output->writeln("Update complete:");
            $output->writeln("  Files indexed: $indexed");
            if (!empty($deletedFiles)) {
                $output->writeln("  Files removed: " . count($deletedFiles));
            }
            $output->writeln("  Entities extracted: $entities");
            $output->writeln("  Time: {$elapsed}s");
            if ($elapsed > 0 && $indexed > 0) {
                $output->writeln("  Rate: " . round($indexed / $elapsed, 2) . " files/sec");
            }
        } else {
            // Show summary
            $totalChanges = $indexed + count($deletedFiles);
            $output->writeln("Updated index: $indexed files indexed, " . count($deletedFiles) . " removed ($entities entities) in {$elapsed}s");
        }
    }

    /**
     * Get the extractor registry
     */
    public function getRegistry(): ExtractorRegistry
    {
        return $this->registry;
    }
}
