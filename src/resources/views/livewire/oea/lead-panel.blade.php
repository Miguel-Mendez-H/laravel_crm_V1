@php
    $badge = fn ($status) => match ($status) {
        'aprobado' => 'badge-success text-white',
        'rechazado' => 'badge-error text-white',
        'devuelto' => 'badge-warning',
        default => 'badge-ghost',
    };
    $statusLabel = fn ($status) => $decisions[$status] ?? 'Pendiente';
    $managementReview = $currentReviews->get('gerencia');
    $step = match (true) {
        in_array($stageKey, ['aprobado', 'rechazado']) => 4,
        $managementReview !== null => 3,
        $stageKey === 'en_revision' => 2,
        default => 1,
    };
@endphp

<div class="crm-content mt-5">
    <x-mary-card title="Aprobación OEA" shadow separator>
        <x-slot:menu>
            @if($stageName)
                <x-mary-badge :value="$stageName" @class([
                    'badge-success text-white' => $stageKey === 'aprobado',
                    'badge-error text-white' => $stageKey === 'rechazado',
                    'badge-warning' => $stageKey === 'requiere_ajustes',
                    'badge-info text-white' => $stageKey === 'en_revision',
                    'badge-neutral text-white' => in_array($stageKey, [null, 'nuevo']),
                ]) />
            @endif
            @can('oea configurar')
                <x-mary-button icon="o-cog-6-tooth" link="{{ route('oea.settings') }}" class="btn-sm btn-ghost" tooltip-left="Configurar responsables y documentos" />
            @endcan
        </x-slot:menu>

        <ul class="steps w-full mb-5">
            <li @class(['step', 'step-primary' => $step >= 1])>Documentos (SAC)</li>
            <li @class(['step', 'step-primary' => $step >= 2])>Revisión de áreas</li>
            <li @class(['step', 'step-primary' => $step >= 3])>Gerencia</li>
            <li @class(['step', 'step-primary' => $step >= 4])>Decisión final</li>
        </ul>

        @if($stageKey === 'requiere_ajustes' && $managementReview?->comment)
            <x-mary-alert icon="o-exclamation-triangle" class="alert-warning mb-5">
                <strong>Gerencia devolvió el cliente para ajustes:</strong> {{ $managementReview->comment }}
            </x-mary-alert>
        @endif

        {{-- DOCUMENTOS --}}
        <div class="flex flex-wrap justify-between items-center gap-2 mb-2">
            <h3 class="font-semibold">
                Documentos
                <span class="text-sm opacity-70">({{ $documents->count() }} cargados, {{ $missing->count() }} obligatorios pendientes)</span>
            </h3>
            @if($canSend)
                <x-mary-button
                    label="Enviar a revisión"
                    icon="o-paper-airplane"
                    wire:click="sendToReview"
                    wire:confirm="¿Enviar el cliente a revisión? Se crearán las tareas para Financiero, Seguridad y Revisor documental, y los documentos quedarán bloqueados."
                    class="btn-sm btn-primary text-white"
                    :disabled="$missing->isNotEmpty()"
                    spinner />
            @endif
        </div>

        @if($types->isEmpty())
            <x-mary-alert icon="o-information-circle" class="alert-info">
                No hay documentos configurados.
                @can('oea configurar')
                    <a href="{{ route('oea.settings') }}" class="link link-primary">Configúralos en Aprobación OEA</a>.
                @endcan
            </x-mary-alert>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm w-full">
                    <thead>
                        <tr>
                            <th>Documento</th>
                            <th>Archivo</th>
                            @if($canUpload)<th class="text-right">Cargar</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($types as $type)
                            @php($doc = $documents->get($type->id))
                            <tr wire:key="doc-{{ $type->id }}">
                                <td>
                                    @if($doc)
                                        <x-mary-icon name="o-check-circle" class="w-5 h-5 text-success" />
                                    @elseif($type->required)
                                        <x-mary-icon name="o-x-circle" class="w-5 h-5 text-error" />
                                    @else
                                        <x-mary-icon name="o-minus-circle" class="w-5 h-5 opacity-60" />
                                    @endif
                                    {{ $type->name }}
                                    @unless($type->required)<span class="text-xs opacity-70">(opcional)</span>@endunless
                                    @if($type->description)<div class="text-xs opacity-70">{{ $type->description }}</div>@endif
                                </td>
                                <td class="text-sm">
                                    @if($doc)
                                        @if($canViewDocuments)
                                            <a href="#" wire:click.prevent="download({{ $doc->id }})" class="link link-hover link-primary break-all">{{ $doc->file->name }}</a>
                                        @else
                                            <span class="break-all">{{ $doc->file->name }}</span>
                                        @endif
                                        <div class="text-xs opacity-70">{{ $doc->uploader?->name }} · {{ $doc->updated_at->format('d/m/Y H:i') }}</div>
                                    @else
                                        <span class="opacity-60">Sin cargar</span>
                                    @endif
                                </td>
                                @if($canUpload)
                                    <td class="text-right">
                                        <div class="flex justify-end items-center gap-2">
                                            <input type="file" wire:model="uploads.{{ $type->id }}" class="file-input file-input-bordered w-full max-w-xs" />
                                            <x-mary-button
                                                :label="$doc ? 'Reemplazar' : 'Subir'"
                                                wire:click="uploadDocument({{ $type->id }})"
                                                class="btn-sm btn-outline"
                                                spinner="uploadDocument({{ $type->id }})" />
                                        </div>
                                        @error('uploads.'.$type->id)<div class="text-xs text-error mt-2">{{ $message }}</div>@enderror
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- REVISIONES --}}
        @if($round > 0 && ($canViewReviews || $decidable->isNotEmpty()))
            <div class="divider"></div>
            <h3 class="font-semibold mb-2">Revisión @if($round > 1)<span class="text-sm opacity-70">(ronda {{ $round }})</span>@endif</h3>

            <div class="grid lg:grid-cols-2 gap-3">
                @foreach($areas as $areaKey => $area)
                    @php($review = $currentReviews->get($areaKey))
                    <div class="border border-base-300 rounded-box p-3" wire:key="review-{{ $areaKey }}-{{ $round }}">
                        <div class="flex justify-between items-center gap-2">
                            <div class="min-w-0">
                                <div class="font-semibold">{{ $area['label'] }}</div>
                                <div class="text-xs opacity-70">{{ $area['task'] }}</div>
                            </div>
                            @if($review)
                                <x-mary-badge :value="$statusLabel($review->status)" class="{{ $badge($review->status) }} shrink-0" />
                            @else
                                <x-mary-badge value="En espera" class="badge-ghost shrink-0" />
                            @endif
                        </div>

                        @if($review)
                            <div class="text-xs opacity-70 mt-2">
                                Responsable: {{ $review->assignedUser?->name ?? '—' }}
                                @if($review->decided_at)
                                    · Resuelto por {{ $review->decidedBy?->name }} el {{ $review->decided_at->format('d/m/Y H:i') }}
                                @endif
                            </div>
                            @if($review->comment && ($canViewReviews || $decidable->has($review->id)))
                                <div class="text-sm mt-2 whitespace-pre-line">{{ $review->comment }}</div>
                            @endif

                            @if($decidable->has($review->id))
                                <div class="mt-2">
                                    <textarea wire:model="comments.{{ $review->id }}" rows="2" class="textarea textarea-bordered w-full" placeholder="Comentario (obligatorio si no apruebas)"></textarea>
                                    <div class="flex flex-wrap justify-end gap-2 mt-2">
                                        @if($areaKey === 'gerencia')
                                            <x-mary-button label="Devolver a SAC" wire:click="decide({{ $review->id }}, 'devuelto')" wire:confirm="¿Devolver el cliente a SAC para ajustes?" class="btn-sm btn-warning" spinner />
                                        @endif
                                        <x-mary-button label="Rechazar" wire:click="decide({{ $review->id }}, 'rechazado')" wire:confirm="{{ $areaKey === 'gerencia' ? '¿Rechazar definitivamente el cliente?' : '¿Registrar concepto RECHAZADO?' }}" class="btn-sm btn-error text-white" spinner />
                                        <x-mary-button label="Aprobar" wire:click="decide({{ $review->id }}, 'aprobado')" wire:confirm="{{ $areaKey === 'gerencia' ? '¿Aprobar el cliente?' : '¿Registrar concepto APROBADO?' }}" class="btn-sm btn-success text-white" spinner />
                                    </div>
                                </div>
                            @endif
                        @elseif($areaKey === 'gerencia')
                            <div class="text-xs opacity-70 mt-2">Se asigna cuando las tres áreas emitan su concepto.</div>
                        @endif
                    </div>
                @endforeach
            </div>

            @if($canViewReviews && $previousRounds->isNotEmpty())
                <div class="collapse collapse-arrow bg-base-200 mt-5">
                    <input type="checkbox" />
                    <div class="collapse-title text-sm font-semibold">Rondas anteriores ({{ $previousRounds->count() }})</div>
                    <div class="collapse-content">
                        @foreach($previousRounds as $number => $roundReviews)
                            <div class="font-semibold text-sm mt-2">Ronda {{ $number }}</div>
                            <table class="table table-sm w-full">
                                <tbody>
                                    @foreach($roundReviews as $review)
                                        <tr>
                                            <td>{{ $areas[$review->area]['label'] }}</td>
                                            <td><x-mary-badge :value="$statusLabel($review->status)" class="{{ $badge($review->status) }} badge-sm" /></td>
                                            <td class="text-sm">{{ $review->comment }}</td>
                                            <td class="text-xs opacity-70">{{ $review->decidedBy?->name }} {{ $review->decided_at?->format('d/m/Y H:i') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </x-mary-card>
</div>
