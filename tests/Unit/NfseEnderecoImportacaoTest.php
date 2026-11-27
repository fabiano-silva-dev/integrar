<?php

namespace Tests\Unit;

use App\Services\Documentos\CredenciaisIaDocumentoService;
use App\Services\Nfse\EnderecoImportacaoNfse;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NfseEnderecoImportacaoTest extends TestCase
{
    private function servico(string $gemini = ''): EnderecoImportacaoNfse
    {
        $credenciais = $this->createMock(CredenciaisIaDocumentoService::class);
        $credenciais->method('credenciais')->with(12)->willReturn(['gemini' => $gemini, 'groq' => '', 'llama_cloud' => '']);

        return new EnderecoImportacaoNfse($credenciais);
    }

    public function test_separa_endereco_integral_preservando_numero_e_complemento(): void
    {
        $r = $this->servico()->local('Rua das Flores, nº 120, apto 21, Edifício Azul, Centro, Santa Maria – RS, CEP 97010-000');
        $this->assertSame('97010000', $r['cep']);
        $this->assertSame('RS', $r['uf']);
        $this->assertSame('Santa Maria', $r['municipio']);
        $this->assertSame('Rua das Flores', $r['logradouro']);
        $this->assertSame('120', $r['numero']);
        $this->assertSame('Centro', $r['bairro']);
        $this->assertSame('apto 21, Edifício Azul', $r['complemento']);
        $this->assertSame('', $r['codigo_municipio_ibge']);
        $this->assertSame('999', $this->servico()->local('Rua 7 de Setembro, 120, Santa Maria – RS, CEP 97010-000', ['numero' => '999'])['numero']);
    }

    public function test_cep_completa_ibge_e_ia_extrai_apenas_trechos_do_texto(): void
    {
        Cache::forget('nfse:cep:v1:97010000');
        config(['documentos.ia.gemini_modelos' => ['gemini-teste']]);
        Http::preventStrayRequests();
        Http::fake([
            'https://viacep.com.br/ws/97010000/json/' => Http::response([
                'cep' => '97010-000', 'localidade' => 'Santa Maria', 'uf' => 'RS', 'ibge' => '4316907',
                'logradouro' => 'Rua do Sol', 'bairro' => 'Centro',
            ]),
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['linhas' => [
                    ['linha' => 2, 'logradouro' => 'Rua do Sol', 'numero' => '84', 'bairro' => 'Centro', 'complemento' => 'fundos'],
                    ['linha' => 3, 'logradouro' => 'Rua inventada', 'numero' => '9876', 'bairro' => 'Inventado', 'complemento' => 'Inexistente'],
                ]])]]]]],
            ]),
        ]);
        $svc = $this->servico('chave-teste');
        $a = 'Rua do Sol 84 fundos, Santa Maria – RS, CEP 97010-000';
        $b = 'Rua do Sol, Santa Maria – RS, CEP 97010-000';
        $r = $svc->completar(12, [
            2 => ['texto' => $a, 'campos' => $svc->local($a)],
            3 => ['texto' => $b, 'campos' => $svc->local($b)],
        ]);
        $this->assertSame('4316907', $r[2]['codigo_municipio_ibge']);
        $this->assertSame('84', $r[2]['numero']);
        $this->assertSame('fundos', $r[2]['complemento']);
        $this->assertSame('Centro', $r[2]['bairro']);
        $this->assertSame('', $r[3]['numero']);
        $this->assertSame('', $r[3]['complemento']);
        $this->assertStringContainsString('IA', $r[2]['origem']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'generativelanguage.googleapis.com')
            && ! str_contains($req->url(), 'chave-teste')
            && str_contains($req->body(), 'Rua do Sol')
            && ! str_contains($req->body(), '52998224725'));
        Cache::forget('nfse:cep:v1:97010000');
    }

    public function test_cep_divergente_nao_substitui_municipio_ou_inventa_ibge(): void
    {
        Cache::forget('nfse:cep:v1:97010000');
        Http::preventStrayRequests();
        Http::fake(['https://viacep.com.br/ws/97010000/json/' => Http::response([
            'cep' => '97010-000', 'localidade' => 'Santa Maria', 'uf' => 'RS', 'ibge' => '4316907',
            'logradouro' => 'Rua do Sol', 'bairro' => 'Centro',
        ])]);
        $svc = $this->servico();
        $texto = 'Rua das Flores, 12, Centro, Florianópolis – SC, CEP 97010-000';
        $r = $svc->completar(12, [2 => ['texto' => $texto, 'campos' => $svc->local($texto)]])[2];
        $this->assertSame('SC', $r['uf']);
        $this->assertSame('', $r['codigo_municipio_ibge']);
        $this->assertStringContainsString('diverge', $r['aviso']);
        Cache::forget('nfse:cep:v1:97010000');
    }
}
