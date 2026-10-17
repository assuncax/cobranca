<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MensagemRecebida extends Model
{
    protected $table = 'mensagens_recebidas';

    protected $fillable = ['telefone', 'cliente_id', 'texto', 'recebida_em', 'meta_message_id'];

    protected $casts = ['recebida_em' => 'datetime'];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
