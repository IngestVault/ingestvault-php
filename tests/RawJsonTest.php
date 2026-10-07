<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use IngestVault\RawJson;
use PHPUnit\Framework\Attributes\DataProvider;

final class RawJsonTest extends TestCase
{
    #[DataProvider('validTexts')]
    public function test_valid_json_text_is_kept_unchanged(string $text): void
    {
        $this->assertSame($text, (new RawJson($text))->json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validTexts(): iterable
    {
        yield 'object' => ['{"order":1042}'];
        yield 'null' => ['null'];
        yield 'zero fraction' => ['1.0'];
        yield 'empty list' => ['[]'];
        yield 'outer whitespace' => [" {\"a\": 1} \n"];
        yield 'duplicate members and a big integer' => ['{ "ratio": 1.0, "e": 1.5e3, "big": 123456789012345678901234567890, "a": 1, "a": 2 }'];
    }

    #[DataProvider('invalidTexts')]
    public function test_invalid_json_text_is_rejected_without_repeating_it(string $text): void
    {
        try {
            new RawJson($text);
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('The payload is not valid JSON.', $e->getMessage());
            if ($text !== '') {
                $this->assertStringNotContainsString($text, $e->getMessage());
            }

            return;
        }

        $this->fail('Expected an InvalidArgumentException.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTexts(): iterable
    {
        yield 'empty' => [''];
        yield 'unclosed object' => ['{'];
        yield 'trailing text' => ['{"a":1} x'];
        yield 'misspelled null' => ['nul'];
    }
}
