<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AiDiagnosticConversation;
use App\Models\AiDiagnosticMessage;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\RepairOrder;
use App\Models\Vehicle;
use App\Models\VehicleInspection;
use App\Services\AiKnowledgeService;
use App\Services\GeminiDiagnosticService;
use App\Services\GreenApiService;
use App\Services\NumberSequenceService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        $prefillRepairOrder = null;
        $requestedRoId = (int) $request->query('repair_order', 0);
        if ($requestedRoId > 0) {
            $ro = RepairOrder::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $requestedRoId)
                ->with(['vehicle:id,license_plate,brand,model,year,fuel_type,mileage'])
                ->first();

            if ($ro) {
                $codes = is_array($ro->fault_codes) && $ro->fault_codes !== []
                    ? 'Codes défauts OBD : '.implode(', ', $ro->fault_codes).'. '
                    : '';
                $notes = trim((string) ($ro->diagnostic_notes ?? '')) !== ''
                    ? 'Notes atelier : '.trim((string) $ro->diagnostic_notes)
                    : 'Merci de proposer les contrôles prioritaires à effectuer en atelier.';

                $prefillRepairOrder = [
                    'id' => $ro->id,
                    'order_number' => $ro->order_number,
                    'vehicle_id' => $ro->vehicle_id,
                    'suggested_prompt' => trim("Aide au diagnostic pour l'OR {$ro->order_number}. {$codes}{$notes}"),
                ];
            }
        }

        $activeConversation = null;
        $requestedId = (int) $request->query('conversation', 0);

        if ($requestedId > 0) {
            $activeConversation = AiDiagnosticConversation::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $requestedId)
                ->with([
                    'vehicle.customer:id,name,phone,whatsapp_phone,email',
                    'repairOrder:id,order_number,status',
                    'messages' => fn ($q) => $q->where('tenant_id', $tenantId)->with('user:id,name')->orderBy('id'),
                ])
                ->first();
        } elseif (! $request->boolean('new') && ! $prefillRepairOrder && $conversations->isNotEmpty()) {
            $activeConversation = AiDiagnosticConversation::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $conversations->first()->id)
                ->with([
                    'vehicle.customer:id,name,phone,whatsapp_phone,email',
                    'repairOrder:id,order_number,status',
                    'messages' => fn ($q) => $q->where('tenant_id', $tenantId)->with('user:id,name')->orderBy('id'),
                ])
                ->first();
        }

        $vehicles = Vehicle::query()
            ->where('tenant_id', $tenantId)
            ->with('customer:id,name,phone,whatsapp_phone')
            ->orderBy('license_plate')
            ->get(['id', 'customer_id', 'license_plate', 'brand', 'model', 'year', 'fuel_type', 'mileage']);

        $activeRepairOrders = RepairOrder::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['livre', 'annule'])
            ->with(['vehicle:id,license_plate', 'customer:id,name'])
            ->orderByDesc('id')
            ->take(30)
            ->get(['id', 'order_number', 'vehicle_id', 'customer_id', 'status']);

        $dailyQuota = (int) PlatformSetting::getValue('ai_daily_quota', 50);
        $usedToday = AiDiagnosticMessage::query()
            ->where('tenant_id', $tenantId)
            ->where('role', AiDiagnosticMessage::ROLE_USER)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        return Inertia::render('Tenant/AiDiagnostic/Index', [
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'vehicles' => $vehicles,
            'activeRepairOrders' => $activeRepairOrders,
            'prefillRepairOrder' => $prefillRepairOrder,
            'dailyQuota' => $dailyQuota,
            'usedToday' => $usedToday,
            'isConfigured' => $gemini->isConfigured(),
            'modelName' => $gemini->model(),
        ]);
    }

    public function send(
        Request $request,
        TenantContext $tenantContext,
        GeminiDiagnosticService $gemini,
        AiKnowledgeService $knowledge
    ): RedirectResponse {
        $tenantId = $tenantContext->id();
        $user = $request->user('web');

        // Vérification du quota journalier configuré par le super-admin (0 = illimité)
        $dailyQuota = (int) PlatformSetting::getValue('ai_daily_quota', 50);
        if ($dailyQuota > 0) {
            $usedToday = AiDiagnosticMessage::query()
                ->where('tenant_id', $tenantId)
                ->where('role', AiDiagnosticMessage::ROLE_USER)
                ->whereDate('created_at', now()->toDateString())
                ->count();

            if ($usedToday >= $dailyQuota) {
                return back()->with('error', "Quota journalier IA atteint ({$dailyQuota} diagnostics/jour pour votre garage). Réessayez demain ou contactez l'administration.");
            }
        }

        $data = $request->validate([
            'prompt' => ['nullable', 'string', 'max:3000'],
            'conversation_id' => ['nullable', 'integer'],
            'vehicle_id' => ['nullable', 'integer'],
            'repair_order_id' => ['nullable', 'integer'],
            'photos' => ['nullable', 'array', 'max:4'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:6144'],
            'audio' => ['nullable', 'file', 'max:10240'],
            'audio_duration' => ['nullable', 'integer', 'min:0', 'max:600'],
            'force_online' => ['nullable', 'boolean'],
        ]);

        $prompt = trim((string) ($data['prompt'] ?? ''));
        $hasPhotos = $request->hasFile('photos');
        $hasAudio = $request->hasFile('audio');

        if ($prompt === '' && ! $hasPhotos && ! $hasAudio) {
            return back()->withErrors(['prompt' => 'Saisissez une question, joignez une photo ou enregistrez un message vocal.']);
        }

        // Sécurité stricte : interdire les vidéos sur tous les champs fichiers
        if ($hasPhotos) {
            foreach ($request->file('photos') as $file) {
                $mime = strtolower((string) $file->getMimeType());
                if (str_starts_with($mime, 'video/')) {
                    return back()->withErrors(['photos' => 'Les vidéos ne sont pas autorisées. Seules les photos (JPG, PNG, WEBP) sont acceptées.']);
                }
            }
        }

        $audioPath = null;
        $audioMime = null;
        $mediaParts = [];
        $storedAttachments = [];

        if ($hasAudio) {
            $audioFile = $request->file('audio');
            $rawMime = strtolower((string) ($audioFile->getMimeType() ?: $audioFile->getClientMimeType()));
            $ext = strtolower((string) $audioFile->getClientOriginalExtension());
            $forbiddenVideoExts = ['mp4', 'mov', 'avi', 'mkv', 'wmv', 'flv', 'm4v', '3gp'];
            $allowedAudioExts = ['webm', 'ogg', 'oga', 'mp3', 'wav', 'm4a', 'aac', 'opus'];

            if (in_array($ext, $forbiddenVideoExts, true) || (str_starts_with($rawMime, 'video/') && $rawMime !== 'video/webm')) {
                return back()->withErrors(['audio' => 'Les vidéos ne sont pas autorisées. Seuls les messages vocaux audio sont acceptés.']);
            }
            if (! str_starts_with($rawMime, 'audio/') && ! in_array($ext, $allowedAudioExts, true)) {
                return back()->withErrors(['audio' => 'Format audio non pris en charge.']);
            }

            $audioMime = str_starts_with($rawMime, 'audio/') ? $rawMime : 'audio/webm';
            $audioPath = Storage::disk('public')->putFile("ai-diagnostics/{$tenantId}/audio", $audioFile);

            $audioBytes = @file_get_contents($audioFile->getRealPath());
            if ($audioBytes !== false && $audioBytes !== '') {
                $mediaParts[] = [
                    'mime_type' => $audioMime,
                    'data' => base64_encode($audioBytes),
                ];
            }
        }

        if ($hasPhotos) {
            foreach ($request->file('photos') as $photoFile) {
                $mime = strtolower((string) ($photoFile->getMimeType() ?: 'image/jpeg'));
                $path = Storage::disk('public')->putFile("ai-diagnostics/{$tenantId}/photos", $photoFile);
                $storedAttachments[] = [
                    'type' => 'image',
                    'path' => $path,
                    'mime' => $mime,
                    'name' => $photoFile->getClientOriginalName(),
                ];

                $imgBytes = @file_get_contents($photoFile->getRealPath());
                if ($imgBytes !== false && $imgBytes !== '') {
                    $mediaParts[] = [
                        'mime_type' => $mime,
                        'data' => base64_encode($imgBytes),
                    ];
                }
            }
        }

        // Isolation stricte du véhicule
        $vehicle = null;
        if (! empty($data['vehicle_id'])) {
            $vehicle = Vehicle::query()
                ->where('tenant_id', $tenantId)
                ->where('id', (int) $data['vehicle_id'])
                ->firstOrFail();
        }

        // Isolation stricte de l'OR facultatif
        $repairOrderId = null;
        if (! empty($data['repair_order_id'])) {
            $ro = RepairOrder::query()
                ->where('tenant_id', $tenantId)
                ->where('id', (int) $data['repair_order_id'])
                ->firstOrFail();
            $repairOrderId = $ro->id;
            if (! $vehicle && $ro->vehicle_id) {
                $vehicle = Vehicle::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $ro->vehicle_id)
                    ->first();
            }
        }

        // Isolation stricte de la conversation : 404 si l'ID appartient à un autre garage
        $titleSeed = $prompt !== ''
            ? $prompt
            : ($hasAudio ? 'Message vocal atelier' : 'Analyse photo diagnostic');

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
                'repair_order_id' => $repairOrderId,
                'title' => Str::limit($titleSeed, 90),
                'last_message_at' => now(),
            ]);
        }

        // Historique récent de la conversation
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

        // Injection automatique de l'historique atelier du véhicule (OR + Inspections de CE garage)
        $vehicleHistoryLines = $this->buildVehicleHistoryLines($tenantId, $vehicle);

        // 1. Enregistrement du prompt / photos / vocal utilisateur en base de données
        $storedContent = $prompt !== ''
            ? $prompt
            : ($hasAudio ? '🎤 Message vocal enregistré' : '📷 Photo(s) envoyée(s) pour analyse');

        AiDiagnosticMessage::create([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => AiDiagnosticMessage::ROLE_USER,
            'content' => $storedContent,
            'attachments' => $storedAttachments !== [] ? $storedAttachments : null,
            'audio_path' => $audioPath,
            'audio_mime' => $audioMime,
            'audio_duration' => isset($data['audio_duration']) ? (int) $data['audio_duration'] : null,
            'is_off_topic' => false,
        ]);

        // 2. Vérifier d'abord dans la base de connaissances MboaCar (prompts similaires déjà résolus en atelier)
        $forceOnline = (bool) ($data['force_online'] ?? false);
        $kbLookup = $prompt !== '' ? $knowledge->lookup($prompt) : ['direct_match' => null, 'learning_hints' => []];
        $matchedMessageId = null;

        if (! $forceOnline && ! $hasPhotos && ! $hasAudio && $kbLookup['direct_match'] !== null) {
            $matchedMessageId = $kbLookup['direct_match']['message_id'];
            AiDiagnosticMessage::withoutTenantScope()
                ->where('id', $matchedMessageId)
                ->increment('reuse_count');

            $result = [
                'ok' => true,
                'reply' => $kbLookup['direct_match']['reply'],
                'is_off_topic' => false,
                'model' => 'mboacar-kb',
                'tokens_used' => 0,
                'error' => null,
            ];
        } else {
            // Appel en ligne à Gemini API (enrichi des retours d'expérience résolus en BDD)
            $result = $gemini->diagnose(
                $prompt,
                $history,
                $vehicle,
                $mediaParts,
                $vehicleHistoryLines,
                $kbLookup['learning_hints']
            );
        }

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
            'matched_from_message_id' => $matchedMessageId,
        ]);

        $conversation->update([
            'last_message_at' => now(),
        ]);

        AuditLog::record('ai_diagnostic.message_sent', subject: $conversation, actor: $user, tenantId: $tenantId, request: $request);

        return redirect("/assistant-ia?conversation={$conversation->id}");
    }

    /**
     * Crée un brouillon de devis (/devis) en 1 clic à partir d'un diagnostic IA.
     */
    public function createQuoteFromMessage(
        Request $request,
        int $messageId,
        TenantContext $tenantContext,
        NumberSequenceService $sequences
    ): RedirectResponse {
        $tenant = $tenantContext->getTenant();
        $tenantId = $tenant->id;

        $message = AiDiagnosticMessage::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $messageId)
            ->where('role', AiDiagnosticMessage::ROLE_ASSISTANT)
            ->with('conversation.vehicle')
            ->firstOrFail();

        $data = $request->validate([
            'vehicle_id' => ['nullable', 'integer'],
        ]);

        $vehicleId = ! empty($data['vehicle_id'])
            ? (int) $data['vehicle_id']
            : $message->conversation?->vehicle_id;

        if (! $vehicleId) {
            return back()->with('error', 'Veuillez sélectionner un véhicule dans la conversation avant de créer un brouillon de devis.');
        }

        $vehicle = Vehicle::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $vehicleId)
            ->firstOrFail();

        $agencyId = $request->session()->get('active_agency_id')
            ?: $request->user('web')->default_agency_id
            ?: Agency::where('tenant_id', $tenantId)->value('id');

        $quote = DB::transaction(function () use ($tenant, $tenantId, $agencyId, $vehicle, $message, $sequences) {
            $quote = Quote::create([
                'tenant_id' => $tenantId,
                'agency_id' => $agencyId,
                'customer_id' => $vehicle->customer_id,
                'vehicle_id' => $vehicle->id,
                'repair_order_id' => $message->conversation?->repair_order_id,
                'quote_number' => $sequences->nextQuoteNumber($tenantId),
                'version' => 1,
                'status' => Quote::STATUS_DRAFT,
                'valid_until' => now()->addDays(15),
                'vat_rate' => $tenant->vat_rate ?? 0,
                'discount_amount' => 0,
                'deposit_required' => 0,
                'notes' => Str::limit("Diagnostic IA MboaCar :\n".$message->content, 1900),
            ]);

            QuoteItem::create([
                'tenant_id' => $tenantId,
                'quote_id' => $quote->id,
                'type' => 'main_oeuvre',
                'urgency' => 'urgent',
                'description' => Str::limit('Diagnostic & intervention suite à analyse IA : '.($message->conversation?->title ?? 'Panne véhicule'), 180),
                'quantity' => 1,
                'unit_price' => 15000,
                'total_price' => 15000,
                'is_accepted' => true,
            ]);

            $quote->recalculateTotals();

            return $quote;
        });

        AuditLog::record('ai_diagnostic.quote_created', subject: $quote, actor: $request->user('web'), tenantId: $tenantId, request: $request);

        return redirect('/devis')->with('success', "Brouillon de devis {$quote->quote_number} créé depuis le diagnostic IA !");
    }

    /**
     * Ajoute un diagnostic IA directement dans les notes d'un Ordre de Réparation (/ordres-reparation).
     */
    public function applyToRepairOrder(
        Request $request,
        int $messageId,
        TenantContext $tenantContext
    ): RedirectResponse {
        $tenantId = $tenantContext->id();

        $message = AiDiagnosticMessage::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $messageId)
            ->where('role', AiDiagnosticMessage::ROLE_ASSISTANT)
            ->firstOrFail();

        $data = $request->validate([
            'repair_order_id' => ['required', 'integer'],
        ]);

        $order = RepairOrder::query()
            ->where('tenant_id', $tenantId)
            ->where('id', (int) $data['repair_order_id'])
            ->firstOrFail();

        $existing = trim((string) ($order->diagnostic_notes ?? ''));
        $stamp = '[MboaCar IA Auto - '.now()->format('d/m/Y H:i').']';
        $appended = $existing !== ''
            ? "{$existing}\n\n{$stamp}\n{$message->content}"
            : "{$stamp}\n{$message->content}";

        $order->update([
            'diagnostic_notes' => Str::limit($appended, 6000),
        ]);

        AuditLog::record('ai_diagnostic.applied_to_ro', subject: $order, actor: $request->user('web'), tenantId: $tenantId, request: $request);

        return back()->with('success', "Diagnostic IA ajouté à l'ordre de réparation {$order->order_number}.");
    }

    /**
     * Génère (ou renvoie) une explication claire et rassurante pour le client.
     */
    public function explainForClient(
        Request $request,
        int $messageId,
        TenantContext $tenantContext,
        GeminiDiagnosticService $gemini
    ): JsonResponse {
        $tenant = $tenantContext->getTenant();
        $tenantId = $tenant->id;

        $message = AiDiagnosticMessage::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $messageId)
            ->where('role', AiDiagnosticMessage::ROLE_ASSISTANT)
            ->with('conversation.vehicle.customer')
            ->firstOrFail();

        if (! $request->boolean('regenerate') && trim((string) ($message->client_summary ?? '')) !== '') {
            return response()->json([
                'ok' => true,
                'summary' => $message->client_summary,
                'phone' => $message->conversation?->vehicle?->customer?->whatsapp_phone
                    ?: $message->conversation?->vehicle?->customer?->phone,
            ]);
        }

        $vehicle = $message->conversation?->vehicle;
        $customer = $vehicle?->customer;

        $res = $gemini->summarizeForClient(
            $message->content,
            $vehicle,
            $customer?->name,
            $tenant?->name
        );

        if (! $res['ok']) {
            return response()->json([
                'ok' => false,
                'error' => $res['reply'],
            ], 422);
        }

        $message->update([
            'client_summary' => $res['reply'],
        ]);

        return response()->json([
            'ok' => true,
            'summary' => $res['reply'],
            'phone' => $customer?->whatsapp_phone ?: $customer?->phone,
        ]);
    }

    /**
     * Envoie l'explication vulgarisée au client via WhatsApp (Green-API).
     */
    public function sendClientWhatsapp(
        Request $request,
        int $messageId,
        TenantContext $tenantContext,
        GreenApiService $greenApi
    ): RedirectResponse {
        $tenantId = $tenantContext->id();

        $message = AiDiagnosticMessage::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $messageId)
            ->where('role', AiDiagnosticMessage::ROLE_ASSISTANT)
            ->with('conversation.vehicle.customer')
            ->firstOrFail();

        $data = $request->validate([
            'phone' => ['required', 'string', 'min:6', 'max:40'],
            'summary' => ['required', 'string', 'min:5', 'max:2500'],
        ]);

        if (! $greenApi->isConfigured()) {
            return back()->with('error', "Green-API WhatsApp n'est pas configuré.");
        }

        $res = $greenApi->sendMessage($data['phone'], $data['summary']);
        if (! ($res['ok'] ?? false)) {
            return back()->with('error', $res['error'] ?? "Échec de l'envoi WhatsApp.");
        }

        $message->update([
            'client_summary' => $data['summary'],
            'client_whatsapp_sent_at' => now(),
        ]);

        AuditLog::record('ai_diagnostic.client_whatsapp_sent', subject: $message, actor: $request->user('web'), tenantId: $tenantId, request: $request);

        return back()->with('success', "Explication client envoyée sur WhatsApp ({$data['phone']}).");
    }

    /**
     * Enregistre le retour du garagiste : est-ce que MboaCar IA Auto a résolu le problème ?
     * Les réponses marquées comme résolues enrichissent automatiquement la base de connaissances IA.
     */
    public function submitFeedback(
        Request $request,
        int $messageId,
        TenantContext $tenantContext
    ): RedirectResponse {
        $tenantId = $tenantContext->id();

        $message = AiDiagnosticMessage::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $messageId)
            ->where('role', AiDiagnosticMessage::ROLE_ASSISTANT)
            ->firstOrFail();

        $data = $request->validate([
            'is_resolved' => ['required', 'boolean'],
            'feedback_note' => ['nullable', 'string', 'max:500'],
        ]);

        $message->update([
            'is_resolved' => (bool) $data['is_resolved'],
            'feedback_note' => isset($data['feedback_note']) ? trim((string) $data['feedback_note']) : null,
            'feedback_at' => now(),
        ]);

        AuditLog::record('ai_diagnostic.feedback_submitted', subject: $message, actor: $request->user('web'), tenantId: $tenantId, request: $request);

        $msg = $data['is_resolved']
            ? 'Merci ! Ce diagnostic est enregistré comme résolu pour améliorer MboaCar IA Auto.'
            : 'Merci pour votre retour ! Vous pouvez relancer une analyse IA fraîche si besoin.';

        return back()->with('success', $msg);
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

    /**
     * Construit un résumé compact des derniers OR et inspections du véhicule dans ce garage.
     *
     * @return array<int, string>
     */
    private function buildVehicleHistoryLines(int $tenantId, ?Vehicle $vehicle): array
    {
        if (! $vehicle) {
            return [];
        }

        $lines = [];

        $recentOrders = RepairOrder::query()
            ->where('tenant_id', $tenantId)
            ->where('vehicle_id', $vehicle->id)
            ->orderByDesc('id')
            ->take(5)
            ->get(['order_number', 'status', 'fault_codes', 'diagnostic_notes', 'created_at']);

        foreach ($recentOrders as $ro) {
            $date = $ro->created_at?->format('d/m/Y') ?? '';
            $codes = is_array($ro->fault_codes) && $ro->fault_codes !== []
                ? ' [OBD: '.implode(', ', $ro->fault_codes).']'
                : '';
            $notes = trim((string) ($ro->diagnostic_notes ?? ''));
            $snippet = $notes !== '' ? ' — '.Str::limit(preg_replace('/\s+/', ' ', $notes), 160) : '';
            $lines[] = "OR {$ro->order_number} ({$date}, statut: {$ro->status}){$codes}{$snippet}";
        }

        if (class_exists(VehicleInspection::class)) {
            $recentInspections = VehicleInspection::query()
                ->where('tenant_id', $tenantId)
                ->where('vehicle_id', $vehicle->id)
                ->orderByDesc('id')
                ->take(3)
                ->get(['summary_notes', 'created_at']);

            foreach ($recentInspections as $insp) {
                $note = trim((string) ($insp->summary_notes ?? ''));
                if ($note !== '') {
                    $date = $insp->created_at?->format('d/m/Y') ?? '';
                    $lines[] = "Inspection DVI ({$date}) — ".Str::limit(preg_replace('/\s+/', ' ', $note), 140);
                }
            }
        }

        return $lines;
    }
}
