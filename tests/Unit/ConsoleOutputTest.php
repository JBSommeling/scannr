<?php

namespace Tests\Unit;

use App\Contracts\OutputInterface;
use App\Services\Output\ConsoleOutput;
use Illuminate\Console\Command;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface as SymfonyOutputInterface;

class ConsoleOutputTest extends TestCase
{
    private function createMockCommand(): Command
    {
        return $this->createMock(Command::class);
    }

    // ==================
    // Constructor tests
    // ==================

    public function test_console_output_implements_output_interface(): void
    {
        $command = $this->createMockCommand();
        $output = new ConsoleOutput($command);

        $this->assertInstanceOf(OutputInterface::class, $output);
    }

    // ==================
    // info tests
    // ==================

    public function test_info_delegates_to_command(): void
    {
        $command = $this->createMockCommand();
        $command->expects($this->once())
            ->method('info')
            ->with('Test message');

        $output = new ConsoleOutput($command);
        $output->info('Test message');
    }

    // ==================
    // warn tests
    // ==================

    public function test_warn_delegates_to_command(): void
    {
        $command = $this->createMockCommand();
        $command->expects($this->once())
            ->method('warn')
            ->with('Warning message');

        $output = new ConsoleOutput($command);
        $output->warn('Warning message');
    }

    // ==================
    // error tests
    // ==================

    public function test_error_delegates_to_command(): void
    {
        $command = $this->createMockCommand();
        $command->expects($this->once())
            ->method('error')
            ->with('Error message');

        $output = new ConsoleOutput($command);
        $output->error('Error message');
    }

    // ==================
    // line tests
    // ==================

    public function test_line_delegates_to_command(): void
    {
        $command = $this->createMockCommand();
        $command->expects($this->once())
            ->method('line')
            ->with('Line message');

        $output = new ConsoleOutput($command);
        $output->line('Line message');
    }

    public function test_line_with_empty_string(): void
    {
        $command = $this->createMockCommand();
        $command->expects($this->once())
            ->method('line')
            ->with('');

        $output = new ConsoleOutput($command);
        $output->line();
    }

    // ==================
    // table tests
    // ==================

    public function test_table_delegates_to_command(): void
    {
        $headers = ['Name', 'Value'];
        $rows = [['foo', 'bar'], ['baz', 'qux']];

        $command = $this->createMockCommand();
        $command->expects($this->once())
            ->method('table')
            ->with($headers, $rows);

        $output = new ConsoleOutput($command);
        $output->table($headers, $rows);
    }

    // ==================
    // newLine tests
    // ==================

    public function test_new_line_delegates_to_command(): void
    {
        $command = $this->createMockCommand();
        $command->expects($this->once())
            ->method('newLine')
            ->with(1);

        $output = new ConsoleOutput($command);
        $output->newLine();
    }

    public function test_new_line_with_count(): void
    {
        $command = $this->createMockCommand();
        $command->expects($this->once())
            ->method('newLine')
            ->with(3);

        $output = new ConsoleOutput($command);
        $output->newLine(3);
    }

    // ==================
    // isVerbose tests
    // ==================

    public function test_is_verbose_returns_false_by_default(): void
    {
        $symfonyOutput = $this->createMock(SymfonyOutputInterface::class);
        $symfonyOutput->method('isVerbose')->willReturn(false);

        $command = $this->createMockCommand();
        $command->method('getOutput')->willReturn($symfonyOutput);

        $output = new ConsoleOutput($command);

        $this->assertFalse($output->isVerbose());
    }

    public function test_is_verbose_returns_true_when_verbose(): void
    {
        $symfonyOutput = $this->createMock(SymfonyOutputInterface::class);
        $symfonyOutput->method('isVerbose')->willReturn(true);

        $command = $this->createMockCommand();
        $command->method('getOutput')->willReturn($symfonyOutput);

        $output = new ConsoleOutput($command);

        $this->assertTrue($output->isVerbose());
    }
}
