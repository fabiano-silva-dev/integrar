import { chromium, type BrowserContext, type Locator, type Page } from 'playwright';
import { z } from 'zod';

const documentSchema = z.string().regex(/^(?:\d{11}|\d{14})$/);
export const webAccessSchema = z
    .object({
        mode: z.enum([
            'senha_propria',
            'senha_representante',
            'certificado_proprio',
            'certificado_representante',
        ]),
        companyDocument: documentSchema,
        loginDocument: documentSchema,
        password: z.string().min(1).max(256).optional(),
        certificate: z
            .object({ cert: z.string().max(65536), key: z.string().max(65536) })
            .strict()
            .optional(),
    })
    .strict()
    .superRefine((v, ctx) => {
        if (
            v.mode.startsWith('senha_')
                ? !v.password || v.certificate
                : !v.certificate || v.password
        ) {
            ctx.addIssue({ code: 'custom', message: 'Credencial incompatível.' });
        }
        if (v.mode.endsWith('_proprio') || v.mode === 'senha_propria') {
            if (v.companyDocument !== v.loginDocument)
                ctx.addIssue({ code: 'custom', message: 'Titular divergente.' });
        } else if (v.companyDocument === v.loginDocument) {
            ctx.addIssue({ code: 'custom', message: 'Selecione o acesso próprio.' });
        }
    });
export type WebAccessInput = z.infer<typeof webAccessSchema>;
export type WebAccessCode =
    | 'ACCESS_CONFIRMED'
    | 'INVALID_INPUT'
    | 'LOGIN_REJECTED'
    | 'IDENTITY_MISMATCH'
    | 'REPRESENTATION_NOT_CONFIRMED'
    | 'MANUAL_REQUIRED'
    | 'LAYOUT_CHANGED'
    | 'UNAVAILABLE';
export type WebAccessResult = {
    code: WebAccessCode;
    identityConfirmed: boolean;
    representationConfirmed: boolean;
};
const ORIGIN = 'https://www.nfse.gov.br';
const CERT_ORIGIN = 'https://certificado.nfse.gov.br';
const LOGIN = `${ORIGIN}/EmissorNacional/Login`;
const documents = (text: string): string[] => [
    ...new Set(
        (
            text.match(
                /(?<!\d)(?:\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}|\d{3}\.\d{3}\.\d{3}-\d{2}|\d{14}|\d{11})(?!\d)/g,
            ) ?? []
        ).map((v) => v.replace(/\D/g, '')),
    ),
];

// Somente texto de identificação do cabeçalho; nunca histórico, listas ou o CPF digitado no modal.
export function identityMatches(
    header: string,
    warning: string,
    login: string,
    company: string,
    represented: boolean,
): boolean {
    const ids = documents(header);
    if (!represented)
        return (
            ids.length === 1 &&
            ids[0] === login &&
            !/representad[oa]|em nome de|atuando.*terceir/i.test(warning)
        );
    const active = `${header}\n${warning}`;
    const all = documents(active);
    return (
        all.length === 2 &&
        ids.includes(company) &&
        all.includes(login) &&
        all.includes(company) &&
        /representad[oa]|em nome de|atuando.*(?:terceir|representa)|representa[çc][aã]o ativa/i.test(
            active,
        )
    );
}

