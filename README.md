# TimeDeo — Backend API (Pure PHP + PDO + MySQL)

The server side for **TimeDeo**, a time-banking platform where people trade *hours
of service* instead of money. Built for a DBMS lab: **vanilla PHP 8**, **MySQL**,
and **strictly raw SQL through PDO prepared statements** — no ORM, no query
builder, no framework.

---

## 1. Setup (XAMPP)

1. **Start** Apache + MySQL from the XAMPP control panel.
2. **Create the database** (schema + view + index + seed data):
   - phpMyAdmin → **Import** → choose `server/init.sql` → **Go**, *or* from a shell:
     ```bash
     "C:\xampp\mysql\bin\mysql.exe" -u root < "server/init.sql"
     ```
3. **Serve the PHP.** Two options:
   - **Copy/clone** the `server/` folder into `C:\xampp\htdocs\timedeo\` and browse
     `http://localhost/timedeo/get_categories.php`, **or**
   - Run PHP's built-in server from the `server/` folder:
     ```bash
     "C:\xampp\php\php.exe" -S 127.0.0.1:8000 -t .
     ```
     …then browse `http://127.0.0.1:8000/get_categories.php`.
4. **Credentials** live in `config.php` (defaults: host `127.0.0.1`, db `timedeo`,
   user `root`, empty password). Edit there if your MySQL differs.

