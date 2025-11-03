<?php

namespace FubberTool;

/**
 * Interface for progress indicators (progress bars, spinners, etc.)
 *
 * Allows Output class to manage active indicators and interrupt them
 * when other output needs to be displayed.
 */
interface ProgressIndicatorInterface
{
    /**
     * Clear the indicator from display (for TTY)
     */
    public function clear(): void;

    /**
     * Re-render the indicator after interruption
     */
    public function rerender(): void;

    /**
     * Check if the indicator is finished/complete
     */
    public function isFinished(): bool;
}
