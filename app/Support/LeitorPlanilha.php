<?php

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use SplFileObject;

final class LeitorPlanilha
{
    /** @return list<array<string, mixed>> linhas indexadas pelo cabeçalho (ex.: link_pagamento) */
    public static function ler(string $caminho, string $extensao): array
    {
        $leitor = match (strtolower($extensao)) {
            'xlsx' => new XlsxReader,
            'csv', 'txt' => new CsvReader(self::opcoesCsv($caminho)),
            default => throw new InvalidArgumentException('Formato não suportado. Use .xlsx ou .csv.'),
        };

        $leitor->open($caminho);
        $cabecalho = null;
        $linhas = [];

        try {
            foreach ($leitor->getSheetIterator() as $aba) {
                foreach ($aba->getRowIterator() as $row) {
                    $valores = $row->toArray();

                    if ($cabecalho === null) {
                        $cabecalho = array_map(fn ($c) => Str::slug((string) $c, '_'), $valores);

                        continue;
                    }

                    if (! array_filter($valores, fn ($v) => $v !== null && $v !== '')) {
                        continue;
                    }

                    $valores = array_pad(array_slice($valores, 0, count($cabecalho)), count($cabecalho), null);
                    $linhas[] = array_combine($cabecalho, $valores);
                }
                break; // só a primeira aba
            }
        } finally {
            $leitor->close();
        }

        return $linhas;
    }

    private static function opcoesCsv(string $caminho): CsvOptions
    {
        $primeiraLinha = (string) (new SplFileObject($caminho))->fgets();

        $opcoes = new CsvOptions;
        $opcoes->FIELD_DELIMITER = substr_count($primeiraLinha, ';') >= substr_count($primeiraLinha, ',') ? ';' : ',';
        $opcoes->ENCODING = mb_check_encoding((string) file_get_contents($caminho), 'UTF-8') ? 'UTF-8' : 'WINDOWS-1252';

        return $opcoes;
    }
}
