# Runner — Portal Automation

Serviço Node.js 24 + Playwright 1.61.1 para validação de acesso aos portais **e-CAC RS** e **NFS-e Emissor Nacional**.

## Scripts

```bash
npm install
npm run build
npm run start
npm run dev
npm run lint
npm run typecheck
npm test
```

## API

- `GET /health`
- `POST /internal/v1/ecac-rs/validate`
- `POST /internal/v1/nfse-emissor/validate`

Ambos exigem `Authorization: Bearer $RUNNER_INTERNAL_TOKEN`.

Body:

```json
{ "runId": "01ARZ3NDEKTSV4RRFFQ69G5FAV", "mode": "fake|discovery|certificate" }
```

Variáveis NFS-e: `NFSE_EMISSOR_MODE`, `NFSE_EMISSOR_ENTRY_URL`, `NFSE_EMISSOR_CERT_ORIGINS` (ex.: `https://certificado.nfse.gov.br`), `NFSE_EMISSOR_ALLOWED_HOST_SUFFIXES`.
