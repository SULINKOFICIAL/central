<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('logs_apis', function (Blueprint $table) {
            $table->enum('api', ['Meta', 'Mercado Livre', 'PagarMe', 'PagHiper', 'SQS'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('logs_apis', function (Blueprint $table) {
            $table->enum('api', ['Meta', 'Mercado Livre', 'PagarMe', 'PagHiper'])->change();
        });
    }
};
