<?php

namespace FubberTool\Index;

use FubberTool\DB;

/**
 * Database schema for FTS5 code indexing
 *
 * Single database at ~/.local/fubber/index.db containing all projects,
 * with path-based filtering for per-project queries.
 */
class Schema
{
    /** Current schema version */
    private const SCHEMA_VERSION = 6;
    /**
     * Get database file path, creating directory if needed
     */
    public static function getDatabasePath(): string
    {
        $dir = $_SERVER['HOME'] . '/.local/fubber';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . '/index.db';
    }

    /**
     * Open database connection, creating schema if needed
     */
    public static function connect(): DB
    {
        $dbPath = self::getDatabasePath();
        $isNew = !file_exists($dbPath);

        $db = new DB('sqlite:' . $dbPath);

        if ($isNew) {
            self::createSchema($db);
        } else {
            // Check schema version and migrate if needed
            $currentVersion = self::getSchemaVersion($db);
            if ($currentVersion < self::SCHEMA_VERSION) {
                self::migrateSchema($db, $currentVersion);
            }
        }

        return $db;
    }

    /**
     * Create database schema
     */
    public static function createSchema(DB $db): void
    {
        // Schema version tracking
        $db->exec("
            CREATE TABLE IF NOT EXISTS schema_version (
                version INTEGER PRIMARY KEY,
                updated_at INTEGER NOT NULL
            )
        ");

        // Project roots registry
        $db->exec("
            CREATE TABLE IF NOT EXISTS project_roots (
                project_root TEXT PRIMARY KEY,       -- Absolute path to project root
                project_name TEXT,                   -- Display name (defaults to basename)
                registered_at INTEGER NOT NULL,      -- Unix timestamp when registered
                last_indexed INTEGER,                -- Unix timestamp of last full index
                last_accessed INTEGER,               -- Unix timestamp of last use
                last_update_check INTEGER            -- Unix timestamp of last auto-update check
            )
        ");

        // File metadata table (non-FTS, for fast lookups and verification)
        $db->exec("
            CREATE TABLE IF NOT EXISTS file_metadata (
                filename TEXT PRIMARY KEY,           -- Full absolute path
                project_root TEXT NOT NULL,          -- Detected project root
                filetime INTEGER NOT NULL,           -- mtime when indexed
                verified_time INTEGER NOT NULL,      -- Last verification check
                file_hash TEXT,                      -- Optional: quick change detection
                entry_count INTEGER DEFAULT 0,       -- Number of code_index entries
                language TEXT,                       -- php, css, js, md, html, etc.
                FOREIGN KEY (project_root) REFERENCES project_roots(project_root) ON DELETE CASCADE
            )
        ");

        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_project
            ON file_metadata(project_root)
        ");

        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_verified
            ON file_metadata(project_root, verified_time)
        ");

        // Real table containing all code entities with proper indexes
        $db->exec("
            CREATE TABLE IF NOT EXISTS code_entities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,

                -- Searchable content (will be indexed by FTS5)
                preamble TEXT,          -- Comments, docblocks, attributes, decorators
                signature TEXT,         -- Function/class/method declaration
                body TEXT,              -- Implementation code
                namespace TEXT,         -- Namespace/package/module path
                ext TEXT,               -- File extension (php, js, py, etc.)
                path TEXT,              -- Relative file path

                -- Original content for display
                preamble_raw TEXT,      -- Original preamble text
                signature_raw TEXT,     -- Original signature text

                -- Metadata
                type TEXT,              -- function, class, method, module, etc.
                filename TEXT NOT NULL, -- Full absolute path
                line_start INTEGER,     -- Start line number
                line_end INTEGER        -- End line number
            )
        ");

        // Index on filename for fast deletes and lookups
        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_entities_filename
            ON code_entities(filename)
        ");

        // Index on type for filtering queries
        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_entities_type
            ON code_entities(type)
        ");

        // FTS5 virtual table for full-text search (external content mode)
        // This references the real table and only stores the search index
        $db->exec("
            CREATE VIRTUAL TABLE IF NOT EXISTS code_index USING fts5(
                preamble,
                signature,
                body,
                namespace,
                ext,
                path,
                content='code_entities',      -- Points to real table
                content_rowid='id',           -- Maps to real table's id column
                tokenize='unicode61'          -- Simple tokenizer, we handle semantics in PHP
            )
        ");

        // Triggers to keep FTS5 index in sync with real table
        $db->exec("
            CREATE TRIGGER IF NOT EXISTS code_entities_ai AFTER INSERT ON code_entities BEGIN
                INSERT INTO code_index(rowid, preamble, signature, body, namespace, ext, path)
                VALUES (new.id, new.preamble, new.signature, new.body, new.namespace, new.ext, new.path);
            END;
        ");

        $db->exec("
            CREATE TRIGGER IF NOT EXISTS code_entities_ad AFTER DELETE ON code_entities BEGIN
                INSERT INTO code_index(code_index, rowid, preamble, signature, body, namespace, ext, path)
                VALUES('delete', old.id, '', '', '', '', '', '');
            END;
        ");

        $db->exec("
            CREATE TRIGGER IF NOT EXISTS code_entities_au AFTER UPDATE ON code_entities BEGIN
                INSERT INTO code_index(code_index, rowid, preamble, signature, body, namespace, ext, path)
                VALUES('delete', old.id, '', '', '', '', '', '');
                INSERT INTO code_index(rowid, preamble, signature, body, namespace, ext, path)
                VALUES (new.id, new.preamble, new.signature, new.body, new.namespace, new.ext, new.path);
            END;
        ");

        // Set schema version
        self::setSchemaVersion($db, self::SCHEMA_VERSION);
    }

    /**
     * Get current schema version
     */
    public static function getSchemaVersion(DB $db): int
    {
        try {
            $version = $db->queryValue("SELECT version FROM schema_version ORDER BY version DESC LIMIT 1");
            return $version !== false ? (int)$version : 1; // Default to version 1 for old databases
        } catch (\PDOException $e) {
            // Table doesn't exist, assume version 1
            return 1;
        }
    }

    /**
     * Set schema version
     */
    private static function setSchemaVersion(DB $db, int $version): void
    {
        $db->execute("
            INSERT OR REPLACE INTO schema_version (version, updated_at)
            VALUES (?, ?)
        ", [$version, time()]);
    }

    /**
     * Migrate schema from old version to current
     */
    public static function migrateSchema(DB $db, int $fromVersion): void
    {
        if ($fromVersion < 2) {
            // Migration from version 1 to version 2:
            // Schema changed significantly (entity_name_tokens -> preamble/signature/body/namespace/ext/path)
            // Safest approach: drop and recreate code_index, then require reindexing

            echo "Migrating database schema from version $fromVersion to 2...\n";

            // Drop old FTS5 table
            $db->exec("DROP TABLE IF EXISTS code_index");

            // Recreate with new schema
            $db->exec("
                CREATE VIRTUAL TABLE code_index USING fts5(
                    -- Searchable content (tokenized with custom tokenizer)
                    preamble,       -- Comments, docblocks, attributes, decorators
                    signature,      -- Function/class/method declaration
                    body,           -- Implementation code
                    namespace,      -- Namespace/package/module path
                    ext,            -- File extension (php, js, py, etc.)
                    path,           -- Relative file path (/ tokenized as _47_)

                    -- Original content for display (UNINDEXED)
                    preamble_raw UNINDEXED,   -- Original preamble text
                    signature_raw UNINDEXED,  -- Original signature text

                    -- Metadata (UNINDEXED - for lookups and display)
                    type UNINDEXED,         -- function, class, method, module, etc.
                    filename UNINDEXED,     -- Full absolute path
                    line_start UNINDEXED,   -- Start line number
                    line_end UNINDEXED,     -- End line number

                    tokenize='unicode61'  -- Simple tokenizer, we handle semantics in PHP
                )
            ");

            // Clear last_indexed timestamps to trigger reindexing
            $db->exec("UPDATE project_roots SET last_indexed = NULL");

            // Clear file metadata (entries no longer match)
            $db->exec("DELETE FROM file_metadata");

            echo "Migration to version 2 complete.\n";
        }

        if ($fromVersion < 3) {
            // Migration from version 2 to version 3:
            // Remove marker_type and primary_language columns from project_roots
            // SQLite doesn't support DROP COLUMN, so we need to recreate the table

            echo "Migrating database schema from version " . max(2, $fromVersion) . " to 3...\n";

            // Create new table
            $db->exec("
                CREATE TABLE project_roots_new (
                    project_root TEXT PRIMARY KEY,
                    project_name TEXT,
                    registered_at INTEGER NOT NULL,
                    last_indexed INTEGER,
                    last_accessed INTEGER
                )
            ");

            // Copy data
            $db->exec("
                INSERT INTO project_roots_new (project_root, project_name, registered_at, last_indexed, last_accessed)
                SELECT project_root, project_name, registered_at, last_indexed, last_accessed
                FROM project_roots
            ");

            // Drop old table
            $db->exec("DROP TABLE project_roots");

            // Rename new table
            $db->exec("ALTER TABLE project_roots_new RENAME TO project_roots");

            echo "Migration to version 3 complete.\n";
        }

        if ($fromVersion < 4) {
            // Migration from version 3 to version 4:
            // Add last_update_check column to project_roots
            // SQLite doesn't support ADD COLUMN with NOT NULL and no default, so recreate table

            echo "Migrating database schema from version " . max(3, $fromVersion) . " to 4...\n";

            // Create new table
            $db->exec("
                CREATE TABLE project_roots_new (
                    project_root TEXT PRIMARY KEY,
                    project_name TEXT,
                    registered_at INTEGER NOT NULL,
                    last_indexed INTEGER,
                    last_accessed INTEGER,
                    last_update_check INTEGER
                )
            ");

            // Copy data (last_update_check will be NULL for existing projects)
            $db->exec("
                INSERT INTO project_roots_new (project_root, project_name, registered_at, last_indexed, last_accessed, last_update_check)
                SELECT project_root, project_name, registered_at, last_indexed, last_accessed, NULL
                FROM project_roots
            ");

            // Drop old table
            $db->exec("DROP TABLE project_roots");

            // Rename new table
            $db->exec("ALTER TABLE project_roots_new RENAME TO project_roots");

            echo "Migration to version 4 complete.\n";
        }

        // Update schema version
        self::setSchemaVersion($db, self::SCHEMA_VERSION);
    }

    /**
     * Drop all tables (for testing/reset)
     */
    public static function dropSchema(DB $db): void
    {
        $db->exec("DROP TABLE IF EXISTS code_index");
        $db->exec("DROP TABLE IF EXISTS file_metadata");
        $db->exec("DROP TABLE IF EXISTS project_roots");
    }

    /**
     * Register a project root
     *
     * @param DB $db
     * @param string $projectRoot Absolute path
     */
    public static function registerProjectRoot(
        DB $db,
        string $projectRoot
    ): void {
        $projectName = basename($projectRoot);
        $now = time();

        $db->execute("
            INSERT INTO project_roots (project_root, project_name, registered_at, last_accessed)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(project_root) DO UPDATE SET
                last_accessed = ?
        ", [
            $projectRoot,
            $projectName,
            $now,
            $now,
            $now
        ]);
    }

    /**
     * Update last indexed timestamp for project
     */
    public static function updateLastIndexed(DB $db, string $projectRoot): void
    {
        $db->execute("
            UPDATE project_roots
            SET last_indexed = ?, last_accessed = ?
            WHERE project_root = ?
        ", [time(), time(), $projectRoot]);
    }

    /**
     * Get all registered project roots
     *
     * @return array<array{project_root: string, project_name: string, last_indexed: ?int}>
     */
    public static function getProjectRoots(DB $db): array
    {
        return $db->query("
            SELECT project_root, project_name, registered_at, last_indexed, last_accessed
            FROM project_roots
            ORDER BY last_accessed DESC
        ");
    }

    /**
     * Check if a project root is registered
     */
    public static function isProjectRegistered(DB $db, string $projectRoot): bool
    {
        return (bool)$db->queryValue("SELECT 1 FROM project_roots WHERE project_root = ?", [$projectRoot]);
    }

    /**
     * Delete all entries for a specific project
     */
    public static function deleteProject(DB $db, string $projectRoot): void
    {
        $output = $GLOBALS['fubber_output'] ?? null;

        // Get all filenames for this project
        if ($output) $output->debug(2, "Querying file_metadata for project files...");
        $filenames = $db->queryColumn("SELECT filename FROM file_metadata WHERE project_root = ?", [$projectRoot]);

        if ($output) $output->debug(2, "Found {count} files to delete", ['count' => count($filenames)]);

        // Delete using batch delete for efficiency
        self::deleteFiles($db, $filenames);
    }

    /**
     * Delete all entries for a specific file
     */
    public static function deleteFile(DB $db, string $filename): void
    {
        // Get rowids before deleting (needed for FTS5 sync)
        $rowids = $db->queryColumn("SELECT id FROM code_entities WHERE filename = ?", [$filename]);

        // Delete from real table (indexed on filename - fast!)
        $db->execute("DELETE FROM code_entities WHERE filename = ?", [$filename]);

        // Sync FTS5 index (tell it which rowids were deleted)
        foreach ($rowids as $rowid) {
            $db->execute("INSERT INTO code_index(code_index, rowid, preamble, signature, body, namespace, ext, path) VALUES('delete', ?, '', '', '', '', '', '')", [$rowid]);
        }

        // Delete from file_metadata
        $db->execute("DELETE FROM file_metadata WHERE filename = ?", [$filename]);
    }

    /**
     * Delete all entries for multiple files (batch operation)
     * Much faster than calling deleteFile() repeatedly because it uses IN clause
     *
     * @param DB $db
     * @param array $filenames Array of absolute file paths
     */
    public static function deleteFiles(DB $db, array $filenames): void
    {
        if (empty($filenames)) {
            return;
        }

        $output = $GLOBALS['fubber_output'] ?? null;

        // SQLite has a limit on the number of variables in a query (default 999)
        // Process in chunks to stay under the limit
        $chunkSize = 500;
        $chunks = array_chunk($filenames, $chunkSize);

        if ($output) $output->debug(2, "Deleting {total} files in {chunks} chunks...", [
            'total' => count($filenames),
            'chunks' => count($chunks)
        ]);

        foreach ($chunks as $chunkIndex => $chunk) {
            if ($output) $output->debug(3, "Processing chunk {index}/{total} ({size} files)...", [
                'index' => $chunkIndex + 1,
                'total' => count($chunks),
                'size' => count($chunk)
            ]);

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            // Get all rowids before deleting (needed for FTS5 sync)
            if ($output) $output->debug(3, "  Fetching rowids...");
            $rowids = $db->queryColumn("SELECT id FROM code_entities WHERE filename IN ($placeholders)", $chunk);
            if ($output) $output->debug(3, "  Found {count} entities to delete", ['count' => count($rowids)]);

            // Delete from real table (trigger will sync FTS5 automatically)
            if ($output) $output->debug(3, "  Deleting {count} entities from code_entities...", ['count' => count($rowids)]);
            $db->execute("DELETE FROM code_entities WHERE filename IN ($placeholders)", $chunk);

            // Delete from file_metadata
            if ($output) $output->debug(3, "  Deleting from file_metadata...");
            $db->execute("DELETE FROM file_metadata WHERE filename IN ($placeholders)", $chunk);

            if ($output) $output->debug(3, "  Chunk complete");
        }

        if ($output) $output->debug(2, "All deletions complete");
    }
}
