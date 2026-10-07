<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use IngestVault\Payload;
use IngestVault\PreparedEvent;
use IngestVault\RawJson;
use PHPUnit\Framework\Attributes\DataProvider;

final class PreparedEventTest extends TestCase
{
    public function test_a_send_prepared_now_is_executed_later_with_the_same_key(): void
    {
        $prepared = new PreparedEvent('order.created', ['order' => 1042]);
        $queued = serialize($prepared);

        $later = unserialize($queued);
        $this->assertInstanceOf(PreparedEvent::class, $later);
        $this->client([self::eventResponse()])->events->sendPrepared($later);

        $this->assertSame($prepared->idempotencyKey, $this->sentHeader(0, 'Idempotency-Key'));
        $this->assertSame($prepared->body, (string) $this->history[0]['request']->getBody());
    }

    public function test_a_supplied_key_is_used_unchanged(): void
    {
        $this->assertSame(' Order 1042 ', (new PreparedEvent('order.created', idempotencyKey: ' Order 1042 '))->idempotencyKey);
    }

    public function test_a_generated_key_is_a_uuid_v4(): void
    {
        $key = (new PreparedEvent('order.created'))->idempotencyKey;

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $key);
    }

    public function test_a_send_without_a_payload_has_no_payload_member(): void
    {
        $this->assertSame('{"type":"order.created"}', (new PreparedEvent('order.created'))->body);
        $this->assertSame('{"type":"order.created"}', (new PreparedEvent('order.created', Payload::None))->body);
    }

    public function test_a_null_payload_is_sent_as_null(): void
    {
        $this->assertSame('{"type":"order.created","payload":null}', (new PreparedEvent('order.created', null))->body);
    }

    public function test_raw_json_is_sent_byte_for_byte(): void
    {
        $text = '{ "ratio": 1.0, "e": 1.5e3, "big": 123456789012345678901234567890, "a": 1, "a": 2 }';

        $this->assertSame('{"type":"order.created","payload":' . $text . '}', (new PreparedEvent('order.created', new RawJson($text)))->body);
        $this->assertSame("{\"type\":\"order.created\",\"payload\": \n[1, 2]\t}", (new PreparedEvent('order.created', new RawJson(" \n[1, 2]\t")))->body);
    }

    public function test_the_body_is_fixed_when_prepared(): void
    {
        $payload = new \stdClass();
        $payload->order = 1042;
        $prepared = new PreparedEvent('order.created', $payload);

        $payload->order = 1043;

        $this->assertSame('{"type":"order.created","payload":{"order":1042}}', $prepared->body);
    }

    #[DataProvider('payloads')]
    public function test_the_payload_is_encoded_as_given(mixed $payload, string $encoded): void
    {
        $this->assertSame('{"type":"order.created","payload":' . $encoded . '}', (new PreparedEvent('order.created', $payload))->body);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function payloads(): iterable
    {
        yield 'empty array' => [[], '[]'];
        yield 'empty object' => [new \stdClass(), '{}'];
        yield 'zero' => [0, '0'];
        yield 'false' => [false, 'false'];
        yield 'zero fraction' => [1.0, '1.0'];
        yield 'string' => ['a/b ü', '"a/b ü"'];
    }

    public function test_an_unencodable_payload_fails_when_prepared(): void
    {
        $this->expectException(\JsonException::class);

        new PreparedEvent('order.created', ['name' => "\xB1\x31"]);
    }
}
