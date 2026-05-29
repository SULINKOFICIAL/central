<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAppRoute extends Model
{
    public const MODE_LOCAL_DATABASE = 'local_database';
    public const MODE_REMOTE_API = 'remote_api';
    public const MODE_DISABLED = 'disabled';

    protected $table = 'tenant_app_routes';

    protected $fillable = [
        'tenant_id',
        'mode',
        'remote_base_url',
        'remote_service_token',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * Tenant dono desta rota de aplicativo.
     */
    public function tenant(): BelongsTo
    {
        /**
         * A rota do app sempre pertence a um tenant registrado na Central.
         */
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }
}
