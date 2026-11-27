import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { after, before, test } from 'node:test';
import { testWebAccess, type WebAccessInput } from '../src/portals/nfse-emissor/WebAccess.js';

const company = '11222333000181';
const representative = '52998224725';
let work = '';
let certificate: { cert: string; key: string };
before(() => {
    work = mkdtempSync('tests/.nfse-access-');
    execFileSync(
        'openssl',
        [
            'req',
            '-x509',
            '-newkey',
            'rsa:2048',
            '-nodes',
            '-keyout',
            `${work}/key.pem`,
            '-out',
            `${work}/cert.pem`,
            '-days',
            '1',
            '-subj',
            '/CN=NfseFixture',
        ],
        { stdio: 'ignore' },
    );
    certificate = {
        cert: readFileSync(`${work}/cert.pem`, 'utf8'),
        key: readFileSync(`${work}/key.pem`, 'utf8'),
    };
});
after(() => {
    if (work) rmSync(work, { recursive: true, force: true });
});

async function fixture(input: WebAccessInput, scenario = 'ok') {
    const posts: string[] = [];
    const result = await testWebAccess(input, async (context) => {
        await context.route('**/*', async (route) => {
            const req = route.request();
            const path = new URL(req.url()).pathname;
            if (req.method() === 'POST') posts.push(path);
            const own = input.loginDocument;
            const initial = scenario === 'wrong_identity' ? '11444777000161' : own;
            let html = `<form method="post" action="/EmissorNacional/Login"><input id="Inscricao" name="Inscricao" placeholder="CPF/CNPJ"><input id="Senha" name="Senha" type="password" placeholder="Senha"><button>Entrar</button></form><a href="https://certificado.nfse.gov.br/EmissorNacional/Certificado">Acesso via certificado digital</a>`;
            if (scenario === 'manual') html += '<p>Digite o código de verificação</p>';
            const authenticated = req.method() === 'POST' || path.endsWith('/Certificado');
            if (authenticated) {
                if (scenario === 'reject') html = '<p>Usuário ou senha incorretos</p>';
                else
                    html = `<header><span id="active">${initial}</span><a href="/EmissorNacional/Logout">Sair</a><a href="#" onclick="document.querySelector('[role=dialog]').style.display='block';return false">Procuração/Representação</a><div class="dropdown-menu" style="display:none">Representações recentes: ${company}</div></header><p id="notice" role="alert"></p><div role="dialog" style="display:none"><input placeholder="CPF/CNPJ"><button onclick="${scenario === 'denied' ? "document.querySelector('#notice').textContent='Procuração inexistente'" : `document.querySelector('#active').textContent='Representante: ${own}; Representado: ${scenario === 'wrong_company' ? '11444777000161' : company}';document.querySelector('#notice').textContent='Atuando em nome de terceiros';this.parentNode.style.display='none'`}">Representar</button></div>`;
            }
            if (scenario === 'hostile_action' && !authenticated)
                html = html.replace(
                    'action="/EmissorNacional/Login"',
                    'action="https://evil.invalid/password"',
                );
            await route.fulfill({ status: 200, contentType: 'text/html', body: html });
        });
    });
    assert.ok(
        posts.every((path) => path === '/EmissorNacional/Login'),
        'nenhuma transmissão fiscal',
    );
    assert.equal(JSON.stringify(result).includes('ficticia'), false);
    return { result, posts };
}

for (const mode of [
    'senha_propria',
    'senha_representante',
    'certificado_proprio',
    'certificado_representante',
] as const) {
    test(`login ${mode} e confirmação do perfil esperado`, async () => {
        const input: WebAccessInput = {
            mode,
            companyDocument: company,
            loginDocument: mode.endsWith('_representante') ? representative : company,
            ...(mode.startsWith('senha_') ? { password: 'ficticia' } : { certificate }),
        };
        const { result } = await fixture(input);
        assert.deepEqual(result, {
            code: 'ACCESS_CONFIRMED',
            identityConfirmed: true,
            representationConfirmed: mode.endsWith('_representante'),
        });
    });
}
const own: WebAccessInput = {
    mode: 'senha_propria',
    companyDocument: company,
    loginDocument: company,
    password: 'ficticia',
};
test('senha recusada não confirma o acesso', async () => {
    assert.equal((await fixture(own, 'reject')).result.code, 'LOGIN_REJECTED');
});
test('outro titular no cabeçalho bloqueia acesso', async () => {
    assert.equal((await fixture(own, 'wrong_identity')).result.code, 'IDENTITY_MISMATCH');
});
test('desafio manual interrompe antes de enviar a senha', async () => {
    const { result, posts } = await fixture(own, 'manual');
    assert.equal(result.code, 'MANUAL_REQUIRED');
    assert.equal(posts.length, 0);
});
test('formulário de outro domínio não recebe credenciais', async () => {
    const { result, posts } = await fixture(own, 'hostile_action');
    assert.equal(result.code, 'LAYOUT_CHANGED');
    assert.equal(posts.length, 0);
});
test('procuração recusada não confirma representação', async () => {
    const input: WebAccessInput = {
        ...own,
        mode: 'senha_representante',
        loginDocument: representative,
    };
    assert.equal((await fixture(input, 'denied')).result.code, 'REPRESENTATION_NOT_CONFIRMED');
});
test('troca para representado diferente não confirma representação', async () => {
    const input: WebAccessInput = {
        ...own,
        mode: 'senha_representante',
        loginDocument: representative,
    };
    assert.equal(
        (await fixture(input, 'wrong_company')).result.code,
        'REPRESENTATION_NOT_CONFIRMED',
    );
});
