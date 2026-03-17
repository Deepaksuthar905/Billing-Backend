<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $table = 'items';

    protected $primaryKey = 'item_id';

    public $incrementing = true;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'item_name',
        'hsncode',
        'description',
        'rate',
        'with_without',
        'gst',
        'gst_amt',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'with_without' => 'integer',
            'rate' => 'decimal:2',
            'gst' => 'decimal:2',
            'gst_amt' => 'decimal:2',
        ];
    }
}
