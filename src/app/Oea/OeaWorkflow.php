<?php

namespace App\Oea;

use App\Models\User;
use App\Oea\Models\OeaDocumentType;
use App\Oea\Models\OeaLeadDocument;
use App\Oea\Models\OeaReview;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Services\SettingService;

/**
 * Flujo de aprobación de clientes OEA.
 *
 * SAC carga los documentos y pasa el prospecto a "En Revisión"; eso crea las tareas de
 * Financiero, Seguridad y Revisor documental. Cuando las tres emiten su concepto se crea
 * la tarea de Gerencia, que decide: aprobar, rechazar o devolver a SAC para ajustes.
 */
class OeaWorkflow
{
    public const STAGES = [
        'nuevo' => 'Nuevo',
        'en_revision' => 'En Revisión',
        'requiere_ajustes' => 'Requiere ajustes',
        'aprobado' => 'Aprobado',
        'rechazado' => 'Rechazado',
    ];

    // Estados que solo puede asignar el flujo (decisión de Gerencia)
    public const SYSTEM_STAGES = ['aprobado', 'rechazado', 'requiere_ajustes'];

    // Estados en los que SAC puede cargar o reemplazar documentos
    public const EDITABLE_STAGES = [null, 'nuevo', 'requiere_ajustes'];

    public const AREAS = [
        'financiero' => [
            'label' => 'Financiero',
            'task' => 'Revisión de estados financieros',
            'permission' => 'oea revisar financiero',
        ],
        'seguridad' => [
            'label' => 'Seguridad',
            'task' => 'Validar que el cliente no tenga problemas legales',
            'permission' => 'oea revisar seguridad',
        ],
        'documental' => [
            'label' => 'Revisor documental',
            'task' => 'Revisar los documentos adjuntos',
            'permission' => 'oea revisar documental',
        ],
        'gerencia' => [
            'label' => 'Gerencia',
            'task' => 'Aprobar o rechazar el cliente',
            'permission' => 'oea decision gerencia',
        ],
    ];

    public const PARALLEL_AREAS = ['financiero', 'seguridad', 'documental'];

    public const PERMISSIONS = [
        'oea cargar documentos' => 'OEA: cargar y reemplazar documentos del cliente',
        'oea ver documentos' => 'OEA: ver y descargar documentos del cliente',
        'oea ver revisiones' => 'OEA: ver los conceptos de las áreas',
        'oea revisar financiero' => 'OEA: emitir concepto Financiero',
        'oea revisar seguridad' => 'OEA: emitir concepto de Seguridad',
        'oea revisar documental' => 'OEA: emitir concepto del Revisor documental',
        'oea decision gerencia' => 'OEA: decisión final de Gerencia',
        'oea configurar' => 'OEA: configurar responsables y documentos',
    ];

    public const DECISIONS = [
        'aprobado' => 'Aprobado',
        'rechazado' => 'Rechazado',
        'devuelto' => 'Devuelto a SAC',
    ];

    // Mientras es true, el observer deja pasar los cambios de estado que hace el propio flujo
    private bool $systemTransition = false;

    public function __construct(private SettingService $settings) {}

    /* ---------------------------------------------------------------- Estados */

    public function leadPipeline(): ?Pipeline
    {
        return Pipeline::where('model', Lead::class)->first();
    }

    public function stageId(string $key): ?int
    {
        $id = $this->settings->get('oea_stage_'.$key);

        return $id ? (int) $id : null;
    }

    public function stageKey(?int $stageId): ?string
    {
        if (! $stageId) {
            return null;
        }

        foreach (array_keys(self::STAGES) as $key) {
            if ($this->stageId($key) === (int) $stageId) {
                return $key;
            }
        }

        return null;
    }

    public function leadStageKey(Lead $lead): ?string
    {
        return $this->stageKey($lead->pipeline_stage_id);
    }

    public function inSystemTransition(): bool
    {
        return $this->systemTransition;
    }

    private function transition(Lead $lead, string $stageKey): void
    {
        $this->systemTransition = true;

        try {
            $lead->update(['pipeline_stage_id' => $this->stageId($stageKey)]);
        } finally {
            $this->systemTransition = false;
        }
    }

    /* ------------------------------------------------------------- Documentos */

    public function documentTypes(): Collection
    {
        return OeaDocumentType::active()->ordered()->get();
    }

