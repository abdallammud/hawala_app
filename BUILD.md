# BUILD.md — Hawala Remittance Management System

Full specification and phased build plan. Read `CLAUDE.md` first; its money rules override
anything here. Tick checkboxes as you finish tasks.

---

## 1. Glossary

| Term | Meaning |
|---|---|
| Sending agent | User in Europe who records transfers. Has **send balance**, **credit owed**, **commission balance**. |
| Payout agent | User in Somalia, belongs to one **branch**. Has **payout balance**, **commission balance**, and **payout accounts**. |
| Admin / Super admin | Staff. Can also have a send balance if they send transfers themselves. Everyone who sends needs balance. |
| Transfer | One remittance: sender → receiver, amount, fee, destination branch. |
| Send balance | How much a sending agent can send. Never < 0. |
| Credit owed | Balance the company gave an agent on trust, not yet paid back. Never < 0. |
| Payout balance | Company money a payout agent holds for paying receivers. Never < 0 (unless setting allows). |
| Commission balance | What an agent earned. Can be withdrawn or moved into send balance. |
| Branch | Name + city. Payout agents belong to one. Transfers are sent to one. |
| Account type | EVC Plus, eDahab, Zaad, Sahal, Premier Bank, Salaam Bank, Cash… Managed by admin. |
| Payout account | A payout agent's account: type + number (e.g. EVC 61xxxxxxx). Used only for reporting which method he paid with. |
| Company account | Where company money sits: "Europe office", "Mogadishu Salaam Bank", "Company EVC"… |
| Uncollected | Transfer sent before the customer paid the agent. Tracked in a register, not the ledger. |

---

## 2. Database schema

All money columns are `BIGINT` cents (`*_cents`). Percentages are `INT` basis points (`*_bp`).
Use foreign keys with `restrictOnDelete()` for anything money-related.

```
branches
  id, name, city, is_active, timestamps

account_types
  id, name, is_active, sort, timestamps

users
  id, name, email (unique), phone, password, type enum(staff, sending_agent, payout_agent),
  branch_id nullable (required for payout_agent), is_active,
  send_commission_type enum(percent, flat, none) nullable,   -- null = use settings default
  send_commission_value int nullable,                          -- bp if percent, cents if flat
  payout_commission_type enum(percent, flat, none) nullable,
  payout_commission_value int nullable,
  max_credit_cents bigint nullable,                            -- null = settings default / no limit
  last_login_at, two_factor fields (optional, admins), timestamps

payout_accounts
  id, user_id, account_type_id, number, label nullable, is_active, timestamps

currencies
  code char(3) PK, name, is_active, manual_rate decimal(18,8) nullable, sort

exchange_rates
  id, currency char(3), rate_to_usd decimal(18,8), source varchar, fetched_at, timestamps
  -- rate_to_usd = how many USD one unit of currency is worth (EUR ≈ 1.08)

company_accounts
  id, name, location enum(europe, somalia, other), is_active, ledger_account_id, timestamps

ledger_accounts
  id, type enum(company, agent_send, agent_credit, agent_payout, agent_commission,
                transfers_payable, fee_income, fx_income, commission_expense,
                bank_charges, adjustments),
  normal_side enum(debit, credit),
  owner_type nullable, owner_id nullable,        -- morph: User or CompanyAccount
  name, balance_cents bigint default 0,          -- cached, in NATURAL sign (see §4)
  timestamps
  unique(type, owner_type, owner_id)

journal_entries
  id, uuid unique, type (enum, see §4), reference_type nullable, reference_id nullable,
  created_by (user_id), approved_by nullable, memo nullable, created_at
  -- no updated_at, no soft deletes

journal_lines
  id, journal_entry_id, ledger_account_id, amount_cents bigint (signed: + debit, − credit),
  created_at
  index(ledger_account_id, created_at)

transfers
  id, code unique (e.g. HW-7K3P9), idempotency_key uuid unique,
  sending_agent_id, branch_id,
  sender_name, sender_phone, receiver_name, receiver_phone,
  receiver_account_type_id nullable, receiver_account_number nullable,
  original_currency char(3), original_amount_cents,          -- what customer is sending, in their currency
  market_rate decimal(18,8), margin_bp int, customer_rate decimal(18,8),
  amount_cents,                                              -- USD the receiver gets
  fee_cents, fx_income_cents, total_debit_cents,             -- total_debit = amount + fee + fx_income
  collect_original_cents,                                    -- total to collect from customer in their currency
  sending_share_cents, payout_share_cents nullable, company_share_cents nullable,
  fee_snapshot json, commission_snapshot json,
  status enum(awaiting_approval, queued, claimed, paid, cancelled, rejected),
  is_large bool,
  claimed_by nullable, claimed_at nullable, claim_expires_at nullable,
  assigned_by nullable,
  paid_by nullable, payout_account_id nullable, payout_reference nullable, paid_at nullable,
  is_uncollected bool default false, collected_cents default 0, fully_collected_at nullable,
  cancelled_by nullable, cancelled_at nullable, cancel_reason nullable, fee_refunded bool nullable,
  note nullable, timestamps
  index(status, branch_id), index(sending_agent_id, created_at), index(receiver_phone)

transfer_collections
  id, transfer_id, amount_cents, currency, original_amount_cents, rate, received_at,
  recorded_by, note nullable, timestamps

cancellation_requests
  id, transfer_id, requested_by, reason, status enum(pending, approved, rejected),
  decided_by nullable, decided_at nullable, timestamps

balance_requests
  id, user_id, kind enum(topup_paid, topup_credit, credit_repayment, payout_funding,
       payout_return, commission_move, commission_withdraw),
  amount_cents, original_currency, original_amount_cents, rate,
  company_account_id nullable, status enum(pending, approved, rejected),
  requested_by, decided_by nullable, decided_at nullable, note nullable, reference nullable,
  journal_entry_id nullable, timestamps

company_transfers
  id, from_company_account_id, to_company_account_id, sent_cents, received_cents,
  charges_cents, reference nullable, transferred_at, created_by, journal_entry_id, timestamps

fee_tiers
  id, from_cents, to_cents nullable (null = no upper limit), rate_bp, flat_cents default 0,
  cap_cents nullable, sort, timestamps

settings
  key varchar PK, value json, updated_by, timestamps

sms_templates
  id, event enum(transfer_sent, transfer_paid, transfer_cancelled, topup_approved,
                 claim_expired, large_transfer), body text,
  to_sender bool, to_receiver bool, to_sending_agent bool, to_payout_agent bool,
  is_active bool, timestamps

sms_logs
  id, to, body, status enum(queued, sent, failed), provider_response text nullable,
  transfer_id nullable, event, timestamps

notifications (Laravel database notifications), activity_log (spatie), jobs, failed_jobs, cache
```

