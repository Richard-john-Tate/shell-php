<?php
namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests.
 *
 * Spawns a real shell process, sends commands, and captures output.
 */
abstract class ShellTestCase extends TestCase
{
    private mixed $process = null;
    private mixed $stdin   = null;
    private mixed $stdout  = null;

    protected function setUp(): void
    {
        $desc = [
            0 => ['pipe', 'r'],  // shell's stdin  — we write here
            1 => ['pipe', 'w'],  // shell's stdout — we read here
            2 => ['pipe', 'w'],  // shell's stderr — ignored / checked per-test
        ];

        $this->process = proc_open(
            [PHP_BINARY, 'app/main.php'],
            $desc,
            $pipes,
            dirname(__DIR__, 2)   // working dir = repo root
        );

        $this->stdin  = $pipes[0];
        $this->stdout = $pipes[1];

        stream_set_blocking($this->stdout, false);

        // Wait for the initial prompt
        $this->readUntilPrompt();
    }

    protected function tearDown(): void
    {
        if ($this->stdin)   fclose($this->stdin);
        if ($this->stdout)  fclose($this->stdout);
        if ($this->process) proc_close($this->process);
    }

    /**
     * Send a command to the shell (appends \n automatically).
     */
    protected function send(string $command): void
    {
        fwrite($this->stdin, $command . "\n");
    }

    /**
     * Send a command and return everything printed before the next prompt.
     */
    protected function runCommand(string $command): string
    {
        $this->send($command);
        return $this->readUntilPrompt();
    }

    /**
     * Read output until we see the "$ " prompt, then return the output without it.
     */
    protected function readUntilPrompt(float $timeoutSec = 3.0): string
    {
        $buffer  = '';
        $deadline = microtime(true) + $timeoutSec;

        while (microtime(true) < $deadline) {
            $chunk = fread($this->stdout, 4096);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
                if (str_ends_with($buffer, '$ ')) {
                    // Strip the trailing prompt
                    return substr($buffer, 0, -2);
                }
            } else {
                usleep(10_000); // 10 ms
            }
        }

        $this->fail("Timed out waiting for shell prompt. Buffer so far: " . json_encode($buffer));
    }

    /**
     * Strip the echoed command line from the output (readline echoes input).
     * Returns only the lines produced by the command.
     */
    protected function outputOf(string $raw): string
    {
        // readline echoes the command on the first line; drop it
        $lines = explode("\n", $raw);
        array_shift($lines);
        return implode("\n", $lines);
    }
}
