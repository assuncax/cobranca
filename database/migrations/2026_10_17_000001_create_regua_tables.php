<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $t) {
            $t->id();
            $t->string('nome');
            $t->string('telefone', 20)->unique();
            $t->timestamps();
        });

        Schema::create('cobrancas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cliente_id')->constrained()->cascadeOnDelete();
            $t->decimal('valor', 12, 2);
            $t->date('vencimento');
            $t->string('link_pagamento', 500);
            $t->string('status', 20)->default('aberta');
            $t->timestamp('pago_em')->nullable();
            $t->timestamps();
            $t->index(['status', 'vencimento']);
        });

        Schema::create('etapas_regua', function (Blueprint $t) {
            $t->id();
            $t->unsignedTinyInteger('ordem');
            $t->integer('dias');
            $t->string('template_meta');
            $t->string('idioma', 10)->default('pt_BR');
            $t->boolean('ativa')->default(true);
            $t->timestamps();
        });

        Schema::create('envios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cobranca_id')->constrained()->cascadeOnDelete();
            $t->foreignId('etapa_regua_id')->constrained('etapas_regua')->cascadeOnDelete();
            $t->boolean('teste')->default(false);
            $t->string('status', 20);
            $t->unsignedTinyInteger('tentativas')->default(0);
            $t->string('meta_message_id')->nullable()->index();
            $t->text('erro')->nullable();
            $t->timestamp('enviado_em')->nullable();
            $t->timestamps();
            $t->unique(['cobranca_id', 'etapa_regua_id', 'teste']);
        });

        Schema::create('mensagens_recebidas', function (Blueprint $t) {
            $t->id();
            $t->string('meta_message_id')->nullable()->unique();
            $t->string('telefone', 20);
            $t->foreignId('cliente_id')->nullable()->constrained()->nullOnDelete();
            $t->text('texto');
            $t->timestamp('recebida_em');
            $t->timestamps();
        });

        Schema::create('configuracoes', function (Blueprint $t) {
            $t->string('chave')->primary();
            $t->text('valor')->nullable();
        });

        $agora = now();
        DB::table('etapas_regua')->insert([
            ['ordem' => 1, 'dias' => -3, 'template_meta' => 'cobranca_lembrete_3dias', 'idioma' => 'pt_BR', 'ativa' => true, 'created_at' => $agora, 'updated_at' => $agora],
            ['ordem' => 2, 'dias' => 0, 'template_meta' => 'cobranca_vence_hoje', 'idioma' => 'pt_BR', 'ativa' => true, 'created_at' => $agora, 'updated_at' => $agora],
            ['ordem' => 3, 'dias' => 3, 'template_meta' => 'cobranca_atraso_3dias', 'idioma' => 'pt_BR', 'ativa' => true, 'created_at' => $agora, 'updated_at' => $agora],
            ['ordem' => 4, 'dias' => 7, 'template_meta' => 'cobranca_atraso_7dias', 'idioma' => 'pt_BR', 'ativa' => true, 'created_at' => $agora, 'updated_at' => $agora],
            ['ordem' => 5, 'dias' => 15, 'template_meta' => 'cobranca_ultimo_aviso', 'idioma' => 'pt_BR', 'ativa' => true, 'created_at' => $agora, 'updated_at' => $agora],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes');
        Schema::dropIfExists('mensagens_recebidas');
        Schema::dropIfExists('envios');
        Schema::dropIfExists('etapas_regua');
        Schema::dropIfExists('cobrancas');
        Schema::dropIfExists('clientes');
    }
};
