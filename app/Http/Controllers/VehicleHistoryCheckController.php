<?php

namespace App\Http\Controllers;

use App\Models\InsuranceClaim;
use App\Models\RepairOrder;
use App\Models\TechnicalInspection;
use App\Models\Vehicle;
use App\Models\VehicleReception;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * MboaCar Check — Rapport Historique Anti-Fraude Véhicule d'Occasion (CarVertical / Histovec africain).
 * Agrège anonymement les passages vérifiés sur tout le réseau MboaCar (Garages, Centres Techniques, Assurances)
 * sans jamais exposer les coordonnées personnelles des anciens propriétaires.
 */
class VehicleHistoryCheckController extends Controller
{
    public function index(Request $request): Response
    {
        $query = strtoupper(trim((string) $request->query('q', '')));
        $report = $query !== '' ? $this->buildReport($query) : null;

        return Inertia::render('Public/MboaCarCheck', [
            'query' => $query,
            'report' => $report,
        ]);
    }

    public function pdf(Request $request): HttpResponse
    {
        $query = strtoupper(trim((string) $request->query('q', '')));
        abort_if($query === '', 404);

        $report = $this->buildReport($query);
        abort_if(! $report['found'], 404);

        $pdf = Pdf::loadView('pdf.vehicle-check', ['report' => $report]);

        return $pdf->download('mboacar-check-'.preg_replace('/[^A-Z0-9\-]/', '', $query).'.pdf');
    }

    /**
     * Scan intelligent d'étiquette QR Pare-Brise / Porte-Clés :
     * - Personnel connecté du garage propriétaire -> Fiche véhicule Check-in rapide (3 secondes).
     * - Client / Public -> Carnet d'entretien numérique (/carnet/{token}).
     */
    public function smartQrScan(Request $request, string $token): RedirectResponse
    {
        $vehicle = Vehicle::withoutGlobalScopes()->where('public_token', $token)->firstOrFail();

        $user = $request->user('web');
        if ($user && (int) $user->tenant_id === (int) $vehicle->tenant_id) {
            return redirect()->route('tenant.vehicles.show', ['vehicle' => $vehicle->id, 'checkin' => 1]);
        }

        return redirect()->route('public.vehicle.passport', ['token' => $token]);
    }

