<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayBy extends Model
{
    protected $table = 'pay_by';

    protected $primaryKey = 'pbid';

    public $incrementing = true;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'name',
        'detail',
        'prebalance',
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
            'type' => 'integer',
            'prebalance' => 'decimal:2',
            'balance' => 'decimal:2',
        ];
    }
}
