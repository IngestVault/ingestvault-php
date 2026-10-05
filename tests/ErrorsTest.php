<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Psr7\Response;
use IngestVault\Exception\ApiException;
use IngestVault\Exception\AuthenticationException;
use IngestVault\Exception\IdempotencyConflictException;
use IngestVault\Exception\IngestVaultException;
use IngestVault\Exception\PayloadTooLargeException;
use IngestVault\Exception\RateLimitedException;
use IngestVault\Exception\ServerException;
use IngestVault\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ErrorsTest extends TestCase
{
    private function raised(Response $response): ApiException
    {
        try {
            $this->client([$response], retries: 0)->sendEvent('order.created');
        } catch (ApiException $e) {
            return $e;
        }

        $this->fail('Expected an ApiException.');
    }

    public function test_a_422_raises_a_validation_error_with_the_field_errors_as_received(): void
    {
        $errors = [
            'type' => ['The type must start with a lowercase letter.', 'The type may not be longer than 128 characters.'],
            'payload.items.0' => ['Example message.'],
        ];

        $e = $this->raised(self::problemResponse(422, 'validation_failed', 'The given data was invalid.', ['errors' => $errors]));

        $this->assertInstanceOf(ValidationException::class, $e);
        $this->assertSame(422, $e->status);
        $this->assertSame('validation_failed', $e->problemCode);
        $this->assertSame('The given data was invalid.', $e->getMessage());
        $this->assertSame($errors, $e->errors);
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('distinguishableErrors')]
    public function test_an_authentication_failure_an_idempotency_conflict_and_a_too_large_payload_are_distinguishable(int $status, string $code, string $class): void
    {
        $e = $this->raised(self::problemResponse($status, $code, 'Refused.'));

        $this->assertInstanceOf($class, $e);
        $this->assertSame($status, $e->status);
        $this->assertSame($code, $e->problemCode);
        $this->assertSame('Refused.', $e->getMessage());
    }

    /**
     * @return iterable<string, array{int, string, class-string<ApiException>}>
     */
    public static function distinguishableErrors(): iterable
    {
        yield 'authentication failure' => [401, 'unauthenticated', AuthenticationException::class];
        yield 'idempotency conflict' => [409, 'idempotency_key_conflict', IdempotencyConflictException::class];
        yield 'payload too large' => [413, 'payload_too_large', PayloadTooLargeException::class];
    }

    public function test_an_unknown_problem_code_raises_a_general_api_error_with_the_code(): void
    {
        $e = $this->raised(self::problemResponse(403, 'organization_suspended', 'The organization is suspended.'));

        $this->assertSame(ApiException::class, $e::class);
        $this->assertInstanceOf(IngestVaultException::class, $e);
        $this->assertSame(403, $e->status);
        $this->assertSame('organization_suspended', $e->problemCode);
        $this->assertSame('The organization is suspended.', $e->getMessage());
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('answersWithoutProblemBody')]
    public function test_answer_without_problem_body(int $status, string $class): void
    {
        $e = $this->raised(new Response($status, ['Content-Type' => 'text/html'], '<html><body>nginx</body></html>'));

        $this->assertSame($class, $e::class);
        $this->assertSame($status, $e->status);
        $this->assertNull($e->problemCode);
        $this->assertSame(sprintf('The API answered with HTTP %d.', $status), $e->getMessage());
    }

    /**
     * @return iterable<string, array{int, class-string<ApiException>}>
     */
    public static function answersWithoutProblemBody(): iterable
    {
        yield 'gateway error' => [502, ServerException::class];
        yield 'body too large at the proxy' => [413, PayloadTooLargeException::class];
    }

    public function test_an_unreadable_success_body_raises_a_general_api_error(): void
    {
        $e = $this->raised(new Response(200, ['Content-Type' => 'text/html'], '<html>Welcome</html>'));

        $this->assertSame(ApiException::class, $e::class);
        $this->assertSame(200, $e->status);
        $this->assertNull($e->problemCode);
    }

    public function test_a_retry_after_of_zero_is_read_as_zero(): void
    {
        $e = $this->raised(self::problemResponse(429, 'rate_limited', 'Slow down.', headers: ['Retry-After' => '0']));

        $this->assertInstanceOf(RateLimitedException::class, $e);
        $this->assertSame(0, $e->retryAfter);
    }

    public function test_a_missing_retry_after_is_read_as_null(): void
    {
        $e = $this->raised(self::problemResponse(429, 'rate_limited', 'Slow down.'));

        $this->assertInstanceOf(RateLimitedException::class, $e);
        $this->assertNull($e->retryAfter);
    }
}
