<?php

namespace FubberTool;

/**
 * Output utility for rendering formatted content
 *
 * Adapts output based on TTY detection:
 * - TTY (human users): Formatted tables, colors, proper padding
 * - Non-TTY (LLM): Markdown tables, plain text, no colors
 */
class Output
{
    private $stream;
    private $errorStream;
    private bool $isTty;
    private int $terminalWidth;
    private int $verbosity;

    // Active progress indicator (progress bar or spinner)
    private ?ProgressIndicatorInterface $activeIndicator = null;

    // ANSI color codes
    private const COLOR_YELLOW = "\033[33m";
    private const COLOR_BRIGHT_WHITE = "\033[97m";
    private const COLOR_GRAY = "\033[90m";
    private const COLOR_RED = "\033[91m";
    private const COLOR_RESET = "\033[0m";

    // Verbosity levels
    public const VERBOSITY_NORMAL = 0;
    public const VERBOSITY_VERBOSE = 1;    // -v
    public const VERBOSITY_VERY_VERBOSE = 2; // -vv
    public const VERBOSITY_DEBUG = 3;      // -vvv

    public function __construct($stream = STDOUT, int $verbosity = self::VERBOSITY_NORMAL, $errorStream = STDERR)
    {
        $this->stream = $stream;
        $this->errorStream = $errorStream;
        $this->isTty = $this->detectTty($stream);
        $this->terminalWidth = $this->detectTerminalWidth();
        $this->verbosity = $verbosity;
    }

    /**
     * Detect if output stream is a TTY
     */
    private function detectTty($stream): bool
    {
        if (!is_resource($stream)) {
            return false;
        }

        return @stream_isatty($stream) === true;
    }

    /**
     * Detect terminal width for TTY output
     */
    private function detectTerminalWidth(): int
    {
        if (!$this->isTty) {
            return 0; // Not relevant for non-TTY
        }

        // Try tput first
        $width = @exec('tput cols 2>/dev/null');
        if ($width && is_numeric($width)) {
            return (int)$width;
        }

        // Try stty as fallback
        $stty = @exec('stty size 2>/dev/null');
        if ($stty && preg_match('/\d+ (\d+)/', $stty, $matches)) {
            return (int)$matches[1];
        }

        // Default fallback
        return 80;
    }

    /**
     * Render a table with adaptive formatting
     *
     * @param array<string> $headings Column headings
     * @param array<array<string>> $rows Data rows (each row is an array of column values)
     */
    public function table(array $headings, array $rows): void
    {
        if (empty($headings)) {
            return;
        }

        $this->interruptIfNeeded();

        if ($this->isTty) {
            $this->renderTtyTable($headings, $rows);
        } else {
            $this->renderMarkdownTable($headings, $rows);
        }

        $this->rerenderIfNeeded();
    }

