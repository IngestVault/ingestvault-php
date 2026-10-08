<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use IngestVault\Resource\Deliveries;
use IngestVault\Resource\Endpoints;
use IngestVault\Resource\Events;
use IngestVault\Resource\EventTypes;
use IngestVault\Resource\Organization;
use IngestVault\Resource\SigningSecrets;
use IngestVault\Resource\Subscriptions;
use Symfony\Component\Yaml\Yaml;

/**
 * Compares the client with the OpenAPI document named by INGESTVAULT_OPENAPI_SPEC. An operation is published when
 * it is not marked x-internal and accepts an API key.
 */
final class SpecCoverageTest extends TestCase
{
    private const OPERATIONS = [
        'ingestEvent' => [Events::class, 'send'],
        'listEvents' => [Events::class, 'list'],
        'getEvent' => [Events::class, 'get'],
        'replayEvent' => [Events::class, 'replay'],
        'listDeliveries' => [Deliveries::class, 'list'],
        'getDelivery' => [Deliveries::class, 'get'],
        'replayDelivery' => [Deliveries::class, 'replay'],
        'listEndpoints' => [Endpoints::class, 'list'],
        'createEndpoint' => [Endpoints::class, 'create'],
        'getEndpoint' => [Endpoints::class, 'get'],
        'updateEndpoint' => [Endpoints::class, 'update'],
        'deleteEndpoint' => [Endpoints::class, 'delete'],
        'listSigningSecrets' => [SigningSecrets::class, 'list'],
        'rotateSigningSecret' => [SigningSecrets::class, 'rotate'],
        'listSubscriptions' => [Subscriptions::class, 'list'],
        'createSubscription' => [Subscriptions::class, 'create'],
        'getSubscription' => [Subscriptions::class, 'get'],
        'updateSubscription' => [Subscriptions::class, 'update'],
        'deleteSubscription' => [Subscriptions::class, 'delete'],
        'listEventTypes' => [EventTypes::class, 'list'],
        'createEventType' => [EventTypes::class, 'create'],
        'getEventType' => [EventTypes::class, 'get'],
        'updateEventType' => [EventTypes::class, 'update'],
        'archiveEventType' => [EventTypes::class, 'archive'],
        'unarchiveEventType' => [EventTypes::class, 'unarchive'],
        'getCurrentOrganization' => [Organization::class, 'current'],
    ];

    private const GROUPS = [Events::class, Deliveries::class, Endpoints::class, SigningSecrets::class, Subscriptions::class, EventTypes::class, Organization::class];

    private const NOT_OPERATIONS = ['all', 'sendPrepared'];

    public function test_every_published_operation_is_offered_exactly_once(): void
    {
        $published = self::operations()['published'];
        $offered = array_keys(self::OPERATIONS);
        sort($published);
        sort($offered);

        $this->assertSame($published, $offered);
    }

    public function test_spec_only_operations_are_not_offered(): void
    {
        $specOnly = self::operations()['specOnly'];

        $this->assertNotSame([], $specOnly);
        $this->assertSame([], array_values(array_intersect($specOnly, array_keys(self::OPERATIONS))));
    }

    public function test_every_group_method_is_a_published_operation(): void
    {
        $offered = [];
        foreach (self::OPERATIONS as [$class, $method]) {
            $this->assertTrue((new \ReflectionMethod($class, $method))->isPublic(), $class . '::' . $method);
            $offered[] = $class . '::' . $method;
        }
        $this->assertSame($offered, array_values(array_unique($offered)));

        foreach (self::GROUPS as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() || in_array($method->getName(), self::NOT_OPERATIONS, true)) {
                    continue;
                }
                $this->assertContains($class . '::' . $method->getName(), $offered);
            }
        }
    }

    public function test_the_spec_documents_the_request_id_the_client_reads(): void
    {
        $spec = self::spec();

        $this->assertSame('string', $spec['components']['headers']['RequestId']['schema']['type'] ?? null);
        $this->assertSame('string', $spec['components']['schemas']['Problem']['properties']['request_id']['type'] ?? null);
    }

    /**
     * @return array<mixed>
     */
    private static function spec(): array
    {
        $path = getenv('INGESTVAULT_OPENAPI_SPEC');
        if ($path === false || $path === '' || ! is_readable($path)) {
            self::markTestSkipped('Set INGESTVAULT_OPENAPI_SPEC to the path of the OpenAPI document to compare with.');
        }

        $spec = Yaml::parseFile($path);

        return is_array($spec) ? $spec : [];
    }

    /**
     * @return array{published: list<string>, specOnly: list<string>}
     */
    private static function operations(): array
    {
        $spec = self::spec();
        $operations = ['published' => [], 'specOnly' => []];

        foreach ($spec['paths'] ?? [] as $pathItem) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $verb) {
                $operation = $pathItem[$verb] ?? null;
                if (! is_array($operation)) {
                    continue;
                }

                if (($operation['x-internal'] ?? false) === true) {
                    $operations['specOnly'][] = $operation['operationId'];
                } elseif (in_array('apiKeyAuth', array_merge(...array_map('array_keys', $operation['security'] ?? [])), true)) {
                    $operations['published'][] = $operation['operationId'];
                }
            }
        }

        return $operations;
    }
}
