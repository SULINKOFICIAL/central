<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenants_plans_items') || !Schema::hasColumn('tenants_plans_items', 'item_id')) {
            return;
        }

        $foreignKeyName = $this->foreignKeyName();

        if ($foreignKeyName !== null) {
            DB::statement("ALTER TABLE `tenants_plans_items` DROP FOREIGN KEY `{$foreignKeyName}`");
        }

        Schema::table('tenants_plans_items', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable()->change();
        });

        DB::statement('ALTER TABLE `tenants_plans_items` ADD CONSTRAINT `tenants_plans_items_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `modules`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');
    }

    public function down(): void
    {
        if (!Schema::hasTable('tenants_plans_items') || !Schema::hasColumn('tenants_plans_items', 'item_id')) {
            return;
        }

        $foreignKeyName = $this->foreignKeyName();

        if ($foreignKeyName !== null) {
            DB::statement("ALTER TABLE `tenants_plans_items` DROP FOREIGN KEY `{$foreignKeyName}`");
        }

        Schema::table('tenants_plans_items', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable(false)->change();
        });

        DB::statement('ALTER TABLE `tenants_plans_items` ADD CONSTRAINT `tenants_plans_items_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `modules`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');
    }

    private function foreignKeyName(): ?string
    {
        $result = DB::selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            ['tenants_plans_items', 'item_id']
        );

        return $result?->CONSTRAINT_NAME;
    }
};