class AccessFailure extends Error {
    constructor(readonly code: WebAccessCode) {
        super(code);
    }
}
function approvedUrl(raw: string): boolean {
    try {
        const u = new URL(raw);
        return [ORIGIN, CERT_ORIGIN].includes(u.origin) && !u.username && !u.password;
    } catch {
        return false;
    }
}
async function visible(loc: Locator): Promise<Locator | null> {
    for (const item of await loc.all()) if (await item.isVisible()) return item;
    return null;
}
async function manual(page: Page): Promise<void> {
    if (
        (await visible(page.locator('iframe[src*="recaptcha"], iframe[src*="hcaptcha"]'))) ||
        (await visible(
            page.getByText(
                /digite.*(?:captcha|código de verifica)|verifique que.*humano|autentica[çc][aã]o.*(?:dois fatores|duas etapas)/i,
            ),
        ))
    ) {
        throw new AccessFailure('MANUAL_REQUIRED');
    }
}
async function headerIdentity(page: Page): Promise<{ header: string; warning: string }> {
    const header = await page
        .locator('header, nav.navbar, .navbar, [role="banner"]')
        .evaluateAll((nodes) =>
            nodes
                .map((node) => {
                    const clone = node.cloneNode(true) as HTMLElement;
                    clone
                        .querySelectorAll(
                            'form, input, [role="dialog"], .modal, .dropdown-menu, [role="menu"], script, style',
                        )
                        .forEach((n) => n.remove());
                    return clone.textContent ?? '';
                })
                .join('\n'),
        );
    const warning = (
        await page.locator('[role="alert"], .alert-warning, .alert-info').allTextContents()
    ).join('\n');
    return { header, warning };
}
async function assertLogin(page: Page, input: WebAccessInput): Promise<void> {
    const deadline = Date.now() + 20_000;
    while (Date.now() < deadline) {
        if (!approvedUrl(page.url())) throw new AccessFailure('UNAVAILABLE');
        await manual(page);
        const logout =
            (await page
                .getByRole('link', {
                    name: /^(sair|encerrar sessão|logout)$/i,
                    includeHidden: true,
                })
                .or(
                    page.getByRole('button', {
                        name: /^(sair|encerrar sessão|logout)$/i,
                        includeHidden: true,
                    }),
                )
                .count()) > 0 && !(await page.locator('input#Senha').isVisible());
        const { header, warning } = await headerIdentity(page);
        if (
            logout &&
            identityMatches(header, warning, input.loginDocument, input.companyDocument, false)
        )
            return;
        if (logout && documents(header).length) throw new AccessFailure('IDENTITY_MISMATCH');
        if (
            await visible(
                page.getByText(
                    /(?:usu[aá]rio|senha|credenciais).*(?:inv[aá]lid|incorret)|acesso negado|n[aã]o foi poss[ií]vel autenticar/i,
                ),
            )
        )
            throw new AccessFailure('LOGIN_REJECTED');
        await page.waitForTimeout(200);
    }
    throw new AccessFailure('LAYOUT_CHANGED');
}
async function represent(page: Page, input: WebAccessInput): Promise<void> {
    let option = await visible(
        page
            .getByRole('link', { name: /procura[çc][aã]o\s*\/\s*representa[çc][aã]o/i })
            .or(page.getByRole('button', { name: /procura[çc][aã]o\s*\/\s*representa[çc][aã]o/i })),
    );
    if (!option) {
        // Menu de conta documentado pelo Portal Nacional. Falha fechada se houver ambiguidade.
        const toggles = page.locator(
            'header .dropdown-toggle, .navbar .dropdown-toggle, header [aria-haspopup="true"], .navbar [aria-haspopup="true"]',
        );
        const candidates: Locator[] = [];
        for (const toggle of await toggles.all()) {
            if (
                (await toggle.isVisible()) &&
                documents((await toggle.textContent()) ?? '').includes(input.loginDocument)
            )
                candidates.push(toggle);
        }
        if (candidates.length !== 1) throw new AccessFailure('LAYOUT_CHANGED');
        await candidates[0]!.click();
        option = await visible(
            page
                .getByRole('link', { name: /procura[çc][aã]o\s*\/\s*representa[çc][aã]o/i })
                .or(
                    page.getByRole('button', {
                        name: /procura[çc][aã]o\s*\/\s*representa[çc][aã]o/i,
                    }),
                ),
        );
    }
    if (!option) throw new AccessFailure('LAYOUT_CHANGED');
    await option.click();
    const dialog = page
        .locator('[role="dialog"]:visible, .modal.show:visible, .modal.in:visible')
        .first();
    await dialog.waitFor({ state: 'visible', timeout: 8000 });
    const field = dialog
        .getByRole('textbox', { name: /CPF.*CNPJ|CNPJ.*CPF/i })
        .or(dialog.getByPlaceholder(/CPF.*CNPJ|CNPJ.*CPF/i))
        .first();
    await field.fill(input.companyDocument);
    await dialog.getByRole('button', { name: /^Representar$/i }).click();
    const deadline = Date.now() + 15_000;
    while (Date.now() < deadline) {
        await manual(page);
        const { header, warning } = await headerIdentity(page);
        if (
            !(await dialog.isVisible()) &&
            identityMatches(header, warning, input.loginDocument, input.companyDocument, true)
        )
            return;
        if (
            await visible(
                page.getByText(
                    /procura[çc][aã]o.*(?:inexistente|expirada|vencida|inv[aá]lida)|n[aã]o.*(?:possui|encontrad).*procura[çc][aã]o|n[aã]o.*autorizad.*representar/i,
                ),
            )
        )
            break;
        await page.waitForTimeout(200);
    }
    throw new AccessFailure('REPRESENTATION_NOT_CONFIRMED');
}

