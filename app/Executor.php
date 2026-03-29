<?php
namespace App;

class Executor
{
    /**
     * Executes a command with the given arguments.
     *
     * @param string $command
     * @param array $args
     * @return void
     */
    public static function run(string $command, array $args, $stdout = null, $stderr = null): void
    {
        $fullPath = self::findInPath($command);

        if ($fullPath === null) {
            fwrite(STDERR, "$command: command not found\n");
            return;
        }
        // argv[0] should be the bare command name, not the full path,
        // as that's what the program receives as its own name (argv[0])
        $cmd = array_merge([$command], $args);
        $descriptors = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (is_resource($process)) {
            fclose($pipes[0]);
            fwrite($stdout ?? STDOUT, stream_get_contents($pipes[1]));
            fwrite($stderr ?? STDERR, stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    /**
     * Executes a pipeline of external commands, connecting stdout→stdin via pipes.
     * Only the last command's stdout/stderr can be redirected.
     *
     * @param array $segments  Each element is [command, args[]]
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public static function runPipeline(array $segments, $stdout = null, $stderr = null): void
    {
        $count       = count($segments);
        $processes   = [];
        $prevReadEnd = null; // read end of the previous inter-process pipe

        for ($i = 0; $i < $count; $i++) {
            [$command, $args] = $segments[$i];
            $isLast = ($i === $count - 1);

            $fullPath = self::findInPath($command);
            if ($fullPath === null) {
                fwrite(STDERR, "$command: command not found\n");
                if ($prevReadEnd) fclose($prevReadEnd);
                foreach (array_reverse($processes) as $p) proc_close($p);
                return;
            }

            $desc = [
                0 => $prevReadEnd ?? STDIN,
                1 => $isLast ? ($stdout ?? STDOUT) : ['pipe', 'w'],
                2 => ($isLast && $stderr) ? $stderr : STDERR,
            ];

            $pipes   = [];
            $process = proc_open(array_merge([$command], $args), $desc, $pipes);

            // Close the read end in the parent now that the child has it
            if ($prevReadEnd) {
                fclose($prevReadEnd);
                $prevReadEnd = null;
            }

            // The write end is the child's stdout; the read end ($pipes[1]) is ours
            if (!$isLast && isset($pipes[1])) {
                $prevReadEnd = $pipes[1];
            }

            if (is_resource($process)) {
                $processes[] = $process;
            }
        }

        // Wait in reverse order: last command exits first, SIGPIPE propagates back
        foreach (array_reverse($processes) as $process) {
            proc_close($process);
        }
    }

    /**
     * Finds the full path of a command in the system's PATH.
     *
     * @param string $command
     * @return string|null
     */
    public static function findInPath(string $command): ?string
    {
        $paths = explode(PATH_SEPARATOR, getenv('PATH'));
        foreach ($paths as $path) {
            $candidates = [
                $path . DIRECTORY_SEPARATOR . $command,
                $path . DIRECTORY_SEPARATOR . $command . '.exe',
            ];
            foreach ($candidates as $candidate) {
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }
}