Every user who can send gets an `agent_send`, `agent_credit` and `agent_commission` ledger
account (created lazily on first need via `LedgerService::accountFor($user, $type)`).
Every payout agent gets `agent_payout` and `agent_commission`. System accounts
(`transfers_payable`, `fee_income`, `fx_income`, `commission_expense`, `bank_charges`,
`adjustments`) are created by a seeder, one each.

---

## 3. Settings (`config/hawala.php` defaults)

| Key | Type | Default | Notes |
|---|---|---|---|
| `fees.mode` | enum `whole` / `marginal` | `whole` | See §5 |
| `fees.min_fee_cents` | int | 0 | |
| `fees.refund_on_cancel` | bool | true | **Pending customer answer** |
| `fees.large_transfer_cents` | int | 300000 ($3,000) | Admin notified at or above |
| `fees.hold_large_transfers` | bool | false | If true, status `awaiting_approval` |
| `commission.send_type` | enum percent/flat/none | percent | Default for sending agents |
| `commission.send_value` | int | 5000 (50%) | bp or cents |
| `commission.payout_type` | enum | percent | Default for payout agents |
| `commission.payout_value` | int | 2000 (20% of remaining) | |
| `commission.move_requires_approval` | bool | false | |
| `currency.margin_bp` | int | 0 | **Pending customer answer** |
| `currency.max_rate_age_hours` | int | 72 | Older → block non-USD unless manual rate set |
| `payout.claim_minutes` | int | 30 | |
| `payout.allow_negative` | bool | false | |
| `payout.reference_required` | bool | false | Parked feature, keep the switch |
| `credit.default_max_cents` | int nullable | null | null = no limit |
| `sms.enabled` | bool | false | |
| `sms.driver` | enum `log` / `http` | log | |
| `sms.http` | json | {} | url, method, headers, param mapping, sender id |
| `receipts.enabled` | bool | false | |
| `general.company_name` | string | "Hawala" | |
| `general.timezone` | string | Africa/Mogadishu | |
| `general.duplicate_warning_minutes` | int | 10 | |

Settings UI: one page with tabs (Fees, Commission, Currency, Payout, Credit, SMS, General).
Fee tiers and SMS templates have their own screens.

---

## 4. Ledger specification

### Sign convention
- `journal_lines.amount_cents`: **positive = debit, negative = credit**. Lines of an entry sum to 0.
- `ledger_accounts.balance_cents` is stored in **natural sign**:
  - debit-normal (company, agent_credit, agent_payout, commission_expense, bank_charges, adjustments):
    `balance = sum(lines)`
  - credit-normal (agent_send, agent_commission, transfers_payable, fee_income, fx_income):
    `balance = −sum(lines)`
