<?php

namespace Doppar\Orion\Process;

/**
 * Splits a command string into its program and arguments the way a shell reads quotes,
 * but without running a shell: nothing is expanded or interpreted, so `$HOME`, `*`, `;`,
 * `|` and `>` stay ordinary characters of an argument.
 *
 *     CommandParser::parse('git commit -m "fix the bug"')   // ['git', 'commit', '-m', 'fix the bug']
 *     CommandParser::parse("printf '%s' 'a b'")            // ['printf', '%s', 'a b']
 *
 * - Whitespace separates arguments; runs of it do not create empty ones.
 * - Single quotes keep everything inside literally.
 * - Double quotes keep everything literally except `\"` and `\\`.
 * - Outside quotes a backslash escapes a space, a quote or another backslash, and is
 *   otherwise literal, so Windows paths such as C:\tools\php keep working.
 * - `""` is an empty argument.
 */
final class CommandParser
{
    /**
     * @param string $command
     * @return array<int, string>
     * @throws \InvalidArgumentException When a quote is not closed or the command contains a NUL byte
     */
    public static function parse(string $command): array
    {
        if (str_contains($command, "\0")) {
            throw new \InvalidArgumentException('A command cannot contain a NUL byte.');
        }

        $arguments = [];
        $current = '';
        $started = false;
        $quote = null;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($quote === "'") {
                if ($char === "'") {
                    $quote = null;
                } else {
                    $current .= $char;
                }

                continue;
            }

            if ($quote === '"') {
                if ($char === '"') {
                    $quote = null;
                } elseif ($char === '\\' && $i + 1 < $length && ($command[$i + 1] === '"' || $command[$i + 1] === '\\')) {
                    $current .= $command[++$i];
                } else {
                    $current .= $char;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $started = true;

                continue;
            }

            if ($char === '\\' && $i + 1 < $length && in_array($command[$i + 1], [' ', "\t", '"', "'", '\\'], true)) {
                $current .= $command[++$i];
                $started = true;

                continue;
            }

            if (ctype_space($char)) {
                if ($started) {
                    $arguments[] = $current;
                    $current = '';
                    $started = false;
                }

                continue;
            }

            $current .= $char;
            $started = true;
        }

        if ($quote !== null) {
            throw new \InvalidArgumentException(sprintf('Unclosed %s quote in command: %s', $quote === '"' ? 'double' : 'single', $command));
        }

        if ($started) {
            $arguments[] = $current;
        }

        return $arguments;
    }
}
