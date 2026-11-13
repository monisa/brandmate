# BrandMate: brand and creator marketplace

Angular + Laravel + MySQL + Redis, as a modular monolith. This phase delivers
**Direction changed (October 2026):** the product is now a two-sided
marketplace. Brands find and shortlist creators; creators register themselves,
list their social accounts and packages. This replaces the subscription-only
model of the original requirements document, which listed the marketplace as
out of scope.

Built so far: accounts, companies, roles and team management; influencer
search, profiles, comparison and shortlists; creator sign-up, creator profiles,
social accounts and packages.

**Not built:** booking a creator, payments and payouts, verified analytics
from Instagram or YouTube. Search shows registered creators plus fictional
sample data.

### Marketplace roadmap

1. Creator accounts, profiles, packages. **Done.**
2. Connect Instagram and YouTube for verified numbers. Needs developer apps
   registered with Meta and Google, and their review. Start this early.
3. Orders and deliverables: a brand books a package, the creator delivers,
   the brand approves.
4. Held payments and creator payouts. Check the legal and tax requirements
   (payment gateway marketplace product, creator KYC, TDS/GST) before building.

## What works

| Area | Included |
| --- | --- |
| Authentication | Register, sign in/out, email verification, forgot/reset/change password, per-device sessions with expiring tokens |
| Companies | Company created at registration, company profile, suspension by the platform owner |
| Tenant isolation | Automatic per-company query scoping that returns nothing when no tenant is set |
| RBAC | Super Admin, Company Admin, Team Member; permission checks enforced by the API |
| Team | Member list, role changes, removal, email invitations |
| Influencer search | Keyword, platform, category, country, follower and engagement ranges, verified; sorting; pagination |
| Influencer profile | Metrics, details, data source and freshness, the creator's other platforms |
| Comparison | Up to 5 influencers side by side |
| Shortlists | Create, rename, delete, add and remove influencers, notes; shared within the company |
| Creator accounts | Separate sign-up; profile, social accounts, packages with prices; creator chooses when to be listed |
| Languages | English, Hindi, Tamil, Malayalam, switchable at any time |
| Admin | Platform overview, companies, users, audit log (API) |
| Cross-cutting | Versioned REST API (`/api/v1`), rate limiting, request IDs, audit logging, queued emails, CORS |

## Run it locally (Windows, PowerShell)

Needs Docker Desktop and Node.js 22.12 or newer.

```powershell
cd C:\Users\monis\Projects\influencer
copy backend\.env.example backend\.env

docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose restart queue scheduler

cd frontend
npm ci
npm start
```

| URL | What |
| --- | --- |
| http://localhost:4200 | The app |
| http://localhost:8080/api/v1 | API |
| http://localhost:8025 | Mailpit: every email the app sends lands here |
| http://localhost:8081 | phpMyAdmin (database administration only) |
| localhost:3307 | MySQL from Windows tools (user `influencer`, password `secret`) |

First run: register at http://localhost:4200/register, open Mailpit, click the
confirmation link, and you are in the dashboard as Company Admin.

**Upgrading from the Phase 1 zip:** extract over the existing folder (your
`backend/.env` is kept), then run:

```powershell
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
```

Run the same two commands after every later zip as well.

**Sample influencers:** `db:seed` adds 72 fictional creators (139 platform
accounts). They are marked `data_source = sample` and their links point to
example.com. Set `SEED_SAMPLE_INFLUENCERS=false` in production.

**Platform owner:** set `SUPER_ADMIN_EMAIL` and `SUPER_ADMIN_PASSWORD` in
`backend/.env`, then run `docker compose exec app php artisan db:seed`.

**After changing backend code** that a queued job uses, run
`docker compose restart queue`. Workers keep the old code in memory.

On Linux hosts, give PHP write access once:
`docker compose exec app chown -R www-data:www-data storage bootstrap/cache`.

## Tests

```powershell
docker compose exec app php artisan test     # backend, SQLite in memory
cd frontend; npx ng test --watch=false       # frontend, Vitest
```

Backend: 55 feature tests covering auth flows, tenant isolation, RBAC,
invitations, influencer search, shortlists and creator accounts. Frontend: 23 unit tests covering
the auth service, guards, the token interceptor, metric formatting and the
comparison selection.

## Layout

```
backend/
  app/Domain/            business code, one folder per domain
    Authentication/      queued verification and reset emails
    Companies/           Company, Membership, Invitation, tenant scoping
    Rbac/                Role, Permission, PermissionRegistry
    Audit/               AuditLog, AuditLogger
    Creators/            CreatorPackage (what a creator sells)
    Influencers/         Influencer (creator), SocialProfile (platform account)
    Search/              InfluencerSearch contract + MySQL implementation
    Shortlists/          Shortlist, ShortlistItem
  app/Http/              controllers (Api/V1), middleware, requests, resources
  routes/api.php         every endpoint, with its middleware layers
  tests/Feature/
frontend/src/app/
  core/                  auth, guards, interceptors, API client, models
  shared/                reusable components
  layout/                signed-in frame
  features/              auth, dashboard, influencers, shortlists, creator, team, settings, admin (lazy loaded)
docker/                  PHP-FPM image, Nginx config
.github/workflows/ci.yml
```

## API (all under `/api/v1`)

