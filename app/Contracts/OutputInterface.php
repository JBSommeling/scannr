<?php

namespace App\Contracts;

/**
 * Interface for output abstraction.
 *
 * Allows output to be directed to console, arrays, or other destinations.
 * This enables the same formatting logic to work for CLI and API responses.
 */
interface OutputInterface
{
    /**
     * Output an informational message.
     */
    public function info(string $message): void;

    /**
     * Output a warning message.
     */
    public function warn(string $message): void;

    /**
     * Output an error message.
     */
    public function error(string $message): void;

    /**
     * Output a plain line.
     */
    public function line(string $message = ''): void;

    /**
     * Output a table.
     *
     * @param array<string> $headers
     * @param array<array<string, mixed>> $rows
     */
    public function table(array $headers, array $rows): void;

    /**
     * Output one or more new lines.
     */
    public function newLine(int $count = 1): void;

    /**
     * Check if verbose output is enabled.
     */
    public function isVerbose(): bool;
}

