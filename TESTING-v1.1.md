# Testing guide — v1.1

Walk through this on your own machine before deploying. Tick a box only when
you saw the **expected result**. If something is off, write down: who you were
logged in as, the page URL, what you clicked, what you expected, what happened.
Server errors are in `storage/logs/laravel.log`.

Time needed: about 90 minutes for everything, 20 minutes for the starred (★) parts.

---

## 0. Set up a practice database (do not test on your real one)

Your real local database is `hawala`. Use a separate one so the demo data and
your experiments never mix with it.

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/projects/2026/Hawala

# builds a fresh practice database "hawala_practice" with demo people and money
DB_DATABASE=hawala_practice php artisan migrate:fresh --seed --seeder=DemoSeeder

# run the app against it
DB_DATABASE=hawala_practice php artisan serve
```

Open http://127.0.0.1:8000. If the page shows your **real** data instead, the
override did not reach the server — stop it, put `DB_DATABASE=hawala_practice` in
`.env` for the test session, and change it back afterwards.

To start over at any time, run the `migrate:fresh` line again.

**Demo people** (password for all: `password`):

| Login | Who | Branch | Notes |
|---|---|---|---|
| `admin@bluestar.test` | Super admin (Staff) | Mogadishu | sees everything |
| `ali@bluestar.test` | Sending agent | London | funded send balance |
| `sara@bluestar.test` | Sending agent | Stockholm | funded send balance |
| `farah@bluestar.test` | Payout agent | Mogadishu | funded payout balance |
| `hodan@bluestar.test` | Payout agent | Mogadishu | funded payout balance |
| `idil@bluestar.test` | Payout agent | Hargeisa | funded payout balance |

Company accounts: *Europe Office Cash*, *Mogadishu Bank*, *Company EVC*.

**Use two browsers** (or one normal + one private window) so you can be an agent
and an admin at the same time.

**Two-factor will stop every login the first time** (that is the feature). For
section 1 you want it on. For sections 2–9, to save time, turn it off:
*Setup → Settings → Security → untick "Require two-factor…" → Save*. Turn it
back on at the end (section 10).

---

## 1. ★ Two-factor sign-in (item 1)

You need an authenticator app on your phone (Google Authenticator is fine).
Leave the Security setting **on** for this section.

- [ ] Log in as `admin@bluestar.test`. You land on a **"Protect your account"** page, not the dashboard. Typing `/dashboard` in the address bar sends you back to it.
- [ ] A QR code is shown. Scan it; the app lists "Blue Star" with a 6-digit code that changes every 30 seconds.
- [ ] Type a wrong code → "That code is not right". Type the real one → you are taken to a page of **8 recovery codes**. Print or copy them.
- [ ] Click "I have saved them" → dashboard. Refresh the recovery-codes page address → it does **not** show them again.
- [ ] Log out. Log in again: password first, then a **code page**. Wrong code is refused; the right one gets you in.
- [ ] Use the **same** code a second time right after logging out/in → refused (a code works once). The next code from the app works.
- [ ] Log out, log in, choose **"Use a recovery code"**, enter one → you are in. Try the same recovery code again later → refused. *Profile* now says "7 recovery codes left".
- [ ] Five wrong codes in a row → "Too many attempts…", even the right code is refused for about a minute.
- [ ] Log in with the password, then wait 11 minutes on the code page and submit → sent back to the login page.
- [ ] *Profile → Make new recovery codes* needs your password and shows a new set; an old code no longer works.
- [ ] While 2FA is required, *Profile* has **no** "Turn off" button.

**Admin reset** (use a second user so you do not lock yourself out):

- [ ] Log in as `ali` and set up 2FA on a second authenticator entry (or any phone). Log out.
- [ ] As admin: *Users → Ali → "Reset two-factor"* (confirm). Ali's page says "Not set up yet".
- [ ] Ali logs in with only the password → straight to the setup page again.
- [ ] You cannot reset your own from the Users page (no button on your own record).

**Command-line way back in** (if nobody can log in):

- [ ] `php artisan hawala:reset-two-factor ali@bluestar.test` prints "Two-factor cleared…". A wrong email prints an error.

## 2. ★ Idle logout (item 2)

- [ ] *Setup → Settings → Security*: set "Log out after inactivity" to **2** minutes, save. (Try 999 → refused: it cannot exceed the session length.)
- [ ] Open the dashboard and leave the tab alone. After about **1 minute** an amber bar appears: "You will be signed out in N seconds". Click **Stay signed in** → bar disappears and the clock restarts.
- [ ] Leave it again and do nothing for the full 2 minutes → you land on the login page with "You were signed out because you were inactive…".
- [ ] Log back in, open *New Transfer*, and type slowly in a field for 3 minutes (keep typing/moving). You are **not** logged out, and the form still submits.
- [ ] Set the value to **0** → no bar, no logout, however long you wait.
- [ ] Put it back to **15** when done.

## 3. Users and branches (item 10)

- [ ] *Users → New user*: "Branch" is **required** for every user type (try Staff with none → error).
- [ ] The *User type* list has **Sending & payout agent**. Choosing it shows **both** the send and payout commission boxes.
- [ ] *Setup → Branches*: each branch has "Pays out transfers". Create **Brussels** with it **unticked**.
- [ ] *New Transfer → Destination branch* lists Mogadishu and Hargeisa, **not** Brussels.
- [ ] Every user in *Users* shows a branch (no "—").

## 4. ★ New Transfer: tracking number, locations, customers (items 7, 9, 4, 8)

Log in as `ali@bluestar.test`.

- [ ] *New Transfer* shows a **Tracking number** (like `HW-AB12C`) at the top. Reload the page → the **same** number stays (it only changes after you save).
- [ ] There are two separate boxes: **Sender** (name, phone, country, city, province) and **Receiver** (same). Receiver country starts as **Somalia**.
- [ ] Leave country/city empty and submit → errors on those four. **Province** may stay empty.
- [ ] Create a transfer for a new sender, e.g. *Hodan Nur, +46 70 111 2222, Sweden, Stockholm*, to a receiver in *Somalia / Mogadishu / Banadir*, $100. After saving, the transfer page shows the **same tracking number** you saw, and both locations.
- [ ] **Download receipt** gives a PDF with the tracking number and both locations. "Share via WhatsApp" opens a message with them too.
- [ ] *New Transfer* again, type **"hod"** in *Find an existing customer* → Hodan appears; click her → name, phone, country, city fill in. Typing a number fragment like **"111 22"** also finds her.
- [ ] Send a second transfer, writing her phone differently (e.g. `0046701112222`). *Customers* still shows **one** Hodan with 2 transfers.
- [ ] *Customers → Hodan*: totals, her transfers, **New transfer** button (opens the form pre-filled), **Statement report** button.
- [ ] As `sara`, search "hod" in New Transfer → **nothing** (agents only find their own customers). As admin, *Customers* lists everyone's.
- [ ] As admin, *Customers → Hodan → Edit* works; as Ali there is no Edit button.
- [ ] *Reports → Customers* (summary) and *Customer statement* (pick a customer from the list) both show data and export CSV.

## 5. Dashboard cards (item 12)

- [ ] As **admin**: click each card — *Money available in Somalia*, *Uncollected*, *Agents owing credit* and the four *Today* tiles open the matching report. *Waiting to be paid* opens the queue.
- [ ] As **ali**: *Send balance*, *Credit owed*, *Commission*, *Uncollected* open reports about **him** only.
- [ ] As **farah**: *Payout balance*, *Commission* open reports; the queue cards open the queue.

## 6. Paid transfers cannot be cancelled (item 11)

- [ ] As Ali, create a transfer; as Farah (other browser) *Payout Queue → Claim* it, then **Mark paid**.
- [ ] Open that transfer as Ali and as admin → **no** Cancel or Request-cancel button anywhere.
- [ ] For comparison, a *queued* transfer **can** be cancelled by Ali (refund shown in his send balance).

## 7. ★ One person who sends and pays (item 13)

- [ ] *Users → New user*: type **Sending & payout agent**, role **sending-payout-agent**, branch Mogadishu. Log in as them (their own browser).
- [ ] Their dashboard shows **both** the Sending and Paying-out cards.
- [ ] Top-ups: *Balance requests → New* offers top-up **and** payout-funding kinds. As admin, approve a top-up and a payout funding for them.
- [ ] They create a transfer to Mogadishu. In *Payout Queue* it has **no Claim button** and says "You sent this — someone else must pay it."
- [ ] As admin, *Assign* that transfer to them → refused ("cannot claim or pay a transfer you sent yourself"). Assign it to Farah instead → works.
- [ ] *Setup → Settings → Payout → tick "Let an agent claim and pay a transfer they sent themselves"* (super admin). Now they **can** claim and pay their own transfer. Check their *Commission* report: "…from sending" and "…from paying out" both have amounts.
- [ ] Untick it again.
- [ ] *Setup → Settings → large transfers*: hold large transfers ON, limit $50. Ali (give him *approve large transfers* via a role, or use a user who has it) creates a $100 transfer → it waits for approval and **he cannot approve his own**; the admin can.
- [ ] Ali requests a cancellation of a *claimed* transfer; if Ali also holds *approve cancellations*, he **cannot** approve his own request; the admin can.

## 8. ★ Reports are scoped per person (item 5)

Make two transfers each for Ali and Sara, paid by Farah and Hodan respectively.

- [ ] Ali's *Reports → Transfers*: only **his** transfers. Sara's: only hers. Admin: all.
- [ ] Ali's agent filter lists only himself (no other names).
- [ ] Farah's *Transfers* and *Payouts by method*: only what **she** paid — not Hodan's, even though they share a branch.
- [ ] *Commissions*: each agent sees only their own line.
- [ ] Ali now has *Credit owed*, *Cancellations* and *Customers* reports; none of Sara's data appears.
- [ ] Nobody but admin sees *Profit*, *Company accounts*, *Branch summary*.

## 9. ★ Expenses

Log in as admin.

- [ ] The sidebar has **one** "Expenses" item (no sub-menu). The page has two tabs: **Expenses** and **Expense types**.
- [ ] *Expense types* has Staff Payroll, Office Rent, Bills (Internet & airtime), Running Cost. **Add** "Security guard"; **rename** it; **deactivate** it → it disappears from the Add-expense list but old expenses keep it.
- [ ] *Add expense*: type *Office Rent*, today, $300, *Paid from* Mogadishu Bank (balance shown in the list), payee "Landlord". Saved → expense page shows it. The **branch is shown as yours and cannot be chosen**.
- [ ] *Company Accounts → Mogadishu Bank*: balance went **down** by $300. The statement shows an expense line.
- [ ] Try an expense **larger than the account's balance** → "…does not have enough money for this expense" and nothing is saved.
- [ ] Add one in **EUR** (e.g. €100): shown as USD with the EUR amount and rate underneath.
- [ ] Future date → refused. Amount 0 or negative → refused.
- [ ] Press the browser Back button and submit the same form twice → only **one** expense.
- [ ] *Edit*: you can change type, date, payee, reference, note — **not** the amount or account.
- [ ] *Void expense* (needs a reason) → status Voided, amount struck through, **money returns** to the account. Voiding twice is impossible.
- [ ] *Reports → Expenses* (list, voided ones left out) and *Expenses by type* (totals and %). *Reports → Profit* has an **Expenses** column and Net is lower by that amount.
- [ ] As Ali or Farah there is **no** Expenses menu and `/expenses` gives "403".
- [ ] Give a test user the *view own* + *create* permissions (a custom role): they see and add only **their own** expenses; with *view branch* they also see colleagues in their branch.

## 10. Final checks

- [ ] Turn *Setup → Settings → Security → Require two-factor* back **on** and save.
- [ ] `php artisan hawala:verify-ledger` (against the practice database) says **"Ledger OK — no problems found."**
- [ ] `php artisan test` is green.
- [ ] **Phone check** (or browser at 375px wide): New Transfer (both boxes and the customer search), the Expenses list, the dashboard cards, a report. Buttons are tappable, tables turn into cards, nothing scrolls sideways.
- [ ] Delete the practice database when you are done (`DROP DATABASE hawala_practice;` in phpMyAdmin).

---

## What is NOT part of this release

- **Item 3 — fee/commission rate override on New Transfer.** On hold until you decide what exactly the rate means.
- **Item 6 — exchange rate must match XE.** Needs a decision on how to get XE rates (see below). The app still uses its existing providers; test that EUR→USD quotes look sensible against xe.com, but they are **not guaranteed to match**.

## Before you deploy

1. There is **uncommitted work in the project folder that is not part of the five v1.1 phases** (an "opening balance" ledger type, a commission-input helper, audit-page wording, new tests). Review it, finish it, and commit it — or set it aside — before deploying, so you know exactly what goes live.
2. Rebuild the assets: `npm run build`, and upload `public/build`.
3. Two **new packages** were added (`pragmarx/google2fa`, `bacon/bacon-qr-code`). `vendor/` must be updated on the server (`composer install --no-dev --optimize-autoloader` locally, then upload `vendor/`, per DEPLOY.md).
4. Back up the server database, upload the code, then on the server, in this order:
   `php artisan migrate --force` → `php artisan db:seed --class=RolesAndPermissionsSeeder --force` → `php artisan config:clear && php artisan cache:clear`.
   **Do not open the site between uploading the code and migrating** — logged-in pages will crash until the database is updated.
5. The roles seeder resets the built-in roles (admin, sending-agent, payout-agent, sending-payout-agent) to their defaults. Re-apply any changes you made to those roles in the Roles screen.
6. On the server, check *Setup → Settings*: receipts are on, Security shows 2FA required and the idle time you want. **Never change `APP_KEY`** (it protects the 2FA secrets).
7. Give every existing user a branch (*Users → edit*) — users with none can still log in but cannot be saved until they have one.
8. Each user sets up their authenticator at their next login. Tell them in advance to install the app and keep their recovery codes.
