<?php

namespace Tests\Feature\Security;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantConversation;
use App\Models\TenantConversationMessage;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class B2bNetworkMessagingTest extends TestCase
{
    use RefreshDatabase;

    private function createEstablishment(string $slug, string $type): array
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'type' => $type,
            'email' => "{$slug}@b2b.test",
            'phone' => '+237690009999',
            'city' => 'Douala',
            'status' => Tenant::STATUS_ACTIVE,
            'b2b_directory_visible' => true,
            'subscription_ends_at' => now()->addMonth(),
        ]);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Principal', 'is_main' => true]);
        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => "Gérant {$slug}",
            'email' => "gerant@{$slug}.test",
            'password' => 'Secret123!',
        ]);
        $user->assignRole('gerant');

        return [$tenant, $agency, $user];
    }

    public function test_garage_and_technical_center_can_exchange_messages_and_attachments_securely(): void
    {
        Storage::fake('local');

        [$garageA, $agencyA, $userA] = $this->createEstablishment('garage-alpha', Tenant::TYPE_GARAGE);
        [$centerB, , $userB] = $this->createEstablishment('centre-beta', 'centre_technique');
        [$garageC, , $userC] = $this->createEstablishment('garage-intruder', Tenant::TYPE_GARAGE);

        $customerA = Customer::create([
            'tenant_id' => $garageA->id,
            'agency_id' => $agencyA->id,
            'name' => 'Paul Client',
            'gender' => 'homme',
            'phone' => '+237699000111',
        ]);
        $vehicleA = Vehicle::create([
            'tenant_id' => $garageA->id,
            'customer_id' => $customerA->id,
            'license_plate' => 'LT-202-BB',
            'brand' => 'Toyota',
            'model' => 'Hilux',
            'mileage' => 120000,
        ]);

        // 1) Garage A ouvre une discussion avec Centre Technique B
        $startRes = $this->actingAs($userA)
            ->postJson('/reseau-pro/conversations', ['target_tenant_id' => $centerB->id])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $convId = $startRes->json('conversation_id');
        $this->assertNotNull($convId);

        // 2) Garage A envoie un message avec fiche véhicule partagée + pièce jointe PDF
        $pdf = UploadedFile::fake()->createWithContent('rapport_obd.pdf', '%PDF-1.4 test content');
        $msgRes = $this->actingAs($userA)
            ->post("/reseau-pro/conversations/{$convId}/messages", [
                'body' => 'Bonjour, pouvez-vous vérifier ce Hilux pour la contre-visite ?',
                'share_type' => 'vehicle',
                'share_id' => $vehicleA->id,
                'attachments' => [$pdf],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message.shared_context.type', 'vehicle');

        $messageId = $msgRes->json('message.id');

        // 3) Centre Technique B lit le message et télécharge la pièce jointe
        $this->actingAs($userB)
            ->getJson("/reseau-pro/etat?conversation_id={$convId}")
            ->assertOk()
            ->assertJsonPath('messages.0.id', $messageId);

        $this->actingAs($userB)
            ->get("/reseau-pro/messages/{$messageId}/piece-jointe/0")
            ->assertOk();

        // 4) SÉCURITÉ IDOR : Garage C (non participant) est bloqué en 403 !
        $this->actingAs($userC)
            ->postJson("/reseau-pro/conversations/{$convId}/messages", ['body' => 'Intrusion'])
            ->assertForbidden();

        $this->actingAs($userC)
            ->get("/reseau-pro/messages/{$messageId}/piece-jointe/0")
            ->assertForbidden();
    }

    public function test_directory_privacy_opt_out_and_blocked_attachments_are_enforced(): void
    {
        Storage::fake('local');

        [$garageA, , $userA] = $this->createEstablishment('garage-open', Tenant::TYPE_GARAGE);
        [$centerB, , $userB] = $this->createEstablishment('centre-private', 'centre_technique');

        // Centre B désactive sa visibilité dans l'annuaire B2B
        $this->actingAs($userB)
            ->patchJson('/reseau-pro/visibilite', ['visible' => false])
            ->assertOk()
            ->assertJsonPath('b2b_directory_visible', false);

        // Garage A ne peut plus démarrer de conversation non sollicitée avec Centre B
        $this->actingAs($userA)
            ->postJson('/reseau-pro/conversations', ['target_tenant_id' => $centerB->id])
            ->assertForbidden();

        // Réactivation pour tester le blocage des fichiers dangereux (.php / .zip)
        $centerB->update(['b2b_directory_visible' => true]);
        $conv = TenantConversation::findOrCreatePair($garageA->id, $centerB->id);

        $badPhp = UploadedFile::fake()->createWithContent('shell.php', '<?php phpinfo();');
        $this->actingAs($userA)
            ->post("/reseau-pro/conversations/{$conv->id}/messages", [
                'body' => 'Test fichier interdit',
                'attachments' => [$badPhp],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $badZip = UploadedFile::fake()->createWithContent('archive.zip', "PK\x03\x04test");
        $this->actingAs($userA)
            ->post("/reseau-pro/conversations/{$conv->id}/messages", [
                'body' => 'Test archive interdite',
                'attachments' => [$badZip],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, TenantConversationMessage::where('conversation_id', $conv->id)->count());
    }

    public function test_sos_board_voice_notes_lightbox_preview_and_anti_spam_block(): void
    {
        Storage::fake('local');

        [$garageA, , $userA] = $this->createEstablishment('garage-sos-a', Tenant::TYPE_GARAGE);
        [$centerB, , $userB] = $this->createEstablishment('centre-sos-b', 'centre_technique');
        [$garageC, , $userC] = $this->createEstablishment('garage-sos-c', Tenant::TYPE_GARAGE);

        // 1) Bourse d'entraide SOS : Garage A publie une demande SOS à Douala
        $sosRes = $this->actingAs($userA)
            ->postJson('/reseau-pro/demandes', [
                'category' => 'piece',
                'title' => 'Cherche injecteur 1.6 HDi urgence',
                'city' => 'Douala',
                'is_urgent' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true);

        $sosId = $sosRes->json('id');

        // Centre B clique sur "J'ai ça / Répondre en privé" -> ouvre la conversation
        $replyRes = $this->actingAs($userB)
            ->postJson("/reseau-pro/demandes/{$sosId}/repondre")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $convId = $replyRes->json('conversation_id');

        // 2) Note vocale + photo Lightbox + anti-IDOR sur audio et aperçu
        $voice = UploadedFile::fake()->createWithContent('vocal.webm', "OggS\x00\x02audio-bytes");
        $photo = UploadedFile::fake()->image('panne.jpg', 300, 200);

        $msgRes = $this->actingAs($userA)
            ->post("/reseau-pro/conversations/{$convId}/messages", [
                'body' => 'Voici le bruit moteur et la photo',
                'audio' => $voice,
                'audio_duration' => 12,
                'attachments' => [$photo],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('ok', true);

        $msgId = $msgRes->json('message.id');

        // Participant B accède à l'audio et à l'aperçu photo
        $this->actingAs($userB)->get("/reseau-pro/messages/{$msgId}/audio")->assertOk();
        $this->actingAs($userB)->get("/reseau-pro/messages/{$msgId}/apercu/0")->assertOk();

        // Intrus C bloqué en 403 sur audio et aperçu photo
        $this->actingAs($userC)->get("/reseau-pro/messages/{$msgId}/audio")->assertForbidden();
        $this->actingAs($userC)->get("/reseau-pro/messages/{$msgId}/apercu/0")->assertForbidden();

        // 3) Blocage anti-spam : Garage A bloque Centre B -> Centre B reçoit 403
        $this->actingAs($userA)->postJson("/reseau-pro/bloquer/{$centerB->id}")->assertOk();
        $this->actingAs($userB)
            ->postJson("/reseau-pro/conversations/{$convId}/messages", ['body' => 'Tentative après blocage'])
            ->assertForbidden();

        // Déblocage + résolution SOS
        $this->actingAs($userA)->deleteJson("/reseau-pro/bloquer/{$centerB->id}")->assertOk();
        $this->actingAs($userA)->patchJson("/reseau-pro/demandes/{$sosId}/resoudre")->assertOk();
    }
}
