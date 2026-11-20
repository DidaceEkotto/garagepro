<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreAppointmentRequest;
use App\Models\Agency;
use App\Models\Appointment;
use App\Models\Country;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AppointmentReminderService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): Response
    {
        $this->authorize('viewAny', Appointment::class);

        $tenant = $tenantContext->getTenant();
        $defaultDialCode = Country::where('code', $tenant?->country ?: 'CM')->value('dial_code') ?? '+237';

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
            'defaultDialCode' => $defaultDialCode,
            'countries' => Country::active()->orderBy('name')->get(['code', 'name', 'dial_code']),
        ]);
    }

    public function store(StoreAppointmentRequest $request, TenantContext $tenantContext): RedirectResponse
    {
        $data = $request->validated();

        $appointment = DB::transaction(function () use ($data, $tenantContext, $request) {
            $customerId = $data['customer_id'] ?? null;

            // Création automatique de la fiche client si nouveau client
            if (! $customerId) {
                $tenant = $tenantContext->getTenant();
                $dialCode = trim((string) ($data['new_customer_dial_code'] ?? ''));
                if ($dialCode === '') {
                    $dialCode = Country::where('code', $tenant?->country ?: 'CM')->value('dial_code') ?? '+237';
                }

                $rawPhone = trim((string) $data['new_customer_phone']);
                $dialDigits = preg_replace('/\D+/', '', $dialCode);
                $phoneDigits = preg_replace('/\D+/', '', $rawPhone);
                if (str_starts_with($rawPhone, '+')) {
                    $formattedPhone = $rawPhone;
                } elseif ($dialDigits !== '' && str_starts_with($phoneDigits, $dialDigits)) {
                    $formattedPhone = '+'.$phoneDigits;
                } else {
                    $formattedPhone = $dialCode.' '.$phoneDigits;
                }

                $photoPath = $request->hasFile('new_customer_photo')
                    ? Storage::disk('public')->putFile('images/customers', $request->file('new_customer_photo'))
                    : null;

                $customer = Customer::create([
                    'tenant_id' => $tenantContext->id(),
                    'agency_id' => $data['agency_id'],
                    'name' => $data['new_customer_name'],
                    'first_name' => $data['new_customer_first_name'] ?? null,
                    'gender' => $data['new_customer_gender'] ?? null,
                    'id_number' => $data['new_customer_id_number'] ?? null,
                    'phone_dial_code' => $dialCode,
                    'phone' => $formattedPhone,
                    'whatsapp_phone' => $formattedPhone,
                    'photo_path' => $photoPath,
                    'description' => $data['new_customer_description'] ?? null,
                    'profession' => $data['new_customer_profession'] ?? null,
                    'date_of_birth' => $data['new_customer_date_of_birth'] ?? null,
                    'emergency_contact' => $data['new_customer_emergency_contact'] ?? null,
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

        if ($appointment->status === Appointment::STATUS_CONFIRMED && $appointment->scheduled_at) {
            app()->terminating(function () {
                app(AppointmentReminderService::class)->triggerAutomaticCheck();
            });
        }

        return back()->with('success', 'Rendez-vous enregistré avec succès.');
    }

    public function sendWhatsappReminder(Appointment $appointment, AppointmentReminderService $reminderService): RedirectResponse
    {
        $this->authorize('update', $appointment);

        $appointment->loadMissing(['customer', 'vehicle', 'tenant']);
        $sent = $reminderService->sendSingleReminder($appointment, '2h', app()->getLocale());

        if (! $sent) {
            return back()->with('warning', 'Le message WhatsApp n\'a pas pu être remis pour le moment (le rendez-vous reste actif).');
        }

        $appointment->update(['reminder_sent_at' => now()]);

        return back()->with('success', 'Rappel WhatsApp envoyé au client.');
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
