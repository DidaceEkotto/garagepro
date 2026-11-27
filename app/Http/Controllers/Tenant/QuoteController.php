<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreQuoteRequest;
use App\Mail\ClientReminderMail;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Part;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\RepairOrder;
use App\Models\RepairTask;
use App\Models\Vehicle;
use App\Services\GreenApiService;
use App\Services\NumberSequenceService;
use App\Services\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QuoteController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Quote::class);

        $quotes = Quote::with(['customer', 'vehicle', 'items'])
            ->latest()
            ->paginate(20);

        $quotes->getCollection()->each(fn (Quote $q) => $q->ensurePublicToken());

        return Inertia::render('Tenant/Quotes/Index', [
            'quotes' => $quotes,
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'vehicles' => Vehicle::orderBy('license_plate')->get(['id', 'customer_id', 'license_plate', 'brand', 'model']),
            'repairOrders' => RepairOrder::orderByDesc('id')->get(['id', 'order_number', 'customer_id', 'vehicle_id']),
            'parts' => Part::orderBy('name')->get(['id', 'name', 'selling_price']),
        ]);
    }

    private function sendQuoteWhatsappLink(Quote $quote, ?\App\Models\Tenant $tenant, GreenApiService $greenApi, bool $isRevision = false): bool
    {
        $quote->loadMissing(['customer', 'vehicle']);
        $customer = $quote->customer;
        $phone = trim((string) ($customer?->whatsapp_phone ?: ($customer?->phone ?? '')));

        if ($phone === '' || ! $greenApi->isConfigured()) {
            return false;
        }

        $garageName = $tenant?->name ?? config('app.name', 'MboaCar');
        $clientName = trim(($customer->first_name ? $customer->first_name.' ' : '').$customer->name);
        $plate = $quote->vehicle?->license_plate ? " ({$quote->vehicle->license_plate})" : '';
        $totalFormatted = number_format((int) $quote->total_ttc, 0, ',', ' ');
        $publicUrl = $quote->publicUrl();

        $intro = $isRevision
            ? "🔄 Bonjour {$clientName}, votre devis {$quote->quote_number} a été réajusté (Version v{$quote->version}) par {$garageName} !"
            : "📋 Bonjour {$clientName}, votre devis {$quote->quote_number} est prêt chez {$garageName} !";

        $message = "{$intro}\n"
            ."🚗 Véhicule : {$quote->vehicle?->brand} {$quote->vehicle?->model}{$plate}\n"
            ."💰 Montant TTC : {$totalFormatted} FCFA\n\n"
            ."👉 Cliquez sur ce lien sécurisé pour consulter le détail, signer électroniquement pour valider ou demander un réajustement :\n"
            ."{$publicUrl}";

        try {
            $res = $greenApi->sendMessage($phone, $message);
            if ($res['ok'] ?? false) {
                $quote->update([
                    'status' => Quote::STATUS_SENT,
                    'sent_at' => now(),
                ]);

                return true;
            }
        } catch (\Throwable $e) {
            Log::warning('Quote WhatsApp send failed (non-blocking)', [
                'quote_id' => $quote->id,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    public function store(
        StoreQuoteRequest $request,
        TenantContext $tenantContext,
        NumberSequenceService $sequences,
        GreenApiService $greenApi
    ): RedirectResponse {
        $data = $request->validated();
        $tenant = $tenantContext->getTenant();

        $quote = DB::transaction(function () use ($data, $tenant, $sequences) {
            $quote = Quote::create([
                'tenant_id' => $tenant->id,
                'agency_id' => $data['agency_id'],
                'customer_id' => $data['customer_id'],
                'vehicle_id' => $data['vehicle_id'],
                'repair_order_id' => $data['repair_order_id'] ?? null,
                'quote_number' => $sequences->nextQuoteNumber($tenant->id),
                'version' => 1,
                'status' => Quote::STATUS_SENT,
                'sent_at' => now(),
                'auto_reminded_at' => null,
                'valid_until' => $data['valid_until'] ?? now()->addDays(15),
                'vat_rate' => $tenant->vat_rate,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'deposit_required' => $data['deposit_required'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                QuoteItem::create([
                    'tenant_id' => $tenant->id,
                    'quote_id' => $quote->id,
                    'type' => $item['type'],
                    'part_condition' => $item['part_condition'] ?? null,
                    'urgency' => $item['urgency'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total_price' => (int) $item['quantity'] * (int) $item['unit_price'],
                    'is_accepted' => true,
                ]);
            }

            $quote->recalculateTotals();

            return $quote;
        });

        $whatsappSent = $this->sendQuoteWhatsappLink($quote, $tenant, $greenApi, false);

        AuditLog::record('quote.created', subject: $quote, actor: $request->user('web'), tenantId: $tenant->id, request: $request);

        return back()->with(
            'success',
            $whatsappSent
                ? "Devis {$quote->quote_number} créé et lien de validation envoyé automatiquement sur WhatsApp au client !"
                : "Devis {$quote->quote_number} créé. Vous pouvez partager son lien de validation au client."
        );
    }

    /**
     * Réajuste un devis (notamment après un refus client), incrémente la version
     * et renvoie automatiquement le nouveau lien WhatsApp au client.
     */
    public function revise(
        Request $request,
        Quote $quote,
        TenantContext $tenantContext,
        GreenApiService $greenApi
    ): RedirectResponse {
        $this->authorize('update', $quote);

        if (in_array($quote->status, [Quote::STATUS_ACCEPTED, Quote::STATUS_PARTIALLY_ACCEPTED], true)) {
            return back()->with('error', 'Ce devis a déjà été validé et signé par le client.');
        }

        $validated = $request->validate([
            'discount_amount' => ['nullable', 'integer', 'min:0'],
            'deposit_required' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', Rule::in(['main_oeuvre', 'piece', 'consommable', 'sous_traitance'])],
            'items.*.part_condition' => ['nullable', 'string', 'max:40'],
            'items.*.urgency' => ['required', Rule::in(['urgent', 'recommande', 'optionnel'])],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
        ]);

        $tenant = $tenantContext->getTenant();

        DB::transaction(function () use ($quote, $validated, $tenant): void {
            $quote->items()->delete();

            foreach ($validated['items'] as $item) {
                QuoteItem::create([
                    'tenant_id' => $tenant->id,
                    'quote_id' => $quote->id,
                    'type' => $item['type'],
                    'part_condition' => $item['part_condition'] ?? null,
                    'urgency' => $item['urgency'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total_price' => (int) $item['quantity'] * (int) $item['unit_price'],
                    'is_accepted' => true,
                ]);
            }

            $quote->update([
                'version' => ((int) $quote->version) + 1,
                'status' => Quote::STATUS_SENT,
                'sent_at' => now(),
                'auto_reminded_at' => null,
                'discount_amount' => (int) ($validated['discount_amount'] ?? 0),
                'deposit_required' => (int) ($validated['deposit_required'] ?? 0),
                'notes' => $validated['notes'] ?? null,
                'rejection_reason' => null,
                'rejected_at' => null,
                'valid_until' => now()->addDays(15),
            ]);

            $quote->recalculateTotals();
        });

        $quote->refresh();
        $whatsappSent = $this->sendQuoteWhatsappLink($quote, $tenant, $greenApi, true);

        AuditLog::record('quote.revised', subject: $quote, actor: $request->user('web'), tenantId: $tenant->id, request: $request);

        return back()->with(
            'success',
            $whatsappSent
                ? "Devis {$quote->quote_number} réajusté en v{$quote->version} et renvoyé par WhatsApp au client !"
                : "Devis {$quote->quote_number} réajusté en v{$quote->version} et prêt à être validé."
        );
    }

    /**
     * Validation totale ou partielle par le client avec conversion automatique en OR.
     */
    public function accept(
        Request $request,
        Quote $quote,
        TenantContext $tenantContext,
        NumberSequenceService $sequences
    ): RedirectResponse {
        $this->authorize('update', $quote);

        $validated = $request->validate([
            'accepted_item_ids' => ['nullable', 'array'],
            'accepted_item_ids.*' => ['integer'],
        ]);

        $tenantId = $tenantContext->id();

        DB::transaction(function () use ($quote, $validated, $tenantId, $sequences): void {
            $acceptedIds = $validated['accepted_item_ids'] ?? null;

            if ($acceptedIds !== null) {
                $quote->items()->update(['is_accepted' => false]);
                $quote->items()->whereIn('id', $acceptedIds)->update(['is_accepted' => true]);
            }

            $totalItems = $quote->items()->count();
            $acceptedCount = $quote->items()->where('is_accepted', true)->count();

            $quote->recalculateTotals();
            $quote->update([
                'status' => $acceptedCount < $totalItems ? Quote::STATUS_PARTIALLY_ACCEPTED : Quote::STATUS_ACCEPTED,
                'signed_at' => now(),
            ]);

            // Conversion automatique du devis en Ordre de Réparation s'il n'existe pas encore
            $repairOrder = $quote->repairOrder;
            if (! $repairOrder) {
                $repairOrder = RepairOrder::create([
                    'tenant_id' => $tenantId,
                    'agency_id' => $quote->agency_id,
                    'customer_id' => $quote->customer_id,
                    'vehicle_id' => $quote->vehicle_id,
                    'order_number' => $sequences->nextRepairOrderNumber($tenantId),
                    'status' => RepairOrder::STATUS_IN_PROGRESS,
                    'subtotal_ht' => $quote->subtotal_ht,
                    'discount_amount' => $quote->discount_amount,
                    'vat_amount' => $quote->vat_amount,
                    'total_ttc' => $quote->total_ttc,
                ]);
                $quote->update(['repair_order_id' => $repairOrder->id]);
            } else {
                $repairOrder->update([
                    'status' => RepairOrder::STATUS_IN_PROGRESS,
                    'subtotal_ht' => $quote->subtotal_ht,
                    'discount_amount' => $quote->discount_amount,
                    'vat_amount' => $quote->vat_amount,
                    'total_ttc' => $quote->total_ttc,
                ]);
            }

            foreach ($quote->items()->where('is_accepted', true)->get() as $acceptedItem) {
                RepairTask::create([
                    'tenant_id' => $tenantId,
                    'repair_order_id' => $repairOrder->id,
                    'title' => $acceptedItem->description,
                    'priority' => $acceptedItem->urgency === 'urgent' ? 'urgente' : 'normale',
                    'estimated_minutes' => 60,
                    'status' => 'a_faire',
                ]);
            }
        });

        return back()->with('success', 'Devis validé et converti en ordre de réparation.');
    }

    public function newVersion(Quote $quote, TenantContext $tenantContext): RedirectResponse
    {
        $this->authorize('update', $quote);

        $newQuote = DB::transaction(function () use ($quote, $tenantContext) {
            $nextVersion = ((int) Quote::where('quote_number', $quote->quote_number)->max('version')) + 1;

            $copy = Quote::create([
                'tenant_id' => $tenantContext->id(),
                'agency_id' => $quote->agency_id,
                'customer_id' => $quote->customer_id,
                'vehicle_id' => $quote->vehicle_id,
                'repair_order_id' => $quote->repair_order_id,
                'parent_quote_id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'version' => $nextVersion,
                'status' => Quote::STATUS_DRAFT,
                'valid_until' => now()->addDays(15),
                'vat_rate' => $quote->vat_rate,
                'discount_amount' => $quote->discount_amount,
                'deposit_required' => $quote->deposit_required,
                'notes' => $quote->notes,
            ]);

            foreach ($quote->items as $item) {
                QuoteItem::create([
                    'tenant_id' => $tenantContext->id(),
                    'quote_id' => $copy->id,
                    'type' => $item->type,
                    'part_condition' => $item->part_condition,
                    'urgency' => $item->urgency,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                    'is_accepted' => $item->is_accepted,
                ]);
            }

            $copy->recalculateTotals();

            return $copy;
        });

        return back()->with('success', "Version {$newQuote->version} du devis {$newQuote->quote_number} créée.");
    }

    public function remind(
        Request $request,
        Quote $quote,
        TenantContext $tenantContext,
        GreenApiService $greenApi
    ): RedirectResponse {
        $this->authorize('view', $quote);

        $data = $request->validate([
            'channel' => ['nullable', 'in:auto,email,whatsapp'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $quote->load(['customer', 'vehicle']);
        $customer = $quote->customer;
        $tenant = $tenantContext->getTenant();
        $garageName = $tenant?->name ?? config('app.name', 'MboaCar');

        $email = trim((string) ($customer?->email ?? ''));
        $phone = trim((string) ($customer?->whatsapp_phone ?: ($customer?->phone ?? '')));
        $hasEmail = $email !== '';
        $hasWhatsapp = $phone !== '';

        // Si le client n'a ni e-mail ni WhatsApp, on n'envoie rien
        if (! $hasEmail && ! $hasWhatsapp) {
            return back()->with('error', "Le client « {$customer?->name} » n'a ni adresse e-mail ni numéro WhatsApp : aucun rappel envoyé.");
        }

        $requested = $data['channel'] ?? 'auto';
        $chosenChannel = match ($requested) {
            'email' => $hasEmail ? 'email' : 'whatsapp',
            'whatsapp' => $hasWhatsapp ? 'whatsapp' : 'email',
            default => $hasEmail ? 'email' : 'whatsapp',
        };

        $totalFormatted = number_format((int) $quote->total_ttc, 0, ',', ' ');
        $plate = $quote->vehicle?->license_plate ? " ({$quote->vehicle->license_plate})" : '';
        $publicUrl = $quote->publicUrl();
        $defaultMessage = "Bonjour {$customer->name}, nous vous rappelons que votre devis {$quote->quote_number} (v{$quote->version}) d'un montant de {$totalFormatted} FCFA pour votre véhicule{$plate} est disponible chez {$garageName}. Cliquez ici pour le consulter et le valider : {$publicUrl}";
        $messageBody = trim((string) ($data['message'] ?? '')) !== '' ? trim((string) $data['message']) : $defaultMessage;

        if ($chosenChannel === 'email') {
            try {
                Mail::to($email)->send(new ClientReminderMail(
                    garageName: $garageName,
                    clientName: $customer->name,
                    subjectLine: "Rappel devis {$quote->quote_number} — {$garageName}",
                    bodyText: $messageBody,
                ));
            } catch (\Throwable $e) {
                return back()->with('error', "Échec de l'envoi du rappel par e-mail : {$e->getMessage()}");
            }

            AuditLog::record('quote.reminded_email', subject: $quote, actor: $request->user('web'), tenantId: $tenantContext->id(), request: $request);

            return back()->with('success', "Rappel du devis {$quote->quote_number} envoyé par e-mail à {$email}.");
        }

        if (! $greenApi->isConfigured()) {
            if ($hasEmail) {
                try {
                    Mail::to($email)->send(new ClientReminderMail(
                        garageName: $garageName,
                        clientName: $customer->name,
                        subjectLine: "Rappel devis {$quote->quote_number} — {$garageName}",
                        bodyText: $messageBody,
                    ));

                    AuditLog::record('quote.reminded_email', subject: $quote, actor: $request->user('web'), tenantId: $tenantContext->id(), request: $request);

                    return back()->with('success', "WhatsApp non configuré : rappel du devis {$quote->quote_number} envoyé par e-mail à {$email}.");
                } catch (\Throwable $e) {
                    return back()->with('error', "Échec de l'envoi du rappel : {$e->getMessage()}");
                }
            }

            return back()->with('error', 'Green-API WhatsApp n\'est pas configuré dans l\'administration.');
        }

        $res = $greenApi->sendMessage($phone, $messageBody);
        if (! ($res['ok'] ?? false)) {
            return back()->with('error', $res['error'] ?? "Impossible d'envoyer le rappel WhatsApp.");
        }

        AuditLog::record('quote.reminded_whatsapp', subject: $quote, actor: $request->user('web'), tenantId: $tenantContext->id(), request: $request);

        return back()->with('success', "Rappel du devis {$quote->quote_number} envoyé par WhatsApp à {$phone}.");
    }

    public function pdf(Quote $quote, TenantContext $tenantContext): HttpResponse
    {
        $this->authorize('view', $quote);

        $quote->load(['customer', 'vehicle', 'agency', 'items']);

        $signatureDataUri = null;
        if ($quote->client_signature_path && Storage::disk('public')->exists($quote->client_signature_path)) {
            $raw = Storage::disk('public')->get($quote->client_signature_path);
            if ($raw !== '') {
                $signatureDataUri = 'data:image/png;base64,'.base64_encode($raw);
            }
        }

        $pdf = Pdf::loadView('pdf.quote', [
            'quote' => $quote,
            'tenant' => $tenantContext->getTenant(),
            'signatureDataUri' => $signatureDataUri,
        ]);

        return $pdf->download("{$quote->quote_number}-v{$quote->version}.pdf");
    }
}
