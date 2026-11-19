<?php

namespace App\Http\Requests\Tenant;

use App\Models\Agency;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAgencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Agency|null $agency */
        $agency = $this->route('agency');

        return $agency !== null && ($this->user('web')?->can('update', $agency) ?? false);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'code' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
