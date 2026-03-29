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
        // Redirect stderr to stdout so error messages are captured
        // The "2>&1" redirection is handled by bash
        $cmd = PHP_BINARY . ' app/main.php';
        $this->process = proc_open(
            "($cmd) 2>&1",
            [
                0 => ['pipe', 'r'],  // shell's stdin
                1 => ['pipe', 'w'],  // shell's stdout (includes stderr)
                2 => ['pipe', 'w'],  // shell's stderr — not used when 2>&1 is in cmd
            ],
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
        // In non-interactive mode (pipes), there's no command echo to strip.
        // The output is exactly what the command produced.
        // Strip nothing - the shell's non-interactive mode handles prompts correctly.
        return $raw;
    }
}
