<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use IngestVault\Exception\ApiException;
use IngestVault\Exception\NetworkException;
use IngestVault\Source;
use PHPUnit\Framework\Attributes\DataProvider;

final class SourcesTest extends TestCase
{
    private const ID = '0199b2c4-aaaa-7a3b-9c4d-5e6f7a8b9c0a';

    private const ENDPOINT = '0199b2c4-1111-7a3b-9c4d-5e6f7a8b9c01';

    private const BASE = 'https://api.example.test/v1';

    private const URL = 'https://hooks.example.test/currentTokenValueForTests';

    private const GRACE_URL = 'https://hooks.example.test/previousTokenValueForTests';

    public function test_a_source_is_created_with_the_published_body(): void
    {
        $source = $this->client([self::sourceResponse(201)])->sources->create('GitHub');

        $this->assertCount(1, $this->history);
        $this->assertSentRequest('POST', self::BASE . '/sources', '{"name":"GitHub","description":null,"enabled":true,"endpoint_ids":[]}');
        $this->assertSame(self::ID, $source->id);
    }

    public function test_a_source_is_created_with_every_member(): void
    {
        $this->client([self::sourceResponse(201)])->sources->create('GitHub', 'Repository hooks', [1 => self::ENDPOINT, 3 => 'second'], false);

        $this->assertSentRequest(
            'POST',
            self::BASE . '/sources',
            '{"name":"GitHub","description":"Repository hooks","enabled":false,"endpoint_ids":["' . self::ENDPOINT . '","second"]}',
        );
    }

    public function test_a_source_is_read(): void
    {
        $source = $this->client([self::sourceResponse()])->sources->get(self::ID);

        $this->assertSentRequest('GET', self::BASE . '/sources/' . self::ID, null);
        $this->assertSame(self::ID, $source->id);
        $this->assertSame('GitHub', $source->name);
        $this->assertSame('Repository hooks', $source->description);
        $this->assertTrue($source->enabled);
        $this->assertSame('https://hooks.example.test/6f1c0b9e4a7d2c85e3f0a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f6', $source->url());
        $this->assertNull($source->graceUrl());
        $this->assertNull($source->graceUrlExpiresAt);
        $this->assertSame([self::ENDPOINT], $source->endpointIds);
        $this->assertEquals(new \DateTimeImmutable('2026-10-01T08:00:00Z'), $source->createdAt);
        $this->assertEquals(new \DateTimeImmutable('2026-10-02T09:30:00Z'), $source->updatedAt);
    }

    public function test_a_source_id_holding_a_slash_and_a_space_is_encoded(): void
    {
        $this->client([self::sourceResponse()])->sources->get('a/b c');

        $this->assertSame(self::BASE . '/sources/a%2Fb%20c', $this->sentUri(0));
    }

