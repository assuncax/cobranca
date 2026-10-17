<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EtapaRegua extends Model
{
    protected $table = 'etapas_regua';

    protected $fillable = ['ordem', 'dias', 'template_meta', 'idioma', 'ativa'];

    protected $casts = ['dias' => 'integer', 'ordem' => 'integer', 'ativa' => 'boolean'];
}
