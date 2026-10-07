<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use IngestVault\Json;
use PHPUnit\Framework\Attributes\DataProvider;

final class JsonTest extends TestCase
{
    #[DataProvider('members')]
    public function test_a_member_is_read_exactly_as_written(string $json, string $expected): void
    {
        $this->assertSame($expected, Json::member($json, 'payload'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function members(): iterable
    {
        yield 'an object' => ['{"id":"e1","payload":{"order":1042},"replay":false}', '{"order":1042}'];
        yield 'empty containers and a zero fraction' => ['{"payload":{"empty":{},"list":[],"ratio":1.0}}', '{"empty":{},"list":[],"ratio":1.0}'];
        yield 'an integer beyond 2^53' => ['{"payload":9007199254740993,"replay":false}', '9007199254740993'];
        yield 'a number followed by the closing brace' => ['{"id":"e1","payload":1.0}', '1.0'];
        yield 'a string before it holding a brace and a quote' => ['{"note":"{\"","payload":[1]}', '[1]'];
        yield 'a string value with escaped quotes and braces' => ['{"payload":"say \"hi\" {}","id":"e1"}', '"say \"hi\" {}"'];
        yield 'a string ending in an escaped backslash' => ['{"payload":["a\\\\",{"b":"\\\\"}],"id":"e1"}', '["a\\\\",{"b":"\\\\"}]'];
        yield 'an object holding a string with a closing brace' => ['{"payload":{"a":"}]"},"id":"e1"}', '{"a":"}]"}'];
        yield 'a nested payload key before the top-level one' => ['{"meta":{"payload":"inner"},"payload":"outer"}', '"outer"'];
        yield 'a key spelt with a unicode escape' => ['{"pay\\u006coad":[]}', '[]'];
        yield 'the last of two equal keys' => ['{"payload":1,"payload":2}', '2'];
        yield 'null' => ['{"payload":null}', 'null'];
        yield 'true' => ['{"payload":true}', 'true'];
        yield 'an empty string' => ['{"payload":""}', '""'];
        yield 'whitespace around everything' => [" {\n  \"payload\" : [ 1, 2 ] ,\n  \"id\" : \"e1\"\n} ", '[ 1, 2 ]'];
    }

    #[DataProvider('misses')]
    public function test_no_member_is_read_from_text_without_it(string $json): void
    {
        $this->assertNull(Json::member($json, 'payload'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function misses(): iterable
    {
        yield 'an absent key' => ['{"id":"e1","replay":false}'];
        yield 'only a nested key' => ['{"meta":{"payload":1}}'];
        yield 'an empty object' => ['{}'];
        yield 'a top-level array' => ['[{"payload":1}]'];
        yield 'a top-level string' => ['"payload"'];
        yield 'empty text' => [''];
        yield 'html' => ['<html>Welcome</html>'];
        yield 'text truncated inside the value' => ['{"payload":{"order":10'];
        yield 'text truncated after the value' => ['{"payload":1'];
        yield 'text truncated inside a string' => ['{"payload":"abc'];
        yield 'a missing value' => ['{"payload":}'];
        yield 'a missing colon' => ['{"payload" 1}'];
        yield 'text after the object' => ['{"payload":1}x'];
    }
}
