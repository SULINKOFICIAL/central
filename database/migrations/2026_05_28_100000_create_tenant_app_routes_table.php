<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cria o registry de roteamento usado pela App API centralizada.
     */
    public function up(): void
    {
        Schema::create('tenant_app_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('mode')->default('local_database');
            $table->string('remote_base_url')->nullable();
            $table->text('remote_service_token')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Remove o registry de roteamento da App API.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_app_routes');
    }
};
