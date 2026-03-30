<?php

declare(strict_types=1);

namespace App\Builtins;

use App\Executor;

/**
 * Builtin `type` — identifies each argument as a shell builtin,
 * an external executable (with its full path), or not found.
 *
 * Returns exit code 1 if any argument was not found, 0 otherwise.
 */
class TypeCommand implements BuiltinInterface
{
    private array $builtins;

    public function __construct(array $builtins)
    {
        $this->builtins = $builtins;
    }

    public function execute(array $args, $stdout = null, $stderr = null): int
    {
        if (empty($args)) return 0;

        $out      = $stdout ?? STDOUT;
        $err      = $stderr ?? STDERR;
        $exitCode = 0;

        foreach ($args as $command) {
            if (isset($this->builtins[$command])) {
                fwrite($out, "$command is a shell builtin\n");
                continue;
            }

            $path = Executor::findInPath($command);
            if ($path !== null) {
                fwrite($out, "$command is $path\n");
                continue;
            }

            fwrite($err, "$command: not found\n");
            $exitCode = 1;
        }

        return $exitCode;
    }
}
