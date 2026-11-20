<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\RepairOrder;
use App\Models\Vehicle;
use App\Models\VehicleInspection;
use App\Services\GreenApiService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InspectionController extends Controller
{
    public function index(): Response
    {
        $inspections = VehicleInspection::with([
            'vehicle:id,license_plate,brand,model,customer_id',
            'customer:id,name,first_name,phone,whatsapp_phone',
            'repairOrder:id,order_number,public_token',
            'inspector:id,name',
        ])
            ->latest('id')
            ->take(40)
            ->get()
            ->map(function (VehicleInspection $insp) {
                $photoUrls = collect($insp->photos ?? [])
                    ->map(fn ($path) => Storage::disk('public')->url($path))
                    ->values()
                    ->all();

                return [
                    'id' => $insp->id,
                    'vehicle' => $insp->vehicle,
                    'customer' => $insp->customer,
                    'repair_order' => $insp->repairOrder,
                    'inspector' => $insp->inspector,
                    'checklist' => $insp->checklist,
                    'photo_urls' => $photoUrls,
                    'summary_notes' => $insp->summary_notes,
                    'whatsapp_sent_at' => $insp->whatsapp_sent_at?->format('d/m/Y H:i'),
                    'created_at' => $insp->created_at?->format('d/m/Y H:i'),
                ];
            });

        return Inertia::render('Tenant/Inspections/Index', [
            'inspections' => $inspections,
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'vehicles' => Vehicle::with('customer:id,name,phone')->orderBy('license_plate')->get(['id', 'customer_id', 'license_plate', 'brand', 'model']),
            'repairOrders' => RepairOrder::with('vehicle:id,license_plate')->latest('id')->take(50)->get(['id', 'order_number', 'vehicle_id', 'customer_id']),
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'agency_id' => ['nullable', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId)],
            'repair_order_id' => ['nullable', 'integer', Rule::exists('repair_orders', 'id')->where('tenant_id', $tenantId)],
            'checklist' => ['required', 'array', 'min:1'],
            'checklist.*.category' => ['required', 'string', 'max:80'],
            'checklist.*.item' => ['required', 'string', 'max:120'],
            'checklist.*.state' => ['required', Rule::in(['ok', 'attention', 'urgent'])],
            'checklist.*.note' => ['nullable', 'string', 'max:300'],
            'summary_notes' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ['image', 'max:5120'],
        ]);

        $vehicle = Vehicle::findOrFail($validated['vehicle_id']);
        $photoPaths = [];
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $file) {
                $photoPaths[] = Storage::disk('public')->putFile('images/inspections', $file);
            }
        }

        VehicleInspection::create([
            'tenant_id' => $tenantId,
            'agency_id' => $validated['agency_id'] ?? Agency::where('is_active', true)->value('id'),
            'repair_order_id' => $validated['repair_order_id'] ?? null,
            'vehicle_id' => $vehicle->id,
            'customer_id' => $vehicle->customer_id,
            'inspector_id' => $request->user('web')?->id,
            'checklist' => $validated['checklist'],
            'photos' => $photoPaths,
            'summary_notes' => $validated['summary_notes'] ?? null,
        ]);

        return back()->with('success', 'Inspection visuelle enregistrée avec succès.');
    }

    public function sendWhatsapp(VehicleInspection $inspection, GreenApiService $greenApi, TenantContext $tenantContext): RedirectResponse
    {
        $inspection->loadMissing(['customer', 'vehicle', 'repairOrder']);
        $phone = $inspection->customer?->whatsapp_phone ?: $inspection->customer?->phone;

        if (empty($phone)) {
            return back()->with('warning', 'Aucun numéro WhatsApp trouvé pour ce client.');
        }

        $items = collect($inspection->checklist ?? []);
        $okCount = $items->where('state', 'ok')->count();
        $warnCount = $items->where('state', 'attention')->count();
        $urgentCount = $items->where('state', 'urgent')->count();

        $urgentLabels = $items->where('state', 'urgent')->pluck('item')->implode(', ');
        $garageName = $tenantContext->getTenant()?->name ?? 'MboaCar';
        $plate = $inspection->vehicle?->license_plate ?? '';
        $clientName = $inspection->customer?->name ?? 'Client';

        $msg = "Bonjour {$clientName}, voici le rapport d'inspection {$garageName} pour votre véhicule {$plate} :\n"
            ."✅ OK : {$okCount} | ⚠️ À surveiller : {$warnCount} | 🔴 Urgent : {$urgentCount}";

        if ($urgentLabels !== '') {
            $msg .= "\nPoints urgents : {$urgentLabels}";
        }
        if ($inspection->summary_notes) {
            $msg .= "\nNote atelier : {$inspection->summary_notes}";
        }
        if ($inspection->repairOrder?->public_token) {
            $msg .= "\nSuivi en direct : ".route('public.repair_order.tracking', ['token' => $inspection->repairOrder->public_token]);
        }

        try {
            $res = $greenApi->sendMessage($phone, $msg);
            if (! ($res['ok'] ?? false)) {
                return back()->with('warning', 'Le rapport n\'a pas pu être envoyé sur WhatsApp pour le moment.');
            }
            $inspection->update(['whatsapp_sent_at' => now()]);

            return back()->with('success', 'Rapport d\'inspection envoyé au client sur WhatsApp.');
        } catch (\Throwable $e) {
            Log::warning('Inspection WhatsApp failed (non-blocking)', ['error' => $e->getMessage()]);

            return back()->with('warning', 'WhatsApp indisponible pour le moment (inspection conservée).');
        }
    }
}
