<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $table = 'employees';

    protected $primaryKey = 'empid';

    public $incrementing = true;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'emp_code',
        'empname',
        'mobno',
        'designation',
        'joining_date',
        'monthly_salary',
        'bank_name',
        'bank_account',
        'ifsc',
        'pan_no',
        'addr',
        'city',
        'state',
        'is_active',
        'isdel',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
            'monthly_salary' => 'decimal:2',
            'is_active' => 'integer',
            'isdel' => 'integer',
        ];
    }

    public function salaryPayments()
    {
        return $this->hasMany(SalaryPayment::class, 'empid', 'empid');
    }
}
