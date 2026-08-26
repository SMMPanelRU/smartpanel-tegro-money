# smartpanel-tegro-money

**Stack:** PHP 7.4+, CodeIgniter 3 HMVC (SmartPanel v4.x), MySQL/MariaDB (JSON functions required)

## What

A drop-in Tegro.Money (tegro.money) payment gateway module for SmartPanel, an
SMM-panel engine sold on CodeCanyon. Adds card/SBP balance top-ups. This repo
contains only the plugin — it must be copied into an existing SmartPanel
install, it is not a runnable app on its own.

## Quick Start

```bash
# No install script — this is a manual copy-in plugin, not a standalone app.
# See README.md "Installation" section for the exact steps:
#   1. copy the two PHP files into the panel webroot at matching paths
#   2. run sql/install.sql against the panel DB (backup first)
#   3. configure keys in admin: Settings -> Payments -> "Карты / СБП"
#   4. point the tegro.money shop's notify/success/fail URLs at the panel
#   5. enable the method, do one minimal live top-up, confirm credit
```

There is nothing to `npm install` / `composer install` / build here — no
dependency manifest, no build step, no test runner.

## Architecture

```
app/modules/
  add_funds/controllers/tegro.php              # gateway controller (redirect + IPN webhook)
  admin/views/payments/integrations/tegro.php   # admin key-entry form (partial view)
sql/
  install.sql                                   # creates tegro_orders (InnoDB) + payments row
```

Flow: customer picks the method on `/add_funds` -> `create_payment()` writes a
`tegro_orders` row (pending) + a `general_transaction_logs` row (status 0) ->
redirect to `https://tegro.money/pay/` with an md5-signed query. Tegro later
POSTs to `/tegro_ipn` (mapped by the engine's generic `$route['(:any)_ipn']`
rule — no `routes.php` change needed) -> `ipn()` verifies the webhook
signature (logged only, not a gate), then re-checks the order status via a
separate hmac-sha256-signed `POST https://tegro.money/api/order/` call, and
only that response can trigger the atomic credit.

## Key Files

```
app/modules/add_funds/controllers/tegro.php
  - class tegro extends MX_Controller
  - create_payment($data)  — step 1: validate, insert order+log rows, redirect to Tegro
  - ipn()                  — step 2: webhook handler at /tegro_ipn
  - api_order_status()     — the actual credit gate (signed API re-check)
  - verify_hook_sign()     — webhook signature check (logged, not authoritative)

app/modules/admin/views/payments/integrations/tegro.php
  - Admin form partial: Shop ID / Secret Key / API Key / Environment /
    Currency code / Currency rate, plus inline setup instructions (RU).

sql/install.sql
  - CREATE TABLE IF NOT EXISTS tegro_orders (InnoDB) — order/claim ledger
  - INSERT ... WHERE NOT EXISTS — adds the 'tegro' row to `payments`, disabled,
    empty keys. Idempotent.
```

## SmartPanel Payment-Plugin Contract

Any SmartPanel payment gateway plugin (this one, or a future one you add)
follows this shape:

- A controller class named `<type>` (e.g. `tegro`) in
  `app/modules/add_funds/controllers/<type>.php`, `extends MX_Controller`.
- Constructor loads the matching row from the `payments` table (`type` column)
  and reads its `params.option` JSON blob for gateway config (keys, mode,
  currency, rate) — nothing gateway-specific is hardcoded.
- `create_payment($data)` — called by the panel's add-funds flow. Validates
  input, persists a durable "expected amount" record *before* redirecting the
  customer, then redirects (or returns JSON for AJAX) to the provider's hosted
  payment page.
- `ipn()` — the provider's webhook/notify endpoint, reached via the engine's
  generic `(:any)_ipn` route (so the file must be named to match the payment
  `type`, e.g. `tegro.php` -> `/tegro_ipn`). Must respond with an HTTP status
  the provider understands as "retry" vs "done" (see invariants below).
- An admin form partial at
  `app/modules/admin/views/payments/integrations/<type>.php`, rendered by the
  panel's existing payments-settings screen — this is where the shop owner
  enters keys; no other UI is needed.
- A method row in `payments` (`type`, `name`, params JSON with `option`
  sub-object for gateway keys), inserted by `sql/install.sql`, created
  **disabled** with **empty keys** — the owner turns it on after configuring
  and smoke-testing it.

## Safety Invariants — must never be weakened

These exist because this module handles real money. Any change touching them
needs explicit, careful review (see CONTRIBUTING.md):

1. **API re-check gate.** Crediting is authorized only by a signed
   (hmac-sha256, API Key) server-to-server `POST /api/order/` call to the
   provider, never by webhook signature alone. The webhook signature is still
   verified and logged (useful for detecting spoofing attempts) but is not a
   trust boundary.
2. **Atomic claim.** Exactly one credit per payment is enforced by a single
   conditional `UPDATE tegro_orders SET status='crediting' ... WHERE
   status='pending' OR (status='crediting' AND updated < now-120s)` and
   checking `affected_rows() === 1`. Do not replace this with
   read-then-write logic — that reintroduces the race it prevents.
3. **Fail-closed.** If Shop ID, Secret Key, or API Key is missing, the method
   must refuse to create payments and the webhook must return HTTP 500
   ("gateway not configured"), never silently succeed or silently skip the
   check.
4. **HTTP 500-on-failure retry semantics.** Any internal error must return
   HTTP 500 so the provider retries later. HTTP 200 is reserved for: already
   credited, freshly credited, or legitimately not-yet-paid. Never return 200
   on an error path — that tells the provider to stop retrying a payment that
   was never actually credited.
5. **MyISAM warning.** `tegro_orders` must stay InnoDB — the atomic claim
   depends on real row-level locking. Other panel tables this module touches
   (`general_transaction_logs`, `general_users` in some SmartPanel builds) may
   be MyISAM and cannot be wrapped in a transaction; that is why the claim
   step exists as a standalone conditional UPDATE rather than relying on
   transactional isolation.
6. **No secrets in code or git.** Keys live only in the `payments.params`
   JSON column, entered via the admin UI. Never hardcode or commit real Shop
   ID / Secret Key / API Key values, including in test fixtures or examples.

## Configuration

There is no `.env` — all configuration is entered through the SmartPanel admin
UI (Settings -> Payments -> "Карты / СБП") and stored in the `payments` table.

| Field | Required | Description |
|-------|----------|--------------|
| Shop ID | yes | tegro.money shop identifier |
| Secret Key | yes | signs the redirect form and verifies the webhook |
| API Key | yes | signs the `/api/order/` status re-check (the actual credit gate) |
| Environment | yes | `live` or `sandbox` (`sandbox` adds `test=1` to the signed payload) |
| Currency code | yes | shop's settlement currency, e.g. `RUB` |
| Currency rate | yes | units of shop currency per 1 unit of panel balance currency |

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).
