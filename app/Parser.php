<?php
namespace App;

class Parser
{
    /**
     * Splits a raw input line on unquoted | characters and returns the segments.
     */
    public static function splitPipeline(string $input): array
    {
        $segments = [];
        $current  = '';
        $i        = 0;
        $len      = strlen($input);

        while ($i < $len) {
            $char = $input[$i];

            if ($char === '\\') {
                $current .= $char;
                $i++;
                if ($i < $len) $current .= $input[$i++];
            } elseif ($char === "'") {
                $current .= $char;
                $i++;
                while ($i < $len && $input[$i] !== "'") $current .= $input[$i++];
                if ($i < $len) $current .= $input[$i++];
            } elseif ($char === '"') {
                $current .= $char;
                $i++;
                while ($i < $len && $input[$i] !== '"') {
                    if ($input[$i] === '\\' && $i + 1 < $len) {
                        $current .= $input[$i++];
                        $current .= $input[$i++];
                    } else {
                        $current .= $input[$i++];
                    }
                }
                if ($i < $len) $current .= $input[$i++];
            } elseif ($char === '|') {
                $segments[] = trim($current);
                $current    = '';
                $i++;
            } else {
                $current .= $char;
                $i++;
            }
        }

        $segments[] = trim($current);
        return array_values(array_filter($segments, fn($s) => $s !== ''));
    }

    public static function parse(string $input): array
    {
        $args = [];
        $current = '';
        $i = 0;
        $len = strlen($input);

        while ($i < $len) {
            $char = $input[$i];

            if ($char === '\\') {
                // Outside quotes — next character is always literal, backslash removed
                $i++;
                if ($i < $len) {
                    $current .= $input[$i];
                    $i++;
                }

            } elseif ($char === "'") {
                // Single quotes — everything literal
                $i++;
                while ($i < $len && $input[$i] !== "'") {
                    $current .= $input[$i];
                    $i++;
                }
                $i++; // skip closing quote

            } elseif ($char === '"') {
                // Double quotes — everything literal for now
                $i++;
                while ($i < $len && $input[$i] !== '"') {
                    if ($input[$i] === '\\' && $i + 1 < $len) {
                        $next = $input[$i + 1];
                        if ($next === '\\' || $next === '"') {
                            // These are the only chars backslash escapes inside double quotes
                            $current .= $next;
                            $i += 2;
                        } else {
                            // Backslash is literal for anything else
                            $current .= $input[$i];
                            $i++;
                        }
                    } else {
                        $current .= $input[$i];
                        $i++;
                    }
                }
                $i++; // skip closing quote

            } elseif ($char === ' ' || $char === "\t") {
                // Whitespace outside quotes = argument delimiter
                if ($current !== '') {
                    $args[] = $current;
                    $current = '';
                }
                $i++;

            } else {
                $current .= $char;
                $i++;
            }
        }

        if ($current !== '') {
            $args[] = $current;
        }

        // Extract stdout/stderr redirections (>, 1>, >>, 1>>, 2>, 2>>)
        $stdoutFile = null;
        $stdoutMode = 'w';
        $stderrFile = null;
        $stderrMode = 'w';
        $filteredArgs = [];
        $i = 0;
        while ($i < count($args)) {
            if ($args[$i] === '>>' || $args[$i] === '1>>') {
                $stdoutFile = $args[$i + 1] ?? null;
                $stdoutMode = 'a';
                $i += 2;
            } elseif ($args[$i] === '>' || $args[$i] === '1>') {
                $stdoutFile = $args[$i + 1] ?? null;
                $stdoutMode = 'w';
                $i += 2;
            } elseif ($args[$i] === '2>>') {
                $stderrFile = $args[$i + 1] ?? null;
                $stderrMode = 'a';
                $i += 2;
            } elseif ($args[$i] === '2>') {
                $stderrFile = $args[$i + 1] ?? null;
                $stderrMode = 'w';
                $i += 2;
            } else {
                $filteredArgs[] = $args[$i];
                $i++;
            }
        }

        $command = array_shift($filteredArgs);
        return [$command, $filteredArgs, $stdoutFile, $stderrFile, $stdoutMode, $stderrMode];
    }
}