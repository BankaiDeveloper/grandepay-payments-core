# GrandePay Payments Core

Servico `Hypervel` para o hot path de pagamentos da GrandePay.

Escopo inicial:

- `cash-in`
- webhooks inbound dos provedores
- processamento financeiro associado
- postbacks outbound

O objetivo deste servico e extrair a parte mais concorrente da API de pagamentos do monolito Laravel, preservando:

- PostgreSQL como source of truth financeiro
- Redis para fila, cache, locks e breaker
- idempotencia, claim atomico e consistencia do ledger

## Status

Projeto criado no WSL e preparado com:

- `Hypervel`
- `hypervel/redis`
- `hyperf/database-pgsql`
- defaults locais para `pgsql + redis`
- healthcheck HTTP
- scaffold inicial para o modulo `PaymentsCore`

## Estrutura

- `app/Http`: adaptadores HTTP e controllers
- `app/PaymentsCore/Application`: casos de uso
- `app/PaymentsCore/Domain`: regras de negocio
- `app/PaymentsCore/Infrastructure`: integracoes com banco, Redis, queue e providers

## Bootstrap no WSL

```bash
cd /mnt/d/repos/grandepay/hypervel-api
composer install
cp .env.example .env
php artisan key:generate
composer test
php artisan start
```

Por padrao o servico sobe em:

```text
http://127.0.0.1:9510
```

## Endpoints iniciais

- `GET /up`
- `GET /api/v1/system/info`

## Configuracao local padrao

- `DB_CONNECTION=pgsql`
- `DB_HOST=127.0.0.1`
- `DB_PORT=5432`
- `DB_DATABASE=grandepay_payments_core`
- `DB_USERNAME=grandepay`
- `DB_PASSWORD=grandepay`
- `CACHE_DRIVER=redis`
- `QUEUE_CONNECTION=redis`

## Proximos passos

1. hardening do provider layer para runtime com corrotina
2. extracao do ingress de webhook
3. migracao do processor inbound
4. migracao do `cash-in`
5. migracao do `postback`
