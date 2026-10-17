<?php

namespace App\Support;

final class ParserValor
{
    /** Converte valores como "1.234,56", "R$ 99,9" ou 150.5 para "1234.56". */
    public static function converter(mixed $valor): ?string
    {
        if (is_int($valor) || is_float($valor)) {
            $numero = (float) $valor;
        } elseif (is_string($valor)) {
            $texto = str_replace([' ', "\u{00A0}"], '', trim(str_ireplace('R$', '', $valor)));

            if (str_contains($texto, ',')) {
                $texto = str_replace(['.', ','], ['', '.'], $texto);
            } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $texto)) {
                $texto = str_replace('.', '', $texto);
            }

            if (! preg_match('/^\d+(\.\d{1,2})?$/', $texto)) {
                return null;
            }
            $numero = (float) $texto;
        } else {
            return null;
        }

        return $numero > 0 ? number_format($numero, 2, '.', '') : null;
    }
}
