<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property list<array<string, mixed>> $sections
 * @property int $version
 * @property int|null $updated_by
 */
class HomeLayout extends Model
{
    public const ROW_ID = 1;

    protected $table = 'home_layouts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'version' => 'integer',
            'updated_by' => 'integer',
        ];
    }
}
