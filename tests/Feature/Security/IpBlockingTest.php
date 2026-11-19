<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\BlockedIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpBlockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocked_ip_receives_403_response(): void
    {
        BlockedIp::blockIp('203.0.113.10', 'Blocage de test', hours: 24);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/');

        $response->assertStatus(403);
    }

    public function test_whitelisted_ip_is_never_blocked(): void
    {
        BlockedIp::create([
            'ip_address' => '203.0.113.20',
            'reason' => 'IP bureau gérant',
            'is_whitelisted' => true,
            'permanent' => false,
        ]);

        // Même si une règle de blocage existe, la liste blanche prime.
        BlockedIp::blockIp('203.0.113.20', 'Tentative erronée', hours: 24);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->get('/');

        $response->assertStatus(200);
    }

    public function test_expired_ip_block_is_automatically_lifted(): void
    {
        BlockedIp::create([
            'ip_address' => '203.0.113.30',
            'reason' => 'Blocage expiré',
            'is_whitelisted' => false,
            'permanent' => false,
            'blocked_until' => now()->subMinute(),
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
            ->get('/');

        $response->assertStatus(200);
    }

    public function test_sensitive_path_scan_triggers_automatic_ip_block(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
            ->get('/.env');

        $response->assertStatus(403);
        $this->assertTrue(BlockedIp::isCurrentlyBlocked('203.0.113.40'));
        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'security.ip_auto_blocked',
            'ip_address' => '203.0.113.40',
        ]);
    }
}
