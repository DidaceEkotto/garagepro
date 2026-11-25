<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\AiDiagnosticConversation;
use App\Models\AiDiagnosticMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
}