export async function testWebAccess(
    input: WebAccessInput,
    fixture?: (context: BrowserContext) => Promise<void>,
): Promise<WebAccessResult> {
    const parsed = webAccessSchema.safeParse(input);
    if (!parsed.success)
        return { code: 'INVALID_INPUT', identityConfirmed: false, representationConfirmed: false };
    input = parsed.data;
    const browser = await chromium.launch({ headless: true, args: ['--disable-dev-shm-usage'] });
    const deadline = setTimeout(() => {
        void browser.close();
    }, 75_000);
    deadline.unref();
    try {
        // Sem trace, screenshots, vídeo, storageState ou perfis persistentes.
        const context = await browser.newContext({
            locale: 'pt-BR',
            acceptDownloads: false,
            serviceWorkers: 'block',
            ...(input.certificate
                ? {
                      clientCertificates: [
                          {
                              origin: CERT_ORIGIN,
                              cert: Buffer.from(input.certificate.cert),
                              key: Buffer.from(input.certificate.key),
                          },
                      ],
                  }
                : {}),
        });
        await context.route('**/*', async (route) => {
            const req = route.request();
            const url = new URL(req.url());
            const mutation = !['GET', 'HEAD', 'OPTIONS'].includes(req.method());
            // Nenhuma operação fiscal é admitida, nem por navegação nem por POST incidental.
            const fiscal = /\/(?:nfse|dps|emitir|emissao|cancelar|substituir)(?:\/|$)/i.test(
                url.pathname,
            );
            const authMutation =
                /^\/EmissorNacional\/(?:Login(?:\/|$)|[^/]*(?:Represent|Procurac|Perfil)[^/]*(?:\/|$))/i.test(
                    url.pathname,
                );
            if (!approvedUrl(req.url()) || fiscal || (mutation && !authMutation))
                await route.abort('blockedbyclient');
            else await route.continue();
        });
        if (fixture) await fixture(context);
        let page = await context.newPage();
        page.setDefaultTimeout(8000);
        page.setDefaultNavigationTimeout(20_000);
        await page.goto(LOGIN, { waitUntil: 'domcontentloaded' });
        await manual(page);
        if (input.mode.startsWith('senha_')) {
            const field = page.locator('input#Inscricao[name="Inscricao"]');
            const password = page.locator('input#Senha[name="Senha"][type="password"]');
            // Não preenche credenciais em form alterado ou redirecionado.
            const action = await field.evaluate(
                (el) => (el as HTMLInputElement).form?.action ?? '',
            );
            if (page.url() !== LOGIN || action !== LOGIN) throw new AccessFailure('LAYOUT_CHANGED');
            await field.fill(input.loginDocument);
            await password.fill(input.password!);
            await page.getByRole('button', { name: /^Entrar$/ }).click();
        } else {
            const link = page.getByRole('link', { name: /acesso via certificado digital/i });
            if ((await link.getAttribute('href')) !== `${CERT_ORIGIN}/EmissorNacional/Certificado`)
                throw new AccessFailure('LAYOUT_CHANGED');
            const popup = page.waitForEvent('popup', { timeout: 1500 }).catch(() => null);
            await link.click();
            const newPage = await popup;
            if (newPage) {
                page = newPage;
                page.setDefaultTimeout(8000);
                page.setDefaultNavigationTimeout(20_000);
                await page.waitForLoadState('domcontentloaded');
            }
        }
        await assertLogin(page, input);
        const represented = input.mode.endsWith('_representante');
        if (represented) await represent(page, input);
        return {
            code: 'ACCESS_CONFIRMED',
            identityConfirmed: true,
            representationConfirmed: represented,
        };
    } catch (error) {
        return {
            code: error instanceof AccessFailure ? error.code : 'UNAVAILABLE',
            identityConfirmed: false,
            representationConfirmed: false,
        };
    } finally {
        clearTimeout(deadline);
        await browser.close();
    }
}
