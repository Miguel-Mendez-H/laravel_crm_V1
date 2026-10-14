<div class="crm-content">
    <x-crm-header title="Aprobación OEA" class="mb-5" />

    <div class="grid lg:grid-cols-2 gap-5 items-start">
        <x-mary-card title="Responsables por área" subtitle="Cada área recibe la tarea de revisión asignada a esta persona." shadow separator>
            <x-mary-form wire:submit="saveGeneral">
                @foreach($areas as $areaKey => $area)
                    <x-mary-select
                        :label="$area['label']"
                        :hint="$area['task']"
                        wire:model="responsibles.{{ $areaKey }}"
                        :options="$users"
                        placeholder="— Sin asignar —"
                        placeholder-value="" />
                @endforeach

                <x-mary-input
                    label="Días de plazo por tarea"
                    hint="Opcional. Si lo dejas vacío, las tareas se crean sin fecha de vencimiento."
                    wire:model="dueDays"
                    type="number"
                    min="1" />

                <x-slot:actions>
                    <x-mary-button label="Guardar" type="submit" icon="o-check" class="btn-primary text-white" spinner="saveGeneral" />
                </x-slot:actions>
            </x-mary-form>
        </x-mary-card>

        <x-mary-card title="Documentos requeridos" subtitle="Lista que SAC debe cargar para cada cliente." shadow separator>
            <x-mary-form wire:submit="saveType">
                <x-mary-input label="Nombre del documento" wire:model="name" />
                <x-mary-input label="Descripción (opcional)" wire:model="description" />
                <x-mary-checkbox label="Obligatorio para enviar a revisión" wire:model="required" />
                <x-slot:actions>
                    @if($editingId)
                        <x-mary-button label="Cancelar" wire:click="cancelEdit" class="btn-ghost" />
                    @endif
                    <x-mary-button :label="$editingId ? 'Guardar cambios' : 'Agregar documento'" type="submit" icon="o-plus" class="btn-primary text-white" spinner="saveType" />
                </x-slot:actions>
            </x-mary-form>

            <div class="divider"></div>

            @if($types->isEmpty())
                <div class="text-sm opacity-70">Todavía no hay documentos configurados.</div>
            @else
                <div class="text-sm opacity-70 mb-2">{{ $types->where('active', true)->where('required', true)->count() }} obligatorios activos de {{ $types->count() }} en total.</div>
                <table class="table table-sm w-full">
                    <tbody>
                        @foreach($types as $type)
                            <tr wire:key="type-{{ $type->id }}" @class(['opacity-60' => ! $type->active])>
                                <td class="w-full">
                                    <div class="font-semibold">{{ $type->name }}</div>
                                    @if($type->description)<div class="text-xs opacity-70">{{ $type->description }}</div>@endif
                                    <div class="flex flex-wrap gap-2 mt-2">
                                        @if($type->required)
                                            <x-mary-badge value="Obligatorio" class="badge-sm badge-info text-white" />
                                        @else
                                            <x-mary-badge value="Opcional" class="badge-sm badge-ghost" />
                                        @endif
                                        @unless($type->active)
                                            <x-mary-badge value="Inactivo" class="badge-sm badge-warning" />
                                        @endunless
                                    </div>
                                </td>
                                <td class="text-right">
                                    <div class="flex justify-end gap-2">
                                        <x-mary-button icon="o-arrow-up" wire:click="move({{ $type->id }}, -1)" class="btn-xs btn-ghost" :disabled="$loop->first" />
                                        <x-mary-button icon="o-arrow-down" wire:click="move({{ $type->id }}, 1)" class="btn-xs btn-ghost" :disabled="$loop->last" />
                                        <x-mary-button icon="o-pencil-square" wire:click="editType({{ $type->id }})" class="btn-xs btn-outline" />
                                        <x-mary-button :icon="$type->active ? 'o-eye-slash' : 'o-eye'" wire:click="toggleActive({{ $type->id }})" class="btn-xs btn-outline" :tooltip="$type->active ? 'Desactivar' : 'Activar'" />
                                        <x-mary-button icon="o-trash" wire:click="deleteType({{ $type->id }})" wire:confirm="¿Eliminar este documento del catálogo?" class="btn-xs btn-error text-white" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-mary-card>
    </div>
</div>
