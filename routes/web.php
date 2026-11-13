<?php

use App\Http\Controllers\AcreditacionController;
use App\Http\Controllers\Auth\EmpresaAuthController;
use App\Http\Controllers\EmpresaInformacionController;
use App\Http\Controllers\ReglamentoExpositorController;
use Illuminate\Support\Facades\Route;

Route::get('/csrf-token', function () {
    return response()->json([
        'token' => csrf_token(),
    ])->header('Cache-Control', 'no-store, private');
});

Route::post('/login', [EmpresaAuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/logout', [EmpresaAuthController::class, 'logout']);
Route::get('/me', [EmpresaAuthController::class, 'me']);

Route::middleware('auth:empresa')->group(function () {
    Route::get('/empresa/informacion', [EmpresaInformacionController::class, 'show']);
    Route::get('/reglamentos-expositor/eventos', [ReglamentoExpositorController::class, 'eventos']);
    Route::get('/reglamentos-expositor/eventos/{idFeria}', [ReglamentoExpositorController::class, 'index'])->whereNumber('idFeria');
    Route::get('/reglamentos-expositor/{idReglamento}/descargar', [ReglamentoExpositorController::class, 'descargar'])->whereNumber('idReglamento');
    Route::get('/acreditaciones/fotos/{filename}', [AcreditacionController::class, 'mostrarFotoAcreditado'])
        ->where('filename', 'acreditado_[A-Fa-f0-9-]+\.(?:jpg|jpeg|png)')
        ->name('acreditaciones.fotos');
    Route::get('/acreditaciones/eventos', [AcreditacionController::class, 'eventos']);
    Route::get('/acreditaciones/eventos/{idFeria}/resumen', [AcreditacionController::class, 'resumen'])->whereNumber('idFeria');
    Route::get('/acreditaciones/expositores/buscar', [AcreditacionController::class, 'buscarExpositor'])->middleware('throttle:60,1');
    Route::post('/acreditaciones/eventos/{idFeria}/acreditados', [AcreditacionController::class, 'acreditar'])->whereNumber('idFeria');
    Route::get('/acreditaciones/credenciales/{idCredencial}/descargar', [AcreditacionController::class, 'descargarIndividual'])->whereNumber('idCredencial');
    Route::get('/acreditaciones/descargas/{archivo}', [AcreditacionController::class, 'descargarArchivo'])
        ->where('archivo', '[A-Za-z0-9._-]+')
        ->name('acreditaciones.descarga');
    Route::post('/acreditaciones/credenciales/descargar', [AcreditacionController::class, 'descargarSeleccionadas']);
    Route::post('/acreditaciones/credenciales/enviar', [AcreditacionController::class, 'enviarSeleccionadas']);
});

Route::get('{any?}', function () {
    return view('application');
})->where('any', '.*');
