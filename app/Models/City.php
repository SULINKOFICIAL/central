<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class City extends Model
{
    protected $table = 'cities';

    protected $fillable = [
        'id',
        'state_id',
        'name',
        'code_ibge',
        'status',
        'filed_by',
        'created_by',
        'updated_by',
    ];

    /**
     * Estado vinculado pelo mesmo ID usado no MiCore.
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id', 'id');
    }
}
