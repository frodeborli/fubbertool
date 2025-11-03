<?php

namespace FubberTool;

/**
 * Progress bar for tracking task progress
 *
 * Adapts behavior based on TTY:
 * - TTY: Updates in place using \r with visual bar
 * - Non-TTY: Outputs progress at intervals (10%, 20%, etc.)
 */
class ProgressBar implements ProgressIndicatorInterface
{
    private $stream;
    private bool $isTty;
    private int $total;
    private int $current = 0;
    private string $label;
    private int $barWidth;
    private bool $finished = false;
    private ?Output $output;

    // For non-TTY: track last reported percentage to avoid spam
    private int $lastReportedPercent = -1;
    private int $reportInterval = 10; // Report every 10%

    // Time estimation
    private float $startTime;
    private bool $showTimeEstimate = true;

    public function __construct($stream, bool $isTty, int $total, string $label = '', int $barWidth = 30, ?Output $output = null)
    {
        $this->stream = $stream;
        $this->isTty = $isTty;
        $this->total = max(1, $total); // Avoid division by zero
        $this->label = $label;
        $this->barWidth = $barWidth;
        $this->startTime = microtime(true);
        $this->output = $output;

        // Register with Output instance if provided
        if ($this->output) {
            $this->output->registerIndicator($this);
        }

        // Initial render
        $this->render();
    }

    /**
     * Set the total (useful when total changes during processing)
     */
    public function setTotal(int $total): void
    {
        $this->total = max(1, $total);
        $this->render();
    }

    /**
     * Increase the total by a given amount
     */
    public function increaseTotal(int $amount): void
    {
        $this->total += $amount;
        $this->render();
    }

    /**
     * Update progress to a specific value
     */
    public function update(int $current): void
    {
        if ($this->finished) {
            return;
        }

        $this->current = min($current, $this->total);
        $this->render();
    }

    /**
     * Advance progress by a given amount (default: 1)
     */
    public function advance(int $step = 1): void
    {
        $this->update($this->current + $step);
    }

    /**
     * Set progress to 100% and finalize
     */
    public function finish(): void
    {
        if ($this->finished) {
            return;
        }

        $this->current = $this->total;
        $this->finished = true;
        $this->render();

        // Add newline to move to next line
        fwrite($this->stream, "\n");

        // Unregister from Output instance
        if ($this->output) {
            $this->output->unregisterIndicator();
        }
    }

    /**
     * Clear the progress bar from display (implementation of ProgressIndicatorInterface)
     */
    public function clear(): void
    {
        if ($this->isTty) {
            fwrite($this->stream, "\r\033[K");
            fflush($this->stream);
        }
    }

    /**
     * Re-render the progress bar (implementation of ProgressIndicatorInterface)
     */
    public function rerender(): void
    {
        $this->render();
    }

    /**
     * Render the progress bar
     */
    private function render(): void
    {
        if ($this->isTty) {
            $this->renderTty();
        } else {
            $this->renderNonTty();
        }
    }

    /**
     * Render progress bar for TTY (updates in place)
     */
    private function renderTty(): void
    {
        $percent = $this->total > 0 ? (int)(($this->current / $this->total) * 100) : 100;
        $filled = (int)(($this->current / $this->total) * $this->barWidth);
        $empty = $this->barWidth - $filled;

        // Build the bar
        $bar = str_repeat('=', max(0, $filled - 1));
        if ($filled > 0) {
            $bar .= '>';
        }
        $bar .= str_repeat(' ', max(0, $empty));

        // Build the full line
        $line = "\r";
        if ($this->label) {
            $line .= $this->label . ' ';
        }
        $line .= "[{$bar}] {$percent}% ({$this->current}/{$this->total})";

        // Add time estimate if applicable
        if ($this->showTimeEstimate && !$this->finished) {
            $timeEstimate = $this->getTimeEstimate();
            if ($timeEstimate !== null) {
                $line .= " ETA: {$timeEstimate}";
            }
        }

        fwrite($this->stream, $line);
        fflush($this->stream);
    }

    /**
     * Calculate time estimate
     * Returns formatted string if conditions are met, null otherwise
     */
    private function getTimeEstimate(): ?string
    {
        $elapsed = microtime(true) - $this->startTime;

        // Only show estimate after 5 seconds have passed
        if ($elapsed < 5.0) {
            return null;
        }

        // Avoid division by zero
        if ($this->current === 0) {
            return null;
        }

        // Calculate estimated total time
        $rate = $this->current / $elapsed;
        $estimatedTotal = $this->total / $rate;

        // Only show if estimated total is > 10 seconds
        if ($estimatedTotal < 10.0) {
            return null;
        }

        // Calculate remaining time
        $remaining = $estimatedTotal - $elapsed;
        if ($remaining < 0) {
            return null;
        }

        return $this->formatTime($remaining);
    }

    /**
     * Format time in human-readable format
     */
    private function formatTime(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%ds', (int)$seconds);
        } elseif ($seconds < 3600) {
            $minutes = (int)($seconds / 60);
            $secs = (int)($seconds % 60);
            return sprintf('%dm%ds', $minutes, $secs);
        } else {
            $hours = (int)($seconds / 3600);
            $minutes = (int)(($seconds % 3600) / 60);
            return sprintf('%dh%dm', $hours, $minutes);
        }
    }

    /**
     * Render progress for non-TTY (outputs at intervals)
     */
    private function renderNonTty(): void
    {
        $percent = $this->total > 0 ? (int)(($this->current / $this->total) * 100) : 100;

        // Only output when crossing interval thresholds or at completion
        $shouldOutput = false;

        if ($this->finished) {
            $shouldOutput = true;
        } elseif ($percent >= $this->lastReportedPercent + $this->reportInterval) {
            $shouldOutput = true;
        } elseif ($this->lastReportedPercent === -1) {
            // First update
            $shouldOutput = true;
        }

        if ($shouldOutput) {
            $this->lastReportedPercent = $percent;

            $line = '';
            if ($this->label) {
                $line .= $this->label . ' ';
            }
            $line .= "{$percent}%";
            if ($this->finished) {
                $line .= " ({$this->current}/{$this->total})";
            }
            $line .= "\n";

            fwrite($this->stream, $line);
        }
    }

    /**
     * Get current progress value
     */
    public function getCurrent(): int
    {
        return $this->current;
    }

    /**
     * Get total value
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * Get progress percentage (0-100)
     */
    public function getPercent(): int
    {
        return $this->total > 0 ? (int)(($this->current / $this->total) * 100) : 100;
    }

    /**
     * Check if progress is complete
     */
    public function isFinished(): bool
    {
        return $this->finished;
    }
}
