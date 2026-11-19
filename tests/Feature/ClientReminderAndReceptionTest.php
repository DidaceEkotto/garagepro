<?php

namespace Tests\Feature;

use App\Mail\ClientReminderMail;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleReception;
use App\Services\TenantContext;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientReminderAndReceptionTest extends TestCase
{
    use RefreshDatabase;

    private function createAuthenticatedGerant(): array
    {
        $tenant = Tenant::create([
            'name' => 'Garage Test',
            'slug' => 'garage-test',
            'email' => 'gerant@test.cm',
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

        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Gérant Test',
            'email' => 'gerant@test.cm',
            'password' => 'MotDePasse@123',
            'is_active' => true,
        ]);
        $user->assignRole('gerant');

        return [$tenant, $agency, $user];
    }

    public function test_reception_accepts_unlimited_photos_without_signature(): void
    {
        Storage::fake('local');
        [$tenant, $agency, $user] = $this->createAuthenticatedGerant();

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Client Photo',
            'phone' => '+237 699112233',
            'type' => 'particulier',
        ]);

        $vehicle = Vehicle::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'LT-123-AB',
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'mileage' => 50000,
        ]);

        $photos = [];
        for ($i = 1; $i <= 10; $i++) {
            $photos[] = UploadedFile::fake()->image("car_{$i}.jpg");
        }

        $response = $this->actingAs($user, 'web')
            ->post('http://garage-test.mboacar.com/receptions', [
                'agency_id' => $agency->id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'visit_reason' => 'entretien',
                'mileage_in' => 50200,
                'fuel_level' => 70,
                'photos' => $photos,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $reception = VehicleReception::withoutTenantScope()->first();
        $this->assertNotNull($reception);
        $this->assertCount(10, $reception->photos);
        $this->assertNull($reception->client_signature_path);
    }

    public function test_can_remind_customer_and_unpaid_invoice_by_email(): void
    {
        Mail::fake();
        [$tenant, $agency, $user] = $this->createAuthenticatedGerant();

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Client Relance',
            'phone' => '+237 699445566',
            'email' => 'client@relance.cm',
            'type' => 'particulier',
        ]);

        $this->actingAs($user, 'web')
            ->post("http://garage-test.mboacar.com/clients/{$customer->id}/relancer", [
                'subject' => 'Rappel entretien',
                'message' => 'Votre révision annuelle approche.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(ClientReminderMail::class, fn ($mail) => $mail->hasTo('client@relance.cm'));

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'FAC-2026-0001',
            'type' => Invoice::TYPE_INVOICE,
            'status' => 'emise',
            'subtotal_ht' => 100000,
            'vat_amount' => 19250,
            'total_ttc' => 119250,
            'paid_amount' => 0,
            'issued_at' => now(),
        ]);

        $this->actingAs($user, 'web')
            ->post("http://garage-test.mboacar.com/factures/{$invoice->id}/relancer")
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(ClientReminderMail::class, fn ($mail) => $mail->hasTo('client@relance.cm') && str_contains($mail->subjectLine, 'FAC-2026-0001'));
    }
}
