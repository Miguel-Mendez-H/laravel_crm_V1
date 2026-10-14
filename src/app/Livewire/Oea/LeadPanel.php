<?php

namespace App\Livewire\Oea;

use App\Oea\Models\OeaDocumentType;
use App\Oea\Models\OeaLeadDocument;
use App\Oea\Models\OeaReview;
use App\Oea\OeaWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Mary\Traits\Toast;
use VentureDrake\LaravelCrm\Models\Lead;

/**
 * Panel "Aprobación OEA" en la ficha del prospecto: checklist de documentos, envío a
 * revisión y conceptos de cada área.
 */
class LeadPanel extends Component
{
    use Toast, WithFileUploads;

    public Lead $lead;

    // Archivo seleccionado por tipo de documento: [type_id => UploadedFile]
    public array $uploads = [];

    // Comentario escrito por revisión: [review_id => string]
    public array $comments = [];

    public array $allowedMimes = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'zip'];

    public function uploadDocument(int $typeId, OeaWorkflow $workflow): void
    {
        abort_unless(auth()->user()->can('oea cargar documentos'), 403);

        if (! $workflow->canEditDocuments($this->lead)) {
            $this->error('Los documentos no se pueden modificar mientras el cliente está en revisión o tiene decisión final.');

            return;
        }

        $type = OeaDocumentType::active()->findOrFail($typeId);

        $this->validate([
            "uploads.{$typeId}" => ['required', 'file', 'max:10240', 'mimes:'.implode(',', $this->allowedMimes)],
        ], [], ["uploads.{$typeId}" => $type->name]);

        $upload = $this->uploads[$typeId];
        $disk = config('filesystems.default', 'local');

        DB::transaction(function () use ($upload, $disk, $type) {
            // Se guarda como archivo normal del CRM para que también aparezca en la pestaña Archivos
            $file = $this->lead->files()->create([
                'file' => $upload->store('laravel-crm/lead/'.$this->lead->id.'/files', $disk),
                'name' => $upload->getClientOriginalName(),
                'title' => $type->name,
                'filesize' => $upload->getSize(),
                'mime' => $upload->getMimeType(),
                'disk' => $disk,
            ]);

            $this->lead->activities()->create([
                'causeable_type' => auth()->user()->getMorphClass(),
                'causeable_id' => auth()->id(),
                'timelineable_type' => $this->lead->getMorphClass(),
                'timelineable_id' => $this->lead->id,
                'recordable_type' => $file->getMorphClass(),
                'recordable_id' => $file->id,
            ]);

            OeaLeadDocument::updateOrCreate(
                ['lead_id' => $this->lead->id, 'oea_document_type_id' => $type->id],
                ['file_id' => $file->id, 'uploaded_by' => auth()->id()],
            );
        });

        unset($this->uploads[$typeId]);
        $this->dispatch('file-added');
        $this->success("Documento \"{$type->name}\" cargado.");
    }

    public function download(int $documentId)
    {
        abort_unless(auth()->user()->can('oea ver documentos'), 403);

        $document = OeaLeadDocument::with('file')->where('lead_id', $this->lead->id)->findOrFail($documentId);
        abort_unless($document->file, 404);

        return Storage::disk($document->file->disk)->download($document->file->file, $document->file->name);
    }

    public function sendToReview(OeaWorkflow $workflow)
    {
        abort_unless(auth()->user()->can('oea cargar documentos'), 403);

        try {
            $this->lead->update(['pipeline_stage_id' => $workflow->stageId('en_revision')]);
        } catch (ValidationException $e) {
            $this->lead->refresh();
            $this->error(collect($e->errors())->flatten()->first());

            return;
        }

        $this->success('Cliente enviado a revisión. Se asignaron las tareas a las áreas.', redirectTo: route('laravel-crm.leads.show', $this->lead));
    }

    public function decide(int $reviewId, string $decision, OeaWorkflow $workflow)
    {
        $review = OeaReview::where('lead_id', $this->lead->id)->findOrFail($reviewId);

        try {
            $workflow->decide($review, $decision, $this->comments[$reviewId] ?? null, auth()->user());
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first());

            return;
        }

        $this->success('Concepto registrado: '.OeaWorkflow::DECISIONS[$decision].'.', redirectTo: route('laravel-crm.leads.show', $this->lead));
    }

    public function render(OeaWorkflow $workflow)
    {
        $user = auth()->user();
        $round = $workflow->currentRound($this->lead);
        $reviews = $workflow->reviews($this->lead);
        $stageKey = $workflow->leadStageKey($this->lead);

        return view('livewire.oea.lead-panel', [
            'stageKey' => $stageKey,
            'stageName' => $this->lead->pipelineStage?->name,
            'types' => $workflow->documentTypes(),
            'documents' => $workflow->leadDocuments($this->lead),
            'missing' => $workflow->missingDocuments($this->lead),
            'round' => $round,
            'currentReviews' => $reviews->where('round', $round)->keyBy('area'),
            'previousRounds' => $reviews->where('round', '<', $round)->groupBy('round')->sortKeysDesc(),
            'canUpload' => $user->can('oea cargar documentos') && $workflow->canEditDocuments($this->lead),
            'canSend' => $user->can('oea cargar documentos') && in_array($stageKey, [null, 'nuevo', 'requiere_ajustes'], true),
            'canViewDocuments' => $user->can('oea ver documentos'),
            'canViewReviews' => $user->can('oea ver revisiones'),
            'decidable' => $reviews->filter(fn ($r) => $workflow->canDecide($user, $r))->keyBy('id'),
            'areas' => OeaWorkflow::AREAS,
            'decisions' => OeaWorkflow::DECISIONS,
        ]);
    }
}
