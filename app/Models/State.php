<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class State extends Model
{
    protected $table = 'states';

    protected $fillable = [
        'id',
        'country_id',
        'name',
        'acronym',
        'code',
        'cuf',
        'status',
        'filed_by',
        'created_by',
        'updated_by',
    ];

    /**
     * Cidades vinculadas ao estado pelo mesmo ID usado no MiCore.
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class, 'state_id', 'id');
    }
}
