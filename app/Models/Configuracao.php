<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Configuracao extends Model
{
    public const CHAVES = [
        'meta_token', 'meta_phone_number_id', 'meta_app_secret', 'meta_verify_token',
        'meta_api_versao', 'email_avisos', 'modo_teste', 'numero_teste',
    ];

    protected $table = 'configuracoes';

    protected $primaryKey = 'chave';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['chave', 'valor'];

    protected $casts = ['valor' => 'encrypted'];

    public static function get(string $chave): ?string
    {
        return static::find($chave)?->valor;
    }

    public static function set(string $chave, ?string $valor): void
    {
        static::updateOrCreate(['chave' => $chave], ['valor' => $valor]);
    }

    public static function modoTeste(): bool
    {
        return static::get('modo_teste') === '1';
    }
}
