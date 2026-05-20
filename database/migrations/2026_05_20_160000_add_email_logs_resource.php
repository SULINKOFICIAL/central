<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Garante o recurso da tela de logs de sincronização de e-mails.
     */
    public function up(): void
    {
        $moduleId = DB::table('modules')
            ->where('slug', 'system_activities')
            ->value('id');

        /**
         * Sem o módulo de atividades, a sincronização de recursos posterior
         * poderá recriar o vínculo com o módulo correto.
         */
        $resourceId = DB::table('resources')->where('name', 'Logs de E-mails')->value('id');

        if ($resourceId) {
            DB::table('resources')
                ->where('id', $resourceId)
                ->update([
                    'module_id'   => $moduleId,
                    'status'      => true,
                    'updated_by'  => 1,
                    'updated_at'  => now(),
                ]);
        } else {
            $resourceId = DB::table('resources')->insertGetId([
                'module_id'   => $moduleId,
                'name'        => 'Logs de E-mails',
                'status'      => true,
                'created_by'  => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        /**
         * Mantém o recurso disponível nos grupos existentes da Central.
         */
        foreach (DB::table('groups')->get(['id']) as $group) {
            $exists = DB::table('group_resource')
                ->where('group_id', $group->id)
                ->where('resource_id', $resourceId)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('group_resource')->insert([
                'group_id'    => $group->id,
                'resource_id' => $resourceId,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    /**
     * Remove somente o vínculo criado para o novo recurso.
     */
    public function down(): void
    {
        $resourceId = DB::table('resources')->where('name', 'Logs de E-mails')->value('id');

        if (!$resourceId) {
            return;
        }

        DB::table('group_resource')->where('resource_id', $resourceId)->delete();
        DB::table('resources')->where('id', $resourceId)->delete();
    }
};
