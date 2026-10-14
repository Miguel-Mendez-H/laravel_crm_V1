<?php

namespace App\Providers;

use App\Livewire\Oea\LeadPanel;
use App\Livewire\Oea\OeaSettings;
use App\Oea\Observers\LeadOeaObserver;
use App\Oea\OeaWorkflow;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use VentureDrake\LaravelCrm\Models\Lead;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped (una instancia por request) para compartir el estado de transición entre el
        // flujo y el observer, igual que el CRM registra su SettingService
        $this->app->scoped(OeaWorkflow::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Lead::observe(LeadOeaObserver::class);

        Livewire::component('oea-lead-panel', LeadPanel::class);
        Livewire::component('oea-settings', OeaSettings::class);
    }
}