- So every agent balance shown on screen is a positive number and must stay ≥ 0.

| Account type | Normal side | Shown to user as |
|---|---|---|
| company | debit | Company account balance |
| agent_send | credit | Send balance |
| agent_credit | debit | Credit owed |
| agent_payout | debit | Payout balance |
| agent_commission | credit | Commission balance |
| transfers_payable | credit | Waiting to be paid |
| fee_income, fx_income | credit | Income |
| commission_expense, bank_charges, adjustments | debit | Costs |

### LedgerService API (suggested)
```php
post(JournalType $type, array $lines, ?Model $reference, User $by, ?User $approvedBy = null, ?string $memo = null): JournalEntry
// $lines = [[LedgerAccount $acc, int $signedCents], ...]
// - asserts sum == 0, at least 2 lines, no zero lines
// - locks all accounts (ordered by id), applies deltas to cached balance in natural sign
// - asserts guarded accounts stay >= 0 (agent_send, agent_credit, agent_commission, agent_payout unless setting)
// - throws InsufficientBalanceException with a friendly message on violation
accountFor(User|CompanyAccount $owner, AccountType $type): LedgerAccount
system(AccountType $type): LedgerAccount
```
The service is always called **inside** the caller's `DB::transaction()` so transfer rows and
ledger rows commit together.

### Journal types and entries (Dr = +, Cr = −)

| Journal type | Debit | Credit |
|---|---|---|
| `topup_paid` | company account | agent_send |
| `topup_credit` | agent_credit | agent_send |
| `credit_repayment` | company account | agent_credit |
| `commission_move` | agent_commission | agent_send |
| `commission_withdraw` | agent_commission | company account |
| `transfer_sent` | agent_send (total_debit) | transfers_payable (amount), fee_income (fee), fx_income (fx_income if > 0) |
| `transfer_sent` (same entry) | commission_expense (sending_share) | agent_commission of sender (sending_share) |
| `transfer_paid` | transfers_payable (amount) | agent_payout of payer (amount) |
| `transfer_paid` (same entry) | commission_expense (payout_share) | agent_commission of payer (payout_share) |
| `transfer_cancelled` | exact negation of the `transfer_sent` lines; if `fees.refund_on_cancel` is false, omit the fee_income and fx_income reversal lines (agent_send gets back only `amount`), but **always** reverse the sending commission |
| `transfer_rejected` | same as cancelled with full refund |
| `payout_funding` | agent_payout | company account |
| `payout_return` | company account | agent_payout |
| `company_transfer` | to company account (received), bank_charges (charges) | from company account (sent) |
| `manual_adjustment` | any two accounts chosen by super admin, reason required, maker ≠ checker |

Zero-amount lines are skipped (e.g. sending share 0, fx_income 0).

### Required test scenario ("Ali and Farah")
1. Company account "Europe office" and "Mogadishu bank" exist. Seed Mogadishu bank with $5,000
   via `manual_adjustment` (Dr Mogadishu bank / Cr adjustments).
2. Ali (sending agent, 50% commission) `topup_paid` $1,000 into Europe office.
3. Farah (payout agent, Mogadishu, 20% of remaining) `payout_funding` $1,000 from Mogadishu bank.
4. Ali sends $100 USD. Fee 5% = $5.00. Total debit $105.00.
5. Farah claims and pays.

Assert: Ali send 895.00 · Ali commission 2.50 · Farah payout 900.00 · Farah commission 0.50 ·
transfers_payable 0 · fee_income 5.00 · commission_expense 3.00 · Europe office 1,000.00 ·
Mogadishu bank 4,000.00 · sum of all journal_lines = 0 · transfer company_share 2.00.

Also test: credit top-up $500 → send 1,395 / credit 500; commission move → send 1,397.50 /
commission 0; credit repayment $300 → credit 200, send unchanged; cancel queued transfer with
refund on and off; insufficient balance blocks and posts nothing; two concurrent sends that
together exceed balance → exactly one succeeds.

### `php artisan hawala:verify-ledger`
Checks, reports each failure, exit code 1 on any failure. Scheduled nightly; failures notify super admins.
1. Every journal entry sums to 0.
2. Every account's cached `balance_cents` equals the recomputed natural-sign sum of its lines.
3. Guarded balances are ≥ 0.
4. `transfers_payable` balance = sum of `amount_cents` of transfers in `awaiting_approval`, `queued`, `claimed`.
5. For every transfer: `sending_share + payout_share + company_share = fee` (paid transfers).
6. For every transfer: `total_debit = amount + fee + fx_income`.

---

## 5. Fee engine — `App\Services\FeeCalculator`

