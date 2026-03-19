<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $table = 'expenses';

    protected $primaryKey = 'exid';

    public $incrementing = true;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'exhid',
        'description',
        'receipt_no',
        'payment',
        'igst',
        'cgst',
        'sgst',
        'dt',
        'party',
        'payby',
        'refno',
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
            'payby' => 'integer',
            'payment' => 'decimal:2',
            'igst' => 'integer',
            'cgst' => 'integer',
            'sgst' => 'integer',
        ];
    }

    /**
     * Boot: set receipt_no to next number when creating.
     */
    protected static function booted(): void
    {
        static::creating(function (Expense $expense) {
            if (empty($expense->receipt_no)) {
                $expense->receipt_no = (int) static::max('receipt_no') + 1;
            }
        });
    }

    /**
     * Expense belongs to ExpensesHead (exhid -> expenses_head.exhid).
     */
    public function expensesHead()
    {
        return $this->belongsTo(ExpensesHead::class, 'exhid', 'exhid');
    }

    /**
     * Expense belongs to Party (party -> party.pid).
     */
    public function partyRelation()
    {
        return $this->belongsTo(Party::class, 'party', 'pid');
    }
}
