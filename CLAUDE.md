# CLAUDE.md — Hawala Remittance Management System

Read this file fully at the start of every session. The detailed build plan is in `BUILD.md`.
Work through `BUILD.md` phase by phase. Never skip ahead.

## What this is

A private web system for a small Somali remittance (hawala) company.

- **Sending agents** (Europe) record money customers give them to send to Somalia.
- **Payout agents** (Somalia, grouped in branches like Mogadishu, Hargeisa) see waiting transfers
  for their branch, claim one, pay the receiver (EVC Plus, eDahab, Zaad, Sahal, bank, cash) and
  mark it paid.
- **Admins** top up agent balances, fund payout agents, assign transfers, and see reports.
- The system keeps every balance, calculates fees and commission splits, sends SMS, and reports.

Not a public product. No customer self-service. Users are created by admins only.

## Stack (do not change without asking)

- Laravel 12, PHP 8.3, MySQL 8 / MariaDB 10.6+ (Hostinger shared hosting)
- Blade + Tailwind CSS (Vite) + Alpine.js. **No** Livewire, Inertia, React or Vue.
- Pest for tests.
- Packages: `spatie/laravel-permission`, `spatie/laravel-activitylog`, `spatie/laravel-backup`,
  `spatie/simple-excel`, `barryvdh/laravel-dompdf`, `brick/math`.
- Queue driver: `database`. Cache: `database` or `file`. No Redis (shared hosting).
- English UI only, but **every user-facing string goes through `__()`** so Somali can be added later.

## Branding

The company logo is in the project root (`logo.png` or `logo.svg`). Copy it to
`public/images/logo.*`, use it in the sidebar, login page, receipts and favicon.
Take the primary brand colour from the logo and define it as a Tailwind theme colour
(`brand-50` … `brand-900`). Clean, professional, mobile-first. Agents use phones at the
counter and in the field; admins use desktops. Large touch targets, readable tables that
turn into cards on small screens.

## Non-negotiable money rules

1. **Money is integer cents** (`BIGINT`, column names end in `_cents`). Never floats, never
   `DECIMAL` arithmetic in PHP. Percentages are **basis points** (`INT`, 5% = 500).
   Exchange rates are `DECIMAL(18,8)` and multiplied with `brick/math` `BigDecimal`,
   then rounded to cents with `RoundingMode::HALF_UP`.
2. **All stored amounts are USD.** Non-USD input keeps `original_currency`,
   `original_amount_cents`, `rate`, and the USD result on the record.
3. **Only `App\Services\Ledger\LedgerService` writes journal entries or changes balances.**
   Controllers, jobs and other services call it. Nothing else touches `ledger_accounts.balance_cents`
   or `journal_lines`.
4. **Every journal entry balances**: the signed amounts of its lines sum to exactly 0.
   Positive = debit, negative = credit. The service throws if not balanced.
5. **Ledger rows are insert-only.** No update or delete of `journal_entries` / `journal_lines`,
   ever. Corrections are new reversing entries.
6. **Balances never go below zero** for: send balance, credit owed, commission balance,
   payout balance (payout can be allowed below zero only by setting `payout.allow_negative`,
   default false). Check inside the locked DB transaction, not only in the form.
7. **Every money operation** runs in `DB::transaction()` and locks affected
   `ledger_accounts` rows with `lockForUpdate()` **ordered by id** (avoids deadlocks).
8. **Fee, commission and FX values are snapshotted** on the transfer at creation. Changing
   settings never changes past transfers.
9. **Agent commission shares are rounded down** to the cent; the company gets the remainder.
   Agent shares can never exceed the fee; company share is never negative.
10. **Idempotency**: every money-changing form carries a UUID token; a repeated token
    returns the existing result instead of posting twice.

## Access control rules

- Permissions via `spatie/laravel-permission`, named `module.action` (full list in `BUILD.md`).
- Every permission is enforced **three times**: Blade `@can` (hide button/menu), route
  middleware `can:`, and a Policy / authorize call in the controller (with own/branch/all scope).
- Sidebar menu is built from `config/menu.php`, each item with a permission key.
- Nobody approves their own request (maker ≠ checker).
- Users are deactivated, never deleted. Deactivation blocked while any balance ≠ 0.
- All important actions logged with `spatie/laravel-activitylog` (who, what, when, before/after).

## Settings

Stored in a `settings` table (key → JSON value), read via `App\Support\Settings` with typed
getters and defaults from `config/hawala.php`. Cached, cache cleared on save.
Many customer decisions are still pending — they are **settings with defaults**, not hard-coded.

## Code conventions

- Thin controllers → Form Requests for validation → Action / Service classes for logic.
- Enums (PHP 8.1 backed enums) for statuses, account types, request kinds, commission types.
- `App\Support\Money` helper for formatting cents (`$1,234.56`) and parsing input.
- Timestamps stored UTC; displayed in `Africa/Mogadishu` (setting `general.timezone`).
- Reusable Blade components in `resources/views/components` (button, card, table, badge,
  money, form fields, modal, empty state, stat card).
- Every list page: search, filters, pagination, and export where a report exists.
- No N+1: eager load. Indexes on status, branch_id, user_id foreign keys, created_at.

## Testing rules

- Write the Pest test **before or with** each money feature. A phase is not done until
  `php artisan test` is fully green.
- Every ledger scenario in `BUILD.md` §4 has a test asserting exact balances and that
  sum of all lines = 0.
- `php artisan hawala:verify-ledger` must report zero problems on seeded demo data.

## Hosting constraints (Hostinger shared)

- No long-running processes, no Supervisor, no Redis, no Node on the server.
- Assets are built locally (`npm run build`) and `public/build` is committed/uploaded.
- One cron: `* * * * * php artisan schedule:run`. Queue is processed by the scheduler with
  `queue:work --stop-when-empty --max-time=50`.
- Do not use features that need `SUPER` MySQL privileges (e.g. triggers may be unavailable).

## How to work

- Follow `BUILD.md` phases in order. At the end of each phase: run tests, run Pint,
  tick the checkboxes in `BUILD.md`, commit with message `phase N: <summary>`,
  then stop and give me a short summary and anything you need me to decide.
- If something in `BUILD.md` is ambiguous or contradicts this file, **ask before coding**.
  This file wins on money rules.
- Do not add features that are listed under "Out of scope" in `BUILD.md`.
- Keep answers short. Show me what changed, not the whole file.
