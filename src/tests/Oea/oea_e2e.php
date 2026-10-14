<?php
// Prueba de punta a punta del flujo OEA. Corre dentro de una transacción que se revierte: no deja datos.
// Uso: docker compose exec app php tests/Oea/oea_e2e.php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Oea\Models\OeaDocumentType;
use App\Oea\Models\OeaLeadDocument;
use App\Oea\Models\OeaReview;
use App\Oea\OeaWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use VentureDrake\LaravelCrm\Models\Lead;

$ok = 0;
$fail = 0;
function check($cond, $msg)
{
    global $ok, $fail;
    $cond ? $ok++ : $fail++;
    echo ($cond ? '  ✔ ' : '  ✘ ').$msg.PHP_EOL;
}
function expectBlocked(callable $fn, $msg)
{
    try {
        $fn();
        check(false, $msg.' (NO se bloqueó)');
    } catch (ValidationException $e) {
        check(true, $msg.' → "'.collect($e->errors())->flatten()->first().'"');
    }
}
function attachDocs(Lead $lead, array $types)
{
    foreach ($types as $t) {
        $file = $lead->files()->create(['file' => "fake/{$t->id}.pdf", 'name' => "{$t->name}.pdf", 'filesize' => 1, 'mime' => 'application/pdf', 'disk' => 'local']);
        OeaLeadDocument::create(['lead_id' => $lead->id, 'oea_document_type_id' => $t->id, 'file_id' => $file->id, 'uploaded_by' => auth()->id()]);
    }
}

