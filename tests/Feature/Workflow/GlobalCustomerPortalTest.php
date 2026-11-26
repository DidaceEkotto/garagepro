<?php

namespace Tests\Feature\Workflow;

use App\Mail\CustomerProfileUpdatedMail;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\GlobalCustomer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GlobalCustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_global_customer_links_across_garages_prefills_and_works_in_portal(): void
    {
        config([
            'services.greenapi.url' => 'https://api.green-api.com',
            'services.greenapi.id_instance' => '123456',
            'services.greenapi.api_token_instance' => 'token-secret',
        ]);

        $sentOtp = null;
        Http::fake(function ($req) use (&$sentOtp) {
            $body = (string) ($req['message'] ?? '');
            if (preg_match('/(\d{5})/', $body, $m)) {
                $sentOtp = $m[1];
            }

            return Http::response(['idMessage' => 'msg-otp'], 200);
        });

        // Garage A
        $tenantA = Tenant::create(['name' => 'Garage Alpha', 'slug' => 'alpha', 'email' => 'alpha@example.com']);
        $agencyA = Agency::create(['tenant_id' => $tenantA->id, 'name' => 'Agence A', 'is_main' => true]);

        // Garage B
        $tenantB = Tenant::create(['name' => 'Garage Beta', 'slug' => 'beta', 'email' => 'beta@example.com']);
        $agencyB = Agency::create(['tenant_id' => $tenantB->id, 'name' => 'Agence B', 'is_main' => true]);
        app(TenantRoleService::class)->provisionForTenant($tenantB);
        setPermissionsTeamId($tenantB->id);
        $userB = User::create([
            'tenant_id' => $tenantB->id,
            'default_agency_id' => $agencyB->id,
            'name' => 'Réceptionniste B',
            'email' => 'rec@beta.cm',
            'password' => 'Secret123!',
        ]);
        $userB->assignRole('receptionniste');

        // Client créé au Garage A -> crée automatiquement le GlobalCustomer
        $custA = Customer::create([
            'tenant_id' => $tenantA->id,
            'agency_id' => $agencyA->id,
            'name' => 'Dupont',
            'first_name' => 'Jean',
            'gender' => 'homme',
            'id_number' => 'CNI-GLOBAL-01',
            'phone_dial_code' => '+237',
            'phone' => '+237699123456',
            'whatsapp_phone' => '+237699123456',
            'profession' => 'Ingénieur',
            'notes' => 'Note privée Garage A',
        ]);

        Vehicle::create([
            'tenant_id' => $tenantA->id,
            'customer_id' => $custA->id,
            'license_plate' => 'LT 100 AA',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

        $this->assertNotNull($custA->global_customer_id);
        $this->assertSame(1, GlobalCustomer::count());

        // Garage B utilise la recherche de pré-remplissage par téléphone
        $lookupRes = $this->actingAs($userB, 'web')
            ->getJson('http://beta.mboacar.com/clients/lookup-global?phone=699123456');

        $lookupRes->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('data.name', 'Dupont')
            ->assertJsonPath('data.id_number', 'CNI-GLOBAL-01')
            ->assertJsonMissing(['notes' => 'Note privée Garage A']);

        // Client créé au Garage B avec le même WhatsApp -> relié au même GlobalCustomer
        $custB = Customer::create([
            'tenant_id' => $tenantB->id,
            'agency_id' => $agencyB->id,
            'name' => 'Dupont',
            'gender' => 'homme',
            'id_number' => 'CNI-GLOBAL-01',
            'phone' => '+237699123456',
            'whatsapp_phone' => '+237699123456',
        ]);

        $this->assertSame($custA->global_customer_id, $custB->global_customer_id);
        $this->assertSame(1, GlobalCustomer::count());

        // Connexion au Portail Client Unique (/mon-espace-client)
        $this->get('/mon-espace-client')->assertOk();

        $this->post('/mon-espace-client/otp', [
            'phone' => '+237699123456',
        ])->assertSessionHas('success');

        $global = GlobalCustomer::firstOrFail();
        $this->assertNotNull($global->otp_code);
        $this->assertNotNull($sentOtp);
        $this->assertNotSame($sentOtp, $global->otp_code, 'OTP must be hashed in database');

        $this->post('/mon-espace-client/verifier', [
            'phone' => '+237699123456',
            'code' => $sentOtp,
        ])->assertSessionHas('success');

        // Mise à jour du profil unique -> synchronisé dans les deux garages
        $this->put('/mon-espace-client/profil', [
            'name' => 'Dupont-Mbarga',
            'first_name' => 'Jean',
            'gender' => 'homme',
            'id_number' => 'CNI-GLOBAL-01',
            'profession' => 'Architecte',
        ])->assertSessionHas('success');

        $this->assertSame('Dupont-Mbarga', $custA->fresh()->name);
        $this->assertSame('Dupont-Mbarga', $custB->fresh()->name);
        $this->assertSame('Architecte', $custB->fresh()->profession);
    }

    public function test_profile_update_uploads_photo_updates_phone_and_notifies_only_linked_establishments(): void
    {
        Storage::fake('public');
        Mail::fake();

        config([
            'services.greenapi.url' => 'https://api.green-api.com',
            'services.greenapi.id_instance' => '123456',
            'services.greenapi.api_token_instance' => 'token-secret',
        ]);

        $sentChats = [];
        Http::fake(function ($req) use (&$sentChats) {
            if (isset($req['chatId'])) {
                $sentChats[] = $req['chatId'];
            }

            return Http::response(['idMessage' => 'msg-notif'], 200);
        });

        $garage = Tenant::create([
            'name' => 'Garage Horizon',
            'slug' => 'horizon',
            'type' => Tenant::TYPE_GARAGE,
            'email' => 'garage@horizon.cm',
            'phone' => '+237690000001',
        ]);
        $visite = Tenant::create([
            'name' => 'Centre Visite Auto',
            'slug' => 'visite-auto',
            'type' => Tenant::TYPE_TECHNICAL_CENTER,
            'email' => 'visite@centre.cm',
            'phone' => '+237690000002',
        ]);
        $unrelatedAssurance = Tenant::create([
            'name' => 'Assurance Autre',
            'slug' => 'assur-autre',
            'type' => Tenant::TYPE_INSURANCE,
            'email' => 'autre@assurance.cm',
            'phone' => '+237690000003',
        ]);

        $custGarage = Customer::create([
            'tenant_id' => $garage->id,
            'name' => 'Kamga',
            'first_name' => 'Paul',
            'gender' => 'homme',
            'phone_dial_code' => '+237',
            'phone' => '+237677112233',
            'whatsapp_phone' => '+237677112233',
        ]);
        $custVisite = Customer::create([
            'tenant_id' => $visite->id,
            'name' => 'Kamga',
            'first_name' => 'Paul',
            'gender' => 'homme',
            'phone_dial_code' => '+237',
            'phone' => '+237677112233',
            'whatsapp_phone' => '+237677112233',
        ]);

        $global = GlobalCustomer::findOrFail($custGarage->global_customer_id);

        // Mini PNG 1x1 valide (évite la dépendance GD en environnement CLI)
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $photo = UploadedFile::fake()->createWithContent('avatar.png', $pngBytes);

        $this->withSession(['global_customer_id' => $global->id])
            ->post('/mon-espace-client/profil', [
                '_method' => 'put',
                'name' => 'Kamga',
                'first_name' => 'Paul-Henri',
                'gender' => 'homme',
                'phone_dial_code' => '+225',
                'phone' => '0701020304',
                'email' => 'paul@example.com',
                'photo' => $photo,
            ])
            ->assertSessionHas('success');

        $global->refresh();
        $this->assertNotNull($global->photo_path);
        Storage::disk('public')->assertExists($global->photo_path);
        $this->assertSame('+225', $global->phone_dial_code);
        $this->assertSame('+2250701020304', $global->whatsapp_phone);

        // Synchronisation vers les établissements où il est client
        $this->assertSame($global->photo_path, $custGarage->fresh()->photo_path);
        $this->assertSame('+2250701020304', $custVisite->fresh()->whatsapp_phone);

        // E-mails envoyés UNIQUEMENT au garage et au centre de visite technique
        Mail::assertSent(CustomerProfileUpdatedMail::class, 2);
        Mail::assertSent(CustomerProfileUpdatedMail::class, fn ($m) => $m->hasTo('garage@horizon.cm'));
        Mail::assertSent(CustomerProfileUpdatedMail::class, fn ($m) => $m->hasTo('visite@centre.cm'));
        Mail::assertNotSent(CustomerProfileUpdatedMail::class, fn ($m) => $m->hasTo('autre@assurance.cm'));

        // WhatsApp envoyés UNIQUEMENT au garage et au centre de visite technique
        $this->assertContains('237690000001@c.us', $sentChats);
        $this->assertContains('237690000002@c.us', $sentChats);
        $this->assertNotContains('237690000003@c.us', $sentChats);
        $this->assertNotNull($unrelatedAssurance->id);
    }

    public function test_profile_update_sends_no_email_or_whatsapp_when_not_linked_to_any_establishment(): void
    {
        Mail::fake();
        Http::fake();

        config([
            'services.greenapi.url' => 'https://api.green-api.com',
            'services.greenapi.id_instance' => '123456',
            'services.greenapi.api_token_instance' => 'token-secret',
        ]);

        Tenant::create([
            'name' => 'Garage Non Lié',
            'slug' => 'non-lie',
            'type' => Tenant::TYPE_GARAGE,
            'email' => 'nonlie@example.com',
            'phone' => '+237690009999',
        ]);

        $standaloneGlobal = GlobalCustomer::create([
            'name' => 'SansLien',
            'first_name' => 'Marie',
            'gender' => 'femme',
            'phone_dial_code' => '+237',
            'phone' => '+237691111111',
            'whatsapp_phone' => '+237691111111',
        ]);

        $this->withSession(['global_customer_id' => $standaloneGlobal->id])
            ->put('/mon-espace-client/profil', [
                'name' => 'SansLien',
                'first_name' => 'Marie-Claire',
                'gender' => 'femme',
                'phone_dial_code' => '+237',
                'phone' => '691111112',
            ])
            ->assertSessionHas('success');

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
