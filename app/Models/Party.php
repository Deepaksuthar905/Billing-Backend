<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Party extends Model
{
    protected $table = 'party';

    protected $primaryKey = 'pid';

    public $incrementing = true;

    /** party table mein created_at/updated_at nahi hain */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'partyname',
        'mobno',
        'cid',
        'billing_name',
        'gst_no',
        'city',
        'state',
        'gst_reg',
        'same_state',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gst_reg' => 'boolean',
            'same_state' => 'boolean',
        ];
    }
}
