<?php

namespace App\Modules\Address\Models;

use Illuminate\Database\Eloquent\Model;

class ZohoBooksToken extends Model
{
    protected $fillable = ['refresh_token'];

    protected $hidden = ['refresh_token'];

    protected function casts(): array
    {
        return ['refresh_token' => 'encrypted'];
    }
}
