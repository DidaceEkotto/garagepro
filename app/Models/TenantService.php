<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Prestation / Service proposé publiquement par un établissement (garage, centre technique, etc.).
 */
class TenantService extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'category',
        'description',
        'price',
        'duration_minutes',
        'image_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function imageUrl(): ?string
    {
        if (empty($this->image_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->image_path);
    }
}
