<?php

namespace Tests\Feature\Workflow;

use App\Models\Admin;
use App\Models\Agency;
use App\Models\AiDiagnosticConversation;
use App\Models\AiDiagnosticMessage;
use App\Models\Customer;
use App\Models\PlatformSetting;
use App\Models\Quote;
use App\Models\RepairOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiDiagnosticAssistantTest extends TestCase
{
    use RefreshDatabase;

    private function createGarageUser(string $slug, string $name): array
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => $slug,
            'email' => "admin@{$slug}.cm",
            'status' => Tenant::STATUS_ACTIVE,
            'type' => 'garage',
        ]);

        $agency = Agency::create([
            'tenant_id' => $tenant->id,
            'name' => 'Atelier Principal',
            'is_main' => true,
        ]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => "Chef {$name}",
            'email' => "admin@{$slug}.cm",
            'password' => 'Password123!',
            'is_active' => true,
        ]);
        $user->assignRole('gerant');

        return [$tenant, $user];
    }

    public function test_persists_prompts_and_gemini_responses_and_flags_off_topic(): void
    {
        config([
            'services.gemini.api_key' => 'test-gemini-key',
            'services.gemini.model' => 'gemini-2.5-flash',
        ]);

        Http::fakeSequence()
            ->push([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '1. Code P0300 : ratés d\'allumage multiples. Vérifiez bougies et bobines.']]],
                ]],
                'usageMetadata' => ['totalTokenCount' => 145],
            ], 200)
            ->push([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '[HORS_SUJET] Je suis MboaCar IA, spécialisé uniquement dans le diagnostic et la mécanique automobile.']]],
                ]],
                'usageMetadata' => ['totalTokenCount' => 62],
            ], 200);

        [$tenantA, $userA] = $this->createGarageUser('ia-alpha', 'Garage Alpha');

        // 1. Question automobile valide
        $res = $this->actingAs($userA, 'web')
            ->post('http://ia-alpha.mboacar.com/assistant-ia/messages', [
                'prompt' => 'Code défaut P0300 sur Toyota Corolla 2016',
            ]);

        $conv = AiDiagnosticConversation::withoutTenantScope()->where('tenant_id', $tenantA->id)->firstOrFail();
        $res->assertRedirect("/assistant-ia?conversation={$conv->id}");

        $this->assertDatabaseHas('ai_diagnostic_messages', [
            'tenant_id' => $tenantA->id,
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => 'Code défaut P0300 sur Toyota Corolla 2016',
            'is_off_topic' => false,
        ]);

        $this->assertDatabaseHas('ai_diagnostic_messages', [
            'tenant_id' => $tenantA->id,
            'conversation_id' => $conv->id,
            'role' => 'assistant',
            'model' => 'gemini-2.5-flash',
            'is_off_topic' => false,
            'tokens_used' => 145,
        ]);

        // 2. Question hors domaine automobile -> détectée et marquée is_off_topic = true
        $this->actingAs($userA, 'web')
            ->post('http://ia-alpha.mboacar.com/assistant-ia/messages', [
                'conversation_id' => $conv->id,
                'prompt' => 'Donne-moi une recette de ndolé',
            ])
            ->assertRedirect("/assistant-ia?conversation={$conv->id}");

        $offTopicReply = AiDiagnosticMessage::withoutTenantScope()
            ->where('conversation_id', $conv->id)
            ->where('role', 'assistant')
            ->latest('id')
            ->firstOrFail();

        $this->assertTrue($offTopicReply->is_off_topic);
        $this->assertStringNotContainsString('[HORS_SUJET]', $offTopicReply->content);
        $this->assertStringContainsString('automobile', $offTopicReply->content);
    }

    public function test_strict_tenant_isolation_prevents_other_garage_from_seeing_or_modifying_conversations(): void
    {
        config(['services.gemini.api_key' => 'test-gemini-key']);
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Diagnostic privé du Garage A.']]],
                ]],
                'usageMetadata' => ['totalTokenCount' => 50],
            ], 200),
        ]);

        [$tenantA, $userA] = $this->createGarageUser('garage-ia-a', 'Garage A');
        [$tenantB, $userB] = $this->createGarageUser('garage-ia-b', 'Garage B');

        // Garage A crée une conversation
        $this->actingAs($userA, 'web')
            ->post('http://garage-ia-a.mboacar.com/assistant-ia/messages', [
                'prompt' => 'Problème injecteur Mercedes C220 CDI',
            ])
            ->assertRedirect();

        $convA = AiDiagnosticConversation::withoutTenantScope()->where('tenant_id', $tenantA->id)->firstOrFail();

        // Garage B consulte /assistant-ia -> 0 conversations visibles
        $this->actingAs($userB, 'web')
            ->get("http://garage-ia-b.mboacar.com/assistant-ia?conversation={$convA->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tenant/AiDiagnostic/Index')
                ->has('conversations', 0)
                ->where('activeConversation', null)
            );

        // Garage B tente d'écrire dans la conversation de Garage A -> 404
        $this->actingAs($userB, 'web')
            ->post('http://garage-ia-b.mboacar.com/assistant-ia/messages', [
                'conversation_id' => $convA->id,
                'prompt' => 'Tentative d\'intrusion',
            ])
            ->assertNotFound();

        // Garage B tente de supprimer la conversation de Garage A -> 404
        $this->actingAs($userB, 'web')
            ->delete("http://garage-ia-b.mboacar.com/assistant-ia/{$convA->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('ai_diagnostic_conversations', [
            'id' => $convA->id,
            'tenant_id' => $tenantA->id,
        ]);
    }

    public function test_photo_and_voice_saved_in_db_videos_rejected_and_workshop_actions_work(): void
    {
        Storage::fake('public');
        config([
            'services.gemini.api_key' => 'test-gemini-key',
            'services.gemini.model' => 'gemini-flash-lite-latest',
            'services.green_api.url' => 'https://api.green-api.com',
            'services.green_api.id_instance' => '123456',
            'services.green_api.api_token_instance' => 'secret',
        ]);

        Http::fakeSequence()
            ->push([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Diagnostic photo + vocal : bobine cylindre 2 défectueuse.']]],
                ]],
                'usageMetadata' => ['totalTokenCount' => 210],
            ], 200)
            ->push([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Bonjour Jean, notre atelier a identifié une pièce d\'allumage à remplacer sur votre Toyota.']]],
                ]],
                'usageMetadata' => ['totalTokenCount' => 95],
            ], 200)
            ->push(['idMessage' => 'wa-ok-1'], 200);

        [$tenant, $user] = $this->createGarageUser('ia-pro', 'Garage IA Pro');
        $agency = Agency::where('tenant_id', $tenant->id)->firstOrFail();

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Jean Client',
            'phone' => '+237 699000111',
            'whatsapp_phone' => '+237 699000111',
            'type' => 'particulier',
        ]);

        $vehicle = Vehicle::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'LT 555 IA',
            'brand' => 'Toyota',
            'model' => 'Camry',
            'mileage' => 95000,
        ]);

        $ro = RepairOrder::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'order_number' => 'OR-IA-001',
            'status' => 'en_cours',
            'priority' => 'normale',
            'fault_codes' => ['P0302'],
        ]);

        // 1. Les vidéos sont strictement rejetées
        $fakeVideo = UploadedFile::fake()->create('panne.mp4', 200, 'video/mp4');
        $this->actingAs($user, 'web')
            ->post('http://ia-pro.mboacar.com/assistant-ia/messages', [
                'prompt' => 'Voici une vidéo',
                'photos' => [$fakeVideo],
            ])
            ->assertSessionHasErrors('photos.0');

        // 2. Photo + Message vocal enregistrés en BDD
        $fakePhoto = UploadedFile::fake()->image('voyant.jpg', 300, 300);
        $fakeAudio = UploadedFile::fake()->create('vocal.webm', 40, 'audio/webm');

        $this->actingAs($user, 'web')
            ->post('http://ia-pro.mboacar.com/assistant-ia/messages', [
                'vehicle_id' => $vehicle->id,
                'repair_order_id' => $ro->id,
                'prompt' => 'Voyant moteur allumé + vocal ci-joint',
                'photos' => [$fakePhoto],
                'audio' => $fakeAudio,
                'audio_duration' => 7,
            ])
            ->assertRedirect();

        $userMsg = AiDiagnosticMessage::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'user')
            ->firstOrFail();

        $this->assertNotNull($userMsg->audio_path);
        $this->assertSame(7, $userMsg->audio_duration);
        $this->assertCount(1, $userMsg->attachments);
        Storage::disk('public')->assertExists($userMsg->audio_path);
        Storage::disk('public')->assertExists($userMsg->attachments[0]['path']);

        $aiMsg = AiDiagnosticMessage::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'assistant')
            ->firstOrFail();

        // 3. Créer un brouillon de devis en 1 clic
        $this->actingAs($user, 'web')
            ->post("http://ia-pro.mboacar.com/assistant-ia/messages/{$aiMsg->id}/creer-devis", [
                'vehicle_id' => $vehicle->id,
            ])
            ->assertRedirect('/devis')
            ->assertSessionHas('success');

        $this->assertSame(1, Quote::withoutTenantScope()->where('tenant_id', $tenant->id)->count());

        // 4. Ajouter le diagnostic à l'OR
        $this->actingAs($user, 'web')
            ->post("http://ia-pro.mboacar.com/assistant-ia/messages/{$aiMsg->id}/appliquer-or", [
                'repair_order_id' => $ro->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertStringContainsString('bobine cylindre 2', (string) $ro->fresh()->diagnostic_notes);

        // 5. Expliquer au client (vulgarisation) + Envoi WhatsApp
        $this->actingAs($user, 'web')
            ->postJson("http://ia-pro.mboacar.com/assistant-ia/messages/{$aiMsg->id}/expliquer-client")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertNotNull($aiMsg->fresh()->client_summary);

        $this->actingAs($user, 'web')
            ->post("http://ia-pro.mboacar.com/assistant-ia/messages/{$aiMsg->id}/envoyer-whatsapp", [
                'phone' => '+237 699000111',
                'summary' => $aiMsg->fresh()->client_summary,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($aiMsg->fresh()->client_whatsapp_sent_at);

        // 6. Quota journalier appliqué (1 diagnostic déjà fait -> bloqué si quota = 1)
        PlatformSetting::setValue('ai_daily_quota', 1);
        $this->actingAs($user, 'web')
            ->post('http://ia-pro.mboacar.com/assistant-ia/messages', [
                'prompt' => 'Deuxième diagnostic du jour',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_soft_delete_stays_visible_in_admin_and_resolved_answers_are_reused_from_db(): void
    {
        config([
            'services.gemini.api_key' => 'test-gemini-key',
            'services.gemini.model' => 'gemini-flash-lite-latest',
        ]);

        Http::fakeSequence()
            ->push([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Solution validée P0300 Toyota Corolla : remplacer les bobines et bougies usées.']]],
                ]],
                'usageMetadata' => ['totalTokenCount' => 120],
            ], 200)
            ->push([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Nouvelle analyse en ligne fraîche depuis Gemini pour P0300.']]],
                ]],
                'usageMetadata' => ['totalTokenCount' => 110],
            ], 200);

        [$tenantA, $userA] = $this->createGarageUser('kb-garage-a', 'Garage Alpha KB');
        [$tenantB, $userB] = $this->createGarageUser('kb-garage-b', 'Garage Beta KB');

        // 1. Garage A pose une question P0300 -> appel Gemini #1
        $this->actingAs($userA, 'web')
            ->post('http://kb-garage-a.mboacar.com/assistant-ia/messages', [
                'prompt' => 'Code défaut P0300 ratés allumage Toyota Corolla 2016 essence ralenti instable',
            ])
            ->assertRedirect();

        Http::assertSentCount(1);

        $convA = AiDiagnosticConversation::withoutTenantScope()->where('tenant_id', $tenantA->id)->firstOrFail();
        $aiMsgA = AiDiagnosticMessage::withoutTenantScope()
            ->where('conversation_id', $convA->id)
            ->where('role', 'assistant')
            ->firstOrFail();

        // 2. Garage A confirme que MboaCar IA Auto a résolu le problème
        $this->actingAs($userA, 'web')
            ->post("http://kb-garage-a.mboacar.com/assistant-ia/messages/{$aiMsgA->id}/feedback", [
                'is_resolved' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue($aiMsgA->fresh()->is_resolved);

        // 3. Garage A supprime sa conversation -> disparaît de son espace, mais reste en BDD (soft-delete) pour l'admin !
        $this->actingAs($userA, 'web')
            ->delete("http://kb-garage-a.mboacar.com/assistant-ia/{$convA->id}")
            ->assertRedirect('/assistant-ia');

        $this->assertSoftDeleted('ai_diagnostic_conversations', ['id' => $convA->id]);

        // L'admin voit la page /admin/ia-diagnostics avec la conversation supprimée
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'superadmin-ia@mboacar.com',
            'password' => 'Password123!',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('http://mboacar.com/admin/ia-diagnostics')
            ->assertOk()
            ->assertSee('Garage Alpha KB')
            ->assertSee('Supprimée');

        // 4. Garage B pose un prompt similaire P0300 -> réponse récupérée directement en BDD (0 nouvel appel Gemini !)
        $this->actingAs($userB, 'web')
            ->post('http://kb-garage-b.mboacar.com/assistant-ia/messages', [
                'prompt' => 'Toyota Corolla 2016 essence code P0300 ratés allumage ralenti instable',
            ])
            ->assertRedirect();

        // Toujours 1 seul appel HTTP Gemini : la réponse de Garage B vient directement de la BDD !
        Http::assertSentCount(1);

        $aiMsgB = AiDiagnosticMessage::withoutTenantScope()
            ->where('tenant_id', $tenantB->id)
            ->where('role', 'assistant')
            ->firstOrFail();

        $this->assertSame('mboacar-kb', $aiMsgB->model);
        $this->assertSame($aiMsgA->id, $aiMsgB->matched_from_message_id);
        $this->assertSame(0, $aiMsgB->tokens_used);
        $this->assertSame(1, $aiMsgA->fresh()->reuse_count);
        $this->assertStringContainsString('Solution validée P0300', $aiMsgB->content);

        // 5. Si Garage B force une recherche en ligne (force_online = true) -> appelle bien Gemini (#2)
        $this->actingAs($userB, 'web')
            ->post('http://kb-garage-b.mboacar.com/assistant-ia/messages', [
                'prompt' => 'Toyota Corolla 2016 essence code P0300 ratés allumage ralenti instable',
                'force_online' => true,
            ])
            ->assertRedirect();

        Http::assertSentCount(2);
    }
}
