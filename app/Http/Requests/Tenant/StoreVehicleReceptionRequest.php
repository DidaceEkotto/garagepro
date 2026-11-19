<?php

namespace App\Http\Requests\Tenant;

use App\Models\VehicleReception;
use App\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleReceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', VehicleReception::class) ?? false;
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
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
            'vehicle_id' => [
                'required',
                'integer',
                Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId),
            ],
            'appointment_id' => [
                'nullable',
                'integer',
                Rule::exists('appointments', 'id')->where('tenant_id', $tenantId),
            ],
            'courtesy_vehicle_id' => [
                'nullable',
                'integer',
                Rule::exists('courtesy_vehicles', 'id')->where('tenant_id', $tenantId),
            ],
            'courtesy_deposit_amount' => ['nullable', 'integer', 'min:0'],
            'visit_reason' => ['required', Rule::in(['panne', 'entretien', 'controle', 'accident'])],
            'mileage_in' => ['required', 'integer', 'min:0'],
            'fuel_level' => ['required', 'integer', 'min:0', 'max:100'],
            'dashboard_warnings' => ['nullable', 'array'],
            'belongings_left' => ['nullable', 'string', 'max:1000'],
            'damage_markers' => ['nullable', 'array'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['file', 'image', 'max:10240'],
            'estimated_exit_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
