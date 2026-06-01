<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    private array $rows = [
        ['quantity' => 5, 'price' => 0.00],
        ['quantity' => 10, 'price' => 14.90],
        ['quantity' => 25, 'price' => 29.90],
        ['quantity' => 50, 'price' => 59.90],
        ['quantity' => 100, 'price' => 99.90],
        ['quantity' => 250, 'price' => 199.90],
        ['quantity' => 500, 'price' => 349.90],
    ];

    public function up(): void
    {
        $seedUserId = $this->seedUserId();

        $now = Carbon::now();

        DB::table('additional_storages')->delete();

        foreach ($this->rows as $row) {
            DB::table('additional_storages')->insert([
                'quantity' => $row['quantity'],
                'price' => $row['price'],
                'status' => true,
                'filed_by' => null,
                'created_by' => $seedUserId,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('additional_storages')->delete();
    }

    private function seedUserId()
    {
        $seedUserId = DB::table('users')->orderBy('id')->value('id');

        if ($seedUserId) {
            return $seedUserId;
        }

        return DB::table('users')->insertGetId([
            'name' => 'Sistema',
            'email' => 'sistema@micore.local',
            'password' => Hash::make('micore-system-user'),
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
