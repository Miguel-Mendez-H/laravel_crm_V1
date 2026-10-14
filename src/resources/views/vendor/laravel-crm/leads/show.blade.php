{{-- Override de laravel-crm::leads.show: agrega el panel de Aprobación OEA debajo de la ficha --}}
<x-crm::app-layout title="{{ ucfirst(__('laravel-crm::lang.leads')) }}">
    <livewire:crm-lead-show :lead="$lead" />
    <livewire:oea-lead-panel :lead="$lead" />
</x-crm::app-layout>
