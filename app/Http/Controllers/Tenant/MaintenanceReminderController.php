<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\MaintenanceReminderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceReminderController extends Controller
{
    public function index(): Response
    {
        $vehicles = Vehicle::with('customer:id,name,first_name,phone,whatsapp_phone')
            ->orderBy('license_plate')
            ->get()
            ->map(fn (Vehicle $v) => [
                'id' => $v->id,
                'license_plate' => $v->license_plate,
                'brand' => $v->brand,
                'model' => $v->model,
                'mileage' => $v->mileage,
                'customer' => $v->customer,
                'next_service_date' => $v->next_service_date?->format('Y-m-d'),
                'next_service_mileage' => $v->next_service_mileage,
                'technical_inspection_expires_at' => $v->technical_inspection_expires_at?->format('Y-m-d'),
                'insurance_expires_at' => $v->insurance_expires_at?->format('Y-m-d'),
                'maintenance_reminder_sent_at' => $v->maintenance_reminder_sent_at?->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Tenant/MaintenanceReminders/Index', [
            'vehicles' => $vehicles,
        ]);
    }

    public function updateDates(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $validated = $request->validate([
            'next_service_date' => ['nullable', 'date'],
            'next_service_mileage' => ['nullable', 'integer', 'min:0'],
            'technical_inspection_expires_at' => ['nullable', 'date'],
            'insurance_expires_at' => ['nullable', 'date'],
        ]);

        $vehicle->update($validated);

        return back()->with('success', 'Échéances d\'entretien et documents mises à jour.');
    }

    public function sendWhatsapp(Vehicle $vehicle, MaintenanceReminderService $service): RedirectResponse
    {
        $vehicle->loadMissing(['customer', 'tenant']);
        $sent = $service->sendForVehicle($vehicle, app()->getLocale());

        if (! $sent) {
            return back()->with('warning', 'Le rappel WhatsApp n\'a pas pu être remis pour le moment.');
        }

        $vehicle->update(['maintenance_reminder_sent_at' => now()]);

        return back()->with('success', 'Rappel périodique envoyé sur WhatsApp.');
    }
}
