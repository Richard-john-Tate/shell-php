<?php
namespace App;

use App\Builtins\EchoCommand;
use App\Builtins\ExitCommand;
use App\Builtins\TypeCommand;
use App\Builtins\PwdCommand;
use App\Builtins\CdCommand;
use App\Builtins\HistoryCommand;

class Shell
{
    private array $builtins     = [];
    private int   $lastExitCode = 0;

    public function __construct()
    {
        $this->builtins = [
            'cd'      => new CdCommand(),
            'pwd'     => new PwdCommand(),
            'echo'    => new EchoCommand(),
            'exit'    => new ExitCommand(),
            'type'    => new TypeCommand([]),
            'history' => new HistoryCommand(),
        ];
        $this->builtins['type'] = new TypeCommand($this->builtins);

        // Seed PWD env var if the environment didn't provide it
        if (getenv('PWD') === false) {
            putenv('PWD=' . getcwd());
        }

        // Load history from HISTFILE on startup
        $histfile = getenv('HISTFILE') ?: null;
        if ($histfile && is_readable($histfile)) {
            foreach (file($histfile, FILE_IGNORE_NEW_LINES) as $line) {
                if ($line !== '') {
                    readline_add_history($line);
                }
            }
            $this->builtins['history']->setAppendOffset(count(readline_list_history()));
        }

        // On exit: append only the commands added this session (safe for concurrent shells)
        if ($histfile) {
            register_shutdown_function(function () use ($histfile) {
                $history  = readline_list_history();
                $newLines = array_slice($history, $this->builtins['history']->getAppendOffset());
                if (!empty($newLines)) {
                    file_put_contents($histfile, implode("\n", $newLines) . "\n", FILE_APPEND);
                }
            });
        }
    }

    // ── Prompt ───────────────────────────────────────────────────────────────

    private function getPrompt(): string
    {
        $user = getenv('USER') ?: getenv('LOGNAME') ?: 'user';
        $host = gethostname() ?: 'localhost';
        $cwd  = getcwd() ?: '/';
        $home = getenv('HOME') ?: '';

        if ($home !== '' && str_starts_with($cwd, $home)) {
            $cwd = '~' . substr($cwd, strlen($home));
        }

        return "$user@$host:$cwd\$ ";
    }

    // ── Variable expansion ───────────────────────────────────────────────────

    private function expand(string $name): string
    {
        if ($name === '?') return (string)$this->lastExitCode;
        if ($name === '$') return (string)getmypid();
        if ($name === '#') return '0';
        $val = getenv($name);
        return $val !== false ? $val : '';
    }

    // ── REPL ─────────────────────────────────────────────────────────────────

    public function run(): void
    {
        readline_completion_function(function (string $input, int $index): array {
            readline_info('attempted_completion_over', 1);

            if ($index === 0) {
                $completions = [];

                foreach (array_keys($this->builtins) as $builtin) {
                    if (str_starts_with($builtin, $input)) {
                        $completions[] = $builtin;
                    }
                }

                foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $dir) {
                    if (!is_dir($dir)) continue;
                    foreach (scandir($dir) as $file) {
                        if ($file === '.' || $file === '..') continue;
                        if (!str_starts_with($file, $input)) continue;
                        $full = $dir . DIRECTORY_SEPARATOR . $file;
                        if (is_file($full) && is_executable($full)) {
                            $completions[] = $file;
                        }
                    }
                }

                $completions = array_values(array_unique($completions));
            } else {
                $completions = [];
                $lastSlash   = strrpos($input, '/');

                if ($lastSlash !== false) {
                    $dirPart    = substr($input, 0, $lastSlash + 1);
                    $prefix     = substr($input, $lastSlash + 1);
                    $scanTarget = $dirPart;
                } else {
                    $dirPart    = '';
                    $prefix     = $input;
                    $scanTarget = '.';
                }

                $matchIsDir = false;
                foreach (scandir($scanTarget) ?: [] as $file) {
                    if ($file === '.' || $file === '..') continue;
                    if (!str_starts_with($file, $prefix)) continue;
                    $checkPath = ($scanTarget === '.') ? $file : ($scanTarget . $file);
                    if (is_dir($checkPath)) {
                        $completions[] = $dirPart . $file . '/';
                        $matchIsDir    = true;
                    } else {
                        $completions[] = $dirPart . $file;
                    }
                }

                readline_info('completion_suppress_append', count($completions) === 1 && $matchIsDir ? 1 : 0);
            }

            if (empty($completions)) {
                readline_info('completion_suppress_append', 1);
                fwrite(STDOUT, "\x07");
            }

            return $completions;
        });

        $interactive = stream_isatty(STDIN);

