<?php

use App\Http\Controllers\WebhookWhatsAppController;
use Illuminate\Support\Facades\Route;

Route::get('/webhook/whatsapp', [WebhookWhatsAppController::class, 'verificar']);
Route::post('/webhook/whatsapp', [WebhookWhatsAppController::class, 'receber']);
