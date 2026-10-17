<?php

namespace App\Support;

final class Telefone
{
    /** Converte um telefone brasileiro em E.164 (+55DDDNUMERO) ou retorna null se inválido. */
    public static function normalizar(string $entrada): ?string
    {
        $digitos = ltrim((string) preg_replace('/\D/', '', $entrada), '0');

        if (strlen($digitos) === 12 || strlen($digitos) === 13) {
            if (! str_starts_with($digitos, '55')) {
                return null;
            }
            $digitos = substr($digitos, 2);
        }

        if (strlen($digitos) !== 10 && strlen($digitos) !== 11) {
            return null;
        }

        if (strlen($digitos) === 11 && $digitos[2] !== '9') {
            return null;
        }

        return '+55'.$digitos;
    }

    /**
     * A Meta às vezes informa celulares antigos sem o 9º dígito.
     *
     * @return list<string>
     */
    public static function variantes(string $e164): array
    {
        $local = substr($e164, 3);

        if (str_starts_with($e164, '+55') && strlen($local) === 10 && in_array($local[2], ['6', '7', '8', '9'], true)) {
            return [$e164, '+55'.substr($local, 0, 2).'9'.substr($local, 2)];
        }

        return [$e164];
    }
}
