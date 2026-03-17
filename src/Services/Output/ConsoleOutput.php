<?php

namespace Scannr\Services\Output;

use Scannr\Contracts\OutputInterface;
use Illuminate\Console\Command;

/**
 * Console implementation of OutputInterface.
 *
 * Wraps a Laravel Command instance to provide output functionality.
 */
class ConsoleOutput implements OutputInterface
{
    public function __construct(
        protected Command $command
    ) {}

    /**
     * Output an informational message.
     */
    public function info(string $message): void
    {
        $this->command->info($message);
    }

    /**
     * Output a warning message.
     */
    public function warn(string $message): void
    {
        $this->command->warn($message);
    }

    /**
     * Output an error message.
     */
    public function error(string $message): void
    {
        $this->command->error($message);
    }

    /**
     * Output a plain line.
     */
    public function line(string $message = ''): void
    {
        $this->command->line($message);
    }

    /**
     * Output a table.
     *
     * @param  array<string>  $headers
     * @param  array<array<string, mixed>>  $rows
     */
    public function table(array $headers, array $rows): void
    {
        $this->command->table($headers, $rows);
    }

    /**
     * Output one or more new lines.
     */
    public function newLine(int $count = 1): void
    {
        $this->command->newLine($count);
    }

    /**
     * Check if verbose output is enabled.
     */
    public function isVerbose(): bool
    {
        return $this->command->getOutput()->isVerbose();
    }
}
