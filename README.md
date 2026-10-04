# TimeDeo — Backend API (Pure PHP + PDO + MySQL)

The server side for **TimeDeo**, a time-banking platform where people trade *hours
of service* instead of money. Built for a DBMS lab: **vanilla PHP 8**, **MySQL /
MariaDB**, and **strictly raw SQL through PDO prepared statements** — no ORM, no
query builder, no framework.

---

## 1. Setup (XAMPP)

1. **Start** Apache + MySQL from the XAMPP control panel.
2. **Create the database** (schema + view + indexes + seed data):
   - phpMyAdmin → **Import** → choose `init.sql` → **Go**, *or* from a shell:
     ```bash
     "C:\xampp\mysql\bin\mysql.exe" -u root < init.sql
     ```
   `init.sql` drops and recreates the `timedeo` database, so re-importing it
   resets everything to the seed. It starts with `SET NAMES utf8mb4` so text
   like "—" and "৳" survives the Windows mysql client.
3. **Serve the PHP** from `C:\xampp\htdocs\timedeo\` (Apache:
   `http://localhost/timedeo/get_categories.php`), or with PHP's built-in server:
   ```bash
   "C:\xampp\php\php.exe" -S 127.0.0.1:8000 -t .
   ```
4. **Settings** live in `config.php` (DB credentials, allowed CORS origins —
   comma-separated, e.g. `http://localhost:5173,http://localhost:5174`).

**Demo login:** every seeded user's password is `password123`
(`avery@timedeo.test` is user 1 and has something waiting in every flow).

All `DATETIME` columns are **UTC** (each connection runs `SET time_zone = '+00:00'`)
and the API returns ISO-8601 strings ending in `Z`.

---

## 2. Schema (14 tables, 3NF)

| Table | Purpose |
|---|---|
| `Users` | identity, bcrypt password, public profile (headline, location, bio) |
| `User_Contacts` | how a member can be reached — one row per method (phone, WhatsApp, Telegram, email, Facebook); **private** |
| `Wallets` | 1:1 with Users — `available_balance` + `escrow_balance` (CHECK ≥ 0) |
| `Categories`, `Skills`, `User_Skills` | skill catalog; members can add new skills |
| `Listings` | `Offer` (I provide this) or `Request` ("Ask for help" — I need this); `delivery_mode`, `preferred_at` |
| `Help_Offers` | a skilled member volunteers for a Request; the author accepts one |
| `Bookings` | requester ↔ provider, `scheduled_at`, two-sided lifecycle timestamps |
| `Transactions` | immutable payout ledger, one per completed booking (`release_reason` confirmed / auto) |
| `Reviews` | both sides rate each other once per booking (`reviewee_id`) |
| `Contact_Reveals` | audit of "show me their contact" — mutual |
| `Credit_Packages`, `Credit_Purchases` | demo bKash top-ups; server-side price list |

### Booking lifecycle (two-sided completion)

```
pending ──provider accepts──▶ in_progress ──provider delivers──▶ delivered
delivered ──requester confirms──▶ completed      (escrow → provider, ledger row)
delivered ──72 h with no answer──▶ completed     (auto-release, reason 'auto')
delivered ──requester "not done yet"──▶ in_progress
pending ──provider declines / requester cancels──▶ cancelled (escrow refunded)
in_progress ──provider cancels──▶ cancelled (escrow refunded)
```

The **auto-release** guarantees the provider is paid for delivered work even if
the requester disappears. It runs lazily (`settle_due_bookings()` in
`booking_lib.php`) whenever wallets or bookings are read, so XAMPP needs no cron.

### Ask for help

1. A member posts a `Request` listing (skill, hours, optional preferred time).
2. Members with that skill (or category) see it on **Help wanted** and send a
   `Help_Offer` with a proposed time.
3. The author accepts one offer → **one transaction** locks the author's hours in
   escrow, creates the booking (already `in_progress`), declines the other offers
   and marks the request `filled`. From there it is a normal booking.

### Contact privacy

Contact details are returned only when the viewer has a **booking**, a **help
offer**, or a **contact reveal** with that member (`can_view_contacts()`).
Revealing a post author's contact is recorded and **mutual** — the author sees
who looked (dashboard "Interested in you") and can see their contact too.

---

## 3. Where each academic requirement is fulfilled

