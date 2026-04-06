<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpensesHead extends Model
{
    protected $table = 'expenses_head';

    protected $primaryKey = 'exhid';

    public $incrementing = true;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'type',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => 'integer',
        ];
    }

    /**
     * Expenses under this head (expenses.exhid -> expenses_head.exhid).
     */
    public function expenses()
    {
        return $this->hasMany(Expense::class, 'exhid', 'exhid');
    }
}
