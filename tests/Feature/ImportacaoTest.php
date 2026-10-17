<?php

namespace Tests\Feature;

use App\Models\Cobranca;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ImportacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function csv(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cobrancas.csv', implode("\n", [
            'Nome;Telefone;Valor;Vencimento;Link Pagamento',
            'Maria;(11) 99999-8888;1.234,56;23/10/2026;https://pag.exemplo/1',
            'João;(21) 98888-7777;99,90;2026-10-25;https://pag.exemplo/2',
            'Sem Telefone;123;10,00;23/10/2026;https://pag.exemplo/3',
            ';;;;',
        ]));
    }

    public function test_previa_mostra_erros_e_confirmar_grava_so_validas(): void
    {
        $this->post('/importar', ['arquivo' => $this->csv()])
            ->assertOk()
            ->assertSee('2 linha(s) válida(s)')
            ->assertSee('telefone inválido')
            ->assertSessionHas('importacao', fn ($linhas) => count($linhas) === 2);

        $this->assertSame(0, Cobranca::count());

        $this->post('/importar/confirmar')
            ->assertRedirect(route('cobrancas.index'))
            ->assertSessionHas('ok', 'Importação concluída: 2 cobrança(s) nova(s), 0 já existente(s).');

        $maria = Cobranca::whereHas('cliente', fn ($q) => $q->where('telefone', '+5511999998888'))->sole();
        $this->assertSame('1234.56', $maria->valor);
        $this->assertSame('2026-10-23', $maria->vencimento->toDateString());
    }

    public function test_importar_de_novo_nao_duplica(): void
    {
        $this->post('/importar', ['arquivo' => $this->csv()]);
        $this->post('/importar/confirmar');
        $this->post('/importar', ['arquivo' => $this->csv()]);

        $this->post('/importar/confirmar')
            ->assertSessionHas('ok', 'Importação concluída: 0 cobrança(s) nova(s), 2 já existente(s).');

        $this->assertSame(2, Cobranca::count());
    }

    public function test_importa_xlsx(): void
    {
        $caminho = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($caminho);
        $writer->addRow(Row::fromValues(['nome', 'telefone', 'valor', 'vencimento', 'link_pagamento']));
        $writer->addRow(Row::fromValues(['Ana', '11977776666', 150.5, '30/10/2026', 'https://pag.exemplo/ana']));
        $writer->close();

        $this->post('/importar', ['arquivo' => new UploadedFile($caminho, 'cobrancas.xlsx', null, null, true)])
            ->assertOk()
            ->assertSee('1 linha(s) válida(s)');

        $this->post('/importar/confirmar');

        $this->assertSame('150.50', Cobranca::sole()->valor);
    }

    public function test_recusa_formato_desconhecido(): void
    {
        $this->post('/importar', ['arquivo' => UploadedFile::fake()->create('foto.png', 10)])
            ->assertSessionHasErrors('arquivo');
    }
}