    public function test_a_disabled_source_without_a_description_or_endpoints_is_read_as_such(): void
    {
        $source = $this->client([self::sourceResponse(overrides: ['description' => null, 'enabled' => false, 'endpoint_ids' => []])])
            ->sources->get(self::ID);

        $this->assertNull($source->description);
        $this->assertFalse($source->enabled);
        $this->assertSame([], $source->endpointIds);
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('updates')]
    public function test_an_update_sends_only_the_given_fields(array $fields, string $body): void
    {
        $this->client([self::sourceResponse()])->sources->update(self::ID, $fields);

        $this->assertCount(1, $this->history);
        $this->assertSentRequest('PATCH', self::BASE . '/sources/' . self::ID, $body);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function updates(): iterable
    {
        yield 'a name and a disabled flag' => [['name' => 'Stripe', 'enabled' => false], '{"name":"Stripe","enabled":false}'];
        yield 'clearing the description' => [['description' => null], '{"description":null}'];
        yield 'disconnecting every endpoint' => [['endpoint_ids' => []], '{"endpoint_ids":[]}'];
        yield 'no fields' => [[], '{}'];
    }

    public function test_an_update_sends_endpoint_ids_as_a_list(): void
    {
        $this->client([self::sourceResponse()])->sources->update(self::ID, ['endpoint_ids' => ['first' => self::ENDPOINT, 'second' => 'other']]);

        $this->assertSentRequest('PATCH', self::BASE . '/sources/' . self::ID, '{"endpoint_ids":["' . self::ENDPOINT . '","other"]}');
    }

    public function test_a_source_is_deleted(): void
    {
        $this->client([new Response(204)])->sources->delete(self::ID);

        $this->assertCount(1, $this->history);
        $this->assertSentRequest('DELETE', self::BASE . '/sources/' . self::ID, null);
    }

    public function test_rotating_the_url_returns_the_source_with_both_urls(): void
    {
        $source = $this->client([self::sourceResponse(overrides: [
            'url' => self::URL,
            'grace_url' => self::GRACE_URL,
            'grace_url_expires_at' => '2026-10-22T08:00:00Z',
        ])])->sources->rotateUrl(self::ID);

        $this->assertCount(1, $this->history);
        $this->assertSentRequest('POST', self::BASE . '/sources/' . self::ID . '/rotate-url', null);
        $this->assertSame(self::URL, $source->url());
        $this->assertSame(self::GRACE_URL, $source->graceUrl());
        $this->assertEquals(new \DateTimeImmutable('2026-10-22T08:00:00Z'), $source->graceUrlExpiresAt);
    }

    public function test_a_page_of_sources_is_read(): void
    {
        $page = $this->client([self::pageResponse([self::source(), self::source(['id' => 'second', 'endpoint_ids' => []])], 'c2')])
            ->sources->list(10, 'c1');

        $this->assertSentRequest('GET', self::BASE . '/sources?page_size=10&cursor=c1', null);
        $this->assertSame([self::ID, 'second'], array_map(static fn(Source $source): string => $source->id, $page->data));
        $this->assertSame([], $page->data[1]->endpointIds);
        $this->assertTrue($page->hasMore);
        $this->assertSame('c2', $page->nextCursor);
    }

    public function test_all_iterates_every_page(): void
    {
        $client = $this->client([
            self::pageResponse([self::source(['id' => 'a'])], 'cursor-2'),
            self::pageResponse([self::source(['id' => 'b'])], null),
        ]);

        $ids = array_map(static fn(Source $source): string => $source->id, iterator_to_array($client->sources->all(5), false));

        $this->assertSame(['a', 'b'], $ids);
        $this->assertCount(2, $this->history);
        $this->assertSame(self::BASE . '/sources?page_size=5', $this->sentUri(0));
        $this->assertSame(self::BASE . '/sources?page_size=5&cursor=cursor-2', $this->sentUri(1));
    }

    public function test_unknown_members_in_the_source_are_ignored(): void
    {
        $source = $this->client([self::sourceResponse(overrides: ['provider' => 'github', 'labels' => ['team' => 'ops']])])->sources->get(self::ID);

        $this->assertSame(self::ID, $source->id);
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     */
    #[DataProvider('unreadableSources')]
    public function test_a_source_of_an_unreadable_shape_makes_the_answer_unreadable(\Closure $change): void
    {
        $client = $this->client([self::jsonSource($change(self::source()))]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('The API answered with HTTP 200 but the body is not a source.');

        $client->sources->get(self::ID);
    }

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>}>
     */
    public static function unreadableSources(): iterable
    {
        yield 'no grace url' => [static function (array $source): array {
            unset($source['grace_url']);

            return $source;
        }];
        yield 'no grace url expiry' => [static function (array $source): array {
            unset($source['grace_url_expires_at']);

            return $source;
        }];
        yield 'an endpoint id that is a number' => [static fn(array $source): array => ['endpoint_ids' => [42]] + $source];
        yield 'endpoint ids that are a map' => [static fn(array $source): array => ['endpoint_ids' => ['a' => self::ENDPOINT]] + $source];
        yield 'a grace url expiry that is not a timestamp' => [static fn(array $source): array => ['grace_url_expires_at' => 'tomorrow'] + $source];
        yield 'a url that is not a string' => [static fn(array $source): array => ['url' => null] + $source];
    }

    public function test_the_urls_are_absent_from_every_printable_form_of_a_source(): void
    {
        $source = $this->client([self::sourceResponse(overrides: [
            'url' => self::URL,
            'grace_url' => self::GRACE_URL,
            'grace_url_expires_at' => '2026-10-22T08:00:00Z',
        ])])->sources->rotateUrl(self::ID);

        $this->assertNoUrlIsPrintable($source);
    }

    public function test_the_urls_are_absent_from_every_printable_form_of_a_source_without_a_grace_window(): void
    {
        $source = $this->client([self::sourceResponse(overrides: ['url' => self::URL])])->sources->get(self::ID);

        $this->assertSame(self::URL, $source->url());
        $this->assertNoUrlIsPrintable($source);
    }

    public function test_the_urls_are_absent_from_an_error_about_an_unreadable_answer_that_holds_them(): void
    {
        $client = $this->client([self::jsonSource(['url' => self::URL, 'grace_url' => self::GRACE_URL, 'endpoint_ids' => 'none'])]);

        try {
            $client->sources->get('src-trace-argument');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->history = [];
            $printed = print_r($e, true);

            $this->assertSame(ApiException::class, $e::class);
            $this->assertSame(200, $e->status);
            $this->assertSame('The API answered with HTTP 200 but the body is not a source.', $e->getMessage());
            $this->assertStringContainsString('src-trace-argument', $printed, 'trace arguments are included');
            foreach ([self::URL, self::GRACE_URL] as $url) {
                $this->assertStringNotContainsString($url, $e->getMessage());
                $this->assertStringNotContainsString($url, (string) $e);
                $this->assertStringNotContainsString($url, $printed);
            }
        }
    }

    public function test_the_urls_are_absent_from_a_network_error(): void
    {
        $client = $this->client([
            self::sourceResponse(overrides: ['url' => self::URL, 'grace_url' => self::GRACE_URL, 'grace_url_expires_at' => '2026-10-22T08:00:00Z']),
            new ConnectException('Connection reset by peer', new Request('POST', 'sources/rotate-url')),
        ], retries: 0);

        $source = $client->sources->get(self::ID);
        $this->assertSame(self::URL, $source->url());

        try {
            $client->sources->rotateUrl('src-trace-argument');
            $this->fail('Expected a NetworkException.');
        } catch (NetworkException $e) {
            $this->history = [];
            $printed = print_r($e, true);

            $this->assertStringContainsString('src-trace-argument', $printed, 'trace arguments are included');
            foreach ([self::URL, self::GRACE_URL] as $url) {
                $this->assertStringNotContainsString($url, $e->getMessage());
                $this->assertStringNotContainsString($url, (string) $e);
                $this->assertStringNotContainsString($url, $printed);
            }
            $this->assertNull($e->getPrevious());
        }
    }

    private function assertNoUrlIsPrintable(Source $source): void
    {
        ob_start();
        var_dump($source);
        $dumped = (string) ob_get_clean();

        try {
            $serialized = serialize($source);
        } catch (\Exception $e) {
            $serialized = $e->getMessage();
        }

        foreach ([
            'print_r' => print_r($source, true),
            'var_export' => var_export($source, true),
            'var_dump' => $dumped,
            'json_encode' => json_encode($source, JSON_THROW_ON_ERROR),
            'array cast' => print_r((array) $source, true),
            'serialize' => $serialized,
        ] as $form => $output) {
            $this->assertStringNotContainsString(self::URL, $output, $form);
            $this->assertStringNotContainsString(self::GRACE_URL, $output, $form);
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function jsonSource(array $source): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($source, JSON_THROW_ON_ERROR));
    }
}
