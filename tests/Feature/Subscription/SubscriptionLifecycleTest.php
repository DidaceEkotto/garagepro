<?php

namespace Tests\Feature\Subscription;

use App\Mail\SubscriptionReminderMail;
use App\Mail\TrialReminderMail;
use App\Models\Agency;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_lifecycle_sends_trial_reminders_and_transitions_statuses(): void
    {
        Mail::fake();

        $j2Tenant = Tenant::create([
            'name' => 'Garage J2',
            'slug' => 'garage-j2',
            'email' => 'j2@example.com',
            'status' => Tenant::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays(2),
        ]);

        $expiredTrial = Tenant::create([
            'name' => 'Garage Expiré',
            'slug' => 'garage-expire',
            'email' => 'expire@example.com',
            'status' => Tenant::STATUS_TRIALING,
            'trial_ends_at' => now()->subDays(3),
        ]);

        $pastDueTenant = Tenant::create([
            'name' => 'Garage Échu',
            'slug' => 'garage-echu',
            'email' => 'echu@example.com',
            'status' => Tenant::STATUS_ACTIVE,
            'subscription_ends_at' => now()->subMinute(),
        ]);

        $graceExceededTenant = Tenant::create([
            'name' => 'Garage Suspendu',
            'slug' => 'garage-suspendu',
            'email' => 'suspendu@example.com',
            'status' => Tenant::STATUS_PAST_DUE,
            'grace_ends_at' => now()->subMinute(),
        ]);

        $this->artisan('garagepro:process-subscriptions')->assertExitCode(0);

        Mail::assertQueued(TrialReminderMail::class, fn ($m) => $m->hasTo('j2@example.com'));
        Mail::assertQueued(SubscriptionReminderMail::class, fn ($m) => $m->hasTo('echu@example.com'));

        $this->assertSame(Tenant::STATUS_EXPIRED, $expiredTrial->fresh()->status);
        $this->assertSame(Tenant::STATUS_PAST_DUE, $pastDueTenant->fresh()->status);
        $this->assertSame(Tenant::STATUS_SUSPENDED, $graceExceededTenant->fresh()->status);

        // Un deuxième passage le même jour ne renvoie pas le rappel J-2 en double.
        Mail::fake();
        $this->artisan('garagepro:process-subscriptions')->assertExitCode(0);
        Mail::assertNothingQueued();
    }

    public function test_expired_tenant_is_read_only_and_preserves_data(): void
    {
        $tenant = Tenant::create([
            'name' => 'Garage Lecture Seule',
            'slug' => 'lecture-seule',
            'email' => 'ro@example.com',
            'status' => Tenant::STATUS_EXPIRED,
            'trial_ends_at' => now()->subDay(),
        ]);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Agence 1', 'is_main' => true]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Gérant RO',
            'email' => 'gerant@ro.com',
            'password' => 'Secret123!',
        ]);
        $user->assignRole('gerant');

        // Lecture autorisée
        $this->actingAs($user, 'web')
            ->get('http://lecture-seule.mboacar.com/agences')
            ->assertStatus(200);

        // Écriture bloquée (données intactes)
        $this->actingAs($user, 'web')
            ->post('http://lecture-seule.mboacar.com/agences', ['name' => 'Nouvelle Agence'])
            ->assertSessionHas('error');

        $this->assertCount(1, Agency::withoutTenantScope()->where('tenant_id', $tenant->id)->get());
    }
}