| Method and path | Access |
| --- | --- |
| `POST auth/register`, `auth/login`, `auth/forgot-password`, `auth/reset-password` | Public, rate limited |
| `GET auth/email/verify/{id}/{hash}` | Signed link |
| `GET invitations/{token}`, `POST invitations/{token}/accept` | Public, token is the credential |
| `GET auth/me`, `POST auth/logout`, `PUT auth/password`, `PUT auth/profile`, `GET/DELETE auth/sessions` | Signed in |
| `GET company`, `GET team/members`, `GET team/roles` | Company member |
| `PUT company` | `company.manage` |
| `PUT/DELETE team/members/{id}`, `GET/POST/DELETE team/invitations` | `team.manage` |
| `GET influencers`, `GET influencers/filters` | `influencer.search` |
| `GET influencers/{id}`, `GET influencers/compare?ids[]=`, `GET shortlists`, `GET shortlists/{id}` | `influencer.view` |
| `POST/PUT/DELETE shortlists`, `POST/PUT/DELETE shortlists/{id}/influencers` | `shortlist.manage` |
| `POST auth/register-creator` | Public, rate limited |
| `GET/PUT creator/profile`, `PUT creator/listing`, `POST/PUT/DELETE creator/accounts`, `POST/PUT/DELETE creator/packages` | Creator account, own records only |
| `GET audit-logs` | `audit.view` |
| `GET admin/overview`, `admin/companies`, `admin/users`, `admin/audit-logs`, `PATCH admin/companies/{id}` | Super Admin |

Success responses are `{ "data": ... }`, lists add `meta` and `links`.
Errors are `{ "message": ..., "errors": { field: [..] } }`. Every response has
an `X-Request-Id` header that also appears in logs and audit records.

## Languages

The app is available in English, Hindi, Tamil and Malayalam. The language menu
is on every signed-out page and at the bottom of the sidebar; the choice is
remembered in the browser.

- The English text is the key: `{{ 'Sign in' | t }}`, or with values
  `{{ 'Page {current} of {last}' | t: { current: 2, last: 9 } }}`. In code:
  `this.i18n.t('{name} was removed.', { name })`.
- Translations live in `frontend/src/app/core/i18n/locales/` (`hi.ts`,
  `ta.ts`, `ml.ts`). A missing entry shows the English text.
- **Every new piece of UI text must go through `| t`** and get an entry in all
  three files. A unit test fails if the three files drift apart.
- To add a language, add it to `LANGUAGES` and `LOADERS` in `i18n.ts` and
  create its locale file.

Not translated yet: e-mails, most server validation messages, dates, country
names and time-zone names. The translations were written without a native
reviewer; have a Hindi, Tamil and Malayalam speaker read them before launch.

## Rules to keep when adding features

1. **Tenant-owned tables** get a `company_id` column and the model uses the
   `BelongsToCompany` trait. That is all that is needed for isolation.
2. **Do not use route model binding for tenant-owned models.** Bindings resolve
   before the tenant is known; look the record up by id in the controller.
3. **Queued jobs and console commands have no tenant.** Set it with
   `app(TenantContext::class)->set($company)` or opt out explicitly with
   `withoutGlobalScope(CompanyScope::class)`.
4. **New permissions** go in `app/Domain/Rbac/PermissionRegistry.php`, then
   `php artisan db:seed --class=RolePermissionSeeder`.
5. **Angular guards are for navigation only.** Every rule must also be a
   middleware or policy on the API route.
6. Write a tenant-isolation test for every new tenant-owned endpoint.

## Decisions that differ from the spec, and why

- **Two account types.** `users.account_type` is `brand` or `creator`. A brand
  user works inside a company; a creator owns exactly one creator profile
  (`influencers.user_id`) and has no company. Each side gets 403 on the
  other's endpoints.
- **Creators start hidden** and choose when to be listed. Listing needs a
  category, a country and at least one social account.
- **Creator-entered follower counts are labelled "Self-reported"** everywhere
  a brand sees them (`data_source = self_reported`). Engagement and averages
  stay "Data unavailable" until accounts can be verified through the
  platforms' APIs. Anyone can currently type any username, so do not treat a
  self-reported account as proof of ownership.
- **Prices are stored in paise** (`price_minor`), single currency (INR,
  `PLATFORM_CURRENCY`).

- **An "influencer" in the app is one platform account.** The `influencers`
  table holds the creator; `social_profiles` holds each platform account with
  its metrics. Search, comparison and shortlists work on platform accounts,
  because followers and engagement only mean something per platform. The
  spec's `influencer_platforms`, `social_metrics` and `social_posts` tables
  are not created yet: they belong with a real data source (metric history,
  recent content).
- **Unknown metrics are stored as NULL** and shown as "Data unavailable",
  never as zero. Range filters skip unknown values; sorting puts them last.
- **Search sits behind an interface** (`InfluencerSearch`), so OpenSearch can
  replace the MySQL implementation without changing the API.

- **One company per user.** `company_users` supports many, but the MVP scope
  defers multi-organisation accounts, so inviting an already-registered email
  is rejected.
- **Role lives on the membership** (`company_users.role_id`) instead of a
  separate `user_roles` table: a role only means something inside a company.
- **Super Admin is a flag** (`users.is_super_admin`), not a role, so it can
  never be granted through the team screens or mass assignment.
- **Bearer tokens (Sanctum) stored in localStorage.** Simple across origins;
  the trade-off is exposure to XSS. Moving to Sanctum's cookie mode is a
  contained change if the app and API share a domain in production.

## Not done yet

- A real influencer data source (YouTube Data API, a licensed provider, or
  creator-authorised access). Everything shown today is sample data.
- Saved searches, exports, audience demographics, growth trends.
- Plan-based search limits (Phase 3, with subscriptions).

- Production deployment (frontend hosting, TLS, backups, monitoring).
- MFA (marked optional in the spec).
- Admin audit-log and company audit-log screens (the endpoints exist).
- End-to-end browser tests.
