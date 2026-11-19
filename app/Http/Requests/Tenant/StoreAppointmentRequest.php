<?php

namespace App\Http\Requests\Tenant;

use App\Models\Appointment;
use App\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', Appointment::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'agency_id' => [
                'required',
                'integer',
                Rule::exists('agencies', 'id')->where('tenant_id', $tenantId),
            ],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
            // Création automatique de la fiche client si nouveau
            'new_customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:150'],
            'new_customer_phone' => ['required_without:customer_id', 'nullable', 'string', 'max:50'],
            'vehicle_id' => [
                'nullable',
                'integer',
                Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId),
            ],
            'assigned_mechanic_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('tenant_id', $tenantId),
            ],
            'bay_name' => ['nullable', 'string', 'max:60'],
            'channel' => ['required', Rule::in(['telephone', 'whatsapp', 'en_ligne', 'sans_rdv'])],
            'scheduled_at' => ['required_unless:channel,sans_rdv', 'nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'reason' => ['required', Rule::in(['panne', 'entretien', 'controle', 'accident', 'autre'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
