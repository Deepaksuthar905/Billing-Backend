<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryPayment extends Model
{
    protected $table = 'salary_payments';

    protected $primaryKey = 'salid';

    public $incrementing = true;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'receipt_no',
        'empid',
        'salary_month',
        'dt',
        'gross_amt',
        'incentive_amt',
        'arrears_amt',
        'deduction_amt',
        'net_amt',
        'payby',
        'refno',
        'remarks',
        'payment_type',
        'isdel',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dt' => 'date',
            'gross_amt' => 'decimal:2',
            'incentive_amt' => 'decimal:2',
            'arrears_amt' => 'decimal:2',
            'deduction_amt' => 'decimal:2',
            'net_amt' => 'decimal:2',
            'payby' => 'integer',
            'isdel' => 'integer',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'empid', 'empid');
    }

    public function payBy()
    {
        return $this->belongsTo(PayBy::class, 'payby', 'pbid');
    }
}
