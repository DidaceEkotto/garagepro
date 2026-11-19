<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AccountingAccount extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'account_class',
        'type',
        'is_auxiliary',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'account_class' => 'integer',
            'is_auxiliary' => 'boolean',
            'is_system' => 'boolean',
        ];
    }
}
