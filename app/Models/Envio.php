<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Envio extends Model
{
    protected $fillable = [
        'cobranca_id', 'etapa_regua_id', 'teste', 'status', 'tentativas', 'meta_message_id', 'erro', 'enviado_em',
    ];

    protected $casts = ['teste' => 'boolean', 'tentativas' => 'integer', 'enviado_em' => 'datetime'];

    public function cobranca(): BelongsTo
    {
        return $this->belongsTo(Cobranca::class);
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(EtapaRegua::class, 'etapa_regua_id');
    }
}