    public function buildReport(string $query): array
    {
        $normalized = preg_replace('/[\s\-]+/', '', $query);

        $vehicles = Vehicle::withoutGlobalScopes()
            ->where(function ($q) use ($query, $normalized) {
                $q->whereRaw('UPPER(license_plate) = ?', [$query])
                    ->orWhereRaw("REPLACE(REPLACE(UPPER(license_plate), ' ', ''), '-', '') = ?", [$normalized])
                    ->orWhereRaw('UPPER(vin) = ?', [$query]);
            })
            ->get(['id', 'tenant_id', 'license_plate', 'brand', 'model', 'year', 'vin', 'fuel_type', 'transmission', 'mileage', 'created_at']);

        if ($vehicles->isEmpty()) {
            return [
                'found' => false,
                'query' => $query,
            ];
        }

        $vehicleIds = $vehicles->pluck('id')->all();
        $primary = $vehicles->first();

        $receptions = VehicleReception::withoutGlobalScopes()
            ->whereIn('vehicle_id', $vehicleIds)
            ->orderBy('received_at')
            ->get(['id', 'vehicle_id', 'visit_reason', 'mileage_in', 'received_at']);

        $repairOrders = RepairOrder::withoutGlobalScopes()
            ->whereIn('vehicle_id', $vehicleIds)
            ->orderBy('created_at')
            ->get(['id', 'vehicle_id', 'order_number', 'status', 'created_at']);

        $techInspections = TechnicalInspection::withoutGlobalScopes()
            ->whereIn('vehicle_id', $vehicleIds)
            ->orderBy('inspected_at')
            ->get(['id', 'vehicle_id', 'pv_number', 'result', 'mileage', 'inspected_at']);

        $claims = InsuranceClaim::withoutGlobalScopes()
            ->whereIn('vehicle_id', $vehicleIds)
            ->orderBy('incident_date')
            ->get(['id', 'vehicle_id', 'claim_number', 'status', 'incident_date']);

        // 1) Courbe kilométrique + détection fraude compteur (recul kilométrique)
        $mileagePoints = collect();
        foreach ($receptions as $r) {
            if ($r->mileage_in > 0 && $r->received_at) {
                $mileagePoints->push([
                    'date' => $r->received_at->format('Y-m-d'),
                    'mileage' => (int) $r->mileage_in,
                    'source' => 'Réception atelier',
                ]);
            }
        }
        foreach ($techInspections as $ti) {
            if ($ti->mileage > 0 && $ti->inspected_at) {
                $mileagePoints->push([
                    'date' => $ti->inspected_at->format('Y-m-d'),
                    'mileage' => (int) $ti->mileage,
                    'source' => 'Centre de contrôle technique',
                ]);
            }
        }

        $mileagePoints = $mileagePoints->sortBy('date')->values();
        $fraudAlerts = [];
        $prev = null;
        foreach ($mileagePoints as $pt) {
            if ($prev !== null && $pt['mileage'] < $prev['mileage']) {
                $diff = $prev['mileage'] - $pt['mileage'];
                $fraudAlerts[] = "Recul kilométrique suspect détecté : passage de ".number_format($prev['mileage'], 0, ',', ' ')." km ({$prev['date']}) à ".number_format($pt['mileage'], 0, ',', ' ')." km ({$pt['date']}) — écart de -".number_format($diff, 0, ',', ' ').' km.';
            }
            $prev = $pt;
        }

        // 2) Chronologie anonymisée vérifiée
        $timeline = collect();
        foreach ($receptions as $r) {
            $timeline->push([
                'date' => $r->received_at?->format('Y-m-d') ?? '-',
                'type' => 'reception',
                'label' => 'Passage atelier vérifié ('.$r->visit_reason.')',
                'mileage' => (int) $r->mileage_in,
            ]);
        }
        foreach ($techInspections as $ti) {
            $timeline->push([
                'date' => $ti->inspected_at?->format('Y-m-d') ?? '-',
                'type' => 'technical_inspection',
                'label' => 'Visite technique : '.$ti->result,
                'mileage' => (int) ($ti->mileage ?? 0),
            ]);
        }
        foreach ($claims as $cl) {
            $timeline->push([
                'date' => $cl->incident_date ? \Illuminate\Support\Carbon::parse($cl->incident_date)->format('Y-m-d') : '-',
                'type' => 'insurance_claim',
                'label' => 'Sinistre assurance déclaré ('.$cl->status.')',
                'mileage' => null,
            ]);
        }
        $timeline = $timeline->sortByDesc('date')->values()->all();

        // 3) Score de confiance /100
        $score = 100;
        if (! empty($fraudAlerts)) {
            $score -= 45;
        }
        $score -= min(30, $claims->count() * 15);
        $failedTech = $techInspections->filter(fn ($t) => in_array(strtolower((string) $t->result), ['defavorable', 'contre_visite', 'refuse'], true))->count();
        $score -= min(20, $failedTech * 10);
        $score = max(10, min(100, $score));

        return [
            'found' => true,
            'query' => $query,
            'generated_at' => now()->format('d/m/Y à H:i'),
            'vehicle' => [
                'license_plate' => $primary->license_plate,
                'brand' => $primary->brand,
                'model' => $primary->model,
                'year' => $primary->year,
                'vin' => $primary->vin,
                'fuel_type' => $primary->fuel_type,
                'transmission' => $primary->transmission,
                'latest_mileage' => (int) ($mileagePoints->last()['mileage'] ?? $primary->mileage ?? 0),
            ],
            'stats' => [
                'garages_visited' => $vehicles->pluck('tenant_id')->unique()->count(),
                'receptions_count' => $receptions->count(),
                'repair_orders_count' => $repairOrders->count(),
                'tech_inspections_count' => $techInspections->count(),
                'claims_count' => $claims->count(),
            ],
            'odometer_fraud_detected' => ! empty($fraudAlerts),
            'fraud_alerts' => $fraudAlerts,
            'trust_score' => $score,
            'mileage_history' => $mileagePoints->all(),
            'timeline' => $timeline,
        ];
    }
}
