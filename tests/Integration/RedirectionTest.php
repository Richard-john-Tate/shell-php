<?php
namespace Tests\Integration;

class RedirectionTest extends ShellTestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'shell_test_');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->tmpFile)) unlink($this->tmpFile);
    }

    public function testStdoutRedirectCreatesFile(): void
    {
        $this->runCommand("echo hello > {$this->tmpFile}");
        $this->assertSame("hello\n", file_get_contents($this->tmpFile));
    }

    public function testStdoutRedirectOverwrites(): void
    {
        file_put_contents($this->tmpFile, "old content\n");
        $this->runCommand("echo new > {$this->tmpFile}");
        $this->assertSame("new\n", file_get_contents($this->tmpFile));
    }

    public function testStdoutAppend(): void
    {
        $this->runCommand("echo first >> {$this->tmpFile}");
        $this->runCommand("echo second >> {$this->tmpFile}");
        $this->assertSame("first\nsecond\n", file_get_contents($this->tmpFile));
    }

    public function testStderrRedirect(): void
    {
        $this->runCommand("cat /nonexistent_file_xyz 2> {$this->tmpFile}");
        $contents = file_get_contents($this->tmpFile);
        $this->assertStringContainsString('nonexistent_file_xyz', $contents);
    }

    public function testStderrAppend(): void
    {
        $this->runCommand("cat /nonexistent1_xyz 2>> {$this->tmpFile}");
        $this->runCommand("cat /nonexistent2_xyz 2>> {$this->tmpFile}");
        $contents = file_get_contents($this->tmpFile);
        $this->assertStringContainsString('nonexistent1_xyz', $contents);
        $this->assertStringContainsString('nonexistent2_xyz', $contents);
    }

    public function testStdoutNotCapturedByStderrRedirect(): void
    {
        $this->runCommand("echo hello 2> {$this->tmpFile}");
        $this->assertSame('', file_get_contents($this->tmpFile));
    }
}
