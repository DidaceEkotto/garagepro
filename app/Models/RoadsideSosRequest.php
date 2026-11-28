<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoadsideSosRequest extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_ON_THE_WAY = 'on_the_way';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'token',
        'driver_name',
        'driver_first_name',
        'driver_dial_code',
        'driver_phone',
        'vehicle_info',
        'vehicle_brand',
        'vehicle_model',
        'vehicle_plate',
        'breakdown_type',
        'description',
        'latitude',
        'longitude',
        'location_label',
        'photo_path',
        'photos',
        'status',
        'accepted_tenant_id',
        'accepted_price',
        'accepted_eta_minutes',
        'accepted_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'photos' => 'array',
            'accepted_price' => 'integer',
            'accepted_eta_minutes' => 'integer',
            'accepted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function offers(): HasMany
    {
        return $this->hasMany(RoadsideSosOffer::class, 'sos_request_id');
    }

    public function acceptedTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'accepted_tenant_id');
    }

    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return round($earthRadius * 2 * asin(min(1.0, sqrt($a))), 1);
    }
}
