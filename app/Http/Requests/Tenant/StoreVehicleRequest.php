<?php

namespace App\Http\Requests\Tenant;

use App\Models\Vehicle;
use App\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', Vehicle::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
            'license_plate' => [
                'required',
                'string',
                'max:30',
                Rule::unique('vehicles', 'license_plate')->where('tenant_id', $tenantId),
            ],
            'brand' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:80'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'vin' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:40'],
            'fuel_type' => ['required', Rule::in(['essence', 'diesel', 'hybride', 'electrique', 'gpl'])],
            'transmission' => ['required', Rule::in(['manuelle', 'automatique'])],
            'mileage' => ['required', 'integer', 'min:0'],
            'first_registration_date' => ['nullable', 'date'],
            'next_service_date' => ['nullable', 'date'],
            'next_service_mileage' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
