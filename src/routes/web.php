<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Flujo de aprobación de clientes OEA (mismo stack de middleware que las rutas del CRM)
Route::prefix(config('laravel-crm.route_prefix', 'crm').'/oea')
    ->middleware(['web', 'crm', 'crm-api', 'auth.laravel-crm'])
    ->group(function () {
        Route::view('configuracion', 'oea.settings')
            ->middleware('can:oea configurar')
            ->name('oea.settings');
    });