| Requirement | File | What to point at |
|---|---|---|
| **DML** SELECT/INSERT/UPDATE/DELETE | `listings.php` | one file, all four verbs by method |
| **Transaction** (BEGIN/COMMIT/ROLLBACK) | `complete_booking.php` + `booking_lib.php` | escrow release: deduct escrow → credit provider → mark completed → ledger row |
| more transactions | `create_booking.php`, `booking_action.php`, `help_offers.php` (accept), `update_profile.php`, `credits.php`, `register.php` | each multi-table change is all-or-nothing, with `FOR UPDATE` row locks |
| **Aggregation** GROUP BY + HAVING | `get_dashboard_stats.php` | `HAVING COUNT(l.listing_id) > :min` with COUNT/SUM/AVG/MIN/MAX |
| **Joins** (2+ types) | `get_marketplace.php` | INNER JOIN Users/Skills/Categories + LEFT JOIN Bookings/Reviews |
| **Subquery** (correlated/nested) | `get_top_providers.php`, `get_marketplace.php`, `help_offers.php` | per-provider AVG vs. platform AVG; per-viewer facts |
| **View** | `init.sql` + `get_active_listings.php` | `vw_active_marketplace_listings` |
| **Index** | `init.sql` | 8 indexes, each with a rationale comment |

> Files carry inline comments tagged **`RUBRIC:`** at the exact line that
> fulfills a requirement.

---

## 4. API quick reference

All responses are JSON: `{ "success": true, "data": ... }` or
`{ "success": false, "error": "..." }`. HTTP codes: `200/201` OK, `400` bad
input, `401/403` auth, `404` missing, `409` conflict, `500` server error.

**Every endpoint that reads private data or changes anything uses the session
user** — no endpoint trusts a user id sent by the client.

| Method | Endpoint | Body / Query |
|---|---|---|
| POST | `register.php` | `{full_name, email, password, contact_method, contact_value, headline?}` |
| POST | `login.php` / `logout.php` | `{email, password}` / — |
| GET | `me.php` | session user + wallet; **401** if signed out |
| GET | `get_profile.php` | `?user_id=` public profile; no id = my own (adds email, wallet, contacts) |
| POST | `update_profile.php` | `{full_name, headline?, location?, bio?, skills?: [{skill_id} \| {skill_name, category_id}], contacts?: [{method, value}]}` |
| GET | `get_skills.php` | categories with their skills |
| GET | `get_marketplace.php` | `?type=Offer\|Request &category_id= &q= &match=1` |
| GET/POST/PUT/DELETE | `listings.php` | POST `{listing_type, title, description?, estimated_hours, delivery_mode?, preferred_at?, skill_id \| (skill_name + category_id)}` |
| POST | `create_booking.php` | `{listing_id, scheduled_at (ISO), note?}` |
| POST | `booking_action.php` | `{booking_id, action: accept\|decline\|cancel\|deliver\|reject, reason?}` |
| POST | `complete_booking.php` | `{booking_id}` — requester confirms, escrow released |
| GET | `get_bookings.php` | `?scope=active\|history` |
| POST | `create_review.php` | `{booking_id, rating, comment?}` |
| GET/POST | `help_offers.php` | GET `?listing_id=` or `?mine=1`; POST `{listing_id, proposed_at, message?}` or `{offer_id, action: accept\|decline\|withdraw}` |
| GET/POST | `contacts.php` | GET `?user_id=` or `?inbox=1`; POST `{listing_id}` (reveal) |
| GET/POST | `credits.php` | GET `?page=&per_page=` → packages + **my** purchases (paged, `no-store`) + summary; POST `{package_id, payer_account}` |
| GET | `get_categories.php`, `get_dashboard_stats.php`, `get_top_providers.php`, `get_active_listings.php` | public read-only |

---

## 5. Security notes

- **Every** SQL value is bound through a PDO prepared statement; emulated
  prepares are off. Dynamic SQL fragments (sort/scope) come from fixed whitelists.
- Passwords are bcrypt-hashed; the hash is never returned.
- Sessions use an httpOnly cookie, regenerated on login.
- Wallet, email and contact details are never in public responses.
- The bKash checkout is a **demo**: the OTP and PIN are checked against fixed demo
  values in the browser and never sent; the server stores only a masked wallet
  number, and prices come from `Credit_Packages`, not the client.
- `detail` (raw DB error text) is only included when `APP_DEBUG=1`.
