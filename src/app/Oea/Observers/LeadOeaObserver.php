<?php

namespace App\Oea\Observers;

use App\Oea\OeaWorkflow;
use VentureDrake\LaravelCrm\Models\Lead;

class LeadOeaObserver
{
    public function __construct(private OeaWorkflow $workflow) {}

    // Cubre el formulario de edición, la creación y el tablero kanban: todos guardan por el modelo
    public function saving(Lead $lead): void
    {
        if ($lead->isDirty('pipeline_stage_id')) {
            $this->workflow->guardStageChange($lead);
        }
    }

    public function updated(Lead $lead): void
    {
        if ($lead->wasChanged('pipeline_stage_id')
            && ! $this->workflow->inSystemTransition()
            && $this->workflow->leadStageKey($lead) === 'en_revision') {
            $this->workflow->startRound($lead);
        }
    }
}
