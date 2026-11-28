<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\B2bNetworkRequest;
use App\Models\RepairOrder;
use App\Models\TechnicalInspection;
use App\Models\Tenant;
use App\Models\TenantConversation;
use App\Models\TenantConversationMessage;
use App\Models\TenantNetworkBlock;
use App\Models\Vehicle;
use App\Services\GreenApiService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Réseau Pro Inter-Établissements (Garage <-> Garage / Centre Technique / Assurance / Boutique Pièces).
 * Inclut : chat fluide, notes vocales, aperçu Lightbox, accusés de lecture, partage dossier 1-clic,
 * Bourse d'entraide Express (SOS par ville) et blocage anti-spam.
 */
class B2bNetworkController extends Controller
{
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'pdf',
        'csv', 'txt', 'log', 'xml', 'json',
        'bin', 'ori', 'mod', 'dbc', 'asc', 'trc',
    ];

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    private const AUDIO_EXTENSIONS = ['webm', 'ogg', 'mp3', 'm4a', 'wav'];

    public function state(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->getTenant();
        $myId = (int) $tenant->id;

        $search = trim((string) $request->query('q', ''));
        $typeFilter = trim((string) $request->query('type', ''));
        $sosCityFilter = trim((string) $request->query('sos_city', ''));
        $activeConvId = (int) $request->query('conversation_id', 0);

        $blockedIds = TenantNetworkBlock::where('blocker_tenant_id', $myId)->pluck('blocked_tenant_id')->all();
        $blockedByIds = TenantNetworkBlock::where('blocked_tenant_id', $myId)->pluck('blocker_tenant_id')->all();
        $excludedIds = array_unique(array_merge([$myId], $blockedIds, $blockedByIds));

        // 1) Annuaire des établissements partenaires visibles (hors bloqués)
        $directory = Tenant::query()
            ->whereNotIn('id', $excludedIds)
            ->where('b2b_directory_visible', true)
            ->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIALING])
            ->when($typeFilter !== '', fn ($q) => $q->where('type', $typeFilter))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('country', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->limit(60)
            ->get(['id', 'name', 'type', 'city', 'country'])
            ->map(fn (Tenant $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'type' => $t->type,
                'type_label' => $this->typeLabel($t->type),
                'city' => $t->city,
                'country' => $t->country,
            ])
            ->values();

        // 2) Mes conversations B2B actives avec compteurs non lus
        $conversationsRaw = TenantConversation::query()
            ->with(['tenantOne:id,name,type,city', 'tenantTwo:id,name,type,city'])
            ->where(fn ($q) => $q->where('tenant_one_id', $myId)->orWhere('tenant_two_id', $myId))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        $convIds = $conversationsRaw->pluck('id')->all();

        $unreadCounts = TenantConversationMessage::query()
            ->whereIn('conversation_id', $convIds)
            ->where('sender_tenant_id', '!=', $myId)
            ->whereNull('read_at')
            ->selectRaw('conversation_id, COUNT(*) as cnt')
            ->groupBy('conversation_id')
            ->pluck('cnt', 'conversation_id');

        $lastMessages = TenantConversationMessage::query()
            ->whereIn('conversation_id', $convIds)
            ->orderByDesc('id')
            ->get()
            ->unique('conversation_id')
            ->keyBy('conversation_id');

        $conversations = $conversationsRaw->map(function (TenantConversation $c) use ($myId, $unreadCounts, $lastMessages, $blockedIds) {
            $peer = (int) $c->tenant_one_id === $myId ? $c->tenantTwo : $c->tenantOne;
            $last = $lastMessages->get($c->id);
            $peerId = (int) ($peer?->id ?? 0);

            return [
                'id' => $c->id,
                'peer' => [
                    'id' => $peerId,
                    'name' => $peer?->name ?? 'Établissement',
                    'type' => $peer?->type ?? 'garage',
                    'type_label' => $this->typeLabel($peer?->type),
                    'city' => $peer?->city,
                    'is_blocked_by_me' => in_array($peerId, $blockedIds, true),
                ],
                'unread_count' => (int) ($unreadCounts->get($c->id) ?? 0),
                'last_preview' => $last ? ($last->audio_path && ! $last->body ? '🎙️ Note vocale' : Str::limit($last->body, 70)) : '',
                'last_message_at' => $c->last_message_at?->toIso8601String(),
            ];
        })->values();

        // 3) Messages de la conversation sélectionnée
        $messages = [];
        $activeConversation = null;
        if ($activeConvId > 0) {
            $conv = $conversationsRaw->firstWhere('id', $activeConvId);
            if ($conv && $conv->involvesTenant($myId)) {
                $activeConversation = $conversations->firstWhere('id', $activeConvId);

                $marked = TenantConversationMessage::where('conversation_id', $conv->id)
                    ->where('sender_tenant_id', '!=', $myId)
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);

                if ($marked > 0) {
                    Cache::forget("mboacar_tenant_nav_{$myId}");
                }

                $messages = TenantConversationMessage::where('conversation_id', $conv->id)
                    ->orderBy('id')
                    ->limit(100)
                    ->get()
                    ->map(fn (TenantConversationMessage $m) => $this->formatMessage($m, $myId))
                    ->values();
            }
        }

        // 4) Bourse d'entraide Express (SOS Pièces & Sous-traitance par ville)
        $sosRequests = B2bNetworkRequest::with('tenant:id,name,type,city')
            ->where('status', B2bNetworkRequest::STATUS_OPEN)
            ->whereNotIn('tenant_id', array_merge($blockedIds, $blockedByIds))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($sosCityFilter !== '', fn ($q) => $q->where('city', 'like', "%{$sosCityFilter}%"))
            ->orderByDesc('is_urgent')
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (B2bNetworkRequest $r) => [
                'id' => $r->id,
                'is_mine' => (int) $r->tenant_id === $myId,
                'tenant_id' => $r->tenant_id,
                'tenant_name' => $r->tenant?->name ?? 'Établissement',
                'tenant_type_label' => $this->typeLabel($r->tenant?->type),
                'city' => $r->city ?: ($r->tenant?->city ?? ''),
                'category' => $r->category,
                'title' => $r->title,
                'description' => $r->description,
                'is_urgent' => (bool) $r->is_urgent,
                'created_at' => $r->created_at?->toIso8601String(),
            ])
            ->values();

        // 5) Éléments partageables en 1 clic
        $shareableVehicles = Vehicle::orderByDesc('id')
            ->limit(30)
            ->get(['id', 'license_plate', 'brand', 'model', 'mileage'])
            ->map(fn ($v) => [
                'id' => $v->id,
                'label' => trim("{$v->license_plate} — {$v->brand} {$v->model}"),
            ]);

        $shareableOrders = RepairOrder::with('vehicle:id,license_plate,brand,model')
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'order_number', 'vehicle_id', 'status'])
            ->map(fn ($ro) => [
                'id' => $ro->id,
                'label' => trim("{$ro->order_number} ({$ro->vehicle?->license_plate}) — {$ro->status}"),
            ]);

        $shareablePvs = TechnicalInspection::with('vehicle:id,license_plate,brand,model')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'pv_number', 'vehicle_id', 'result'])
            ->map(fn ($pv) => [
                'id' => $pv->id,
                'label' => trim("PV {$pv->pv_number} ({$pv->vehicle?->license_plate}) — {$pv->result}"),
            ]);

        return response()->json([
            'ok' => true,
            'my_tenant_id' => $myId,
            'my_city' => $tenant->city ?? '',
            'b2b_directory_visible' => (bool) $tenant->b2b_directory_visible,
            'total_unread' => (int) $unreadCounts->sum(),
            'directory' => $directory,
            'conversations' => $conversations,
            'active_conversation' => $activeConversation,
            'messages' => $messages,
            'sos_requests' => $sosRequests,
            'shareable' => [
                'vehicles' => $shareableVehicles,
                'repair_orders' => $shareableOrders,
                'technical_pvs' => $shareablePvs,
            ],
        ]);
    }

    public function startConversation(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->getTenant();
        $validated = $request->validate([
            'target_tenant_id' => ['required', 'integer', 'exists:tenants,id'],
        ]);

        $targetId = (int) $validated['target_tenant_id'];
        if ($targetId === (int) $tenant->id) {
            return response()->json(['ok' => false, 'error' => 'Vous ne pouvez pas ouvrir une conversation avec votre propre établissement.'], 422);
        }

        if (TenantNetworkBlock::isBlockedBetween((int) $tenant->id, $targetId)) {
            return response()->json(['ok' => false, 'error' => 'Communication impossible : un blocage existe entre ces deux établissements.'], 403);
        }

        $target = Tenant::where('id', $targetId)
            ->where('b2b_directory_visible', true)
            ->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIALING])
            ->first();

        if (! $target) {
            return response()->json(['ok' => false, 'error' => 'Cet établissement n\'est pas disponible dans l\'annuaire B2B.'], 403);
        }

        $conv = TenantConversation::findOrCreatePair((int) $tenant->id, $targetId);
        if (! $conv->last_message_at) {
            $conv->update(['last_message_at' => now()]);
        }

        return response()->json([
            'ok' => true,
            'conversation_id' => $conv->id,
        ]);
    }

    public function sendMessage(
        Request $request,
        TenantConversation $conversation,
        TenantContext $tenantContext,
        GreenApiService $greenApi
    ): JsonResponse {
        $tenant = $tenantContext->getTenant();
        $myId = (int) $tenant->id;

        if (! $conversation->involvesTenant($myId)) {
            abort(403, 'Accès refusé à cette conversation inter-établissements.');
        }

        $peerId = $conversation->otherTenantId($myId);
        if (TenantNetworkBlock::isBlockedBetween($myId, $peerId)) {
            return response()->json(['ok' => false, 'error' => 'Envoi bloqué : un blocage existe entre ces deux établissements.'], 403);
        }

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'is_urgent' => ['nullable', 'boolean'],
            'notify_whatsapp' => ['nullable', 'boolean'],
            'share_type' => ['nullable', 'in:vehicle,repair_order,technical_pv'],
            'share_id' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['file', 'max:10240'],
            'audio' => ['nullable', 'file', 'max:10240'],
            'audio_duration' => ['nullable', 'integer', 'min:0', 'max:180'],
        ]);

        $bodyText = trim((string) ($validated['body'] ?? ''));
        $hasAudio = $request->hasFile('audio');
        $hasFiles = $request->hasFile('attachments');

        if ($bodyText === '' && ! $hasAudio && ! $hasFiles) {
            return response()->json(['ok' => false, 'error' => 'Veuillez saisir un message, joindre un fichier ou enregistrer une note vocale.'], 422);
        }

        // Pièces jointes sécurisées
        $storedAttachments = [];
        foreach ($request->file('attachments', []) as $file) {
            $ext = strtolower($file->getClientOriginalExtension());
            if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                return response()->json([
                    'ok' => false,
                    'error' => "Le format .{$ext} n'est pas autorisé dans le Réseau Pro (vidéos, archives et exécutables bloqués).",
                ], 422);
            }

            $storedPath = $file->storeAs(
                "b2b-network/conv-{$conversation->id}",
                Str::random(32).'.'.$ext,
                'local'
            );

            $storedAttachments[] = [
                'path' => $storedPath,
                'original_name' => $file->getClientOriginalName(),
                'ext' => $ext,
                'size' => (int) $file->getSize(),
            ];
        }

        // Note vocale atelier (audio uniquement, jamais de vidéo)
        $audioPath = null;
        $audioDuration = null;
        if ($hasAudio) {
            $audioFile = $request->file('audio');
            $audioExt = strtolower($audioFile->getClientOriginalExtension() ?: 'webm');
            $mime = strtolower((string) $audioFile->getMimeType());
            $isForbiddenVideoMime = str_starts_with($mime, 'video/') && $mime !== 'video/webm';
            if ($isForbiddenVideoMime || ! in_array($audioExt, self::AUDIO_EXTENSIONS, true)) {
                return response()->json(['ok' => false, 'error' => 'Seules les notes vocales audio (webm, ogg, mp3, m4a, wav) sont autorisées.'], 422);
            }
            $audioPath = $audioFile->storeAs(
                "b2b-network/conv-{$conversation->id}/audio",
                Str::random(32).'.'.$audioExt,
                'local'
            );
            $audioDuration = (int) ($validated['audio_duration'] ?? 0);
        }

        $sharedContext = $this->resolveSharedContext(
            $validated['share_type'] ?? null,
            isset($validated['share_id']) ? (int) $validated['share_id'] : null
        );

        $user = $request->user('web');
        $isUrgent = (bool) ($validated['is_urgent'] ?? false);

        $message = TenantConversationMessage::create([
            'conversation_id' => $conversation->id,
            'sender_tenant_id' => $myId,
            'sender_user_id' => $user->id,
            'sender_name' => $tenant->name.' — '.$user->name,
            'body' => $bodyText !== '' ? $bodyText : ($audioPath ? '🎙️ Note vocale atelier' : '📎 Pièce jointe'),
            'shared_context' => $sharedContext,
            'attachments' => $storedAttachments,
            'audio_path' => $audioPath,
            'audio_duration' => $audioDuration,
            'is_urgent' => $isUrgent,
        ]);

        $conversation->update(['last_message_at' => now()]);
        Cache::forget("mboacar_tenant_nav_{$peerId}");

        if ($request->boolean('notify_whatsapp') || $isUrgent) {
            $peer = Tenant::find($peerId);
            if ($peer && ! empty($peer->phone)) {
                try {
                    $prefix = $isUrgent ? '🚨 [URGENT Réseau Pro MboaCar]' : '💬 [Réseau Pro MboaCar]';
                    $waText = "{$prefix}\n*{$tenant->name}* vous a envoyé un message :\n\"".Str::limit($message->body, 220)."\"\n\nRépondez depuis MboaCar → Messagerie → Réseau Pro.";
                    $greenApi->sendMessage($peer->phone, $waText);
                } catch (\Throwable $e) {
                    Log::warning('B2B network WhatsApp alert failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
                }
            }
        }

        AuditLog::record('b2b_network.message_sent', subject: $message, actor: $user, tenantId: $myId, request: $request);

        return response()->json([
            'ok' => true,
            'message' => $this->formatMessage($message, $myId),
        ], 201);
    }

    public function downloadAttachment(
        TenantConversationMessage $message,
        int $index,
        TenantContext $tenantContext
    ): StreamedResponse {
        $message->loadMissing('conversation');
        $myId = (int) $tenantContext->id();

        if (! $message->conversation || ! $message->conversation->involvesTenant($myId)) {
            abort(403, 'Accès refusé à cette pièce jointe.');
        }

        $attachments = is_array($message->attachments) ? $message->attachments : [];
        $att = $attachments[$index] ?? null;
        if (! $att || empty($att['path']) || ! Storage::disk('local')->exists($att['path'])) {
            abort(404);
        }

        return Storage::disk('local')->download($att['path'], $att['original_name'] ?? 'piece-jointe');
    }

    public function previewAttachment(
        TenantConversationMessage $message,
        int $index,
        TenantContext $tenantContext
    ): StreamedResponse {
        $message->loadMissing('conversation');
        $myId = (int) $tenantContext->id();

        if (! $message->conversation || ! $message->conversation->involvesTenant($myId)) {
            abort(403, 'Accès refusé à cet aperçu.');
        }

        $attachments = is_array($message->attachments) ? $message->attachments : [];
        $att = $attachments[$index] ?? null;
        $ext = strtolower((string) ($att['ext'] ?? ''));
        if (! $att || empty($att['path']) || ! in_array($ext, self::IMAGE_EXTENSIONS, true) || ! Storage::disk('local')->exists($att['path'])) {
            abort(404);
        }

        return Storage::disk('local')->response($att['path']);
    }

    public function streamAudio(
        TenantConversationMessage $message,
        TenantContext $tenantContext
    ): StreamedResponse {
        $message->loadMissing('conversation');
        $myId = (int) $tenantContext->id();

        if (! $message->conversation || ! $message->conversation->involvesTenant($myId)) {
            abort(403, 'Accès refusé à cette note vocale.');
        }

        if (empty($message->audio_path) || ! Storage::disk('local')->exists($message->audio_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($message->audio_path);
    }

    public function toggleVisibility(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $validated = $request->validate([
            'visible' => ['required', 'boolean'],
        ]);

        $tenant = $tenantContext->getTenant();
        $tenant->update(['b2b_directory_visible' => (bool) $validated['visible']]);

        return response()->json([
            'ok' => true,
            'b2b_directory_visible' => (bool) $tenant->b2b_directory_visible,
        ]);
    }

    public function storeSosRequest(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->getTenant();
        $validated = $request->validate([
            'category' => ['required', 'in:piece,diagnostic,sous_traitance,outillage'],
            'title' => ['required', 'string', 'min:4', 'max:180'],
            'description' => ['nullable', 'string', 'max:1500'],
            'city' => ['nullable', 'string', 'max:120'],
            'is_urgent' => ['nullable', 'boolean'],
        ]);

        $sos = B2bNetworkRequest::create([
            'tenant_id' => $tenant->id,
            'user_id' => $request->user('web')->id,
            'city' => trim((string) ($validated['city'] ?? $tenant->city ?? '')),
            'category' => $validated['category'],
            'title' => trim($validated['title']),
            'description' => $validated['description'] ?? null,
            'is_urgent' => (bool) ($validated['is_urgent'] ?? false),
            'status' => B2bNetworkRequest::STATUS_OPEN,
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json(['ok' => true, 'id' => $sos->id], 201);
    }

    public function resolveSosRequest(B2bNetworkRequest $sosRequest, TenantContext $tenantContext): JsonResponse
    {
        if ((int) $sosRequest->tenant_id !== (int) $tenantContext->id()) {
            abort(403);
        }

        $sosRequest->update(['status' => B2bNetworkRequest::STATUS_RESOLVED]);

        return response()->json(['ok' => true]);
    }

    public function replyToSosRequest(B2bNetworkRequest $sosRequest, TenantContext $tenantContext): JsonResponse
    {
        $myId = (int) $tenantContext->id();
        $targetId = (int) $sosRequest->tenant_id;

        if ($targetId === $myId) {
            return response()->json(['ok' => false, 'error' => 'C\'est votre propre annonce.'], 422);
        }

        if (TenantNetworkBlock::isBlockedBetween($myId, $targetId)) {
            return response()->json(['ok' => false, 'error' => 'Un blocage existe avec cet établissement.'], 403);
        }

        $conv = TenantConversation::findOrCreatePair($myId, $targetId);
        if (! $conv->last_message_at) {
            $conv->update(['last_message_at' => now()]);
        }

        return response()->json([
            'ok' => true,
            'conversation_id' => $conv->id,
            'prefill' => "Bonjour, concernant votre demande SOS « {$sosRequest->title} » : ",
        ]);
    }

    public function blockTenant(Tenant $targetTenant, TenantContext $tenantContext): JsonResponse
    {
        $myId = (int) $tenantContext->id();
        if ((int) $targetTenant->id === $myId) {
            return response()->json(['ok' => false, 'error' => 'Action invalide.'], 422);
        }

        TenantNetworkBlock::firstOrCreate([
            'blocker_tenant_id' => $myId,
            'blocked_tenant_id' => (int) $targetTenant->id,
        ]);

        return response()->json(['ok' => true, 'blocked' => true]);
    }

    public function unblockTenant(Tenant $targetTenant, TenantContext $tenantContext): JsonResponse
    {
        TenantNetworkBlock::where('blocker_tenant_id', (int) $tenantContext->id())
            ->where('blocked_tenant_id', (int) $targetTenant->id)
            ->delete();

        return response()->json(['ok' => true, 'blocked' => false]);
    }

    private function formatMessage(TenantConversationMessage $m, int $myTenantId): array
    {
        $attachments = collect($m->attachments ?? [])->map(function ($att, $idx) use ($m) {
            $ext = strtolower((string) ($att['ext'] ?? ''));
            $isImage = in_array($ext, self::IMAGE_EXTENSIONS, true);

            return [
                'index' => $idx,
                'name' => $att['original_name'] ?? 'fichier',
                'ext' => $ext,
                'is_image' => $isImage,
                'size_kb' => round(((int) ($att['size'] ?? 0)) / 1024, 1),
                'download_url' => route('tenant.b2b_network.attachment', ['message' => $m->id, 'index' => $idx]),
                'preview_url' => $isImage ? route('tenant.b2b_network.preview', ['message' => $m->id, 'index' => $idx]) : null,
            ];
        })->values();

        return [
            'id' => $m->id,
            'is_mine' => (int) $m->sender_tenant_id === $myTenantId,
            'sender_name' => $m->sender_name,
            'body' => $m->body,
            'shared_context' => $m->shared_context,
            'attachments' => $attachments,
            'audio_url' => $m->audio_path ? route('tenant.b2b_network.audio', ['message' => $m->id]) : null,
            'audio_duration' => $m->audio_duration,
            'is_urgent' => (bool) $m->is_urgent,
            'read_at' => $m->read_at?->toIso8601String(),
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    private function resolveSharedContext(?string $type, ?int $id): ?array
    {
        if (! $type || ! $id) {
            return null;
        }

        if ($type === 'vehicle') {
            $v = Vehicle::find($id);
            if (! $v) {
                return null;
            }

            return [
                'type' => 'vehicle',
                'badge' => 'Fiche Véhicule',
                'title' => trim("{$v->brand} {$v->model} ({$v->license_plate})"),
                'details' => trim(($v->year ? "Année {$v->year} • " : '').($v->mileage ? number_format($v->mileage, 0, ',', ' ').' km' : '').($v->vin ? " • VIN {$v->vin}" : '')),
                'action_label' => 'Ouvrir en Réception',
                'action_url' => '/receptions',
            ];
        }

        if ($type === 'repair_order') {
            $ro = RepairOrder::with('vehicle:id,license_plate,brand,model')->find($id);
            if (! $ro) {
                return null;
            }

            return [
                'type' => 'repair_order',
                'badge' => 'Ordre de Réparation',
                'title' => "{$ro->order_number} — ".trim(($ro->vehicle?->brand ?? '').' '.($ro->vehicle?->model ?? '').' ('.($ro->vehicle?->license_plate ?? '').')'),
                'details' => "Statut : {$ro->status}".($ro->customer_complaint ? ' • '.Str::limit($ro->customer_complaint, 120) : ''),
                'action_label' => 'Créer un Devis',
                'action_url' => '/devis',
            ];
        }

        if ($type === 'technical_pv') {
            $pv = TechnicalInspection::with('vehicle:id,license_plate,brand,model')->find($id);
            if (! $pv) {
                return null;
            }

            return [
                'type' => 'technical_pv',
                'badge' => 'PV Visite Technique',
                'title' => "PV {$pv->pv_number} — ".trim(($pv->vehicle?->brand ?? '').' '.($pv->vehicle?->model ?? '').' ('.($pv->vehicle?->license_plate ?? '').')'),
                'details' => "Résultat : {$pv->result}",
                'pv_url' => $pv->public_token ? url("/pv-visite/{$pv->public_token}") : null,
                'action_label' => 'Créer un Devis',
                'action_url' => '/devis',
            ];
        }

        return null;
    }

    private function typeLabel(?string $type): string
    {
        return match ($type) {
            'centre_technique' => 'Centre Technique',
            'assurance' => 'Assurance',
            'boutique_pieces', 'pieces' => 'Boutique Pièces',
            default => 'Garage / Atelier',
        };
    }
}