**Demo login:** every seeded user's password is `password123`
(e.g. `avery@timedeo.test` = the front-end's "current user", `user_id = 1`).

---

## 2. Files

| File | Purpose |
|---|---|
| `init.sql` | Full dump: 9 tables (3NF + constraints), the VIEW, the INDEXes, seed data |
| `config.php` | DB credentials + CORS origin |
| `db.php` | PDO singleton connection + CORS/JSON headers + response helpers |
| `register.php` `login.php` | Auth (bcrypt via `password_hash`/`password_verify`); establish a session |
| `logout.php` `me.php` `auth.php` | Session sign-out, current-user restore, and the shared session helpers |
| `get_marketplace.php` | Explore grid — **INNER + LEFT JOINs** |
| `get_active_listings.php` | Storefront read through the **VIEW** |
| `get_dashboard_stats.php` | Dashboard — **GROUP BY + HAVING**, COUNT/SUM/AVG/MIN/MAX |
| `get_top_providers.php` | **Correlated + nested SUBQUERY** |
| `listings.php` | **Full DML CRUD** (SELECT/INSERT/UPDATE/DELETE by HTTP method) |
| `create_booking.php` | **TRANSACTION** — lock hours into escrow |
| `complete_booking.php` | **TRANSACTION** — release escrow (the flagship) |
| `create_review.php` | Leave a rating (guarded by UNIQUE + CHECK) |
| `get_bookings.php` | A user's bookings (both roles), JOINed |
| `get_profile.php` | Profile page: user + wallet + skills + reviews + stats |
| `get_categories.php` | Category facets with active-listing counts |

---

## 3. Where each academic requirement is fulfilled

| Requirement (§3) | File | What to point at |
|---|---|---|
| **DML** SELECT/INSERT/UPDATE/DELETE | `listings.php` | one file, all four verbs by method |
| **Transaction** (BEGIN/COMMIT/ROLLBACK) | `complete_booking.php` | escrow release: deduct escrow → credit provider → update booking → insert ledger, atomically |
| **Aggregation** GROUP BY + HAVING | `get_dashboard_stats.php` | "popular_categories": `HAVING COUNT(l.listing_id) > :min` with COUNT/SUM/AVG/MIN/MAX |
| **Joins** (2+ types) | `get_marketplace.php` | INNER JOIN Users/Skills/Categories + LEFT JOIN Bookings/Reviews |
| **Subquery** (correlated/nested) | `get_top_providers.php` | correlated AVG per provider vs. nested platform-wide AVG |
| **View** | `init.sql` + `get_active_listings.php` | `vw_active_marketplace_listings`; endpoint queries the view, not base tables |
| **Index** | `init.sql` | `idx_bookings_provider_status` (+3 more), each with a rationale comment |

> Every file has inline comments tagged **`RUBRIC:`** at the exact line that
> fulfills a requirement.

---

## 4. API quick reference

All responses are JSON: `{ "success": true, "data": ... }` or
`{ "success": false, "error": "..." }`. HTTP codes: `200/201` OK, `400` bad
input, `401/403` auth, `404` missing, `409` conflict, `500` server error.

| Method | Endpoint | Body / Query |
|---|---|---|
| POST | `register.php` | `{full_name, email, password}` — creates user, auto-logs-in |
| POST | `login.php` | `{email, password}` — returns user, sets session cookie |
| POST | `logout.php` | — destroys the session (sign out) |
| GET | `me.php` | — current session user; **401** if not logged in |
| GET | `get_marketplace.php` | `?category_id= &type= &q=` (all optional) |
| GET | `get_active_listings.php` | `?category=` (optional) |
| GET | `get_dashboard_stats.php` | `?min=1 &user_id=` (optional) |
| GET | `get_top_providers.php` | — |
| GET | `get_categories.php` | — |
| GET | `get_bookings.php` | `?user_id=` (req), `?scope=active\|history` |
| GET | `get_profile.php` | `?user_id=` (req) |
| GET/POST/PUT/DELETE | `listings.php` | see file header |
| POST | `create_booking.php` | `{listing_id, requester_id, agreed_hours?}` |
| POST | `complete_booking.php` | `{booking_id}` |
| POST | `create_review.php` | `{booking_id, reviewer_id, rating, comment?}` |

### Example — the escrow-release transaction
```bash
curl -X POST http://127.0.0.1:8000/complete_booking.php \
  -H "Content-Type: application/json" \
  -d '{"booking_id":1}'
```
Booking #1 (Avery → Idris, 2h, in_progress) moves 2 credits from Avery's escrow
to Idris's balance, marks the booking completed, and writes one `Transactions`
row — all or nothing.

### Authentication (login / logout)

Session-based, using PHP's native session. Because the Vite dev server **proxies
`/api` to this PHP server, every request is same-origin from the browser** — so
the `TIMEDEO_SESSID` cookie round-trips normally (no CORS-credentials or
`SameSite=None` gymnastics). The client does not need to store a token; it just
makes normal `fetch` calls (same-origin cookies are sent automatically).

Flow for the SPA:
1. On app load, `GET /api/me.php` → `200` with the user if a session exists, else
   `401` → show the login screen.
2. `POST /api/login.php` (or `register.php`) → returns the user **and** sets the
   session cookie. Store the returned `user_id` for the param-based endpoints
   (`get_profile.php?user_id=…`, etc.).
3. `POST /api/logout.php` → clears the session; then route back to login.

`login.php` and `me.php` return the **same** user shape:
`{ user_id, full_name, email, join_date, available_balance, escrow_balance }`.

Verified end-to-end: `me` → 401, `login` → 200 + cookie, `me` → 200, `logout`,
`me` → 401, and a wrong password → 401.

---

## 5. Security notes

- **Every** SQL value is bound through a PDO prepared statement (`prepare` +
  `execute`); no user input is ever concatenated into SQL. Emulated prepares are
  **off**, so these are real server-side prepared statements.
- Passwords are bcrypt-hashed; the hash is never returned to the client.
- CORS is open (`*`) for local lab convenience — tighten `cors_allow_origin` in
  `config.php` for anything real.
- `detail` (raw DB error text) is included in error responses to aid the lab
  demo; remove it before any real deployment.

## 6. Connecting the React client

The client (`../client`, Vite on `:5173`) currently uses mock Zustand stores.
To go live, point its data layer at these endpoints — the JSON field names
(`available_balance`, `escrow_balance`, `role`, `counterparty_name`, `avg_rating`,
`booking_status`, …) are shaped to match what the existing pages already render.
