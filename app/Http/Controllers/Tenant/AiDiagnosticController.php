<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AiDiagnosticConversation;
use App\Models\AiDiagnosticMessage;
use App\Models\AuditLog;
use App\Models\Vehicle;
use App\Services\GeminiDiagnosticService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AiDiagnosticController extends Controller
{
    public function index(
        Request $request,
        TenantContext $tenantContext,
        GeminiDiagnosticService $gemini
    ): Response {
        $tenantId = $tenantContext->id();

        // Isolation stricte par tenant : un garagiste ne voit JAMAIS les conversations d'un autre garage
        $conversations = AiDiagnosticConversation::query()
            ->where('tenant_id', $tenantId)
            ->with(['vehicle:id,license_plate,brand,model,year'])
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->take(40)
            ->get();

        $activeConversation = null;
        $requestedId = (int) $request->query('conversation', 0);

        if ($requestedId > 0) {
            $activeConversation = AiDiagnosticConversation::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $requestedId)
                ->with([
                    'vehicle:id,license_plate,brand,model,year,fuel_type,mileage',
                    'messages' => fn ($q) => $q->where('tenant_id', $tenantId)->with('user:id,name')->orderBy('id'),
                ])
                ->first();
        } elseif (! $request->boolean('new') && $conversations->isNotEmpty()) {
            $activeConversation = AiDiagnosticConversation::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $conversations->first()->id)
                ->with([
                    'vehicle:id,license_plate,brand,model,year,fuel_type,mileage',
                    'messages' => fn ($q) => $q->where('tenant_id', $tenantId)->with('user:id,name')->orderBy('id'),
                ])
                ->first();
        }

        $vehicles = Vehicle::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('license_plate')
            ->get(['id', 'license_plate', 'brand', 'model', 'year', 'fuel_type', 'mileage']);

        return Inertia::render('Tenant/AiDiagnostic/Index', [
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'vehicles' => $vehicles,
            'isConfigured' => $gemini->isConfigured(),
            'modelName' => $gemini->model(),
        ]);
    }

    public function send(
        Request $request,
        TenantContext $tenantContext,
        GeminiDiagnosticService $gemini
    ): RedirectResponse {
        $tenantId = $tenantContext->id();
        $user = $request->user('web');

        $data = $request->validate([
            'prompt' => ['required', 'string', 'min:2', 'max:3000'],
            'conversation_id' => ['nullable', 'integer'],
            'vehicle_id' => ['nullable', 'integer'],
        ]);

        $prompt = trim($data['prompt']);

        // Isolation stricte du véhicule
        $vehicle = null;
        if (! empty($data['vehicle_id'])) {
            $vehicle = Vehicle::query()
                ->where('tenant_id', $tenantId)
                ->where('id', (int) $data['vehicle_id'])
                ->firstOrFail();
        }

        // Isolation stricte de la conversation : 404 si l'ID appartient à un autre garage
        if (! empty($data['conversation_id'])) {
            $conversation = AiDiagnosticConversation::query()
                ->where('tenant_id', $tenantId)
                ->where('id', (int) $data['conversation_id'])
                ->firstOrFail();

            if ($vehicle && $conversation->vehicle_id !== $vehicle->id) {
                $conversation->update(['vehicle_id' => $vehicle->id]);
            } elseif (! $vehicle && $conversation->vehicle_id) {
                $vehicle = Vehicle::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $conversation->vehicle_id)
                    ->first();
            }
        } else {
            $conversation = AiDiagnosticConversation::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'vehicle_id' => $vehicle?->id,
                'title' => Str::limit($prompt, 90),
                'last_message_at' => now(),
            ]);
        }

        // Récupérer l'historique récent avant d'enregistrer le nouveau prompt
        $history = AiDiagnosticMessage::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->take(12)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();

        // 1. Enregistrement du prompt utilisateur en base de données
        AiDiagnosticMessage::create([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => AiDiagnosticMessage::ROLE_USER,
            'content' => $prompt,
            'is_off_topic' => false,
        ]);

        // 2. Appel à Gemini API (strictement spécialisé automobile)
        $result = $gemini->diagnose($prompt, $history, $vehicle);

        // 3. Enregistrement de la réponse IA en base de données
        AiDiagnosticMessage::create([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversation->id,
            'user_id' => null,
            'role' => AiDiagnosticMessage::ROLE_ASSISTANT,
            'content' => $result['reply'],
            'model' => $result['model'],
            'is_off_topic' => (bool) $result['is_off_topic'],
            'tokens_used' => $result['tokens_used'],
        ]);

        $conversation->update([
            'last_message_at' => now(),
        ]);

        AuditLog::record('ai_diagnostic.message_sent', subject: $conversation, actor: $user, tenantId: $tenantId, request: $request);

        return redirect("/assistant-ia?conversation={$conversation->id}");
    }

    public function destroy(
        Request $request,
        int $conversationId,
        TenantContext $tenantContext
    ): RedirectResponse {
        $tenantId = $tenantContext->id();

        $conversation = AiDiagnosticConversation::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $conversationId)
            ->firstOrFail();

        $conversation->delete();

        AuditLog::record('ai_diagnostic.conversation_deleted', actor: $request->user('web'), tenantId: $tenantId, request: $request);

        return redirect('/assistant-ia')->with('success', 'Conversation IA supprimée.');
    }
}
