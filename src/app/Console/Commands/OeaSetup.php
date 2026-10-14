<?php

namespace App\Console\Commands;

use App\Oea\OeaWorkflow;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Services\SettingService;

/**
 * Prepara el CRM para el flujo OEA. Se puede ejecutar varias veces sin duplicar nada.
 */
class OeaSetup extends Command
{
    protected $signature = 'oea:setup';

    protected $description = 'Configura estados, permisos y roles del flujo de aprobación de clientes OEA';

    // Permisos base del CRM que necesita cada perfil además de los de OEA
    private const REVIEWER_BASE = [
        'view crm leads', 'view crm organizations', 'view crm people', 'view crm contacts',
        'view crm activities', 'view crm tasks', 'edit crm tasks', 'view crm notes', 'create crm notes', 'view crm files',
    ];

    private const ROLES = [
        'SAC Customs' => [
            'description' => 'Crea clientes, carga documentos y los envía a revisión OEA',
            'oea' => ['oea cargar documentos', 'oea ver documentos', 'oea ver revisiones'],
            'extra' => [
                'create crm leads', 'edit crm leads', 'create crm organizations', 'edit crm organizations',
                'create crm people', 'edit crm people', 'create crm files', 'create crm tasks',
            ],
        ],
        'Financiero' => [
            'description' => 'Revisión de estados financieros (OEA)',
            'oea' => ['oea ver documentos', 'oea ver revisiones', 'oea revisar financiero'],
        ],
        'Seguridad' => [
            'description' => 'Valida que el cliente no tenga problemas legales (OEA)',
            'oea' => ['oea ver documentos', 'oea ver revisiones', 'oea revisar seguridad'],
        ],
        'Revisor Documental' => [
            'description' => 'Revisa los documentos adjuntos (OEA)',
            'oea' => ['oea ver documentos', 'oea ver revisiones', 'oea revisar documental'],
        ],
        // Para equipos pequeños donde una misma persona revisa las tres áreas
        'Revisor OEA' => [
            'description' => 'Revisa las tres áreas OEA: financiero, seguridad y documental',
            'oea' => ['oea ver documentos', 'oea ver revisiones', 'oea revisar financiero', 'oea revisar seguridad', 'oea revisar documental'],
        ],
        'Gerencia' => [
            'description' => 'Aprueba, rechaza o devuelve el cliente (OEA)',
            'oea' => ['oea ver documentos', 'oea ver revisiones', 'oea decision gerencia'],
        ],
    ];

    public function handle(OeaWorkflow $workflow, SettingService $settings): int
    {
        $this->setupStages($workflow, $settings);
        $this->setupPermissionsAndRoles();

        $this->info('Flujo OEA configurado.');

        return self::SUCCESS;
    }

    private function setupStages(OeaWorkflow $workflow, SettingService $settings): void
    {
        $pipeline = $workflow->leadPipeline();

        if (! $pipeline) {
            $this->error('No se encontró el embudo de prospectos del CRM.');

            return;
        }

        $order = 1;
        $ours = [];

        foreach (OeaWorkflow::STAGES as $key => $name) {
            // Busca por el id guardado (el nombre se puede cambiar desde el CRM) y luego por nombre
            $stage = PipelineStage::find($settings->get('oea_stage_'.$key))
                ?? PipelineStage::where('pipeline_id', $pipeline->id)->where('name', $name)->first()
                ?? PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => $name]);

            $stage->update(['pipeline_id' => $pipeline->id, 'order' => $order++]);
            $settings->set('oea_stage_'.$key, $stage->id, 'Estado OEA: '.$name);
            $ours[] = $stage->id;

            $this->line("  Estado <info>{$stage->name}</info> (#{$stage->id})");
        }

        // Quita las etapas genéricas del embudo de prospectos que no usa ningún prospecto
        PipelineStage::where('pipeline_id', $pipeline->id)
            ->whereNotIn('id', $ours)
            ->get()
            ->each(function ($stage) {
                if (Lead::withTrashed()->where('pipeline_stage_id', $stage->id)->exists()) {
                    $this->warn("  Se mantiene la etapa \"{$stage->name}\": tiene prospectos asignados.");

                    return;
                }

                $stage->delete();
                $this->line("  Etapa genérica eliminada: {$stage->name}");
            });
    }

    private function setupPermissionsAndRoles(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (OeaWorkflow::PERMISSIONS as $name => $description) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $permission->forceFill(['description' => $description, 'crm_permission' => 1])->save();
        }

        // Owner y Admin reciben todos los permisos OEA
        foreach (['Owner', 'Admin'] as $roleName) {
            Role::findByName($roleName, 'web')->givePermissionTo(array_keys(OeaWorkflow::PERMISSIONS));
        }

        foreach (self::ROLES as $name => $definition) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->forceFill(['description' => $definition['description'], 'crm_role' => 1])->save();

            // Solo se asignan permisos al crear el rol; después se administran desde Roles y permisos
            if ($role->wasRecentlyCreated) {
                $base = array_merge(self::REVIEWER_BASE, $definition['extra'] ?? []);
                $existing = Permission::whereIn('name', $base)->pluck('name')->all();
                $role->syncPermissions(array_merge($existing, $definition['oea']));
                $this->line("  Rol creado: <info>{$name}</info>");
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
