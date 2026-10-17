<?php

namespace App\Providers;

use App\Models\Configuracao;
use App\WhatsApp\EnviadorWhatsApp;
use App\WhatsApp\MetaWhatsApp;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EnviadorWhatsApp::class, fn () => new MetaWhatsApp(
            (string) Configuracao::get('meta_token'),
            (string) Configuracao::get('meta_phone_number_id'),
            Configuracao::get('meta_api_versao') ?: 'v23.0',
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
