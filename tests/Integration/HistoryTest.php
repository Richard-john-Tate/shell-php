<?php
namespace Tests\Integration;

class HistoryTest extends ShellTestCase
{
    private string $histFile;

    protected function setUp(): void
    {
        $this->histFile = tempnam(sys_get_temp_dir(), 'shell_hist_');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->histFile)) unlink($this->histFile);
    }

    public function testHistoryListsCommands(): void
    {
        $this->runCommand('echo hello');
        $this->runCommand('echo world');
        $out = $this->outputOf($this->runCommand('history'));

        $this->assertStringContainsString('echo hello', $out);
        $this->assertStringContainsString('echo world', $out);
        $this->assertStringContainsString('history', $out);
    }

    public function testHistoryNumbering(): void
    {
        $this->runCommand('echo a');
        $this->runCommand('echo b');
        $out = $this->outputOf($this->runCommand('history'));

        $this->assertMatchesRegularExpression('/^\s+1\s+echo a$/m', $out);
        $this->assertMatchesRegularExpression('/^\s+2\s+echo b$/m', $out);
    }

    public function testHistoryLimit(): void
    {
        $this->runCommand('echo one');
        $this->runCommand('echo two');
        $this->runCommand('echo three');
        $out = $this->outputOf($this->runCommand('history 2'));

        $this->assertStringNotContainsString('echo one', $out);
        $this->assertStringContainsString('echo three', $out);
        $this->assertStringContainsString('history 2', $out);
    }

    public function testHistoryWriteAndRead(): void
    {
        $this->runCommand('echo hello');
        $this->runCommand('echo world');
        $this->runCommand("history -w {$this->histFile}");

        $contents = file_get_contents($this->histFile);
        $this->assertStringContainsString('echo hello', $contents);
        $this->assertStringContainsString('echo world', $contents);
        $this->assertStringContainsString("history -w {$this->histFile}", $contents);
        $this->assertStringEndsWith("\n", $contents);
    }

    public function testHistoryReadAppendsToMemory(): void
    {
        file_put_contents($this->histFile, "echo from_file\n");
        $this->runCommand("history -r {$this->histFile}");
        $out = $this->outputOf($this->runCommand('history'));

        $this->assertStringContainsString('echo from_file', $out);
    }

    public function testHistoryAppendOnlyNewCommands(): void
    {
        file_put_contents($this->histFile, "echo existing\n");
        $this->runCommand("history -r {$this->histFile}");
        $this->runCommand('echo new_command');
        $this->runCommand("history -a {$this->histFile}");

        $contents = file_get_contents($this->histFile);
        // Original line preserved
        $this->assertStringContainsString('echo existing', $contents);
        // New commands appended
        $this->assertStringContainsString('echo new_command', $contents);
        // "echo existing" should appear only once
        $this->assertSame(1, substr_count($contents, 'echo existing'));
    }
}