Input: `amount_cents` (USD the receiver gets). Output: `fee_cents` + snapshot array.

Tiers: `from_cents <= amount < to_cents` (null `to` = infinity). Validate on save: first tier
from 0, no gaps, no overlaps, exactly one open-ended last tier, rate 0–10000 bp.

**Mode `whole`:** find the containing tier → `fee = round(amount × rate_bp / 10000) + flat`;
if `cap_cents` set → `fee = min(fee, cap)`.

**Mode `marginal`:** for each tier, `slice = min(amount, to) − from` (if > 0) →
`fee += round(slice × rate_bp / 10000)`; add `flat` of the containing tier once.
Caps are ignored in marginal mode (show a hint in the UI).

Then `fee = max(fee, min_fee_cents)`. Rounding HALF_UP to the cent.

Test table (tiers: 0–999.99 @ 5% cap $25 · 1000+ @ 2%):

| Amount | whole (no cap) | whole + cap 25 | marginal |
|---|---|---|---|
| 100.00 | 5.00 | 5.00 | 5.00 |
| 600.00 | 30.00 | 25.00 | 30.00 |
| 999.00 | 49.95 | 25.00 | 49.95 |
| 1,000.00 | 20.00 | 20.00 | 50.00 |
| 1,500.00 | 30.00 | 30.00 | 60.00 |

Default seeded tiers: 0–999.99 @ 5% with cap $25; 1,000+ @ 2%.

Fee tier screen shows a live preview: type an amount, see fee under both modes.

---

## 6. Commission engine — `App\Services\CommissionCalculator`

Rule resolution: user's own `*_commission_type/value` if not null, else settings default.

**Sending share (at send):**
- percent: `floor(fee × bp / 10000)`
- flat: `min(flat_cents, fee)`
- none: 0

