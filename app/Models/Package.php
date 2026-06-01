<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    protected $fillable = [
        'name',
        'description',
        'resources_list',
        'popular',
        'order',
        'status',
        'filed_by',
        'created_by',
        'updated_by',
    ];

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'packages_modules', 'package_id', 'module_id')
            ->withPivot('module_pricing_tier_id', 'price');
    }

    public function benefits(): HasMany
    {
        return $this->hasMany(PackageBenefit::class)->orderBy('position');
    }
}
