<?php

declare(strict_types=1);

namespace App\Builtins;

/**
 * Builtin `cd` — changes the current working directory.
 *
 * Supports:
 *  - `cd` or `cd ~`  → $HOME
 *  - `cd ~/path`      → $HOME/path
 *  - `cd -`           → previous directory ($OLDPWD), printed to stdout
 *
 * Updates both $PWD and $OLDPWD environment variables.
 */
class CdCommand implements BuiltinInterface
{
    public function execute(array $args, $stdout = null, $stderr = null): int
    {
        $err = $stderr ?? STDERR;
        $dir = $args[0] ?? null;

        // cd with no args → HOME
        if ($dir === null || $dir === '') {
            $dir = getenv('HOME') ?: '/';
        }

        // cd ~ → HOME
        if ($dir === '~') {
            $dir = getenv('HOME') ?: '/';
        }

        // Expand ~/ prefix
        if (str_starts_with($dir, '~/')) {
            $dir = (getenv('HOME') ?: '') . substr($dir, 1);
        }

        // cd - → previous directory
        if ($dir === '-') {
            $oldPwd = getenv('OLDPWD') ?: null;
            if ($oldPwd === null) {
                fwrite($err, "cd: OLDPWD not set\n");
                return 1;
            }
            $dir = $oldPwd;
            fwrite($stdout ?? STDOUT, $dir . "\n");
        }

        if (!is_dir($dir)) {
            fwrite($err, "cd: $dir: No such file or directory\n");
            return 1;
        }

        $prevPwd = getcwd();
        if (!chdir($dir)) {
            fwrite($err, "cd: $dir: Permission denied\n");
            return 1;
        }

        putenv('OLDPWD=' . $prevPwd);
        putenv('PWD=' . getcwd());

        return 0;
    }
}