DB::beginTransaction();
try {
    $wf = app(OeaWorkflow::class);
    $settings = app('laravel-crm.settings');
    $pipelineId = $wf->leadPipeline()->id;

    $users = [];
    foreach (['sac' => 'SAC Customs', 'financiero' => 'Financiero', 'seguridad' => 'Seguridad', 'documental' => 'Revisor Documental', 'gerencia' => 'Gerencia'] as $k => $role) {
        $users[$k] = User::create(['name' => "Test $role", 'email' => "test-$k@example.com", 'password' => bcrypt('x'), 'crm_access' => 1]);
        $users[$k]->assignRole($role);
    }
    foreach (['financiero', 'seguridad', 'documental', 'gerencia'] as $a) {
        $settings->set('oea_responsable_'.$a, '');
    }

    // Aísla la prueba de los documentos ya configurados (se revierte con la transacción)
    OeaDocumentType::query()->update(['active' => false]);

    $t1 = OeaDocumentType::create(['name' => 'RUT', 'required' => true, 'order' => 1]);
    $t2 = OeaDocumentType::create(['name' => 'Cámara de Comercio', 'required' => true, 'order' => 2]);
    OeaDocumentType::create(['name' => 'Carta opcional', 'required' => false, 'order' => 3]);

    echo "1) Creación y bloqueos\n";
    auth()->login($users['sac']);
    $lead = Lead::create(['title' => 'Importadora Test S.A.S', 'pipeline_id' => $pipelineId, 'pipeline_stage_id' => $wf->stageId('nuevo'), 'user_owner_id' => $users['sac']->id]);
    check($wf->leadStageKey($lead) === 'nuevo', 'Prospecto creado en "Nuevo"');
    expectBlocked(fn () => Lead::create(['title' => 'X', 'pipeline_id' => $pipelineId, 'pipeline_stage_id' => $wf->stageId('en_revision')]), 'No se puede crear directamente en "En Revisión"');
    expectBlocked(fn () => $lead->update(['pipeline_stage_id' => $wf->stageId('en_revision')]), 'Sin documentos no pasa a "En Revisión"');
    $lead->refresh();
    expectBlocked(fn () => $lead->update(['pipeline_stage_id' => $wf->stageId('aprobado')]), 'No se puede poner "Aprobado" a mano');
    $lead->refresh();

    attachDocs($lead, [$t1, $t2]);
    check($wf->missingDocuments($lead)->isEmpty(), 'Obligatorios completos (el opcional no bloquea)');
    expectBlocked(fn () => $lead->update(['pipeline_stage_id' => $wf->stageId('en_revision')]), 'Sin responsables configurados no pasa a revisión');
    $lead->refresh();

    foreach (['financiero', 'seguridad', 'documental', 'gerencia'] as $a) {
        $settings->set('oea_responsable_'.$a, $users[$a]->id);
    }
    $settings->set('oea_dias_plazo', '');

    echo "2) Envío a revisión (ronda 1)\n";
    $lead->update(['pipeline_stage_id' => $wf->stageId('en_revision')]);
    $r = $wf->reviews($lead, 1);
    check($wf->leadStageKey($lead->fresh()) === 'en_revision', 'Estado "En Revisión"');
    check($r->pluck('area')->sort()->values()->all() === ['documental', 'financiero', 'seguridad'], 'Se crearon 3 revisiones (Gerencia todavía no)');
    $expected = collect([$users['financiero']->id, $users['seguridad']->id, $users['documental']->id])->sort()->values()->all();
    check($lead->tasks()->pluck('user_assigned_id')->sort()->values()->all() === $expected, '3 tareas del CRM asignadas a los responsables');
    check($lead->tasks()->whereNull('due_at')->count() === 3, 'Sin plazo configurado → tareas sin fecha de vencimiento');
    expectBlocked(fn () => $lead->fresh()->update(['pipeline_stage_id' => $wf->stageId('nuevo')]), 'Durante la revisión no se cambia el estado a mano');
    check(! $wf->canEditDocuments($lead->fresh()), 'Documentos bloqueados durante la revisión');

    $fin = $r->firstWhere('area', 'financiero');
    check(! $wf->canDecide($users['seguridad'], $fin), 'Seguridad NO puede resolver la revisión de Financiero');
    try {
        $wf->decide($fin, 'rechazado', '', $users['financiero']);
        check(false, 'Rechazo sin comentario aceptado');
    } catch (ValidationException $e) {
        check(true, 'Rechazar exige comentario');
    }

    $wf->decide($fin, 'aprobado', null, $users['financiero']);
    $wf->decide($r->firstWhere('area', 'seguridad'), 'rechazado', 'Reporte en listas restrictivas', $users['seguridad']);
    check(! OeaReview::where('lead_id', $lead->id)->where('area', 'gerencia')->exists(), 'Con 2 de 3 conceptos, Gerencia aún no tiene tarea');
    try {
        $wf->decide($fin->fresh(), 'rechazado', 'cambio', $users['financiero']);
        check(false, 'Concepto modificable');
    } catch (ValidationException $e) {
        check(true, 'Un concepto emitido no se puede cambiar');
    }
    $wf->decide($r->firstWhere('area', 'documental'), 'aprobado', null, $users['documental']);
    $ger = OeaReview::where('lead_id', $lead->id)->where('round', 1)->where('area', 'gerencia')->first();
    check($ger && $ger->user_assigned_id === $users['gerencia']->id, 'Con los 3 conceptos (aunque uno sea rechazo) se crea la tarea de Gerencia');
    check($lead->tasks()->whereNotNull('completed_at')->count() === 3, 'Las 3 tareas de área quedaron completadas');

    echo "3) Gerencia devuelve a SAC → ronda 2\n";
    $wf->decide($ger, 'devuelto', 'Aclarar reporte de Seguridad', $users['gerencia']);
    check($wf->leadStageKey($lead->fresh()) === 'requiere_ajustes', 'Estado "Requiere ajustes"');
    check($wf->canEditDocuments($lead->fresh()), 'SAC puede volver a cargar documentos');
    $lead->fresh()->update(['pipeline_stage_id' => $wf->stageId('en_revision')]);
    check($wf->currentRound($lead) === 2 && $wf->reviews($lead, 2)->count() === 3, 'El reenvío abre la ronda 2 con 3 revisiones nuevas');

    echo "4) Ronda 2 aprobada por todos → Aprobado\n";
    foreach ($wf->reviews($lead, 2) as $rv) {
        $wf->decide($rv, 'aprobado', null, $users[$rv->area]);
    }
    $ger2 = OeaReview::where('lead_id', $lead->id)->where('round', 2)->where('area', 'gerencia')->first();
    $wf->decide($ger2, 'aprobado', null, $users['gerencia']);
    check($wf->leadStageKey($lead->fresh()) === 'aprobado', 'Estado final "Aprobado"');
    expectBlocked(fn () => $lead->fresh()->update(['pipeline_stage_id' => $wf->stageId('nuevo')]), 'Con decisión final no se puede cambiar el estado');
    check($lead->notes()->where('content', 'like', '[Aprobación OEA]%')->count() === 10, 'Trazabilidad (5 notas por ronda): '.$lead->notes()->count().' notas en el timeline');

    echo "5) Gerencia rechaza → Rechazado\n";
    $lead2 = Lead::create(['title' => 'Otro cliente', 'pipeline_id' => $pipelineId, 'pipeline_stage_id' => $wf->stageId('nuevo')]);
    attachDocs($lead2, [$t1, $t2]);
    $lead2->update(['pipeline_stage_id' => $wf->stageId('en_revision')]);
    foreach ($wf->reviews($lead2, 1) as $rv) {
        $wf->decide($rv, 'aprobado', null, $users[$rv->area]);
    }
    $wf->decide(OeaReview::where('lead_id', $lead2->id)->where('area', 'gerencia')->first(), 'rechazado', 'No cumple política', $users['gerencia']);
    check($wf->leadStageKey($lead2->fresh()) === 'rechazado', 'Estado final "Rechazado"');

    echo "6) Archivo borrado desde el CRM cuenta como faltante\n";
    $lead3 = Lead::create(['title' => 'C3', 'pipeline_id' => $pipelineId, 'pipeline_stage_id' => $wf->stageId('nuevo')]);
    attachDocs($lead3, [$t1]);
    OeaLeadDocument::where('lead_id', $lead3->id)->first()->file->delete();
    check($wf->missingDocuments($lead3)->count() === 2, 'El RUT borrado vuelve a figurar como faltante');

    echo "7) Plazo configurado\n";
    $settings->set('oea_dias_plazo', 3);
    $lead4 = Lead::create(['title' => 'C4', 'pipeline_id' => $pipelineId, 'pipeline_stage_id' => $wf->stageId('nuevo')]);
    attachDocs($lead4, [$t1, $t2]);
    $lead4->update(['pipeline_stage_id' => $wf->stageId('en_revision')]);
    $due = $lead4->tasks()->first()->due_at;
    check($due && abs(now()->addDays(3)->diffInMinutes($due)) < 5, 'Con 3 días de plazo las tareas vencen en 3 días');

    echo "8) Render del panel (Livewire)\n";
    foreach (['sac', 'gerencia'] as $who) {
        $html = Livewire\Livewire::actingAs($users[$who])->test(App\Livewire\Oea\LeadPanel::class, ['lead' => $lead->fresh()])->html();
        check(str_contains($html, 'Aprobación OEA') && str_contains($html, 'Rondas anteriores'), "Panel renderiza para $who");
    }
    $html = Livewire\Livewire::actingAs($users['gerencia'])->test(App\Livewire\Oea\LeadPanel::class, ['lead' => $lead2->fresh()])->html();
    check(! str_contains($html, 'Enviar a revisión'), 'Gerencia no ve el botón "Enviar a revisión"');
} finally {
    DB::rollBack();
}
echo PHP_EOL."Resultado: $ok OK, $fail fallidos".PHP_EOL;