**Payout share (at payout, using the payer's rule):** `remaining = fee − sending_share`
- percent: `floor(remaining × bp / 10000)` (percent of remaining, not of fee)
- flat: `min(flat_cents, remaining)`
- none: 0

**Company share** = `fee − sending_share − payout_share` (set at payout; ≥ 0 guaranteed).
Store rule snapshots in `commission_snapshot`. If a flat share was capped, record `capped: true`
in the snapshot and show a small badge on the admin transfer view.

Example: fee 500 → sending 50% = 250 → remaining 250 → payout 20% = 50 → company 200.

---

## 7. Currency and exchange rates

- `App\Services\Rates\RateProvider` interface; `FrankfurterProvider` (primary,
  `https://api.frankfurter.app/latest?from=USD` — verify the current endpoint; newer docs use
  `api.frankfurter.dev/v1`) and `OpenErApiProvider` (fallback, `https://open.er-api.com/v6/latest/USD`).
  Responses give units per USD → store `rate_to_usd = 1 / rate`.
- Scheduled hourly `hawala:fetch-rates`: fetch active currencies, insert into `exchange_rates`.
  On total failure, notify super admins once per day.
- `RateService::current($code)`: USD → 1. If `manual_rate` set → use it. Else latest fetched
  rate if younger than `max_rate_age_hours`, else throw `RateUnavailableException`
  ("Exchange rate for EUR is out of date. Ask admin to set a manual rate.").
- Seed currencies: USD, EUR, GBP, SEK, NOK, DKK, CHF (USD, EUR, GBP, SEK, NOK active).

**Transfer maths** (agent enters `original_amount` in currency C = what the receiver should get, in C):
```
market_rate    = RateService::current(C)
customer_rate  = market_rate × (1 − margin_bp/10000)
amount         = round(original × customer_rate)                 // USD receiver gets
fee            = FeeCalculator(amount)
fee_original   = round(fee / customer_rate)                      // fee in C
collect_orig   = original + fee_original                         // show agent: "Collect €X from customer"
market_value   = round(collect_orig × market_rate)               // USD value of cash collected
fx_income      = margin_bp == 0 ? 0 : max(0, market_value − amount − fee)   // cent rounding never creates income
total_debit    = amount + fee + fx_income
```
For USD, `rate = 1`, `fx_income = 0`. Top-ups and repayments in other currencies convert at
`market_rate` (no margin).

---

## 8. Transfer lifecycle

```
create ─▶ [is_large && hold] awaiting_approval ─approve▶ queued / ─reject▶ rejected (full reversal)
create ─▶ queued ─claim▶ claimed ─mark paid▶ paid
                   ▲        │
                   └────────┘ claim expires (scheduler) or admin unassigns → queued, admins notified
queued ─cancel (sending agent own / admin)▶ cancelled
claimed ─request cancel (sending agent)▶ admin approves → cancelled | rejects → stays claimed
paid  ─ no cancel. Admin `manual_adjustment` only.
```

### Create (`transfers.create`)
Form fields: sender name*, sender phone*, receiver name*, receiver phone*, destination branch*
(auto-selected and hidden if only one active branch), currency* (default USD), amount*,
receiver prefers (optional account type + number), "Customer hasn't paid yet" checkbox, note.
Live preview (Alpine, calls a `POST /transfers/quote` JSON endpoint): USD amount, fee,
"Collect from customer", total from balance, balance after. Confirmation step shows amount in
large text before final submit.

Server side, in one DB transaction:
1. Idempotency: if `idempotency_key` exists → redirect to that transfer.
2. Quote again server-side (never trust client numbers).
3. Duplicate warning: same sending agent + receiver phone + amount within
   `duplicate_warning_minutes` → require an extra "Send anyway" confirmation (not a block).
4. Create transfer, compute sending share, post `transfer_sent` (LedgerService enforces balance).
5. `is_large` if `amount >= large_transfer_cents` → notify admins; if hold setting → `awaiting_approval`.
6. Status `queued` otherwise. Dispatch SMS event `transfer_sent`.
Friendly error on insufficient balance with a "Request top-up" button.

### Queue & payout
- Payout agents see `queued` transfers of **their branch** (and their own `claimed`).
- **Claim**: locks the transfer row; blocked if payout balance < amount (unless allow_negative);
  sets `claimed_by`, `claim_expires_at = now + claim_minutes`.
- **Mark paid**: must be the claimer (or admin acting for him); choose one of his payout
  accounts; reference optional unless `payout.reference_required`; post `transfer_paid`
  with payout share; set company share; SMS `transfer_paid`.
- **Expire**: scheduled every minute `hawala:expire-claims`; also run lazily when the queue page
  loads. Returns to `queued`, clears claim, notifies admins (database notification + optional SMS).
- **Force assign** (`queue.force_assign`): admin picks a payout agent of that branch → becomes
  claimed by him with a fresh timer; notify him. **Unassign** returns to queue.

### Cancel
- Sending agent: own transfers in `queued` → cancel directly (reason required).
- Sending agent: own transfers in `claimed` → creates `cancellation_requests` row; admins notified.
- Admin (`transfers.cancel_any`): any `awaiting_approval`/`queued`/`claimed` transfer.
- Posts `transfer_cancelled` per §4; SMS `transfer_cancelled`.

### Uncollected
- `is_uncollected` set at creation from the checkbox. Nothing different in the ledger.
- "Record payment" (own transfers, or admin): amount + currency (converted at market rate),
  date, note → `transfer_collections`. When collected ≥ `amount + fee` (USD) → `fully_collected_at`.
- Report shows outstanding = `amount + fee − collected`, days open, grouped by customer phone.
- Dashboard card: total uncollected per agent (admin) / own total (agent).

---

## 9. Balance requests & money screens

| Kind | Who requests | Who approves | Needs company account | Entry |
|---|---|---|---|---|
| topup_paid | sending agent / admin on behalf | `balances.approve` (≠ requester) | yes (where money arrived) | `topup_paid` |
| topup_credit | sending agent / admin | `balances.give_credit` (≠ requester) | no | `topup_credit`; blocked if credit owed + amount > max credit |
| credit_repayment | admin records | `balances.record_repayment` | yes | `credit_repayment` |
| payout_funding | payout agent requests or admin direct | `balances.fund_payout` | yes (source) | `payout_funding` |
| payout_return | payout agent / admin | `balances.fund_payout` | yes (destination) | `payout_return` |
| commission_move | sending agent | instant, or approval if setting on | no | `commission_move` |
| commission_withdraw | any agent | `commission.approve_withdraw` | yes (paid from) | `commission_withdraw` |

- Approval screen for `topup_paid` asks explicitly: "Has the money arrived in a company account?"
  If no → suggest converting to credit.
- Admins can create any of these directly (status approved immediately, `requested_by` = admin,
  `decided_by` = admin) — allowed only for kinds where the admin is not the beneficiary.
- Amount can be entered in any active currency; stored in USD with original + rate.
- Requesting user and admins get database notifications on every status change.

Company accounts screen: list with balances, movements (statement), "Move money between
company accounts" form (from, to, sent, received, reference, date) → `company_transfer`
with charges = sent − received (must be ≥ 0).

---

## 10. Roles & permissions

Seed these permissions (`module.action`). Seed roles: **super-admin** (all, via `Gate::before`),
**admin**, **sending-agent**, **payout-agent**. Role editor UI groups checkboxes by module.

```
dashboard.view
transfers.create, transfers.view_own, transfers.view_branch, transfers.view_all,
transfers.cancel_own, transfers.request_cancel, transfers.cancel_any,
transfers.approve_cancel, transfers.approve_large,
transfers.record_collection, transfers.export
queue.view, queue.claim, queue.mark_paid, queue.force_assign, queue.unassign
balances.request, balances.approve, balances.give_credit, balances.record_repayment,
balances.fund_payout, balances.view_all
commission.view_own, commission.view_all, commission.move, commission.approve_move,
commission.request_withdraw, commission.approve_withdraw
company_accounts.view, company_accounts.manage, company_accounts.transfer
users.view, users.create, users.edit, users.deactivate, users.set_commission,
users.manage_payout_accounts, users.set_credit_limit
setup.branches, setup.account_types, setup.currencies, setup.fees, setup.settings,
setup.sms, setup.roles
reports.transfers, reports.agent_statement, reports.uncollected, reports.credit,
reports.commissions, reports.payouts_by_method, reports.branch_summary,
reports.profit, reports.large_transfers, reports.cancellations, reports.company_accounts
audit.view
adjustments.create, adjustments.approve
```

Default role mapping:
- **admin**: everything except `setup.roles`, `setup.settings`, `adjustments.approve`, `audit.view`
  (super admin can change this in the UI).
- **sending-agent**: dashboard.view, transfers.create/view_own/cancel_own/request_cancel/
  record_collection/export, balances.request, commission.view_own/move/request_withdraw,
  reports.transfers, reports.agent_statement, reports.uncollected, reports.commissions.
- **payout-agent**: dashboard.view, transfers.view_branch, queue.view/claim/mark_paid,
  balances.request, commission.view_own/request_withdraw, reports.agent_statement,
  reports.payouts_by_method, reports.commissions.

Scopes: "own" = rows where user is sending agent / payer / requester; "branch" = user's
branch_id; "all" = everything. Implement as query scopes used by policies and reports.

---

## 11. Screens

- **Auth**: login (email or phone + password), forgot password, profile, change password.
  Optional 2FA for staff later — leave a hook, don't build now.
- **Dashboard** (different cards per role):
  - Sending agent: send balance, credit owed, commission, uncollected total, today's transfers,
    "New transfer" big button, recent transfers.
  - Payout agent: payout balance, commission, queue count for branch, my claimed, "Open queue".
  - Admin: money available in Somalia (Somali company accounts + all payout balances), waiting
    to be paid (transfers_payable), today's volume/fees/profit, pending requests, expired claims,
    large transfers, uncollected total, rate status (age of latest rate).
- **Transfers**: list (filters: status, date, agent, branch, currency, uncollected, large),
  create, show (timeline: created, claimed, expired, paid, cancelled with who/when), receipt PDF
  (if enabled).
- **Queue** (payout): branch queue, my claimed with countdown, claim / mark paid modal.
- **Balance requests**: my requests, new request, admin inbox (pending first), approve/reject.
- **Commission**: balance, move to send balance, request withdrawal, history.
- **Company accounts**, **company transfers**.
- **Users**: list, create/edit (type, branch, role, commission overrides, credit limit),
  payout accounts sub-page, deactivate, user statement.
- **Setup**: branches, account types, currencies & manual rates, fee tiers (with preview),
  settings (tabs), SMS templates (with placeholder help and "send test"), roles & permissions.
- **Reports** (§12), **Audit log**, **Notifications** (bell with unread count).

Every screen works at 360 px width. Tables become stacked cards below `md`.

---

## 12. Reports

All: date range + relevant filters, totals row, pagination, CSV/XLSX export (`spatie/simple-excel`),
respect own/branch/all scope.

| Report | Content |
|---|---|
| Transfers | Every transfer: code, date, agents, branch, sender, receiver, currency, original, amount, fee, shares, status |
| Agent statement | Pick agent + balance (send / credit / payout / commission): opening balance, each journal line with description and running balance, closing balance |
| Uncollected | Open uncollected transfers: customer name/phone, amount owed, collected, outstanding, days open; grouped totals per customer and per agent |
| Credit owed | Per agent: credit given, repaid, outstanding, last repayment date |
| Commissions | Per agent: earned, moved, withdrawn, balance |
| Payouts by method | Per account type (and per payout agent): count and total paid |
| Branch summary | Per branch: count/amount queued, claimed, paid, cancelled |
| Profit | Fee income, FX income, commission expense, bank charges, adjustments, net — by day/month |
| Large transfers | Transfers ≥ threshold |
| Cancellations & time-outs | Cancelled transfers (who, why, fee refunded?) and claim expiries per payout agent |
| Company accounts | Balance and movements per company account |

---

## 13. SMS & receipts

- `App\Services\Sms\SmsSender` with drivers `LogDriver` (writes to `sms_logs` + log file) and
  `HttpDriver` (generic: URL, method, headers, body/query mapping with `{to}`, `{message}`,
  `{sender_id}` placeholders — works with most local SMS gateways). Provider not chosen yet.
- Events fire `SendTemplatedSms` queued job. Template placeholders:
  `{code} {sender_name} {sender_phone} {receiver_name} {receiver_phone} {amount_usd} {fee_usd}
  {branch} {date} {agent_name} {company_name}`.
- Seed one sensible English template per event (inactive until `sms.enabled`).
- "Send test SMS" button on the template screen.
- Receipt PDF (`barryvdh/laravel-dompdf`): logo, company name, code, date, sender, receiver,
  branch, amounts, fee, collected-in-currency, agent. Hidden unless `receipts.enabled`.
  Also a "Share via WhatsApp" link with a text summary (no API needed, `https://wa.me/?text=`).

---

## 14. Scheduler (`routes/console.php`)

| Command | Frequency |
|---|---|
| `queue:work --stop-when-empty --max-time=50` | every minute, withoutOverlapping |
| `hawala:expire-claims` | every minute |
| `hawala:fetch-rates` | hourly |
| `hawala:verify-ledger` | daily 02:00 |
| `backup:clean` + `backup:run` | daily 03:00 |
| `activitylog:clean` | monthly (keep 2 years) |

---

## 15. Seeders

- `ProductionSeeder`: system ledger accounts, permissions, 4 roles, currencies, account types
  (EVC Plus, eDahab, Zaad, Sahal, Premier Bank, Salaam Somali Bank, Cash), default fee tiers,
  default settings, SMS templates, one super admin from `.env` (`SUPER_ADMIN_EMAIL`,
  `SUPER_ADMIN_PASSWORD`).
- `DemoSeeder` (local only): branches Mogadishu + Hargeisa, company accounts (Europe office,
  Mogadishu bank, Company EVC), 1 admin, 2 sending agents (London, Stockholm), 3 payout agents
  (2 Mogadishu, 1 Hargeisa) with payout accounts, opening balances via proper ledger entries,
  ~40 transfers across all statuses, a few uncollected. `hawala:verify-ledger` must pass after it.

---

## 16. Out of scope (do NOT build now)

Mobile money APIs (EVC/eDahab/Zaad) · mandatory payout proof (setting switch only) ·
customer self-service app · Somali UI translation (strings must be translatable) ·
KYC / ID / sanctions screening · automatic debt blocking · per-sender limits ·
multi-company / multi-tenant · native mobile app.

---

## 17. Build phases

Stop after each phase: tests green, Pint clean, tick boxes, commit, summarise, wait for approval.

### Phase 0 — Project setup
- [x] Laravel 12 app, PHP 8.3, MySQL `.env.example`, timezone UTC, app locale `en`
- [x] Install packages from `CLAUDE.md`; Pest; Pint
- [x] Tailwind + Alpine via Vite; brand colours from logo; base layout (sidebar + topbar, mobile drawer)
- [x] Blade components: button, input, select, textarea, checkbox, card, stat, table, badge,
      money, modal, alert, empty-state, pagination
- [x] Auth (login by email or phone, logout, forgot/reset, profile, password change) — build
      with plain Blade controllers (Breeze Blade stack acceptable, then restyle)
- [x] `config/hawala.php`, `settings` table, `App\Support\Settings`, `App\Support\Money`
- **Done when:** login works on phone and desktop with the company logo and colours.
  - Verified on desktop widths. Full navigation shell (every Phase 1–7 screen) is wired to
    placeholder "coming soon" pages behind real routes/permission-key stubs, per the request
    to settle the UI before building modules one at a time.

### Phase 1 — Ledger core
- [ ] Migrations: ledger_accounts, journal_entries, journal_lines, company_accounts, users additions
- [ ] Enums: LedgerAccountType, JournalType, NormalSide
- [ ] `LedgerService` (post, accountFor, system) with locking, balance guard, natural-sign cache
- [ ] Exceptions: UnbalancedEntryException, InsufficientBalanceException
- [ ] `hawala:verify-ledger` command
- [ ] Tests: every journal type in §4, Ali & Farah scenario, guards, concurrency (two sends), verify command
- **Done when:** all ledger tests pass without any UI.

### Phase 2 — Setup & access
- [x] spatie/permission, permission + role seeders, `Gate::before` for super-admin, role editor
      UI (Roles tab under Users: create/edit roles, assign permissions grouped by module,
      protects the super-admin role, blocks deleting a role still assigned to users)
- [x] `config/menu.php` + permission-aware sidebar
- [ ] Branches, account types, currencies, company accounts CRUD — branches and account types
      exist as minimal seeded reference data only (`ReferenceDataSeeder`), no CRUD screens yet
- [x] Users CRUD (type, branch, role, commission overrides, credit limit), payout accounts,
      deactivate with balance check (`hasNonZeroBalances()` stubbed false until Phase 1 ledger lands)
- [ ] Settings page (tabs), activity logging on all setup models — activity logging is live on
      User/Branch/AccountType/PayoutAccount; Settings UI not built
- [x] Policies + route middleware for everything above; tests that forbidden users get 403
- **Done when:** super admin can set up the whole company from the UI.
  - Not yet — Users is done; Branches/Account Types/Currencies/Company Accounts/Settings/Roles
    editor are still outstanding within this phase.

### Phase 3 — Balances
- [ ] balance_requests model, form, admin inbox, approve/reject, maker ≠ checker
- [ ] topup_paid, topup_credit (max credit check), credit_repayment, payout_funding,
      payout_return, commission_move, commission_withdraw
- [ ] Currency input on all money forms (USD only until Phase 4 rates exist; design for it)
- [ ] Company account statement + company transfers with charges
- [ ] Manual adjustments (maker ≠ checker)
- [ ] Notifications (database) for request status changes
- [ ] Agent statement page (running balance) for each balance
- [ ] Tests for every kind, approvals, self-approval blocked
- **Done when:** every balance moves correctly through the UI and verify-ledger passes.

### Phase 4 — Rates, fees, commission
- [ ] exchange_rates, providers, `hawala:fetch-rates`, manual override, RateService, stale handling
- [ ] fee_tiers CRUD with validation + live preview, FeeCalculator (both modes, caps, min fee)
- [ ] CommissionCalculator with resolution and caps
- [ ] `POST /transfers/quote` endpoint
- [ ] Tests: fee table in §5, commission examples, FX maths in §7 (margin 0 and margin 1%)
- **Done when:** quote endpoint returns correct numbers for USD and EUR inputs.

### Phase 5 — Transfers & queue
- [ ] transfers, cancellation_requests, transfer_collections migrations + models + code generator
- [ ] Create form with live preview, confirmation step, idempotency, duplicate warning
- [ ] Large transfer alert / hold + approval
- [ ] Branch queue, claim, countdown, mark paid (payout account select, optional reference)
- [ ] `hawala:expire-claims`, lazy expiry, admin notifications, force assign, unassign
- [ ] Cancel (queued), cancellation requests (claimed), admin cancel, fee refund setting
- [ ] Uncollected flag, record payment, fully collected
- [ ] Transfer show page with timeline
- [ ] Tests: full lifecycle, every status transition, guards, expiry, cancellation with refund on/off
- **Done when:** a transfer can go from London to paid in Mogadishu on a phone, and every
  cancel/expiry path leaves the ledger correct.

### Phase 6 — Dashboards & reports
- [ ] Role dashboards (§11)
- [ ] All reports in §12 with filters, totals, scope, exports
- [ ] Audit log viewer
- **Done when:** admin can answer "how much did we make this month and who owes us" in one click each.

### Phase 7 — SMS, receipts, go-live
- [ ] SMS drivers, templates CRUD, events, queued jobs, sms_logs, test send
- [ ] Receipt PDF + WhatsApp share (behind setting)
- [ ] Scheduler entries (§14), backups configured to a remote disk (ask me which)
- [ ] ProductionSeeder + DemoSeeder
- [ ] `DEPLOY.md`: Hostinger steps (below) written for this project
- [ ] Final pass: N+1 check, indexes, 360 px check on every screen, `php artisan test` green
- **Done when:** deployed to Hostinger and a one-week trial can start.

---

## 18. Hostinger deployment notes (for DEPLOY.md)

1. hPanel → set PHP 8.3; enable extensions: bcmath, intl, gd, zip, pdo_mysql, fileinfo.
2. Create MySQL database + user.
3. Upload project **outside** `public_html` (e.g. `~/domains/<domain>/laravel`); build assets
   locally first (`npm ci && npm run build`), include `vendor/` or run `composer install
   --no-dev --optimize-autoloader` over SSH.
4. Point the domain's document root to `laravel/public` if the plan allows; otherwise copy
   `public/*` into `public_html` and fix paths in `public_html/index.php`.
5. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, DB creds, `QUEUE_CONNECTION=database`,
   `CACHE_STORE=database`, `SESSION_DRIVER=database`, mail settings, super admin creds.
6. SSH: `php artisan key:generate`, `migrate --force`, `db:seed --class=ProductionSeeder --force`,
   `storage:link`, `config:cache`, `route:cache`, `view:cache`.
7. Cron (hPanel → Advanced → Cron Jobs), every minute:
   `cd /home/<user>/domains/<domain>/laravel && php artisan schedule:run >> /dev/null 2>&1`
8. Force HTTPS (hPanel SSL). Test backup job runs and lands off-server.
