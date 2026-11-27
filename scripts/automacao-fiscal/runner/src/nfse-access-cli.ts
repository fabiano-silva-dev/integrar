import {
    testWebAccess,
    webAccessSchema,
    type WebAccessResult,
} from './portals/nfse-emissor/WebAccess.js';

// Entrada somente por pipe privado do processo pai. Nunca usa argv, logs ou arquivos de credenciais.
const unavailable: WebAccessResult = {
    code: 'UNAVAILABLE',
    identityConfirmed: false,
    representationConfirmed: false,
};
const deadline = setTimeout(() => {
    process.stdout.write(JSON.stringify(unavailable));
    process.exit(1);
}, 15_000);
deadline.unref();
try {
    let content = '';
    for await (const chunk of process.stdin) {
        content += String(chunk);
        if (Buffer.byteLength(content) > 150_000) throw new Error('INVALID_INPUT');
    }
    const input = webAccessSchema.parse(JSON.parse(content));
    content = '';
    clearTimeout(deadline);
    const result = await testWebAccess(input);
    process.stdout.write(JSON.stringify(result));
} catch {
    process.stdout.write(JSON.stringify(unavailable));
    process.exitCode = 1;
} finally {
    clearTimeout(deadline);
}
