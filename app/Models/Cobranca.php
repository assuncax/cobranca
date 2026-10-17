<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cobranca extends Model
{
    use HasFactory;

    protected $fillable = ['cliente_id', 'valor', 'vencimento', 'link_pagamento', 'status', 'pago_em'];

    protected $casts = [
        'valor' => 'decimal:2',
        'vencimento' => 'immutable_date',
        'pago_em' => 'datetime',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function envios(): HasMany
    {
        return $this->hasMany(Envio::class);
    }

    /**
     * Cria (ou reaproveita) cliente pelo telefone e a cobrança, sem duplicar cliente+vencimento+valor.
     *
     * @param  array{nome: string, telefone: string, valor: string, vencimento: string, link_pagamento: string}  $dados
     */
    public static function registrar(array $dados): self
    {
        $cliente = Cliente::updateOrCreate(['telefone' => $dados['telefone']], ['nome' => $dados['nome']]);

        $existente = static::where('cliente_id', $cliente->id)
            ->whereDate('vencimento', $dados['vencimento'])
            ->where('valor', $dados['valor'])
            ->first();

        return $existente ?? static::create([
            'cliente_id' => $cliente->id,
            'valor' => $dados['valor'],
            'vencimento' => $dados['vencimento'],
            'link_pagamento' => $dados['link_pagamento'],
            'status' => 'aberta',
        ]);
    }
}
