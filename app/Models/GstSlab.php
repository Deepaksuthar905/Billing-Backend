<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GstSlab extends Model
{
    protected $table = 'gst_slab';

    protected $primaryKey = 'gid';

    public $incrementing = true;

    /** gst_slab table mein created_at/updated_at nahi hain */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'gstid',
        'igst',
        'cgst',
        'sgst',
        'label',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'igst' => 'boolean',
            'cgst' => 'boolean',
            'sgst' => 'boolean',
            'label' => 'string',
        ];
    }
}
