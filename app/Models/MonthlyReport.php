<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyReport extends Model
{
    protected $table = 'monthly_reports';

    protected $primaryKey = 'mrid';

    public $incrementing = true;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'yr',
        'mnth',
        'inv_amt',
        'received_amt',
        'due_amt',
        'purchase_amt',
        'expense_amt',
        'salary_amt',
        'journal_amt',
        'inv_count',
        'purchase_count',
        'expense_count',
        'salary_count',
        'journal_count',
        'sales_taxable',
        'purchase_taxable',
        'sales_cgst',
        'sales_sgst',
        'sales_igst',
        'purchase_cgst',
        'purchase_sgst',
        'purchase_igst',
        'expense_heads',
        'net_pl',
        'generated_at',
        'is_locked',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'yr' => 'integer',
            'mnth' => 'integer',
            'inv_amt' => 'decimal:2',
            'received_amt' => 'decimal:2',
            'due_amt' => 'decimal:2',
            'purchase_amt' => 'decimal:2',
            'expense_amt' => 'decimal:2',
            'salary_amt' => 'decimal:2',
            'journal_amt' => 'decimal:2',
            'inv_count' => 'integer',
            'purchase_count' => 'integer',
            'expense_count' => 'integer',
            'salary_count' => 'integer',
            'journal_count' => 'integer',
            'sales_taxable' => 'decimal:2',
            'purchase_taxable' => 'decimal:2',
            'sales_cgst' => 'decimal:2',
            'sales_sgst' => 'decimal:2',
            'sales_igst' => 'decimal:2',
            'purchase_cgst' => 'decimal:2',
            'purchase_sgst' => 'decimal:2',
            'purchase_igst' => 'decimal:2',
            'expense_heads' => 'array',
            'net_pl' => 'decimal:2',
            'generated_at' => 'datetime',
            'is_locked' => 'integer',
        ];
    }
}
