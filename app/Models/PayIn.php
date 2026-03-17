<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayIn extends Model
{
    protected $table = 'pay_in';

    protected $primaryKey = 'pinid';

    public $incrementing = true;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'party_id',
        'inv_id',
        'dt',
        'description',
        'payby',
        'amount',
        'referal',
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
            'amount' => 'decimal:2',
        ];
    }

    /**
     * PayIn belongs to Party (party_id -> party.pid).
     */
    public function party()
    {
        return $this->belongsTo(Party::class, 'party_id', 'pid');
    }

    /**
     * PayIn belongs to Invoice (inv_id -> invoice.invid).
     */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'inv_id', 'invid');
    }

    /**
     * PayIn belongs to PayBy (payby -> pay_by.pbid).
     */
    public function payBy()
    {
        return $this->belongsTo(PayBy::class, 'payby', 'pbid');
    }
}
