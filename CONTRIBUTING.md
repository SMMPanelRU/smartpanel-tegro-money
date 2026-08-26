# Contributing

Thanks for considering a contribution to `smartpanel-tegro-money`. This is a small,
focused payment-gateway module for SmartPanel — please keep changes proportionate
to that scope.

## Development setup

1. You need a working SmartPanel v4.x installation (CodeIgniter 3 HMVC, PHP 7.4+)
   to test against. This repo does not ship the panel itself.
2. Copy the two PHP files from this repo into the corresponding paths inside your
   panel's webroot (see README for exact paths).
3. Run `sql/install.sql` against a **test/staging** copy of the panel database —
   never against production while developing.
4. Configure a tegro.money **sandbox** or low-value test shop for iterating.

## Branch / PR workflow

- Fork the repo, create a feature branch off `main`.
- Keep pull requests small and focused on one change.
- Describe what you tested and how (manual steps are fine — there is no
  automated test harness for this module).
- Open a PR against `main`; a maintainer will review before merge.

## Code style

- Match the existing style: CodeIgniter 3 HMVC conventions, plain PHP 7.4-compatible
  syntax (no PHP 8-only features), no external Composer dependencies.
- Keep comments that explain *why*, especially around the money path — do not
  remove them.
- Do not introduce new files outside `app/modules/**` and `sql/**` unless the
  change genuinely requires it.

## Money-path changes require extra care

Changes to `app/modules/add_funds/controllers/tegro.php` (`create_payment()`,
`ipn()`) or `sql/install.sql` touch real payment flows. These invariants must
never be weakened, and any PR touching them should call this out explicitly in
the description:

- Crediting is gated by the signed `POST /api/order/` re-check to tegro.money's
  API — never by webhook signature alone.
- The claim on `tegro_orders` must remain atomic (a single conditional
  `UPDATE ... WHERE`) so a payment can only be credited once, even under
  concurrent/duplicate webhook delivery.
- The gateway must fail closed: missing Shop ID / Secret Key / API Key must
  keep the method inactive, not silently pass through.
- Internal failures must return HTTP 500 so the provider retries; HTTP 200 is
  reserved for "credited", "already credited", and "not yet paid".
- `tegro_orders` must stay InnoDB. Do not port it to MyISAM — atomic claims
  depend on real row locking, not on the engines used elsewhere by this panel
  build.

If you are not sure whether a change affects these invariants, ask in the PR
description rather than assuming.

## Reporting issues

Use the GitHub issue templates (bug report / feature request). For anything
that looks like a security or money-handling issue, please avoid posting
exploit details in a public issue — describe the impact and open a private
channel with a maintainer first if possible.

## Using Claude Code

This repo ships a `CLAUDE.md` with the SmartPanel payment-plugin contract,
file layout, and the safety invariants above. If you use Claude Code, it will
pick this up automatically and should keep you within the same guardrails.
