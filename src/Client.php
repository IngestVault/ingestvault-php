<?php

declare(strict_types=1);

namespace IngestVault;

use GuzzleHttp\ClientInterface;
use IngestVault\Resource\Deliveries;
use IngestVault\Resource\Endpoints;
use IngestVault\Resource\Events;
use IngestVault\Resource\EventTypes;
use IngestVault\Resource\Organization;
use IngestVault\Resource\SigningSecrets;
use IngestVault\Resource\Subscriptions;

final class Client
{
    public const VERSION = '0.5.0';

    public readonly Events $events;

    public readonly Endpoints $endpoints;

    public readonly SigningSecrets $signingSecrets;

    public readonly Subscriptions $subscriptions;

    public readonly EventTypes $eventTypes;

    public readonly Deliveries $deliveries;

    public readonly Organization $organization;

    public function __construct(
        #[\SensitiveParameter]
        string $apiKey,
        string $baseUrl = 'https://api.ingestvault.com/v1',
        float $timeout = 10.0,
        int $retries = 2,
        ?ClientInterface $httpClient = null,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('An API key is required.');
        }

        if (preg_match('/^[\x21-\x7E]+$/D', $apiKey) !== 1) {
            throw new \InvalidArgumentException('The API key contains characters that cannot be sent in a header.');
        }

        $transport = new Transport(
            $apiKey,
            rtrim($baseUrl, '/'),
            $timeout,
            $retries,
            $httpClient ?? new \GuzzleHttp\Client(),
        );

        $this->events = new Events($transport);
        $this->endpoints = new Endpoints($transport);
        $this->signingSecrets = new SigningSecrets($transport);
        $this->subscriptions = new Subscriptions($transport);
        $this->eventTypes = new EventTypes($transport);
        $this->deliveries = new Deliveries($transport);
        $this->organization = new Organization($transport);
    }
}
