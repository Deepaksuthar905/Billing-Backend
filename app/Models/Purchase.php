<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $table = 'purchase';

    protected $primaryKey = 'prid';

    public $incrementing = true;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'item_id',
        'p_inv_no',
        'dt',
        'state',
        'payment',
        'taxable_amt',
        'party_id',
        'gst',
        'cgst',
        'sgst',
        'igst',
        'payby',
        'refno',
        'isdel',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dt' => 'date',
            'payment' => 'decimal:2',
            'taxable_amt' => 'decimal:2',
            'gst' => 'decimal:2',
            'cgst' => 'decimal:2',
            'sgst' => 'decimal:2',
            'igst' => 'decimal:2',
            'isdel' => 'integer',
        ];
    }

    /**
     * Purchase belongs to Item (item_id -> items.item_id).
     */
    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }

    /**
     * Purchase belongs to Party (party_id -> party.pid).
     */
    public function party()
    {
        return $this->belongsTo(Party::class, 'party_id', 'pid');
    }

    /**
     * Purchase belongs to PayBy (payby -> pay_by.pbid).
     */
    public function payBy()
    {
        return $this->belongsTo(PayBy::class, 'payby', 'pbid');
    }
}
