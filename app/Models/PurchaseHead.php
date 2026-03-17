<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseHead extends Model
{
    protected $table = 'purchase_head';

    protected $primaryKey = 'prhid';

    public $incrementing = true;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'type',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => 'integer',
        ];
    }
}
