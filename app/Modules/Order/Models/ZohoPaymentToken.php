<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;

class ZohoPaymentToken extends Model
{
    protected $fillable = [
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