        while (true) {
            $prompt = $this->getPrompt();

            if ($interactive) {
                $input = readline($prompt);
                if ($input === false) break;
            } else {
                fwrite(STDOUT, $prompt);
                $input = fgets(STDIN);
                if ($input === false) break;
            }

            $input = trim($input);
            if ($input === '') continue;

            readline_add_history($input);

            $expandFn     = fn(string $name): string => $this->expand($name);
            $pipeSegments = Parser::splitPipeline($input);

            if (count($pipeSegments) === 1) {
                [$command, $args, $stdoutFile, $stderrFile, $stdoutMode, $stderrMode, $stdinFile]
                    = Parser::parse($input, $expandFn);

                $stdout = $stdoutFile !== null ? (fopen($stdoutFile, $stdoutMode) ?: null) : null;
                $stderr = $stderrFile !== null ? (fopen($stderrFile, $stderrMode) ?: null) : null;
                $stdin  = $stdinFile  !== null ? (fopen($stdinFile,  'r') ?: null)         : null;

                $this->lastExitCode = $this->dispatch($command, $args, $stdout, $stderr, $stdin);

                if ($stdout !== null) fclose($stdout);
                if ($stderr !== null) fclose($stderr);
                if ($stdin  !== null) fclose($stdin);
            } else {
                $parsed = array_map(fn($seg) => Parser::parse($seg, $expandFn), $pipeSegments);

                $last   = end($parsed);
                $stdout = $last[2] !== null ? (fopen($last[2], $last[4]) ?: null) : null;
                $stderr = $last[3] !== null ? (fopen($last[3], $last[5]) ?: null) : null;
                // Stdin redirect (< file) applies to the first segment only
                $stdin  = $parsed[0][6] !== null ? (fopen($parsed[0][6], 'r') ?: null) : null;

                $this->lastExitCode = $this->executePipeline($parsed, $stdout, $stderr, $stdin);

                if ($stdout !== null) fclose($stdout);
                if ($stderr !== null) fclose($stderr);
                if ($stdin  !== null) fclose($stdin);
            }
        }
    }

    // ── Pipeline execution ───────────────────────────────────────────────────

    /**
     * All-external pipelines are delegated to Executor::runPipeline() (true concurrent
     * OS pipes, supports tail -f and other streaming commands).
     * Any pipeline containing a builtin runs sequentially with php://temp buffers.
     */
    private function executePipeline(array $parsed, $finalStdout, $finalStderr, $firstStdin = null): int
    {
        $hasBuiltin = false;
        foreach ($parsed as [$command]) {
            if (isset($this->builtins[$command])) {
                $hasBuiltin = true;
                break;
            }
        }

        if (!$hasBuiltin) {
            $segments = array_map(fn($p) => [$p[0], $p[1]], $parsed);
            return Executor::runPipeline($segments, $finalStdout, $finalStderr);
        }

        $count    = count($parsed);
        $prevOut  = null;
        $exitCode = 0;

        for ($i = 0; $i < $count; $i++) {
            [$command, $args] = $parsed[$i];
            $isLast  = ($i === $count - 1);
            $stepOut = $isLast ? ($finalStdout ?? STDOUT) : fopen('php://temp', 'r+');
            $stepErr = ($isLast && $finalStderr) ? $finalStderr : STDERR;
            $stepIn  = ($i === 0 && $firstStdin !== null) ? $firstStdin : ($prevOut ?? STDIN);

            if (isset($this->builtins[$command])) {
                $exitCode = $this->builtins[$command]->execute($args, $stepOut, $stepErr);
            } else {
                $fullPath = Executor::findInPath($command);
                if ($fullPath === null) {
                    fwrite(STDERR, "$command: command not found\n");
                    if ($prevOut) fclose($prevOut);
                    if (!$isLast) fclose($stepOut);
                    return 127;
                }
                $pipes   = [];
                $process = proc_open(
                    array_merge([$command], $args),
                    [0 => $stepIn, 1 => $stepOut, 2 => $stepErr],
                    $pipes
                );
                if (is_resource($process)) {
                    $exitCode = proc_close($process);
                }
            }

            if ($prevOut !== null) {
                fclose($prevOut);
                $prevOut = null;
            }

            if (!$isLast) {
                rewind($stepOut);
                $prevOut = $stepOut;
            }
        }

        return $exitCode;
    }

    // ── Dispatch ─────────────────────────────────────────────────────────────

    private function dispatch(string $command, array $args, $stdout = null, $stderr = null, $stdin = null): int
    {
        if (isset($this->builtins[$command])) {
            return $this->builtins[$command]->execute($args, $stdout, $stderr);
        }
        return Executor::run($command, $args, $stdout, $stderr, $stdin);
    }
}
