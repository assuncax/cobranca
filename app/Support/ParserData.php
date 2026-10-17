<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;

final class ParserData
{
    /** Aceita dd/mm/aaaa, aaaa-mm-dd, DateTime e número de série do Excel. */
    public static function converter(mixed $valor): ?CarbonImmutable
    {
        if ($valor instanceof DateTimeInterface) {
            return CarbonImmutable::instance($valor)->startOfDay();
        }

        if ((is_int($valor) || is_float($valor)) && $valor > 30000 && $valor < 80000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $valor);
        }

        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        foreach (['!d/m/Y', '!Y-m-d'] as $formato) {
            $data = DateTimeImmutable::createFromFormat($formato, trim($valor));
            $erros = DateTimeImmutable::getLastErrors();
            $semAvisos = $erros === false || ($erros['warning_count'] === 0 && $erros['error_count'] === 0);

            if ($data !== false && $semAvisos && (int) $data->format('Y') >= 2000) {
                return CarbonImmutable::instance($data)->startOfDay();
            }
        }

        return null;
    }
}
