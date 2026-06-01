<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    private array $rows = [
        ['quantity' => 3, 'price' => 0.00],
        ['quantity' => 5, 'price' => 19.90],
        ['quantity' => 10, 'price' => 49.90],
        ['quantity' => 15, 'price' => 89.90],
        ['quantity' => 20, 'price' => 129.90],
        ['quantity' => 30, 'price' => 199.90],
    ];

    public function up(): void
    {
        $seedUserId = $this->seedUserId();

        $now = Carbon::now();

        DB::table('additional_users')->delete();

        foreach ($this->rows as $row) {
            DB::table('additional_users')->insert([
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
        DB::table('additional_users')->delete();
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