    /**
     * Documentos vigentes del prospecto indexados por tipo. Los que apuntan a un archivo
     * borrado desde el CRM se descartan.
     */
    public function leadDocuments(Lead $lead): Collection
    {
        return OeaLeadDocument::with(['file', 'uploader'])
            ->where('lead_id', $lead->id)
            ->get()
            ->filter(fn ($doc) => $doc->file !== null)
            ->keyBy('oea_document_type_id');
    }

    public function missingDocuments(Lead $lead): Collection
    {
        $uploaded = $this->leadDocuments($lead);

        return $this->documentTypes()
            ->where('required', true)
            ->reject(fn ($type) => $uploaded->has($type->id))
            ->values();
    }

    public function canEditDocuments(Lead $lead): bool
    {
        return in_array($this->leadStageKey($lead), self::EDITABLE_STAGES, true);
    }

    /* ----------------------------------------------------------- Responsables */

    public function responsibleId(string $area): ?int
    {
        $id = $this->settings->get('oea_responsable_'.$area);

        return $id ? (int) $id : null;
    }

    public function missingResponsibles(): array
    {
        return collect(self::AREAS)
            ->filter(fn ($area, $key) => ! $this->responsibleId($key) || ! User::find($this->responsibleId($key)))
            ->map(fn ($area) => $area['label'])
            ->values()
            ->all();
    }

    public function dueDays(): ?int
    {
        $days = $this->settings->get('oea_dias_plazo');

        return $days ? (int) $days : null;
    }

    /* ---------------------------------------------------------------- Reglas */

    /**
     * Valida un cambio de estado hecho por un usuario (formulario, tablero kanban o el
     * botón del panel). Lanza ValidationException para que Livewire lo muestre.
     */
    public function guardStageChange(Lead $lead): void
    {
        if ($this->systemTransition) {
            return;
        }

        $from = $lead->exists ? $this->stageKey($lead->getOriginal('pipeline_stage_id')) : null;
        $to = $this->stageKey($lead->pipeline_stage_id);

        if ($from === $to) {
            return;
        }

        if ($from === 'en_revision') {
            $this->fail('La revisión OEA está en curso. El estado lo define el flujo de aprobación.');
        }

        if (in_array($from, ['aprobado', 'rechazado'], true)) {
            $this->fail('Este cliente ya tiene una decisión final de Gerencia.');
        }

        if (in_array($to, self::SYSTEM_STAGES, true)) {
            $this->fail('El estado "'.self::STAGES[$to].'" solo lo asigna Gerencia desde el panel de aprobación OEA.');
        }

        if ($to === 'en_revision') {
            if (! $lead->exists) {
                $this->fail('Primero crea el prospecto y carga los documentos; luego envíalo a revisión.');
            }

            $missing = $this->missingDocuments($lead);
            if ($missing->isNotEmpty()) {
                $this->fail('Faltan documentos obligatorios: '.$missing->pluck('name')->implode(', ').'.');
            }

            $responsibles = $this->missingResponsibles();
            if ($responsibles) {
                $this->fail('Falta configurar el responsable de: '.implode(', ', $responsibles).' (Configuración → Aprobación OEA).');
            }
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['pipeline_stage_id' => $message]);
    }

    /* ------------------------------------------------------------ Revisiones */

    public function currentRound(Lead $lead): int
    {
        return (int) OeaReview::where('lead_id', $lead->id)->max('round');
    }

    public function reviews(Lead $lead, ?int $round = null): Collection
    {
        return OeaReview::with(['assignedUser', 'decidedBy'])
            ->where('lead_id', $lead->id)
            ->when($round, fn ($q) => $q->where('round', $round))
            ->orderBy('round')
            ->orderBy('id')
            ->get();
    }

    /**
     * Abre una nueva ronda: una revisión y una tarea por cada área en paralelo.
     */
    public function startRound(Lead $lead): void
    {
        $round = $this->currentRound($lead) + 1;

        foreach (self::PARALLEL_AREAS as $area) {
            $this->createReview($lead, $round, $area);
        }

        $this->addNote($lead, "Ronda {$round} de revisión iniciada. Se asignaron tareas a Financiero, Seguridad y Revisor documental.");
    }

