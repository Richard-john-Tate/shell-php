<?php

declare(strict_types=1);

namespace App\Builtins;

/**
 * Contract for all shell builtin commands.
 *
 * Every builtin receives its arguments, an optional stdout stream override,
 * and an optional stderr stream override. It returns an integer exit code
 * (0 for success, non-zero for failure).
 */
interface BuiltinInterface
{
    /**
     * @param  string[]      $args   Command arguments (excluding the command name itself)
     * @param  resource|null $stdout Override for stdout (null = use STDOUT)
     * @param  resource|null $stderr Override for stderr (null = use STDERR)
     * @return int Exit code
     */
    public function execute(array $args, $stdout = null, $stderr = null): int;
}
