# Deploying the TimeDeo API to Railway

Deploy the PHP + MySQL backend to **Railway**, connected to a **Vercel** frontend.

The `server/` folder is now deploy-ready:

| File | Purpose |
|---|---|
| `Dockerfile` | PHP 8.2 + Apache image; production `php.ini`; serves `server/*.php`. |
| `docker-entrypoint.sh` | Makes Apache listen on Railway's `$PORT`. |
| `.dockerignore` | Keeps SQL/docs/Docker files out of the image & web root. |
| `index.php` | `GET /` health check → `{"service":"timedeo-api","status":"ok"}`. |
| `config.php` | Reads DB creds + CORS + debug from **env vars** (XAMPP defaults for local). |
| `config.example.php` | Documents the env vars to set. |
| `init.prod.sql` | Schema + seed **without** `DROP/CREATE DATABASE` — safe for a managed DB. |

---

## 1. Push to GitHub
Commit the repo (client + server) and push. You'll point both Railway and Vercel at it.

## 2. Create the Railway service (the PHP API)
1. **railway.com → New Project → Deploy from GitHub repo** → pick your repo.
2. Open the service → **Settings → Root Directory** → set to **`server`**.
   (This makes Railway build `server/Dockerfile` with `server/` as the context.)
3. **Settings → Networking → Generate Domain** to get a public URL, e.g.
   `https://timedeo-api-production.up.railway.app`.

## 3. Add a MySQL database
1. In the project: **New → Database → Add MySQL**. Railway provisions it and exposes
   `MYSQLHOST / MYSQLPORT / MYSQLDATABASE / MYSQLUSER / MYSQLPASSWORD`.

## 4. Set the API service's Variables
On the **API service → Variables**, add (using Railway's reference syntax so they
track the DB automatically):

```
DB_HOST = ${{MySQL.MYSQLHOST}}
DB_PORT = ${{MySQL.MYSQLPORT}}
DB_NAME = ${{MySQL.MYSQLDATABASE}}
DB_USER = ${{MySQL.MYSQLUSER}}
DB_PASS = ${{MySQL.MYSQLPASSWORD}}
APP_DEBUG = 0
CORS_ORIGIN = *        # fine for the same-origin setup in step 7A; see 7B otherwise
```
Redeploy if it doesn't redeploy automatically.

## 5. Load the schema **once**
Railway's MySQL already **created the database for you** (name = `MYSQLDATABASE`,
usually `railway`), which is why we use `init.prod.sql` (no `CREATE DATABASE`).

Get the **public** connection details from **MySQL service → Variables / Connect**
(Railway gives a public proxy host + port), then from your machine:

```bash
mysql -h <MYSQL_PUBLIC_HOST> -P <MYSQL_PUBLIC_PORT> \
      -u <MYSQLUSER> -p<MYSQLPASSWORD> <MYSQLDATABASE> \
      --default-character-set=utf8mb4 < server/init.prod.sql
```
> Keep `--default-character-set=utf8mb4` or multibyte text (em-dashes, Bengali)
> corrupts. Run this **once** — `init.prod.sql` has no `DROP`, so re-running errors
> on the existing tables (intentional; evolve with migrations from here).

## 6. Verify the API
- `https://<your-api>.up.railway.app/` → `{"service":"timedeo-api","status":"ok"}`
- `https://<your-api>.up.railway.app/get_categories.php` → JSON list of categories

## 7. Connect the Vercel frontend
Pick **one**:

### 7A. Same-origin via Vercel rewrite (recommended — no CORS, cookies "just work")
Add **`client/vercel.json`** (Vercel's Root Directory is `client`) and redeploy Vercel:
```json
{
  "rewrites": [
    { "source": "/api/:path*", "destination": "https://<your-api>.up.railway.app/:path*" }
  ]
}
```
Leave `VITE_API_BASE` **unset** — the client keeps calling `/api/...`, Vercel forwards
it to Railway server-side, and the browser only ever sees your Vercel origin.
Keep `CORS_ORIGIN = *` on Railway.

### 7B. Direct cross-origin call
- In **Vercel → Settings → Environment Variables**: `VITE_API_BASE = https://<your-api>.up.railway.app`, then redeploy.
- On **Railway**: set `CORS_ORIGIN = https://<your-app>.vercel.app` (your exact frontend URL — not `*`, which is invalid once you add cookie auth).
- Note: cross-site cookie sessions need `SameSite=None; Secure`. Prefer token auth,
  or use `app.` + `api.` subdomains of one domain to keep cookies same-site.

---

## Before real users (do not skip)
This deploy still ships the **demo seed** and has **no authentication** — anyone can
act as any user by passing an id. Fine for a private experiment; **not** for real
users' data. Work the **P0** list in `../PRODUCTION.md` (auth + per-request
authorization, error hardening) before going public. `APP_DEBUG=0` and the production
`php.ini` already stop error/stack-trace leaks.

## Cost / ops notes
- Railway bills usage (small for an experiment); the managed MySQL gives you backups.
- The `Dockerfile` is portable: the same image runs on Fly.io, Render, or any VPS if
  you outgrow Railway — no rewrite, no lock-in.
