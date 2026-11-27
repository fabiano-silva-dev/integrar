import assert from 'node:assert/strict';
import { test } from 'node:test';
import { identityMatches, webAccessSchema } from '../src/portals/nfse-emissor/WebAccess.js';

const own = {
    mode: 'senha_propria',
    companyDocument: '11222333000181',
    loginDocument: '11222333000181',
    password: 'ficticia',
};
test('credenciais de senha/certificado são exclusivas e o titular próprio deve coincidir', () => {
    assert.equal(webAccessSchema.safeParse(own).success, true);
    assert.equal(
        webAccessSchema.safeParse({ ...own, loginDocument: '52998224725' }).success,
        false,
    );
    assert.equal(webAccessSchema.safeParse({ ...own, mode: 'senha_representante' }).success, false);
    assert.equal(
        webAccessSchema.safeParse({ ...own, certificate: { cert: 'x', key: 'x' } }).success,
        false,
    );
    assert.equal(webAccessSchema.safeParse({ ...own, mode: 'certificado_proprio' }).success, false);
    assert.equal(
        webAccessSchema.safeParse({ ...own, portalUrl: 'https://evil.invalid' }).success,
        false,
    );
});
test('identidade própria exige um único documento no cabeçalho', () => {
    assert.equal(
        identityMatches(
            'Empresa · 11.222.333/0001-81',
            '',
            own.loginDocument,
            own.companyDocument,
            false,
        ),
        true,
    );
    assert.equal(
        identityMatches(
            'Outra · 529.982.247-25',
            '',
            own.loginDocument,
            own.companyDocument,
            false,
        ),
        false,
    );
    assert.equal(
        identityMatches(
            '529.982.247-25 e 11.222.333/0001-81',
            '',
            own.loginDocument,
            own.companyDocument,
            false,
        ),
        false,
    );
    assert.equal(
        identityMatches(
            '',
            'CPF digitado: 11.222.333/0001-81',
            own.loginDocument,
            own.companyDocument,
            false,
        ),
        false,
    );
});
test('representação exige identificação do representante, representado e aviso de atuação', () => {
    const login = '52998224725';
    const company = own.companyDocument;
    assert.equal(
        identityMatches(
            'Representante: 529.982.247-25; Representado: 11.222.333/0001-81',
            '',
            login,
            company,
            true,
        ),
        true,
    );
    assert.equal(
        identityMatches(
            '52998224725',
            'Atuando em nome de 11.222.333/0001-81',
            login,
            company,
            true,
        ),
        false,
    );
    assert.equal(
        identityMatches('52998224725', 'Últimos acessos: 11222333000181', login, company, true),
        false,
    );
    assert.equal(identityMatches('11222333000181', 'Representado', login, company, true), false);
    assert.equal(
        identityMatches('52998224725', 'Atuando em nome de 11444777000161', login, company, true),
        false,
    );
    assert.equal(
        identityMatches(
            '52998224725 11444777000161',
            'Representado 11222333000181',
            login,
            company,
            true,
        ),
        false,
    );
});
