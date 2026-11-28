<?php

namespace Tests\Feature\Security;

use App\Livewire\Admin\PlatformDashboard;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\BlockedIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_sensitive_path_scan_reports_suspicious_ip_without_auto_blocking(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
            ->get('/.env');

        // L'IP n'est PAS bloquée automatiquement : une requête normale passe toujours
        $this->assertFalse(BlockedIp::isCurrentlyBlocked('203.0.113.40'));
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
            ->get('/')
            ->assertStatus(200);

        $report = BlockedIp::suspiciousReported()->where('ip_address', '203.0.113.40')->first();
        $this->assertNotNull($report);
        $this->assertSame(1, $report->attempts_count);
        $this->assertSame('/.env', $report->last_path);

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'security.suspicious_ip_reported',
            'ip_address' => '203.0.113.40',
        ]);
    }

    public function test_admin_can_block_suspicious_ip_for_days_or_ban_permanently(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'sec-admin@mboacar.com',
            'password' => 'AdminPass123!',
            'role' => Admin::ROLE_SUPER_ADMIN,
        ]);

        $report = BlockedIp::reportSuspiciousIp('203.0.113.50', 'Scan suspect', '/wp-admin');
        $report2 = BlockedIp::reportSuspiciousIp('203.0.113.51', 'Rafale suspecte', '/');

        $this->actingAs($admin, 'admin');

        // Blocage pour 3 jours
        Livewire::test(PlatformDashboard::class, ['section' => 'security'])
            ->set("suspiciousBlockDays.{$report->id}", 3)
            ->call('blockSuspiciousIp', $report->id);

        $report->refresh();
        $this->assertFalse($report->permanent);
        $this->assertTrue(BlockedIp::isCurrentlyBlocked('203.0.113.50'));
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->get('/')->assertStatus(403);

        // Déblocage après 4 jours
        $this->travel(4)->days();
        $this->assertFalse(BlockedIp::isCurrentlyBlocked('203.0.113.50'));

        // Bannissement définitif
        Livewire::test(PlatformDashboard::class, ['section' => 'security'])
            ->call('banSuspiciousIp', $report2->id);

        $report2->refresh();
        $this->assertTrue($report2->permanent);
        $this->assertTrue(BlockedIp::isCurrentlyBlocked('203.0.113.51'));
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.51'])->get('/')->assertStatus(403);
    }
}
