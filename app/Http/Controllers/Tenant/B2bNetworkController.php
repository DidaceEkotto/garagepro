<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\RepairOrder;
use App\Models\TechnicalInspection;
use App\Models\Tenant;
use App\Models\TenantConversation;
use App\Models\TenantConversationMessage;
use App\Models\Vehicle;
use App\Services\GreenApiService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Réseau Pro Inter-Établissements (Garage <-> Garage / Centre Technique / Assurance / Boutique Pièces).
 * Isolation stricte : un établissement ne peut accéder qu'aux conversations dont il est participant.
 */
class B2bNetworkController extends Controller
{
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'pdf',
        'csv', 'txt', 'log', 'xml', 'json',
        'bin', 'ori', 'mod', 'dbc', 'asc', 'trc',
    ];

    public function state(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->getTenant();
        $myId = (int) $tenant->id;

        $search = trim((string) $request->query('q', ''));
        $typeFilter = trim((string) $request->query('type', ''));
        $activeConvId = (int) $request->query('conversation_id', 0);

        // 1) Annuaire des établissements partenaires visibles
        $directory = Tenant::query()
            ->where('id', '!=', $myId)
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
            ->get(['id', 'name', 'type', 'city', 'country', 'phone'])
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

        $conversations = $conversationsRaw->map(function (TenantConversation $c) use ($myId, $unreadCounts, $lastMessages) {
            $peer = (int) $c->tenant_one_id === $myId ? $c->tenantTwo : $c->tenantOne;
            $last = $lastMessages->get($c->id);

            return [
                'id' => $c->id,
                'peer' => [
                    'id' => $peer?->id,
                    'name' => $peer?->name ?? 'Établissement',
                    'type' => $peer?->type ?? 'garage',
                    'type_label' => $this->typeLabel($peer?->type),
                    'city' => $peer?->city,
                ],
                'unread_count' => (int) ($unreadCounts->get($c->id) ?? 0),
                'last_preview' => $last ? Str::limit($last->body, 70) : '',
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

                TenantConversationMessage::where('conversation_id', $conv->id)
                    ->where('sender_tenant_id', '!=', $myId)
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);

                $messages = TenantConversationMessage::where('conversation_id', $conv->id)
                    ->orderBy('id')
                    ->limit(100)
                    ->get()
                    ->map(fn (TenantConversationMessage $m) => $this->formatMessage($m, $myId))
                    ->values();
            }
        }

        // 4) Éléments partageables en 1 clic (véhicules, OR, PV visite technique)
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
            'b2b_directory_visible' => (bool) $tenant->b2b_directory_visible,
            'total_unread' => (int) $unreadCounts->sum(),
            'directory' => $directory,
            'conversations' => $conversations,
            'active_conversation' => $activeConversation,
            'messages' => $messages,
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

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:4000'],
            'is_urgent' => ['nullable', 'boolean'],
            'notify_whatsapp' => ['nullable', 'boolean'],
            'share_type' => ['nullable', 'in:vehicle,repair_order,technical_pv'],
            'share_id' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        // Validation stricte des pièces jointes (blocage vidéos, archives compressées, scripts)
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

        // Construction serveur du contexte partagé en 1 clic (uniquement des ressources du tenant courant)
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
            'body' => trim($validated['body']),
            'shared_context' => $sharedContext,
            'attachments' => $storedAttachments,
            'is_urgent' => $isUrgent,
        ]);

        $conversation->update(['last_message_at' => now()]);

        // Alerte WhatsApp optionnelle vers l'établissement destinataire
        if ($request->boolean('notify_whatsapp') || $isUrgent) {
            $peerId = $conversation->otherTenantId($myId);
            $peer = Tenant::find($peerId);
            if ($peer && ! empty($peer->phone)) {
                try {
                    $prefix = $isUrgent ? '🚨 [URGENT Réseau Pro MboaCar]' : '💬 [Réseau Pro MboaCar]';
                    $waText = "{$prefix}\n*{$tenant->name}* vous a envoyé un message :\n\"".Str::limit($message->body, 220)."\"\n\nRépondez directement depuis votre espace MboaCar → Messagerie → Réseau Pro.";
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

    private function formatMessage(TenantConversationMessage $m, int $myTenantId): array
    {
        $attachments = collect($m->attachments ?? [])->map(fn ($att, $idx) => [
            'index' => $idx,
            'name' => $att['original_name'] ?? 'fichier',
            'ext' => $att['ext'] ?? '',
            'size_kb' => round(((int) ($att['size'] ?? 0)) / 1024, 1),
            'download_url' => route('tenant.b2b_network.attachment', ['message' => $m->id, 'index' => $idx]),
        ])->values();

        return [
            'id' => $m->id,
            'is_mine' => (int) $m->sender_tenant_id === $myTenantId,
            'sender_name' => $m->sender_name,
            'body' => $m->body,
            'shared_context' => $m->shared_context,
            'attachments' => $attachments,
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
                'details' => "Résultat : {$pv->result}".($pv->public_token ? ' • Lien PV : '.url("/pv-visite/{$pv->public_token}") : ''),
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
