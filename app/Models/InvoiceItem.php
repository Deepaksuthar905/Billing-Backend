<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $table = 'invoice_item';

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'inv_id',
        'item_id',
        'hsnocde',
        'description',
        'rate',
        'qty',
        'payment',
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
            'qty' => 'decimal:2',
            'payment' => 'decimal:2',
            'gst' => 'decimal:2',
            'gst_amt' => 'decimal:2',
        ];
    }

    /**
     * InvoiceItem belongs to Invoice (inv_id -> invoice.invid).
     */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'inv_id', 'invid');
    }

    /**
     * InvoiceItem belongs to Item (item_id -> items.item_id).
     */
    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }
}
