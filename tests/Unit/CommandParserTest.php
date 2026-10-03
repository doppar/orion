<?php

namespace Doppar\Orion\Tests\Unit;

use Doppar\Orion\Process\CommandParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CommandParserTest extends TestCase
{
    #[DataProvider('commands')]
    public function testItSplitsACommandLikeAShellReadsQuotes(string $command, array $expected): void
    {
        $this->assertSame($expected, CommandParser::parse($command));
    }

    public static function commands(): array
    {
        return [
            'plain words' => ['ls -la /tmp', ['ls', '-la', '/tmp']],
            'several spaces do not make empty arguments' => ['printf   a    b', ['printf', 'a', 'b']],
            'tabs and line breaks separate' => ["a\tb\nc", ['a', 'b', 'c']],
            'leading and trailing whitespace' => ["  ls  \n", ['ls']],
            'empty string' => ['', []],
            'only whitespace' => ["  \t ", []],
            'double quotes' => ['git commit -m "fix the bug"', ['git', 'commit', '-m', 'fix the bug']],
            'single quotes' => ["printf '%s|%s' 'a b' c", ['printf', '%s|%s', 'a b', 'c']],
            'an empty quoted argument is kept' => ['cmd "" x', ['cmd', '', 'x']],
            'adjacent quoted pieces join' => ['a"b c"d', ['ab cd']],
            'quotes inside the other kind' => ['echo "it\'s" \'say "hi"\'', ['echo', "it's", 'say "hi"']],
            'escaped quote inside double quotes' => ['echo "say \"hi\""', ['echo', 'say "hi"']],
            'escaped backslash inside double quotes' => ['echo "a\\\\b"', ['echo', 'a\\b']],
            'a backslash is literal in single quotes' => ["echo 'a\\b'", ['echo', 'a\\b']],
            'a backslash before an ordinary character is literal' => ['echo "a\\nb"', ['echo', 'a\\nb']],
            'escaped space outside quotes' => ['cat my\\ file.txt', ['cat', 'my file.txt']],
            'windows paths keep their backslashes' => ['php C:\\tools\\php\\run.php', ['php', 'C:\\tools\\php\\run.php']],
            'a quoted windows path with spaces' => ['php "C:\\Program Files\\x.php"', ['php', 'C:\\Program Files\\x.php']],
            'shell characters are ordinary characters' => ['echo $HOME ; ls | wc > out * ~ `x` $(y) &', ['echo', '$HOME', ';', 'ls', '|', 'wc', '>', 'out', '*', '~', '`x`', '$(y)', '&']],
            'unicode' => ['echo "héllo wörld"', ['echo', 'héllo wörld']],
        ];
    }

    public function testAnUnclosedDoubleQuoteIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unclosed double quote');

        CommandParser::parse('echo "oops');
    }

    public function testAnUnclosedSingleQuoteIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unclosed single quote');

        CommandParser::parse("echo 'oops");
    }

    public function testANulByteIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('NUL byte');

        CommandParser::parse("echo a\0b");
    }
}
