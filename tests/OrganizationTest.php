<?php

declare(strict_types=1);

namespace IngestVault\Tests;

final class OrganizationTest extends TestCase
{
    public function test_reads_the_current_organization_with_the_expected_request(): void
    {
        $this->client([self::organizationResponse()])->organization->current();

        $this->assertCount(1, $this->history);
        $this->assertSentRequest('GET', 'https://api.example.test/v1/organizations/current', null);
    }

    public function test_returns_the_organization_from_the_answer(): void
    {
        $organization = $this->client([self::organizationResponse()])->organization->current();

        $this->assertSame('0199b2c4-8888-7a3b-9c4d-5e6f7a8b9c08', $organization->id);
        $this->assertSame('Acme', $organization->name);
        $this->assertSame('ops@acme.example', $organization->notificationEmail);
        $this->assertEquals(new \DateTimeImmutable('2026-09-01T10:00:00Z'), $organization->createdAt);
        $this->assertFalse($organization->payloadsVisible);
    }

    public function test_unknown_members_in_the_organization_are_ignored(): void
    {
        $organization = $this->client([self::organizationResponse(overrides: ['payloads_visible' => true, 'updated_at' => '2026-10-01T00:00:00Z', 'region' => 'eu'])])
            ->organization->current();

        $this->assertSame('Acme', $organization->name);
        $this->assertTrue($organization->payloadsVisible);
    }
}
