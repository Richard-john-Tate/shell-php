<?php

declare(strict_types=1);

namespace App\Builtins;

/**
 * Builtin `history` — manages the readline history list.
 *
 * Modes:
 *  - `history`         List all entries
 *  - `history N`       Show the last N entries
 *  - `history -r file` Read history from file
 *  - `history -w file` Write full history to file
 *  - `history -a file` Append only new (session) entries to file
 */
class HistoryCommand implements BuiltinInterface
{
    private int $appendOffset = 0;

    /** Sets the append offset (number of pre-existing history entries). */
    public function setAppendOffset(int $offset): void
    {
        $this->appendOffset = $offset;
    }

    /** Returns the current append offset for incremental writes. */
    public function getAppendOffset(): int
    {
        return $this->appendOffset;
    }

    public function execute(array $args, $stdout = null, $stderr = null): int
    {
        if (($args[0] ?? null) === '-r') {
            $path = $args[1] ?? null;
            if ($path !== null && is_readable($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
                    if ($line !== '') {
                        readline_add_history($line);
                    }
                }
                $this->appendOffset = count(readline_list_history());
            }
            return 0;
        }

        if (($args[0] ?? null) === '-a') {
            $path = $args[1] ?? null;
            if ($path !== null) {
                $history  = readline_list_history();
                $newLines = array_slice($history, $this->appendOffset);
                if (!empty($newLines)) {
                    file_put_contents($path, implode("\n", $newLines) . "\n", FILE_APPEND);
                }
                $this->appendOffset = count($history);
            }
            return 0;
        }

        if (($args[0] ?? null) === '-w') {
            $path = $args[1] ?? null;
            if ($path !== null) {
                $content = implode("\n", readline_list_history()) . "\n";
                file_put_contents($path, $content);
            }
            return 0;
        }

        $out     = $stdout ?? STDOUT;
        $history = readline_list_history();
        $total   = count($history);

        $limit = isset($args[0]) && ctype_digit($args[0]) ? (int)$args[0] : $total;
        $start = max(0, $total - $limit);

        for ($i = $start; $i < $total; $i++) {
            fprintf($out, "    %d  %s\n", $i + 1, $history[$i]);
        }

        return 0;
    }
}
