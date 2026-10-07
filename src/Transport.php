<?php

declare(strict_types=1);

namespace IngestVault;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use IngestVault\Exception\ApiException;
use IngestVault\Exception\NetworkException;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
final class Transport
{
    private readonly \SensitiveParameterValue $apiKey;

    public function __construct(
        #[\SensitiveParameter]
        string $apiKey,
        private readonly string $baseUrl,
        private readonly float $timeout,
        private readonly int $retries,
        private readonly ClientInterface $httpClient,
    ) {
        $this->apiKey = new \SensitiveParameterValue($apiKey);
    }

    /**
     * @param array<string, string|int|null> $query
     * @param array<string, string> $headers
     */
    public function request(string $method, string $path, array $query = [], ?string $body = null, array $headers = [], bool $retry = true): ResponseInterface
    {
        $options = [];
        $query = array_filter($query, static fn(string|int|null $value): bool => $value !== null);

        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($body !== null) {
            $options['body'] = $body;
            $headers += ['Content-Type' => 'application/json'];
        }

        $retries = $retry ? $this->retries : 0;

        for ($try = 1; ; $try++) {
            if ($try > 1) {
                usleep(250_000 * ($try - 1));
            }
            $isLastTry = $try > $retries;

            try {
                $response = $this->httpClient->request($method, $this->baseUrl . $path, [
                    ...$options,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey->getValue(),
                        'Accept' => 'application/json',
                        'User-Agent' => 'ingestvault-php/' . Client::VERSION,
                        ...$headers,
                    ],
                    'timeout' => $this->timeout,
                    'connect_timeout' => $this->timeout,
                    'http_errors' => false,
                    'allow_redirects' => false,
                ]);
            } catch (TransferException $e) {
                if ($isLastTry) {
                    // Not chained: the Guzzle exception holds the request, Authorization header included.
                    throw new NetworkException($e->getMessage());
                }

                continue;
            } catch (\InvalidArgumentException $e) {
                // Rebuilt without chaining so the trace loses the request() frame, whose options hold the Authorization header.
                throw new \InvalidArgumentException($e->getMessage());
            }

            $status = $response->getStatusCode();

            if ($status >= 500 && ! $isLastTry) {
                continue;
            }

            if ($status >= 200 && $status < 300) {
                return $response;
            }

            throw ApiException::fromResponse($response);
        }
    }

    /**
     * @param array<mixed> $fields
     *
     * @throws \JsonException When a value cannot be encoded as JSON.
     */
    public static function encode(array $fields): string
    {
        // A request body is always a JSON object; an empty PHP array would encode as a list.
        return json_encode(
            $fields === [] ? new \stdClass() : $fields,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
