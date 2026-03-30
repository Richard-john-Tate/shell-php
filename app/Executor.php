<?php

declare(strict_types=1);

namespace App;

/**
 * Executes external commands via proc_open.
 *
 * Provides single-command execution (with streaming I/O) and multi-stage
 * pipeline execution using true OS pipes for concurrency.
 */
class Executor
{
    // ── Single command execution ────────────────────────────────────────────

    /**
     * Executes a single external command, wiring streams directly to proc_open.
     *
     * Unlike buffered approaches, this passes file descriptors straight through,
     * so streaming programs like `tail -f` work correctly.
     *
     * @param  string       $command Command name (resolved via findInPath)
     * @param  string[]     $args    Arguments to pass to the process
     * @param  resource|null $stdout  Override for stdout stream
     * @param  resource|null $stderr  Override for stderr stream
     * @param  resource|null $stdin   Override for stdin stream
     * @return int Process exit code (127 if command not found)
     */
    public static function run(
        string $command,
        array  $args,
               $stdout = null,
               $stderr = null,
               $stdin  = null
    ): int {
        $fullPath = self::findInPath($command);

        if ($fullPath === null) {
            fwrite($stderr ?? STDERR, "$command: command not found\n");
            return 127;
        }

        // argv[0] is the bare command name, matching standard shell convention
        $cmd = array_merge([$command], $args);

        $descriptors = [
            0 => $stdin  ?? ['file', 'php://stdin',  'r'],
            1 => $stdout ?? ['file', 'php://stdout', 'w'],
            2 => $stderr ?? ['file', 'php://stderr', 'w'],
        ];

        $pipes   = [];
        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            fwrite($stderr ?? STDERR, "$command: failed to start\n");
            return 1;
        }

        return proc_close($process);
    }

    // ── Pipeline execution ──────────────────────────────────────────────────

    /**
     * Executes a pipeline of external commands with true concurrent OS pipes.
     *
     * Each command's stdout is wired to the next command's stdin via OS-level
     * pipes. Only the last segment's stdout/stderr may be redirected.
     * Processes are reaped in reverse order so SIGPIPE propagates correctly.
     *
     * @param  array<int, array{0:string, 1:string[]}> $segments Each entry is [command, args[]]
     * @param  resource|null $stdout Redirect target for the last stage's stdout
     * @param  resource|null $stderr Redirect target for the last stage's stderr
     * @return int Exit code of the last command in the pipeline (127 on not-found)
     */
    public static function runPipeline(array $segments, $stdout = null, $stderr = null): int
    {
        $count       = count($segments);
        $processes   = [];
        $prevReadEnd = null;

        for ($i = 0; $i < $count; $i++) {
            [$command, $args] = $segments[$i];
            $isLast = ($i === $count - 1);

            $fullPath = self::findInPath($command);
            if ($fullPath === null) {
                fwrite(STDERR, "$command: command not found\n");
                if ($prevReadEnd) fclose($prevReadEnd);
                foreach (array_reverse($processes) as $p) proc_close($p);
                return 127;
            }

            $desc = [
                0 => $prevReadEnd ?? STDIN,
                1 => $isLast ? ($stdout ?? STDOUT) : ['pipe', 'w'],
                2 => ($isLast && $stderr) ? $stderr : STDERR,
            ];

            $pipes   = [];
            $process = proc_open(array_merge([$command], $args), $desc, $pipes);

            // Close the read end in the parent now that the child has inherited it
            if ($prevReadEnd) {
                fclose($prevReadEnd);
                $prevReadEnd = null;
            }

            if (!$isLast && isset($pipes[1])) {
                $prevReadEnd = $pipes[1];
            }

            if (is_resource($process)) {
                $processes[] = $process;
            }
        }

        // Wait in reverse order: last process exits first, SIGPIPE propagates back.
        // The first iteration (reversed) is the LAST process — capture its exit code.
        $lastExitCode = 0;
        $first        = true;
        foreach (array_reverse($processes) as $process) {
            $code = proc_close($process);
            if ($first) {
                $lastExitCode = $code;
                $first        = false;
            }
        }

        return $lastExitCode;
    }

    // ── PATH resolution ─────────────────────────────────────────────────────

    /**
     * Resolves a command name to its full path using the system PATH.
     *
     * Results are cached for the lifetime of the process so repeated
     * invocations of the same command do not re-scan the filesystem.
     *
     * @param  string $command Bare command name to look up
     * @return string|null Absolute path, or null if the command was not found
     */
    public static function findInPath(string $command): ?string
    {
        static $cache = [];

        if (array_key_exists($command, $cache)) {
            return $cache[$command];
        }

        $paths = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
        foreach ($paths as $path) {
            $candidates = [
                $path . DIRECTORY_SEPARATOR . $command,
                $path . DIRECTORY_SEPARATOR . $command . '.exe',
            ];
            foreach ($candidates as $candidate) {
                if (is_file($candidate) && is_executable($candidate)) {
                    return $cache[$command] = $candidate;
                }
            }
        }

        return $cache[$command] = null;
    }
}
