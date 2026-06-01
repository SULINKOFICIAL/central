<?php

use App\Services\TemplateLocationSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

afterEach(function () {
    $database = storage_path('framework/testing-location-source.sqlite');

    DB::disconnect('location_source');

    if (file_exists($database)) {
        unlink($database);
    }
});

it('syncs states and cities from a configured source connection', function () {
    $database = storage_path('framework/testing-location-source.sqlite');

    file_put_contents($database, '');

    config([
        'database.connections.location_source' => [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
    ]);

    Schema::connection('location_source')->create('states', function (Blueprint $table) {
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
    });

    Schema::connection('location_source')->create('cities', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('state_id');
        $table->string('name');
        $table->integer('code_ibge');
        $table->integer('status')->default(1);
        $table->integer('filed_by')->nullable();
        $table->integer('created_by');
        $table->integer('updated_by')->nullable();
        $table->timestamps();
    });

    DB::connection('location_source')->table('states')->insert([
        'id' => 16,
        'country_id' => 26,
        'name' => 'Paraná',
        'acronym' => 'PR',
        'code' => 41,
        'cuf' => 41,
        'status' => 1,
        'created_by' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('location_source')->table('cities')->insert([
        'id' => 4179,
        'state_id' => 16,
        'name' => 'Curitiba',
        'code_ibge' => 4106902,
        'status' => 1,
        'created_by' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = app(TemplateLocationSyncService::class)->syncFromConnection('location_source');

    expect($result)->toBe([
        'states' => 1,
        'cities' => 1,
    ]);

    $this->assertDatabaseHas('states', [
        'id' => 16,
        'name' => 'Paraná',
        'acronym' => 'PR',
    ]);

    $this->assertDatabaseHas('cities', [
        'id' => 4179,
        'state_id' => 16,
        'name' => 'Curitiba',
        'code_ibge' => 4106902,
    ]);
});
