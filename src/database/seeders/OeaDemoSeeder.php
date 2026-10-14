<?php

namespace Database\Seeders;

use App\Models\User;
use App\Oea\Models\OeaDocumentType;
use App\Oea\OeaWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use VentureDrake\LaravelCrm\Services\SettingService;

/**
 * Datos de ejemplo para probar el flujo OEA: documentos, usuarios y responsables.
 * Uso: php artisan db:seed --class=OeaDemoSeeder (no duplica si se corre varias veces)
 */
class OeaDemoSeeder extends Seeder
{
    public function run(SettingService $settings): void
    {
        // Listado de documentos tomado de un expediente real de cliente OEA
        $documents = [
            ['name' => 'CO-F-24 Formato creación / actualización de cliente', 'description' => 'Diligenciado y firmado'],
            ['name' => 'RUT', 'description' => 'Registro Único Tributario actualizado'],
            ['name' => 'Cámara de Comercio', 'description' => 'Certificado de existencia y representación legal reciente'],
            ['name' => 'Copia de la cédula del representante legal', 'description' => null],
            ['name' => 'RUB', 'description' => 'Registro Único de Beneficiarios Finales'],
            ['name' => 'Composición accionaria', 'description' => null],
            ['name' => 'Estados financieros', 'description' => null],
            ['name' => 'Declaración de renta', 'description' => 'Último año gravable'],
            ['name' => 'Certificación bancaria', 'description' => null],
            ['name' => 'Referencia comercial 1', 'description' => null],
            ['name' => 'Referencia comercial 2', 'description' => null],
            ['name' => 'Mandato DIAN', 'description' => null],
            ['name' => 'Manifestación suscrita', 'description' => null],
            ['name' => 'CO-F-14 Formato acuerdo de responsabilidad', 'description' => 'Diligenciado y firmado'],
            ['name' => 'CO-F-08 Formato registro de visita', 'description' => null],
            ['name' => 'Registro fotográfico', 'description' => 'No necesita firma'],
        ];

        foreach ($documents as $i => $document) {
            OeaDocumentType::updateOrCreate(['name' => $document['name']], $document + ['required' => false, 'order' => $i + 1]);
        }

        $revisor = $this->user('Usuario Revisión', 'revision@example.com', 'Revisor OEA');
        $this->user('Usuario Cliente', 'cliente@example.com', 'SAC Customs');
        $manager = User::where('email', 'admin@example.com')->firstOrFail();

        // El revisor cubre las tres áreas y el administrador hace de Gerencia
        foreach (OeaWorkflow::PARALLEL_AREAS as $area) {
            $settings->set('oea_responsable_'.$area, $revisor->id, 'Responsable OEA: '.OeaWorkflow::AREAS[$area]['label']);
        }
        $settings->set('oea_responsable_gerencia', $manager->id, 'Responsable OEA: '.OeaWorkflow::AREAS['gerencia']['label']);
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->forceFill(['name' => $name, 'password' => Hash::make('password123'), 'crm_access' => 1, 'email_verified_at' => now()])->save();
        }

        $user->syncRoles([$role]);

        return $user;
    }
}
