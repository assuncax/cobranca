<?php

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
    Route::get('/', fn () => view('layout'));
});
