<?php

namespace Doppar\Orion\Process;

trait InteractsWithCommandSanitization
{
    /**
     * Sanitize command input to prevent injection attacks
     *
     * @param string|array $command
     * @return string
     * @throws \InvalidArgumentException If dangerous characters detected
     */
    protected static function sanitizeCommand($command)
    {
        if (is_array($command)) {
            return array_map([static::class, 'validateCommand'], $command);
        }

        if (!is_string($command)) {
            throw new \InvalidArgumentException('Command must be string or array');
        }

        static::validateCommand($command);
        return $command;
    }

    /**
     * Validate command for dangerous patterns without modifying it
     *
     * @param string $command
     * @throws \InvalidArgumentException
     */
    protected static function validateCommand(string $command): void
    {
        $dangerousPatterns = [
            '/;/',
            '/\|/',
            '/&/',
            '/`/',
            '/\$\(/',
            '/>/',
            '/</',
            '/\${/'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $command)) {
                throw new \InvalidArgumentException(
                    "Potential command injection detected: " .
                        htmlspecialchars($command, ENT_QUOTES, 'UTF-8')
                );
            }
        }
    }
}
