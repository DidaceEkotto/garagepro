<?php

namespace Tests\Feature;

use App\Mail\SubscriptionReminderMail;
use App\Models\Agency;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionLifecycleAndGraceTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantWithUsers(): array
    {
        $tenant = Tenant::create([
            'name' => 'Garage Lifecycle',
            'slug' => 'garage-lifecycle',
            'email' => 'lifecycle@test.local',
            'country' => 'CM',
            'status' => Tenant::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays(7),
        ]);

        app(TenantContext::class)->setTenant($tenant);
        app(TenantRoleService::class)->provisionForTenant($tenant);

        $agency = Agency::create([
            'tenant_id' => $tenant->id,
            'name' => 'Agence Principale',
            'code' => 'AG01',
            'is_main' => true,
            'is_active' => true,
        ]);

        $gerant = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Gérant',
            'email' => 'gerant@lifecycle.local',
            'password' => 'Secret@123',
            'is_active' => true,
        ]);
        $gerant->assignRole('gerant');

        $mechanic = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Mécanicien',
            'email' => 'mecano@lifecycle.local',
            'password' => 'Secret@123',
            'is_active' => true,
        ]);
        $mechanic->assignRole('mecanicien');

        return [$tenant, $gerant, $mechanic];
    }

    public function test_trial_grace_and_strict_read_only_for_all_professionals(): void
    {
        PlatformSetting::setValue('trial_days', '14');
        $this->assertSame(14, PlatformSetting::trialDays());

        [$tenant, $gerant, $mechanic] = $this->createTenantWithUsers();

        // Dans les 2 jours de grâce après la fin d'essai -> encore accessible, isInTrialGrace = true
        $tenant->update(['trial_ends_at' => now()->subDay()]);
        $this->assertTrue($tenant->fresh()->isInTrialGrace());
        $this->assertFalse($tenant->fresh()->isReadOnly());

        // Après les 2 jours de grâce -> lecture seule pour TOUS les professionnels
        $tenant->update(['trial_ends_at' => now()->subDays(3)]);
        $this->assertTrue($tenant->fresh()->isReadOnly());

        foreach ([$gerant, $mechanic] as $pro) {
            $this->actingAs($pro, 'web')
                ->withSession(['active_tenant_id' => $tenant->id])
                ->postJson('/clients', ['name' => 'Test Client'])
                ->assertStatus(403);
        }

        // L'espace Abonnement et ses exports PDF / Excel restent accessibles
        Payment::create([
            'tenant_id' => $tenant->id,
            'payable_type' => Tenant::class,
            'payable_id' => $tenant->id,
            'reference_code' => 'SUB-TEST01',
            'idempotency_key' => (string) Str::uuid(),
            'provider' => 'saspay',
            'payment_method' => 'saspay_checkout',
            'amount' => 10000,
            'currency' => 'XAF',
            'status' => Payment::STATUS_SUCCESS,
            'paid_at' => now(),
        ]);

        $this->actingAs($gerant, 'web')
            ->withSession(['active_tenant_id' => $tenant->id])
            ->get('/abonnement')
            ->assertOk();

        $this->actingAs($gerant, 'web')
            ->withSession(['active_tenant_id' => $tenant->id])
            ->get('/abonnement/export/excel')
            ->assertOk();

        $this->actingAs($gerant, 'web')
            ->withSession(['active_tenant_id' => $tenant->id])
            ->get('/abonnement/export/pdf')
            ->assertOk();
    }

    public function test_subscription_reminders_every_2_days_and_5_days_grace(): void
    {
        Mail::fake();
        [$tenant] = $this->createTenantWithUsers();

        // 1. 5 jours avant expiration -> envoi d'un email de rappel
        $tenant->update([
            'status' => Tenant::STATUS_ACTIVE,
            'subscription_ends_at' => now()->addDays(5),
        ]);

        $this->artisan('garagepro:process-subscriptions')->assertExitCode(0);
        Mail::assertQueued(SubscriptionReminderMail::class, 1);

        // Le lendemain (moins de 2 jours) -> pas de nouvel envoi
        $this->artisan('garagepro:process-subscriptions')->assertExitCode(0);
        Mail::assertQueued(SubscriptionReminderMail::class, 1);

        // 2. Date d'échéance dépassée -> passage en past_due (5 jours de grâce) + relance
        $tenant->update([
            'subscription_ends_at' => now()->subHour(),
            'settings' => [],
        ]);
        $this->artisan('garagepro:process-subscriptions')->assertExitCode(0);
        $tenant->refresh();
        $this->assertSame(Tenant::STATUS_PAST_DUE, $tenant->status);
        $this->assertTrue($tenant->isInSubscriptionGrace());
        Mail::assertQueued(SubscriptionReminderMail::class, 2);

        // 3. Fin des 5 jours de grâce -> suspended (lecture seule) et arrêt des emails
        $tenant->update(['grace_ends_at' => now()->subMinute()]);
        $this->artisan('garagepro:process-subscriptions')->assertExitCode(0);
        $tenant->refresh();
        $this->assertSame(Tenant::STATUS_SUSPENDED, $tenant->status);
        $this->assertTrue($tenant->isReadOnly());
        Mail::assertQueued(SubscriptionReminderMail::class, 2);
    }
}
