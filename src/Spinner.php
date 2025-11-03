<?php

namespace FubberTool;

/**
 * Spinner for unbounded progress (when total is unknown)
 *
 * Adapts behavior based on TTY:
 * - TTY: Animated spinner with status text
 * - Non-TTY: Outputs status updates at intervals
 */
class Spinner implements ProgressIndicatorInterface
{
    private $stream;
    private bool $isTty;
    private string $message;
    private bool $finished = false;
    private ?Output $output;

    // Spinner animation frames
    private array $frames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    private int $currentFrame = 0;

    // For non-TTY: track last update time to avoid spam
    private float $lastUpdateTime = 0;
    private float $updateInterval = 2.0; // Update every 2 seconds

    public function __construct($stream, bool $isTty, string $message = '', ?Output $output = null)
    {
        $this->stream = $stream;
        $this->isTty = $isTty;
        $this->message = $message;
        $this->lastUpdateTime = microtime(true);
        $this->output = $output;

        // Register with Output instance if provided
        if ($this->output) {
            $this->output->registerIndicator($this);
        }

        // Initial render
        $this->render();
    }

    /**
     * Update spinner message
     */
    public function setMessage(string $message): void
    {
        if ($this->finished) {
            return;
        }

        $this->message = $message;
        $this->render();
    }

    /**
     * Advance spinner animation (call this periodically)
     */
    public function tick(): void
    {
        if ($this->finished) {
            return;
        }

        $this->currentFrame = ($this->currentFrame + 1) % count($this->frames);
        $this->render();
    }

    /**
     * Finish spinner and move to next line
     */
    public function finish(string $finalMessage = ''): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;

        if ($this->isTty) {
            // Clear the line and show final message
            fwrite($this->stream, "\r\033[K");
            if ($finalMessage) {
                fwrite($this->stream, $finalMessage . "\n");
            }
        } else {
            // Just output final message
            if ($finalMessage) {
                fwrite($this->stream, $finalMessage . "\n");
            }
        }

        // Unregister from Output instance
        if ($this->output) {
            $this->output->unregisterIndicator();
        }
    }

    /**
     * Clear the spinner from display (implementation of ProgressIndicatorInterface)
     */
    public function clear(): void
    {
        if ($this->isTty) {
            fwrite($this->stream, "\r\033[K");
            fflush($this->stream);
        }
    }

    /**
     * Re-render the spinner (implementation of ProgressIndicatorInterface)
     */
    public function rerender(): void
    {
        $this->render();
    }

    /**
     * Check if spinner is finished (implementation of ProgressIndicatorInterface)
     */
    public function isFinished(): bool
    {
        return $this->finished;
    }

    /**
     * Render the spinner
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
     * Render spinner for TTY (animated)
     */
    private function renderTty(): void
    {
        $frame = $this->frames[$this->currentFrame];
        $line = "\r{$frame} {$this->message}";
        fwrite($this->stream, $line);
        fflush($this->stream);
    }

    /**
     * Render spinner for non-TTY (periodic updates)
     */
    private function renderNonTty(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastUpdateTime;

        // Only output at intervals to avoid spam
        if ($elapsed >= $this->updateInterval) {
            fwrite($this->stream, $this->message . "\n");
            $this->lastUpdateTime = $now;
        }
    }
}
