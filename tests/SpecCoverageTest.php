<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use IngestVault\Resource\Deliveries;
use IngestVault\Resource\Endpoints;
use IngestVault\Resource\Events;
use IngestVault\Resource\EventTypes;
use IngestVault\Resource\Organization;
use IngestVault\Resource\SigningSecrets;
use IngestVault\Resource\Sources;
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
        'listSources' => [Sources::class, 'list'],
        'createSource' => [Sources::class, 'create'],
        'getSource' => [Sources::class, 'get'],
        'updateSource' => [Sources::class, 'update'],
        'deleteSource' => [Sources::class, 'delete'],
        'rotateSourceUrl' => [Sources::class, 'rotateUrl'],
    ];

    private const GROUPS = [Events::class, Deliveries::class, Endpoints::class, SigningSecrets::class, Subscriptions::class, EventTypes::class, Organization::class, Sources::class];

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

    public function test_the_spec_documents_the_inactive_organization_answer_and_fields_the_client_reads(): void
    {
        $spec = self::spec();
        $example = $spec['components']['responses']['OrganizationUnpaid']['content']['application/problem+json']['example'] ?? [];
        $organization = $spec['components']['schemas']['Organization'] ?? [];

        $this->assertSame(402, $example['status'] ?? null);
        $this->assertSame('organization_unpaid', $example['code'] ?? null);
        $this->assertContains('state', $organization['required'] ?? []);
        $this->assertArrayHasKey('plan', $organization['properties'] ?? []);
        $this->assertArrayHasKey('unpaid_reason', $organization['properties'] ?? []);
    }

    public function test_the_spec_documents_the_source_and_inbound_members_the_client_reads(): void
    {
        $spec = self::spec();
        $schemas = $spec['components']['schemas'] ?? [];
        $inbound = ['origin', 'source', 'provider_event_type'];

        foreach (array_merge($inbound, ['body', 'body_encoding', 'content_type', 'headers', 'query_string']) as $member) {
            $this->assertContains($member, $schemas['Event']['required'] ?? [], 'Event.' . $member);
        }
        foreach (['EventSummary', 'Delivery'] as $schema) {
            foreach ($inbound as $member) {
                $this->assertContains($member, $schemas[$schema]['required'] ?? [], $schema . '.' . $member);
            }
        }
        foreach (['url', 'grace_url', 'grace_url_expires_at', 'endpoint_ids'] as $member) {
            $this->assertContains($member, $schemas['Source']['required'] ?? [], 'Source.' . $member);
        }

        foreach (['listEvents', 'listDeliveries'] as $operationId) {
            $parameters = self::parameterNames($spec, $operationId);
            $this->assertContains('source_id', $parameters, $operationId);
            $this->assertContains('origin', $parameters, $operationId);
        }
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
     * @param array<mixed> $spec
     * @return list<string>
     */
    private static function parameterNames(array $spec, string $operationId): array
    {
        foreach ($spec['paths'] ?? [] as $pathItem) {
            foreach ($pathItem as $operation) {
                if (! is_array($operation) || ($operation['operationId'] ?? null) !== $operationId) {
                    continue;
                }

                $names = [];
                foreach ($operation['parameters'] ?? [] as $parameter) {
                    $ref = $parameter['$ref'] ?? null;
                    if (is_string($ref)) {
                        $parameter = $spec['components']['parameters'][substr($ref, strlen('#/components/parameters/'))] ?? [];
                    }
                    $names[] = (string) ($parameter['name'] ?? '');
                }

                return $names;
            }
        }

        return [];
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
