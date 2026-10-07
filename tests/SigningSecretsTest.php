<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use IngestVault\Exception\ApiException;
use IngestVault\Exception\NetworkException;
use IngestVault\SigningSecret;

final class SigningSecretsTest extends TestCase
{
    private const SECRET = 'whsec_knownSecretValueForTests';

    private const ENDPOINT = '0199b2c4-1111-7a3b-9c4d-5e6f7a8b9c01';

    private const BASE = 'https://api.example.test/v1/endpoints/' . self::ENDPOINT . '/signing-secrets';

    private static function secretsResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => [
            self::signingSecret(['id' => 'current', 'secret' => self::SECRET]),
            self::signingSecret(['id' => 'previous', 'secret' => 'whsec_previousSecretValue', 'expires_at' => '2026-10-08T08:00:00Z']),
        ]], JSON_THROW_ON_ERROR));
    }

    public function test_lists_the_secrets_with_the_expected_request(): void
    {
        $this->client([self::secretsResponse()])->signingSecrets->list(self::ENDPOINT);

        $this->assertCount(1, $this->history);
        $this->assertSentRequest('GET', self::BASE, null);
    }

    public function test_rotates_the_secret_with_the_expected_request(): void
    {
        $this->client([self::signingSecretResponse(201)])->signingSecrets->rotate(self::ENDPOINT);

        $this->assertCount(1, $this->history);
        $this->assertSentRequest('POST', self::BASE . '/rotate', null);
    }

    public function test_an_endpoint_id_holding_a_slash_is_encoded(): void
    {
        $this->client([self::secretsResponse()])->signingSecrets->list('a/b');

        $this->assertSame('https://api.example.test/v1/endpoints/a%2Fb/signing-secrets', $this->sentUri(0));
    }

    public function test_returns_the_signing_secret_from_the_answer(): void
    {
        $secret = $this->client([self::signingSecretResponse(201, ['secret' => self::SECRET])])->signingSecrets->rotate(self::ENDPOINT);

        $this->assertSame('0199b2c4-4444-7a3b-9c4d-5e6f7a8b9c04', $secret->id);
        $this->assertSame(self::SECRET, $secret->secret());
        $this->assertNull($secret->expiresAt);
        $this->assertEquals(new \DateTimeImmutable('2026-10-01T08:00:00Z'), $secret->createdAt);
        $this->assertEquals(new \DateTimeImmutable('2026-10-01T08:00:00Z'), $secret->updatedAt);
    }

    public function test_unknown_members_in_the_signing_secret_are_ignored(): void
    {
        $secret = $this->client([self::signingSecretResponse(201, ['algorithm' => 'hmac-sha256', 'label' => 'primary'])])
            ->signingSecrets->rotate(self::ENDPOINT);

        $this->assertSame('0199b2c4-4444-7a3b-9c4d-5e6f7a8b9c04', $secret->id);
    }

    public function test_lists_the_secrets_in_the_order_of_the_answer_with_the_grace_secret_keeping_its_expiry(): void
    {
        $secrets = $this->client([self::secretsResponse()])->signingSecrets->list(self::ENDPOINT);

        $this->assertSame(['current', 'previous'], array_map(static fn(SigningSecret $secret): string => $secret->id, $secrets));
        $this->assertSame(self::SECRET, $secrets[0]->secret());
        $this->assertNull($secrets[0]->expiresAt);
        $this->assertSame('whsec_previousSecretValue', $secrets[1]->secret());
        $this->assertEquals(new \DateTimeImmutable('2026-10-08T08:00:00Z'), $secrets[1]->expiresAt);
    }

    public function test_the_order_of_the_answer_is_kept_as_received(): void
    {
        $answer = new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => [
            self::signingSecret(['id' => 'previous', 'expires_at' => '2026-10-08T08:00:00Z']),
            self::signingSecret(['id' => 'current']),
        ]], JSON_THROW_ON_ERROR));

        $secrets = $this->client([$answer])->signingSecrets->list(self::ENDPOINT);

        $this->assertSame(['previous', 'current'], array_map(static fn(SigningSecret $secret): string => $secret->id, $secrets));
    }

    public function test_an_expiry_that_is_not_a_timestamp_makes_the_answer_unreadable(): void
    {
        $client = $this->client([self::signingSecretResponse(201, ['expires_at' => 'tomorrow'])]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('The API answered with HTTP 201 but the body is not a signing secret.');

        $client->signingSecrets->rotate(self::ENDPOINT);
    }

    public function test_the_secret_is_absent_from_every_printable_form_of_a_signing_secret(): void
    {
        $secret = $this->client([self::signingSecretResponse(201, ['secret' => self::SECRET])])->signingSecrets->rotate(self::ENDPOINT);

        ob_start();
        var_dump($secret);
        $dumped = (string) ob_get_clean();

        try {
            $serialized = serialize($secret);
        } catch (\Exception $e) {
            $serialized = $e->getMessage();
        }

        foreach ([
            'print_r' => print_r($secret, true),
            'var_export' => var_export($secret, true),
            'var_dump' => $dumped,
            'json_encode' => json_encode($secret, JSON_THROW_ON_ERROR),
            'array cast' => print_r((array) $secret, true),
            'serialize' => $serialized,
        ] as $form => $output) {
            $this->assertStringNotContainsString(self::SECRET, $output, $form);
        }
    }

    public function test_the_secret_is_absent_from_an_error_about_an_unreadable_list_that_holds_it(): void
    {
        $answer = new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => [
            ['secret' => self::SECRET, 'expires_at' => null],
        ]], JSON_THROW_ON_ERROR));
        $client = $this->client([$answer]);

        try {
            $client->signingSecrets->list('ep-trace-argument');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->history = [];
            $printed = print_r($e, true);

            $this->assertSame(ApiException::class, $e::class);
            $this->assertSame(200, $e->status);
            $this->assertSame("The API answered with HTTP 200 but the body is not the endpoint's signing secrets.", $e->getMessage());
            $this->assertStringContainsString('ep-trace-argument', $printed, 'trace arguments are included');
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET, (string) $e);
            $this->assertStringNotContainsString(self::SECRET, $printed);
        }
    }

    public function test_the_secret_is_absent_from_a_network_error_after_it_was_listed(): void
    {
        $client = $this->client([
            self::secretsResponse(),
            new ConnectException('Connection reset by peer', new Request('POST', 'signing-secrets/rotate')),
        ], retries: 0);

        $secrets = $client->signingSecrets->list(self::ENDPOINT);
        $this->assertSame(self::SECRET, $secrets[0]->secret());

        try {
            $client->signingSecrets->rotate('ep-trace-argument');
            $this->fail('Expected a NetworkException.');
        } catch (NetworkException $e) {
            $this->history = [];
            $printed = print_r($e, true);

            $this->assertStringContainsString('ep-trace-argument', $printed, 'trace arguments are included');
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET, (string) $e);
            $this->assertStringNotContainsString(self::SECRET, $printed);
            $this->assertNull($e->getPrevious());
        }
    }
}
