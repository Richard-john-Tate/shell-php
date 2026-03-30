<?php

declare(strict_types=1);

namespace App\Builtins;

/**
 * Builtin `echo` — prints arguments to stdout.
 *
 * Supports `-n` (suppress trailing newline) and `-e` (interpret escape
 * sequences like \n, \t, \\) in any combination (e.g. `-ne`, `-en`).
 */
class EchoCommand implements BuiltinInterface
{
    public function execute(array $args, $stdout = null, $stderr = null): int
    {
        $noNewline = false;
        $interpret = false;

        // Consume leading flag arguments (-n, -e, -ne, -en, etc.)
        while (!empty($args) && isset($args[0][0]) && $args[0][0] === '-') {
            if (!preg_match('/^-[ne]+$/', $args[0])) {
                break;
            }
            $flag = array_shift($args);
            if (str_contains($flag, 'n')) $noNewline = true;
            if (str_contains($flag, 'e')) $interpret = true;
        }

        $text = implode(' ', $args);

        if ($interpret) {
            // Interpret escape sequences: \n \t \\ \a \b \r etc.
            $text = stripcslashes($text);
        }

        fwrite($stdout ?? STDOUT, $text . ($noNewline ? '' : "\n"));
        return 0;
    }
}
