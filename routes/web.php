<?php

use App\Http\Controllers\CobrancaController;
use App\Http\Controllers\ImportacaoController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\WebhookWhatsAppController;
use Illuminate\Support\Facades\Route;

Route::get('/webhook/whatsapp', [WebhookWhatsAppController::class, 'verificar']);
Route::post('/webhook/whatsapp', [WebhookWhatsAppController::class, 'receber']);

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'form'])->name('login');
    Route::post('/login', [LoginController::class, 'entrar'])->middleware('throttle:5,1');
});

Route::post('/logout', [LoginController::class, 'sair'])->name('logout')->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/cobrancas');
    Route::resource('cobrancas', CobrancaController::class)->except(['show', 'destroy']);
    Route::post('/cobrancas/{cobranca}/pagar', [CobrancaController::class, 'pagar'])->name('cobrancas.pagar');
    Route::post('/cobrancas/{cobranca}/cancelar', [CobrancaController::class, 'cancelar'])->name('cobrancas.cancelar');
    Route::get('/importar', [ImportacaoController::class, 'form'])->name('importar.form');
    Route::post('/importar', [ImportacaoController::class, 'previa'])->name('importar.previa');
    Route::post('/importar/confirmar', [ImportacaoController::class, 'confirmar'])->name('importar.confirmar');
});
