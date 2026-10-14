<?php

namespace App\Livewire\Oea;

use App\Models\User;
use App\Oea\Models\OeaDocumentType;
use App\Oea\OeaWorkflow;
use Livewire\Component;
use Mary\Traits\Toast;
use VentureDrake\LaravelCrm\Services\SettingService;

class OeaSettings extends Component
{
    use Toast;

    // [area => user_id]
    public array $responsibles = [];

    public ?int $dueDays = null;

    // Formulario de tipo de documento (crear / editar)
    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public bool $required = true;

    public function mount(OeaWorkflow $workflow): void
    {
        abort_unless(auth()->user()->can('oea configurar'), 403);

        foreach (array_keys(OeaWorkflow::AREAS) as $area) {
            $this->responsibles[$area] = $workflow->responsibleId($area);
        }

        $this->dueDays = $workflow->dueDays();
    }

    public function saveGeneral(SettingService $settings): void
    {
        abort_unless(auth()->user()->can('oea configurar'), 403);

        $this->validate([
            'responsibles.*' => ['nullable', 'integer', 'exists:users,id'],
            'dueDays' => ['nullable', 'integer', 'min:1', 'max:365'],
        ], [], ['dueDays' => 'días de plazo']);

        foreach (OeaWorkflow::AREAS as $area => $config) {
            $settings->set('oea_responsable_'.$area, $this->responsibles[$area] ?: '', 'Responsable OEA: '.$config['label']);
        }

        $settings->set('oea_dias_plazo', $this->dueDays ?: '', 'OEA: días de plazo por tarea');

        $this->success('Configuración OEA guardada.');
    }

    public function saveType(): void
    {
        abort_unless(auth()->user()->can('oea configurar'), 403);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [], ['name' => 'nombre', 'description' => 'descripción']);

        $data = ['name' => $this->name, 'description' => $this->description ?: null, 'required' => $this->required];

        if ($this->editingId) {
            OeaDocumentType::findOrFail($this->editingId)->update($data);
        } else {
            OeaDocumentType::create($data + ['order' => (int) OeaDocumentType::max('order') + 1]);
        }

        $this->success($this->editingId ? 'Documento actualizado.' : 'Documento agregado.');
        $this->cancelEdit();
    }

    public function editType(int $id): void
    {
        $type = OeaDocumentType::findOrFail($id);

        $this->editingId = $type->id;
        $this->name = $type->name;
        $this->description = (string) $type->description;
        $this->required = $type->required;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'name', 'description', 'required');
        $this->resetValidation();
    }

    public function toggleActive(int $id): void
    {
        abort_unless(auth()->user()->can('oea configurar'), 403);

        $type = OeaDocumentType::findOrFail($id);
        $type->update(['active' => ! $type->active]);
    }

    public function move(int $id, int $direction): void
    {
        abort_unless(auth()->user()->can('oea configurar'), 403);

        $types = OeaDocumentType::ordered()->get()->values();
        $index = $types->search(fn ($t) => $t->id === $id);
        $swap = $index + $direction;

        if ($index === false || ! isset($types[$swap])) {
            return;
        }

        // Renumera todo para que el orden quede consistente aunque haya valores repetidos
        [$types[$index], $types[$swap]] = [$types[$swap], $types[$index]];
        $types->each(fn ($type, $i) => $type->update(['order' => $i + 1]));
    }

    public function deleteType(int $id): void
    {
        abort_unless(auth()->user()->can('oea configurar'), 403);

        $type = OeaDocumentType::withCount('leadDocuments')->findOrFail($id);

        // Si ya hay documentos cargados se desactiva en vez de borrar, para no perder trazabilidad
        if ($type->lead_documents_count > 0) {
            $this->error('Este documento ya fue cargado en algún cliente. Desactívalo en lugar de eliminarlo.');

            return;
        }

        $type->delete();
        $this->success('Documento eliminado.');
    }

    public function render()
    {
        return view('livewire.oea.settings', [
            'areas' => OeaWorkflow::AREAS,
            'users' => User::where('crm_access', 1)->orderBy('name')->get(['id', 'name'])->toArray(),
            'types' => OeaDocumentType::ordered()->get(),
        ]);
    }
}
