<?php

declare(strict_types=1);

namespace IngestVault;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use IngestVault\Exception\ApiException;
use IngestVault\Exception\NetworkException;

final class Client
{
    public const VERSION = '0.1.0';

    private readonly \SensitiveParameterValue $apiKey;

    private readonly string $baseUrl;

    private readonly ClientInterface $httpClient;

    public function __construct(
        #[\SensitiveParameter]
        string $apiKey,
        string $baseUrl = 'https://api.ingestvault.com/v1',
        private readonly float $timeout = 10.0,
        private readonly int $retries = 2,
        ?ClientInterface $httpClient = null,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('An API key is required.');
        }

        if (preg_match('/^[\x21-\x7E]+$/D', $apiKey) !== 1) {
            throw new \InvalidArgumentException('The API key contains characters that cannot be sent in a header.');
        }

        $this->apiKey = new \SensitiveParameterValue($apiKey);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->httpClient = $httpClient ?? new \GuzzleHttp\Client();
    }

    /**
     * @throws \JsonException When the payload cannot be encoded as JSON.
     */
    public function sendEvent(string $type, mixed $payload = null, ?string $idempotencyKey = null): Event
    {
        return $this->sendPreparedEvent(new PreparedEvent($type, $payload, $idempotencyKey));
    }

    public function sendPreparedEvent(PreparedEvent $event): Event
    {
        for ($try = 1; ; $try++) {
            if ($try > 1) {
                usleep(250_000 * ($try - 1));
            }
            $isLastTry = $try > $this->retries;

            try {
                $response = $this->httpClient->request('POST', $this->baseUrl . '/events', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey->getValue(),
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Idempotency-Key' => $event->idempotencyKey,
                        'User-Agent' => 'ingestvault-php/' . self::VERSION,
                    ],
                    'body' => $event->body,
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
                return Event::fromResponse($response);
            }

            throw ApiException::fromResponse($response);
        }
    }
}
