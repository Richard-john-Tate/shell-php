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

    /**
     * Tokenises a single pipeline segment and extracts redirections.
     *
     * @param string        $input   Raw command string (no unquoted |).
     * @param callable|null $expand  fn(string $name): string — invoked for $VAR / ${VAR} / $? / $$.
     *                               Not called inside single quotes. Never called when null.
     * @return array [$command, $args, $stdoutFile, $stderrFile, $stdoutMode, $stderrMode, $stdinFile]
     */
    public static function parse(string $input, ?callable $expand = null): array
    {
        $tokens  = [];
        $current = '';
        $i       = 0;
        $len     = strlen($input);

        while ($i < $len) {
            $char = $input[$i];

            // ── backslash outside quotes ──────────────────────────────
            if ($char === '\\') {
                $i++;
                if ($i < $len) {
                    $current .= $input[$i];
                    $i++;
                }

            // ── single quotes — no expansion, no escaping ─────────────
            } elseif ($char === "'") {
                $i++;
                while ($i < $len && $input[$i] !== "'") {
                    $current .= $input[$i];
                    $i++;
                }
                $i++; // closing '

            // ── double quotes — expansion + limited escaping ──────────
            } elseif ($char === '"') {
                $i++;
                while ($i < $len && $input[$i] !== '"') {
                    if ($input[$i] === '\\' && $i + 1 < $len) {
                        $next = $input[$i + 1];
                        // Inside double quotes backslash only escapes \, ", and $ (when expanding)
                        if ($next === '\\' || $next === '"' || ($expand !== null && $next === '$')) {
                            $current .= $next;
                            $i += 2;
                        } else {
                            $current .= $input[$i];
                            $i++;
                        }
                    } elseif ($expand !== null && $input[$i] === '$') {
                        $i++;
                        $current .= self::consumeVar($input, $i, $len, $expand);
                    } else {
                        $current .= $input[$i];
                        $i++;
                    }
                }
                $i++; // closing "

            // ── variable expansion outside quotes ─────────────────────
            } elseif ($char === '$' && $expand !== null) {
                $i++;
                $current .= self::consumeVar($input, $i, $len, $expand);

            // ── redirection operators ─────────────────────────────────
            } elseif ($char === '>') {
                // If $current is exactly '1' or '2', it is an fd prefix for the operator.
                // If $current ends with '1' or '2' (multi-char word), split the digit off.
                $fdPrefix = '';
                if ($current === '1' || $current === '2') {
                    // Bare digit immediately before > is an fd specifier (e.g. 2>err, 1>>log)
                    $fdPrefix = $current;
                    $current  = '';
                } else {
                    if ($current !== '') {
                        $tokens[] = $current;
                        $current  = '';
                    }
                }
                $i++;
                $op = $fdPrefix . '>';
                if ($i < $len && $input[$i] === '>') {
                    $op .= '>';
                    $i++;
                }
                $tokens[] = $op;

            } elseif ($char === '<') {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current  = '';
                }
                $tokens[] = '<';
                $i++;

            // ── whitespace ────────────────────────────────────────────
            } elseif ($char === ' ' || $char === "\t") {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current  = '';
                }
                $i++;

            // ── ordinary character ────────────────────────────────────
            } else {
                $current .= $char;
                $i++;
            }
        }

        if ($current !== '') {
            $tokens[] = $current;
        }

        // ── Pass 2: extract redirections ──────────────────────────────
        $stdoutFile   = null;
        $stdoutMode   = 'w';
        $stderrFile   = null;
        $stderrMode   = 'w';
        $stdinFile    = null;
        $filteredArgs = [];
        $j            = 0;
        $count        = count($tokens);

        while ($j < $count) {
            $tok = $tokens[$j];
            if ($tok === '>>' || $tok === '1>>') {
                $stdoutFile = $tokens[$j + 1] ?? null;
                $stdoutMode = 'a';
                $j += 2;
            } elseif ($tok === '>' || $tok === '1>') {
                $stdoutFile = $tokens[$j + 1] ?? null;
                $stdoutMode = 'w';
                $j += 2;
            } elseif ($tok === '2>>') {
                $stderrFile = $tokens[$j + 1] ?? null;
                $stderrMode = 'a';
                $j += 2;
            } elseif ($tok === '2>') {
                $stderrFile = $tokens[$j + 1] ?? null;
                $stderrMode = 'w';
                $j += 2;
            } elseif ($tok === '<') {
                $stdinFile = $tokens[$j + 1] ?? null;
                $j += 2;
            } else {
                $filteredArgs[] = $tok;
                $j++;
            }
        }

        $command = array_shift($filteredArgs);
        return [$command, $filteredArgs, $stdoutFile, $stderrFile, $stdoutMode, $stderrMode, $stdinFile];
    }

    /**
     * Called after consuming the leading '$'. Reads the variable name/specifier,
     * invokes $expand, and returns the substituted string. Advances $i past the token.
     */
    private static function consumeVar(string $input, int &$i, int $len, callable $expand): string
    {
        if ($i >= $len) {
            return '$';
        }

        // ${VAR} braced form
        if ($input[$i] === '{') {
            $i++;
            $name = '';
            while ($i < $len && $input[$i] !== '}') {
                $name .= $input[$i++];
            }
            if ($i < $len) $i++; // skip closing }
            return $expand($name);
        }

        // Single-character special variables: $? $$ $# $! $@ $*
        if (str_contains('?$#!@*', $input[$i])) {
            return $expand($input[$i++]);
        }

        // Named variable: [a-zA-Z_][a-zA-Z0-9_]*
        if (ctype_alpha($input[$i]) || $input[$i] === '_') {
            $name = '';
            while ($i < $len && (ctype_alnum($input[$i]) || $input[$i] === '_')) {
                $name .= $input[$i++];
            }
            return $expand($name);
        }

        // Bare $ not followed by a recognised pattern — keep literal
        return '$';
    }
}
