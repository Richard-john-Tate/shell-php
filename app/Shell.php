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
    private array $builtins = [];

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
        // TypeCommand needs to know about builtins for its lookup
        $this->builtins['type'] = new TypeCommand($this->builtins);

        // Load history from HISTFILE on startup; write it back on exit
        $histfile = getenv('HISTFILE') ?: null;
        if ($histfile && is_readable($histfile)) {
            foreach (file($histfile, FILE_IGNORE_NEW_LINES) as $line) {
                if ($line !== '') {
                    readline_add_history($line);
                }
            }
            // Advance the append offset so history -a only writes new commands
            $this->builtins['history']->setAppendOffset(count(readline_list_history()));
        }
        if ($histfile) {
            register_shutdown_function(function () use ($histfile) {
                $history = readline_list_history();
                if (!empty($history)) {
                    file_put_contents($histfile, implode("\n", $history) . "\n");
                }
            });
        }
    }

    public function run(): void
    {
        readline_completion_function(function (string $input, int $index): array {
            readline_info('attempted_completion_over', 1);

            if ($index === 0) {
                // Completing the command word
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
                // Completing a filename argument
                $completions = [];
                $lastSlash = strrpos($input, '/');
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
                        $matchIsDir = true;
                    } else {
                        $completions[] = $dirPart . $file;
                    }
                }

                // Directories get a trailing / with no space; files get the default space
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
            if ($interactive) {
                $input = readline('$ ');
                if ($input === false) break;
            } else {
                fwrite(STDOUT, '$ ');
                $input = fgets(STDIN);
                if ($input === false) break;
            }

            $input = trim($input);

            if ($input === '') continue;

            if ($interactive) readline_add_history($input);

            $pipeSegments = Parser::splitPipeline($input);

            if (count($pipeSegments) === 1) {
                [$command, $args, $stdoutFile, $stderrFile, $stdoutMode, $stderrMode] = Parser::parse($input);
                $stdout = $stdoutFile !== null ? fopen($stdoutFile, $stdoutMode) : null;
                $stderr = $stderrFile !== null ? fopen($stderrFile, $stderrMode) : null;
                $this->dispatch($command, $args, $stdout, $stderr);
                if ($stdout !== null) fclose($stdout);
                if ($stderr !== null) fclose($stderr);
            } else {
                // Pipeline: parse each segment, apply redirects only from the last one
                $parsed = array_map([Parser::class, 'parse'], $pipeSegments);
                $last   = end($parsed);
                $stdout = $last[2] !== null ? fopen($last[2], $last[4]) : null;
                $stderr = $last[3] !== null ? fopen($last[3], $last[5]) : null;
                $this->executePipeline($parsed, $stdout, $stderr);
                if ($stdout !== null) fclose($stdout);
                if ($stderr !== null) fclose($stderr);
            }
        }
    }

    /**
     * Executes a parsed pipeline.
     *
     * - All-external pipelines: delegated to Executor::runPipeline() (concurrent, supports tail -f etc.)
     * - Any built-in present: run sequentially, buffering intermediate output in php://temp streams.
     */
    private function executePipeline(array $parsed, $finalStdout, $finalStderr): void
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
            Executor::runPipeline($segments, $finalStdout, $finalStderr);
            return;
        }

        // Sequential execution with php://temp buffers between stages
        $count   = count($parsed);
        $prevOut = null; // read-ready stream from previous stage

        for ($i = 0; $i < $count; $i++) {
            [$command, $args] = $parsed[$i];
            $isLast  = ($i === $count - 1);
            $stepOut = $isLast ? ($finalStdout ?? STDOUT) : fopen('php://temp', 'r+');
            $stepErr = ($isLast && $finalStderr) ? $finalStderr : STDERR;
            $stepIn  = $prevOut ?? STDIN;

            if (isset($this->builtins[$command])) {
                $this->builtins[$command]->execute($args, $stepOut, $stepErr);
            } else {
                $fullPath = Executor::findInPath($command);
                if ($fullPath === null) {
                    fwrite(STDERR, "$command: command not found\n");
                    if ($prevOut) fclose($prevOut);
                    if (!$isLast) fclose($stepOut);
                    return;
                }
                $pipes   = [];
                $process = proc_open(
                    array_merge([$command], $args),
                    [0 => $stepIn, 1 => $stepOut, 2 => $stepErr],
                    $pipes
                );
                if (is_resource($process)) {
                    proc_close($process);
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
    }

    private function dispatch(string $command, array $args, $stdout = null, $stderr = null): void
    {
        if (isset($this->builtins[$command])) {
            $this->builtins[$command]->execute($args, $stdout, $stderr);
        } else {
            Executor::run($command, $args, $stdout, $stderr);
        }
    }
}