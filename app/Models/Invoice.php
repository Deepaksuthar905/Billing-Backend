<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $table = 'invoice';

    protected $primaryKey = 'invid';

    public $incrementing = true;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'inv_no',
        'dt',
        'state',
        'addr',
        'pid',
        'gst',
        'payment',
        'cgst',
        'sgst',
        'igst',
        'paytype',
        'paynow',
        'payby',
        'refno',
        'paylater',
        'balance',
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
            'paytype' => 'integer',
            'gst' => 'decimal:2',
            'payment' => 'decimal:2',
            'cgst' => 'decimal:2',
            'sgst' => 'decimal:2',
            'igst' => 'decimal:2',
            'paynow' => 'decimal:2',
            'paylater' => 'decimal:2',
            'balance' => 'decimal:2',
        ];
    }

    /**
     * Invoice belongs to Party (pid -> party.pid).
     */
    public function party()
    {
        return $this->belongsTo(Party::class, 'pid', 'pid');
    }

    /**
     * Invoice has many InvoiceItems.
     */
    public function invoiceItems()
    {
        return $this->hasMany(InvoiceItem::class, 'inv_id', 'invid');
    }
}