    /**
     * Render a formatted table for TTY (human-readable with padding)
     */
    private function renderTtyTable(array $headings, array $rows): void
    {
        $numColumns = count($headings);

        // Calculate column widths
        $columnWidths = [];
        foreach ($headings as $i => $heading) {
            $columnWidths[$i] = mb_strlen($heading);
        }

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $cellLength = mb_strlen($cell);
                if ($cellLength > ($columnWidths[$i] ?? 0)) {
                    $columnWidths[$i] = $cellLength;
                }
            }
        }

        // Apply max width constraints based on terminal width
        $totalWidth = array_sum($columnWidths) + ($numColumns - 1) * 3; // 3 = " | " separator
        if ($totalWidth > $this->terminalWidth && $this->terminalWidth > 0) {
            // If table is too wide, apply truncation to longest columns
            $columnWidths = $this->constrainColumnWidths($columnWidths, $numColumns);
        }

        // Render header
        $headerLine = '';
        foreach ($headings as $i => $heading) {
            if ($i > 0) {
                $headerLine .= ' | ';
            }
            $headerLine .= str_pad($heading, $columnWidths[$i]);
        }
        fwrite($this->stream, $headerLine . "\n");

        // Render separator
        $separatorLine = '';
        foreach ($headings as $i => $heading) {
            if ($i > 0) {
                $separatorLine .= '-+-';
            }
            $separatorLine .= str_repeat('-', $columnWidths[$i]);
        }
        fwrite($this->stream, $separatorLine . "\n");

        // Render rows
        foreach ($rows as $row) {
            $rowLine = '';
            foreach ($row as $i => $cell) {
                if ($i > 0) {
                    $rowLine .= ' | ';
                }

                // Truncate with ellipsis if needed
                $maxWidth = $columnWidths[$i];
                if (mb_strlen($cell) > $maxWidth) {
                    $cell = mb_substr($cell, 0, $maxWidth - 3) . '...';
                }

                $rowLine .= str_pad($cell, $maxWidth);
            }
            fwrite($this->stream, $rowLine . "\n");
        }
    }

    /**
     * Constrain column widths to fit terminal width
     */
    private function constrainColumnWidths(array $columnWidths, int $numColumns): array
    {
        $separatorWidth = ($numColumns - 1) * 3; // " | " separators
        $availableWidth = $this->terminalWidth - $separatorWidth - 2; // -2 for margins

        // Calculate proportional widths
        $totalWidth = array_sum($columnWidths);
        $constrained = [];

        foreach ($columnWidths as $i => $width) {
            $proportion = $width / $totalWidth;
            $newWidth = max(10, (int)($availableWidth * $proportion)); // Minimum 10 chars
            $constrained[$i] = $newWidth;
        }

        return $constrained;
    }

    /**
     * Render a markdown table (for LLM consumption)
     */
    private function renderMarkdownTable(array $headings, array $rows): void
    {
        // Header
        fwrite($this->stream, '|' . implode('|', $headings) . "|\n");

        // Separator
        $separators = array_map(fn() => '---', $headings);
        fwrite($this->stream, '|' . implode('|', $separators) . "|\n");

        // Rows
        foreach ($rows as $row) {
            fwrite($this->stream, '|' . implode('|', $row) . "|\n");
        }
    }

    /**
     * Render a title
     *
     * @param string $title The title text
     */
    public function title(string $title): void
    {
        $this->interruptIfNeeded();

        if ($this->isTty) {
            fwrite($this->stream, self::COLOR_BRIGHT_WHITE . $title . self::COLOR_RESET . "\n");
        } else {
            fwrite($this->stream, "# $title\n");
        }

        $this->rerenderIfNeeded();
    }

    /**
     * Output a subtitle/section header
     *
     * @param string $subtitle The subtitle text
     */
    public function subtitle(string $subtitle): void
    {
        $this->interruptIfNeeded();

        if ($this->isTty) {
            fwrite($this->stream, self::COLOR_BRIGHT_WHITE . $subtitle . self::COLOR_RESET . "\n");
        } else {
            fwrite($this->stream, "## $subtitle\n");
        }

        $this->rerenderIfNeeded();
    }

    /**
     * Render a usage list (commands or options)
     *
     * @param string $listTitle The list title (e.g., "COMMANDS:")
     * @param array<array{0: string, 1: string}> $items Array of [command, description] pairs
     */
    public function usageList(string $listTitle, array $items): void
    {
        $this->interruptIfNeeded();

        fwrite($this->stream, $listTitle . "\n");

        if ($this->isTty) {
            // Calculate padding width for alignment
            $maxCommandWidth = 0;
            foreach ($items as [$command, $_]) {
                $commandWidth = mb_strlen($command);
                if ($commandWidth > $maxCommandWidth) {
                    $maxCommandWidth = $commandWidth;
                }
            }

            // Apply minimum column width based on terminal width (35% of terminal)
            // This ensures consistent alignment across consecutive usageList() calls
            if ($this->terminalWidth > 0) {
                $minColumnWidth = (int)($this->terminalWidth * 0.35);
                $maxCommandWidth = max($maxCommandWidth, $minColumnWidth);
            }

            // Cap at maximum 45 characters to prevent overly wide first column
            $maxCommandWidth = min($maxCommandWidth, 45);

            // Render with padding
            foreach ($items as [$command, $description]) {
                $padding = str_repeat(' ', $maxCommandWidth - mb_strlen($command) + 2);
                fwrite($this->stream, "  $command$padding$description\n");
            }
        } else {
            // Simple list for LLM
            foreach ($items as [$command, $description]) {
                fwrite($this->stream, "  $command - $description\n");
            }
        }

        $this->rerenderIfNeeded();
    }

    /**
     * Render a warning message
     *
     * @param string $message Warning message
     */
    public function warn(string $message): void
    {
        $this->interruptIfNeeded();

        if ($this->isTty) {
            fwrite($this->stream, self::COLOR_YELLOW . $message . self::COLOR_RESET . "\n");
        } else {
            fwrite($this->stream, $message . "\n");
        }

        $this->rerenderIfNeeded();
    }

    /**
     * Render an error message to STDERR
     *
     * @param string $message Error message
     */
    public function error(string $message): void
    {
        $this->interruptIfNeeded();

        if ($this->isTty) {
            fwrite($this->errorStream, self::COLOR_RED . $message . self::COLOR_RESET . "\n");
        } else {
            fwrite($this->errorStream, $message . "\n");
        }

        $this->rerenderIfNeeded();
    }

    /**
     * Register an active progress indicator
     * @internal Called by ProgressBar and Spinner
     */
    public function registerIndicator(ProgressIndicatorInterface $indicator): void
    {
        $this->activeIndicator = $indicator;
    }

    /**
     * Unregister the active progress indicator
     * @internal Called by ProgressBar and Spinner on finish
     */
    public function unregisterIndicator(): void
    {
        $this->activeIndicator = null;
    }

    /**
     * Interrupt active progress indicator to output content
     */
    private function interruptIfNeeded(): void
    {
        if ($this->activeIndicator && !$this->activeIndicator->isFinished()) {
            $this->activeIndicator->clear();
        }
    }

    /**
     * Re-render active progress indicator after interruption
     */
    private function rerenderIfNeeded(): void
    {
        if ($this->activeIndicator && !$this->activeIndicator->isFinished()) {
            $this->activeIndicator->rerender();
        }
    }

    /**
     * Write plain text to output
     *
     * @param string $text Text to output
     */
    public function write(string $text): void
    {
        $this->interruptIfNeeded();
        fwrite($this->stream, $text);
        $this->rerenderIfNeeded();
    }

    /**
     * Write a line of plain text to output
     *
     * @param string $text Text to output
     */
    public function writeln(string $text = ''): void
    {
        $this->interruptIfNeeded();
        fwrite($this->stream, $text . "\n");
        $this->rerenderIfNeeded();
    }

    /**
     * Check if output is a TTY
     */
    public function isTty(): bool
    {
        return $this->isTty;
    }

    /**
     * Create a progress bar
     *
     * @param int $total Total number of items
     * @param string $label Label to display (optional)
     * @param int $barWidth Width of the progress bar in characters (TTY only)
     * @return ProgressBar
     */
    public function progressBar(int $total, string $label = '', int $barWidth = 30): ProgressBar
    {
        return new ProgressBar($this->stream, $this->isTty, $total, $label, $barWidth, $this);
    }

    /**
     * Create a spinner for unbounded progress
     *
     * @param string $message Initial message to display
     * @return Spinner
     */
    public function spinner(string $message = ''): Spinner
    {
        return new Spinner($this->stream, $this->isTty, $message, $this);
    }

    /**
     * Output debug message with verbosity level check
     *
     * @param int $level Required verbosity level (1=verbose, 2=very verbose, 3=debug)
     * @param string $message Debug message with {varName} placeholders
     * @param array<string,mixed> $vars Variables to interpolate into message
     */
    public function debug(int $level, string $message, array $vars = []): void
    {
        if ($this->verbosity < $level) {
            return;
        }

        // Interpolate variables
        if (!empty($vars)) {
            foreach ($vars as $key => $value) {
                $message = str_replace('{' . $key . '}', (string)$value, $message);
            }
        }

        $this->interruptIfNeeded();

        if ($this->isTty) {
            fwrite($this->stream, self::COLOR_GRAY . '[DEBUG] ' . $message . self::COLOR_RESET . "\n");
        } else {
            fwrite($this->stream, '[DEBUG] ' . $message . "\n");
        }

        $this->rerenderIfNeeded();
    }

    /**
     * Get current verbosity level
     */
    public function getVerbosity(): int
    {
        return $this->verbosity;
    }

    /**
     * Set verbosity level
     */
    public function setVerbosity(int $level): void
    {
        $this->verbosity = $level;
    }
}
