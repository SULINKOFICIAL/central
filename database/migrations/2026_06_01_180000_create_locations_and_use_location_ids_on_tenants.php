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
        if (!Schema::hasTable('states')) {
            Schema::create('states', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('country_id');
                $table->string('name');
                $table->string('acronym');
                $table->integer('code')->nullable();
                $table->integer('cuf')->nullable();
                $table->integer('status')->default(1);
                $table->integer('filed_by')->nullable();
                $table->integer('created_by');
                $table->integer('updated_by')->nullable();
                $table->timestamps();

                $table->index('acronym');
                $table->index('status');
            });
        }

        if (!Schema::hasTable('cities')) {
            Schema::create('cities', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('state_id');
                $table->string('name');
                $table->integer('code_ibge');
                $table->integer('status')->default(1);
                $table->integer('filed_by')->nullable();
                $table->integer('created_by');
                $table->integer('updated_by')->nullable();
                $table->timestamps();

                $table->index('state_id');
                $table->index('code_ibge');
                $table->index('status');
            });
        }

        if (Schema::hasTable('tenants')) {
            Schema::table('tenants', function (Blueprint $table) {
                if (!Schema::hasColumn('tenants', 'company_state_id')) {
                    $table->unsignedBigInteger('company_state_id')->nullable()->after('company_zip_code')->index();
                }

                if (!Schema::hasColumn('tenants', 'company_city_id')) {
                    $table->unsignedBigInteger('company_city_id')->nullable()->after('company_state_id')->index();
                }

                if (Schema::hasColumn('tenants', 'company_city_state')) {
                    $table->dropColumn('company_city_state');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('tenants')) {
            Schema::table('tenants', function (Blueprint $table) {
                if (!Schema::hasColumn('tenants', 'company_city_state')) {
                    $table->string('company_city_state')->nullable()->after('company_zip_code');
                }

                if (Schema::hasColumn('tenants', 'company_city_id')) {
                    $table->dropColumn('company_city_id');
                }

                if (Schema::hasColumn('tenants', 'company_state_id')) {
                    $table->dropColumn('company_state_id');
                }
            });
        }

        Schema::dropIfExists('cities');
        Schema::dropIfExists('states');
    }
};
