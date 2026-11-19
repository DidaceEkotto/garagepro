<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreAppointmentRequest;
use App\Jobs\SendAppointmentReminderJob;
use App\Models\Agency;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Appointment::class);

        $appointments = Appointment::with(['customer', 'vehicle', 'mechanic', 'agency'])
            ->orderByRaw('scheduled_at IS NULL ASC')
            ->orderBy('scheduled_at')
            ->paginate(30);

        return Inertia::render('Tenant/Appointments/Index', [
            'appointments' => $appointments,
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'phone']),
            'vehicles' => Vehicle::orderBy('license_plate')->get(['id', 'customer_id', 'license_plate', 'brand', 'model']),
            'mechanics' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreAppointmentRequest $request, TenantContext $tenantContext): RedirectResponse
    {
        $data = $request->validated();

        $appointment = DB::transaction(function () use ($data, $tenantContext) {
            $customerId = $data['customer_id'] ?? null;

            // Création automatique de la fiche client si nouveau client
            if (! $customerId) {
                $customer = Customer::create([
                    'tenant_id' => $tenantContext->id(),
                    'agency_id' => $data['agency_id'],
                    'name' => $data['new_customer_name'],
                    'phone' => $data['new_customer_phone'],
                    'type' => 'particulier',
                ]);
                $customerId = $customer->id;
            }

            $status = $data['channel'] === 'sans_rdv'
                ? Appointment::STATUS_WALK_IN_QUEUE
                : Appointment::STATUS_CONFIRMED;

            return Appointment::create([
                'tenant_id' => $tenantContext->id(),
                'agency_id' => $data['agency_id'],
                'customer_id' => $customerId,
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'assigned_mechanic_id' => $data['assigned_mechanic_id'] ?? null,
                'bay_name' => $data['bay_name'] ?? null,
                'channel' => $data['channel'],
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'duration_minutes' => $data['duration_minutes'] ?? 60,
                'status' => $status,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
            ]);
        });

        if ($appointment->status === Appointment::STATUS_CONFIRMED) {
            SendAppointmentReminderJob::dispatch($appointment->id);
        }

        return back()->with('success', 'Rendez-vous enregistré avec succès.');
    }

    public function updateStatus(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('update', $appointment);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_RECEIVED,
                Appointment::STATUS_RESCHEDULED,
                Appointment::STATUS_CANCELLED,
            ])],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $appointment->update([
            'status' => $validated['status'],
            'scheduled_at' => $validated['scheduled_at'] ?? $appointment->scheduled_at,
        ]);

        return back()->with('success', 'Statut du rendez-vous mis à jour.');
    }
}