    private function createReview(Lead $lead, int $round, string $area): OeaReview
    {
        $config = self::AREAS[$area];
        $userId = $this->responsibleId($area);
        $dueDays = $this->dueDays();

        $task = $lead->tasks()->create([
            'name' => "OEA · {$config['label']}: {$lead->title}",
            'description' => $config['task'].'. Emite tu concepto en el panel "Aprobación OEA" del prospecto: '.route('laravel-crm.leads.show', $lead),
            'due_at' => $dueDays ? now()->addDays($dueDays) : null,
            'user_owner_id' => $userId,
            'user_assigned_id' => $userId,
        ]);

        $this->logActivity($lead, $task);

        return OeaReview::create([
            'lead_id' => $lead->id,
            'round' => $round,
            'area' => $area,
            'task_id' => $task->id,
            'user_assigned_id' => $userId,
        ]);
    }

    public function canDecide(User $user, OeaReview $review): bool
    {
        return $review->isPending()
            && $review->round === $this->currentRound($review->lead)
            && $this->leadStageKey($review->lead) === 'en_revision'
            && $user->can(self::AREAS[$review->area]['permission']);
    }

    /**
     * Registra el concepto de un área. Si es el último de las tres áreas crea la tarea de
     * Gerencia; si es Gerencia, cambia el estado final del prospecto.
     */
    public function decide(OeaReview $review, string $decision, ?string $comment, User $user): void
    {
        $allowed = $review->area === 'gerencia' ? ['aprobado', 'rechazado', 'devuelto'] : ['aprobado', 'rechazado'];
        $comment = trim((string) $comment) ?: null;

        if (! in_array($decision, $allowed, true)) {
            throw ValidationException::withMessages(['decision' => 'Decisión no válida.']);
        }

        if ($decision !== 'aprobado' && ! $comment) {
            throw ValidationException::withMessages(['comment' => 'El comentario es obligatorio cuando no se aprueba.']);
        }

        DB::transaction(function () use ($review, $decision, $comment, $user) {
            // Bloqueo de fila: evita que dos clics simultáneos registren dos decisiones
            $review = OeaReview::lockForUpdate()->findOrFail($review->id);

            if (! $this->canDecide($user, $review)) {
                throw ValidationException::withMessages(['decision' => 'Esta revisión ya no está pendiente o no tienes permiso para resolverla.']);
            }

            $review->update([
                'status' => $decision,
                'comment' => $comment,
                'decided_by' => $user->id,
                'decided_at' => now(),
            ]);

            $review->task?->update(['completed_at' => now()]);

            $lead = $review->lead;
            $label = self::AREAS[$review->area]['label'];
            $this->addNote($lead, "{$label}: ".self::DECISIONS[$decision].($comment ? " — {$comment}" : ''));

            if ($review->area !== 'gerencia') {
                $this->createManagementReviewIfReady($lead, $review->round);

                return;
            }

            match ($decision) {
                'aprobado' => $this->transition($lead, 'aprobado'),
                'rechazado' => $this->transition($lead, 'rechazado'),
                'devuelto' => $this->transition($lead, 'requiere_ajustes'),
            };
        });
    }

    private function createManagementReviewIfReady(Lead $lead, int $round): void
    {
        $reviews = OeaReview::where('lead_id', $lead->id)->where('round', $round)->get();

        $parallelDone = $reviews->whereIn('area', self::PARALLEL_AREAS)->every(fn ($r) => ! $r->isPending());

        if ($parallelDone && ! $reviews->contains('area', 'gerencia')) {
            $this->createReview($lead, $round, 'gerencia');
        }
    }

    /* --------------------------------------------------------------- Helpers */

    private function addNote(Lead $lead, string $content): void
    {
        $note = $lead->notes()->create([
            'external_id' => (string) Str::uuid(),
            'content' => '[Aprobación OEA] '.$content,
            'noted_at' => now(),
        ]);

        $this->logActivity($lead, $note);
    }

    // Igual que hace el CRM al crear tareas/notas, para que aparezcan en el timeline del prospecto
    private function logActivity(Lead $lead, $record): void
    {
        $user = auth()->user();

        $lead->activities()->create([
            'causeable_type' => $user?->getMorphClass(),
            'causeable_id' => $user?->id,
            'timelineable_type' => $lead->getMorphClass(),
            'timelineable_id' => $lead->id,
            'recordable_type' => $record->getMorphClass(),
            'recordable_id' => $record->id,
        ]);
    }
}
