<?php

namespace App\Http\Requests\Auth;

use App\Models\AffiliateLink;
use App\Models\AffiliateReferral;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegisterTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('slug')) {
            $this->merge([
                'slug' => Str::slug((string) $this->input('slug')),
            ]);
        }
        if ($this->input('type') === 'boutique_pieces') {
            $this->merge(['agencies_count' => 1]);
        }
    }

    public function rules(): array
    {
        $reserved = config('garagepro.reserved_subdomains', []);

        return [
            'type' => ['nullable', Rule::in(['garage', 'centre_technique', 'assurance', 'boutique_pieces'])],
            'garage_name' => ['required', 'string', 'min:2', 'max:150'],
            'owner_last_name' => ['nullable', 'string', 'min:2', 'max:100'],
            'owner_first_name' => ['nullable', 'string', 'min:2', 'max:100'],
            'gender' => ['nullable', 'in:homme,femme,autre'],
            'slug' => [
                'required',
                'string',
                'min:3',
                'max:60',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn($reserved),
                Rule::unique('tenants', 'slug'),
            ],
            'email' => ['required', 'string', 'email', 'max:150', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'min:6', 'max:50'],
            'country' => ['required', 'string', 'size:2', Rule::exists('countries', 'code')->where('is_active', true)],
            'city' => ['nullable', 'string', 'max:120'],
            'formatted_location' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'password' => [
                'nullable',
                'string',
                'min:8',
                'max:255',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[_\-@]/',
                'confirmed',
            ],
            'agencies_count' => ['required', 'integer', 'min:1', 'max:20'],
            'promo_code' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $promoCode = strtoupper(trim((string) $this->input('promo_code', '')));
            if ($promoCode === '') {
                return;
            }

            $link = AffiliateLink::where('code', $promoCode)->first();
            if (! $link) {
                $validator->errors()->add('promo_code', __('auth.promo_not_found'));

                return;
            }

            if ($link->is_blocked) {
                $validator->errors()->add('promo_code', __('auth.promo_blocked'));

                return;
            }

            $ownerUser = User::withoutTenantScope()->find($link->user_id);
            $email = strtolower(trim((string) $this->input('email', '')));
            $ip = $this->ip() ?: '127.0.0.1';

            $sameOwnerEmail = $ownerUser && $email !== '' && strtolower($ownerUser->email) === $email;
            $sameIpRecent = AffiliateReferral::where('affiliate_link_id', $link->id)
                ->where('ip_address', $ip)
                ->where('created_at', '>=', now()->subDays(30))
                ->exists();

            if ($sameOwnerEmail || $sameIpRecent) {
                $validator->errors()->add('promo_code', __('auth.promo_invalid_usage'));
            }
        });
    }
}
