<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Quote;
use App\Models\RepairOrder;
use App\Models\RepairTask;
use App\Services\GreenApiService;
use App\Services\NumberSequenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PublicQuoteController extends Controller
{
    public function show(string $token): Response
    {
        $quote = Quote::withoutTenantScope()
            ->with([
                'tenant:id,name,city,phone,email',
                'agency:id,name,address,phone',
                'customer:id,name,first_name,phone,whatsapp_phone',
                'vehicle:id,license_plate,brand,model,year,mileage',
                'repairOrder:id,order_number,tracking_token,status',
                'items',
            ])
            ->where('public_token', $token)
            ->firstOrFail();

        return Inertia::render('Public/QuoteApproval', [
            'quote' => [
                'id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'public_token' => $quote->public_token,
                'version' => (int) $quote->version,
                'status' => $quote->status,
                'valid_until' => $quote->valid_until?->format('d/m/Y'),
                'vat_rate' => (float) $quote->vat_rate,
                'discount_amount' => (int) $quote->discount_amount,
                'subtotal_ht' => (int) $quote->subtotal_ht,
                'vat_amount' => (int) $quote->vat_amount,
                'total_ttc' => (int) $quote->total_ttc,
                'deposit_required' => (int) $quote->deposit_required,
                'notes' => $quote->notes,
                'signed_at' => $quote->signed_at?->format('d/m/Y à H:i'),
                'signed_by_name' => $quote->signed_by_name,
                'signature_url' => $quote->signatureUrl(),
                'rejection_reason' => $quote->rejection_reason,
                'rejected_at' => $quote->rejected_at?->format('d/m/Y à H:i'),
                'created_at' => $quote->created_at?->format('d/m/Y'),
                'can_respond' => in_array($quote->status, [Quote::STATUS_DRAFT, Quote::STATUS_SENT], true),
                'items' => $quote->items->map(fn ($item) => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'part_condition' => $item->part_condition,
                    'urgency' => $item->urgency,
                    'description' => $item->description,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (int) $item->unit_price,
                    'total_price' => (int) $item->total_price,
                    'is_accepted' => (bool) $item->is_accepted,
                ])->values(),
            ],
            'garage' => $quote->tenant ? [
                'name' => $quote->tenant->name,
                'city' => $quote->tenant->city,
                'phone' => $quote->agency?->phone ?: $quote->tenant->phone,
                'address' => $quote->agency?->address,
            ] : null,
            'customer' => $quote->customer ? [
                'full_name' => trim(($quote->customer->first_name ? $quote->customer->first_name.' ' : '').$quote->customer->name),
            ] : null,
            'vehicle' => $quote->vehicle ? [
                'license_plate' => $quote->vehicle->license_plate,
                'brand' => $quote->vehicle->brand,
                'model' => $quote->vehicle->model,
                'year' => $quote->vehicle->year,
                'mileage' => $quote->vehicle->mileage,
            ] : null,
            'trackingUrl' => $quote->repairOrder?->tracking_token
                ? route('public.repair_order.tracking', ['token' => $quote->repairOrder->tracking_token])
                : null,
        ]);
    }

    public function respond(
        Request $request,
        string $token,
        NumberSequenceService $sequences,
        GreenApiService $greenApi
    ): RedirectResponse {
        $quote = Quote::withoutTenantScope()
            ->with(['tenant:id,name,phone,email', 'customer:id,name,first_name', 'vehicle:id,license_plate', 'items'])
            ->where('public_token', $token)
            ->firstOrFail();

        if (! in_array($quote->status, [Quote::STATUS_DRAFT, Quote::STATUS_SENT], true)) {
            return back()->with('error', 'Une décision a déjà été enregistrée sur cette version du devis.');
        }

        $validated = $request->validate([
            'decision' => ['required', 'in:accept,reject'],
            'signed_by_name' => ['nullable', 'string', 'max:150'],
            'signature_data' => ['nullable', 'string', 'max:500000'],
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
            'accepted_item_ids' => ['nullable', 'array'],
            'accepted_item_ids.*' => ['integer'],
        ]);

        $customerName = trim(($quote->customer?->first_name ? $quote->customer->first_name.' ' : '').($quote->customer?->name ?? 'Client'));
        $plate = $quote->vehicle?->license_plate ? " ({$quote->vehicle->license_plate})" : '';
        $totalFormatted = number_format((int) $quote->total_ttc, 0, ',', ' ');

        if ($validated['decision'] === 'reject') {
            $reason = trim((string) ($validated['rejection_reason'] ?? ''));
            if ($reason === '') {
                return back()->withErrors([
                    'rejection_reason' => 'Veuillez indiquer brièvement le motif de votre refus ou l\'ajustement souhaité.',
                ]);
            }

            $quote->update([
                'status' => Quote::STATUS_REFUSED,
                'rejection_reason' => $reason,
                'rejected_at' => now(),
            ]);

            AuditLog::record('quote.refused_remote', subject: $quote, tenantId: $quote->tenant_id, request: $request);

            // Notifier immédiatement le garage sur WhatsApp afin qu'il puisse réajuster et renvoyer
            $garagePhone = $quote->tenant?->phone;
            if ($greenApi->isConfigured() && ! empty($garagePhone)) {
                try {
                    $greenApi->sendMessage(
                        $garagePhone,
                        "⚠️ MboaCar — Devis refusé à distance !\n"
                        ."Devis : {$quote->quote_number} (v{$quote->version}) — {$totalFormatted} FCFA\n"
                        ."Client : {$customerName}{$plate}\n"
                        ."Motif / demande du client : « {$reason} »\n"
                        ."Connectez-vous sur MboaCar → Devis pour réajuster et renvoyer le devis au client."
                    );
                } catch (\Throwable $e) {
                    Log::warning('PublicQuote reject WhatsApp notify failed', ['error' => $e->getMessage()]);
                }
            }

            return back()->with('success', 'Votre retour a bien été transmis au garage. Il pourra réajuster le devis et vous le renvoyer sur WhatsApp.');
        }

        // Validation & signature électronique manuscrite
        $signaturePath = $quote->client_signature_path;
        $sigData = (string) ($validated['signature_data'] ?? '');
        if (str_starts_with($sigData, 'data:image/png;base64,')) {
            $raw = base64_decode(substr($sigData, strlen('data:image/png;base64,')), true);
            if ($raw !== false && strlen($raw) > 100) {
                $path = "quotes/signatures/{$quote->tenant_id}/quote-{$quote->id}-v{$quote->version}.png";
                Storage::disk('public')->put($path, $raw);
                $signaturePath = $path;
            }
        }

        $signerName = trim((string) ($validated['signed_by_name'] ?? '')) ?: $customerName;
        $acceptedItemIds = $validated['accepted_item_ids'] ?? null;

        DB::transaction(function () use ($quote, $signaturePath, $signerName, $acceptedItemIds, $request, $sequences): void {
            if (is_array($acceptedItemIds)) {
                $urgentIds = $quote->items()
                    ->where('urgency', 'urgent')
                    ->pluck('id')
                    ->all();
                $finalAcceptedIds = array_values(array_unique(array_merge(
                    array_map('intval', $acceptedItemIds),
                    array_map('intval', $urgentIds)
                )));

                $quote->items()->update(['is_accepted' => false]);
                if ($finalAcceptedIds !== []) {
                    $quote->items()->whereIn('id', $finalAcceptedIds)->update(['is_accepted' => true]);
                }
            }

            $totalItems = $quote->items()->count();
            $acceptedCount = $quote->items()->where('is_accepted', true)->count();
            $isPartial = $totalItems > 0 && $acceptedCount < $totalItems;

            $quote->recalculateTotals();
            $quote->update([
                'status' => $isPartial ? Quote::STATUS_PARTIALLY_ACCEPTED : Quote::STATUS_ACCEPTED,
                'client_signature_path' => $signaturePath,
                'signed_at' => now(),
                'signed_ip' => $request->ip(),
                'signed_by_name' => $signerName,
                'rejection_reason' => null,
                'rejected_at' => null,
            ]);

            // Conversion automatique en Ordre de Réparation si non encore lié
            $repairOrder = $quote->repairOrder;
            if (! $repairOrder) {
                $repairOrder = RepairOrder::withoutTenantScope()->create([
                    'tenant_id' => $quote->tenant_id,
                    'agency_id' => $quote->agency_id,
                    'customer_id' => $quote->customer_id,
                    'vehicle_id' => $quote->vehicle_id,
                    'order_number' => $sequences->nextRepairOrderNumber($quote->tenant_id),
                    'status' => RepairOrder::STATUS_IN_PROGRESS,
                    'subtotal_ht' => $quote->subtotal_ht,
                    'discount_amount' => $quote->discount_amount,
                    'vat_amount' => $quote->vat_amount,
                    'total_ttc' => $quote->total_ttc,
                ]);
                $quote->update(['repair_order_id' => $repairOrder->id]);

                foreach ($quote->items()->where('is_accepted', true)->get() as $acceptedItem) {
                    RepairTask::withoutTenantScope()->create([
                        'tenant_id' => $quote->tenant_id,
                        'repair_order_id' => $repairOrder->id,
                        'title' => $acceptedItem->description,
                        'priority' => $acceptedItem->urgency === 'urgent' ? 'urgente' : 'normale',
                        'estimated_minutes' => 60,
                        'status' => 'a_faire',
                    ]);
                }
            } else {
                $repairOrder->update([
                    'status' => RepairOrder::STATUS_IN_PROGRESS,
                    'subtotal_ht' => $quote->subtotal_ht,
                    'discount_amount' => $quote->discount_amount,
                    'vat_amount' => $quote->vat_amount,
                    'total_ttc' => $quote->total_ttc,
                ]);
            }
        });

        $quote->refresh();
        $totalFormatted = number_format((int) $quote->total_ttc, 0, ',', ' ');
        $validationLabel = $quote->status === Quote::STATUS_PARTIALLY_ACCEPTED ? 'VALIDÉ PARTIELLEMENT & SIGNÉ' : 'VALIDÉ & SIGNÉ';

        AuditLog::record('quote.accepted_remote', subject: $quote, tenantId: $quote->tenant_id, request: $request);

        // Notifier le garage sur WhatsApp que le devis est validé & signé
        $garagePhone = $quote->tenant?->phone;
        if ($greenApi->isConfigured() && ! empty($garagePhone)) {
            try {
                $greenApi->sendMessage(
                    $garagePhone,
                    "✅ MboaCar — Devis {$validationLabel} à distance !\n"
                    ."Devis : {$quote->quote_number} (v{$quote->version}) — {$totalFormatted} FCFA\n"
                    ."Client : {$signerName}{$plate}\n"
                    ."L'ordre de réparation a été activé automatiquement dans votre espace."
                );
            } catch (\Throwable $e) {
                Log::warning('PublicQuote accept WhatsApp notify failed', ['error' => $e->getMessage()]);
            }
        }

        return back()->with('success', 'Merci ! Votre devis a été validé et signé électroniquement. Le garage vient d\'être notifié.');
    }
}
