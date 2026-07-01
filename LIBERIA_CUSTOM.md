# Liberia PBO Custom Backend Features

This fork of `ushahidi/platform` carries the backend for a small set of custom public features
built for the Liberia Peacebuilding Office (PBO): **Get Alerts**, **Contact Us**, **About Us**,
and **LERN Import**. See `ushahidi-client/LIBERIA_CUSTOM.md` (sibling repo) for the frontend
side of the same features, and `ireport/docs/MIGRATION_PLAN.md` (Phase 3) in the parent repo for
the full migration background.

## The pattern: minimize the stock-file diff

Almost every custom route/controller/model lives in its own new, isolated file with no
dependency on stock controllers — `AlertController`, `ContactUsController`,
`LernImportController`, `GeoLookupHelper`, and their models (`Alert`, `ContactUs`), all under
`src/Ushahidi/Modules/V5/`. New database tables get their own dedicated Phinx migrations,
prefixed `liberia_` for easy identification (`database/migrations/phinx/`):
`20260605000001_add_liberia_mgmt_levels.php`, `20260605000002_liberia_create_alerts_table.php`,
`20260630000001_liberia_add_location_to_alerts_table.php`,
`20260605000003_liberia_create_contact_us_table.php`.

Stock files are touched as little as possible:

| Stock file | What was added | Why |
|---|---|---|
| `src/Ushahidi/Modules/V5/ServiceProvider.php` (`boot()`) | 4-line `Route::prefix('api')->middleware('api')->namespace(...)->group(__DIR__.'/routes/liberia.php')` | Registers all Liberia routes in one hook. All actual route definitions live in `routes/liberia.php`, so this file's diff never grows as routes are added. |
| `src/Ushahidi/Modules/V5/Models/Config.php` | `about_us` added to `AVIALABLE_CONFIG_GROUPS` and `AVIALABLE_CONFIG_GROUPS_FOR_NON_ADMIN` | About Us reuses the stock generic config-group mechanism instead of a bespoke controller/migration — see `ushahidi-client/LIBERIA_CUSTOM.md` for the full rationale. |
| `src/Ushahidi/Modules/V5/Policies/ConfigPolicy.php` | `about_us` added to `$public_groups` (NOT `$readonly_groups`) | Makes the About Us config group publicly readable, admin-writable, same as `site`/`map`. |
| `src/Ushahidi/Modules/V5/Providers/EventServiceProvider.php` | `SendPostAlertsListener::class` added to both `PostCreatedEvent` and `PostUpdatedEvent` listener arrays | Hooks Get Alerts' notification-on-publish into the existing stock post-created/updated events — no `PostController.php` changes needed. See `SendPostAlertsListener.php` below. |
| `.env.example` | `APP_URL`, and a documented `MAIL_*` block (`MAIL_DRIVER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`) | `config/mail.php` reads these but none were previously documented anywhere in the repo — mail was silently falling back to placeholder defaults (`smtp.mailgun.org`, no credentials). `APP_URL` is needed to build absolute unsubscribe/report links from the background alert listener, which has no HTTP request context to infer a host from. |
| `src/Ushahidi/Contracts/Permission.php` | `ACCESS_ANALYSIS = 'Access analysis'` constant | New permission gating the Analysis dashboard/templates feature. Exact string `Access analysis`, per PBO requirement — not the old UNICC-fork's longer `Access analysis and filters section`. |
| `src/Ushahidi/Modules/V5/Models/Post/Post.php` | `mgmt_lev_1`, `mgmt_lev_2` added to `ALLOWED_FIELDS` | These columns already existed (`20260605000001_add_liberia_mgmt_levels.php`, for Get Alerts/LERN) but were excluded from the default field list `ListPostsQuery`/`FindPostByIdQuery` build via `addOnlyParameteresFromRequest()`, so they never reached `PostResource`. Adding them here is the minimal change needed to expose county/district on `GET /posts` for the Analysis dashboard's reports-per-county chart, without touching `EloquentPostRepository::getGroupedTotals()`'s `group_by` switch statement (a bigger, riskier stock diff). Note: `$fillable` is a separate array governing mass-assignment, not field selection — it was NOT the right place for this (an earlier draft of this change assumed otherwise; corrected after tracing `ListPostsQuery::fromRequest()` directly). |
| `src/Ushahidi/Modules/V5/routes/liberia.php` | 5 new `v5/analysis-templates*` route lines | Registers the Analysis Templates CRUD API. Uses `v5/` (unlike the existing `v3/get-alerts` etc. entries above) to match the stock API's actual version convention — the `v3/` prefix on the older routes is a known pre-existing inconsistency, not one to replicate going forward. |

## New isolated files (no stock-file diff)

| File | Purpose |
|---|---|
| `src/Ushahidi/Modules/V5/routes/liberia.php` | All Liberia route definitions (`get-alerts`, `contact-us`, `lern-import`). |
| `src/Ushahidi/Modules/V5/Http/Controllers/AlertController.php` | Get Alerts subscribe/unsubscribe/location-lookup. |
| `src/Ushahidi/Modules/V5/Models/Alert.php` | `alerts` table model. |
| `src/Ushahidi/Modules/V5/Listeners/SendPostAlertsListener.php` | Emails Get Alerts subscribers when a published report matches their radius/category. Ports `SendAlertsJob` from the old UNICC backend (`ireport/backend/src/App/Listener/SendAlertsJob.php`), which was never carried over when this fork was built — until this listener was added, subscribing to Get Alerts stored a row and did nothing else. Registered via `EventServiceProvider.php` above. |
| `resources/views/emails/post-alert.php` | Plain-PHP email view for the above (same convention as `resources/views/emails/forgot-password.php` — not Blade). |
| `src/Ushahidi/Modules/V5/Helpers/GeoLookupHelper.php` | Resolves a lat/lng to a Liberia county/district name for the Get Alerts location field. |
| `src/Ushahidi/Modules/V5/Http/Controllers/ContactUsController.php` | Public contact form; sends a notification email directly via `Mail::send()` (see `Mailer.php` below for why this bypasses the `Mailer` tool). |
| `src/Ushahidi/Modules/V5/Models/ContactUs.php` | `contact_us` table model. |
| `src/Ushahidi/Modules/V5/Http/Controllers/LernImportController.php` | LERN data import (admin only) — backend scaffolding, see `ireport/docs/MIGRATION_PLAN.md` Phase 3b. |
| `database/migrations/phinx/20260605000001_add_liberia_mgmt_levels.php`, `20260605000002_liberia_create_alerts_table.php`, `20260630000001_liberia_add_location_to_alerts_table.php`, `20260605000003_liberia_create_contact_us_table.php` | New tables/columns for the features above. |
| `src/Ushahidi/Modules/V5/Http/Controllers/AnalysisTemplateController.php` | CRUD for saved Analysis dashboard report templates. Enforces `Permission::ACCESS_ANALYSIS` itself via `service('authorizer.post')->acl->hasPermission()` (defense in depth — same pattern `PostController` uses for permission checks; reuses the existing `authorizer.post` binding rather than registering a new `authorizer.*` binding since `Acl::hasPermission()` is generic). |
| `src/Ushahidi/Modules/V5/Models/AnalysisTemplate.php` | `analysis_templates` table model. |
| `database/migrations/phinx/20260701000001_liberia_add_access_analysis_permission.php` | Seeds the `Access analysis` permission and grants it to the `admin` role. |
| `database/migrations/phinx/20260701000002_liberia_create_analysis_templates_table.php` | Creates the `analysis_templates` table. |

## Why `ContactUsController`/`SendPostAlertsListener` use `Mail::send()` directly

`src/Ushahidi/Core/Tool/Mailer.php` (a stock file) only implements one mail type,
`resetpassword`, dispatched via reflection (`'send'.Str::ucfirst($type)`). Rather than extending
that stock class with Liberia-specific mail types — which would mean editing a Core file every
time a new custom email is added — both `ContactUsController` and `SendPostAlertsListener` call
`Illuminate\Support\Facades\Mail::send()` directly, wrapped in try/catch + `\Log::warning()` so a
delivery failure never breaks the request/listener. `SendPostAlertsListener` does still reuse
the stock `env('DEFAULT_CLIENT_URL')` pattern from `Mailer.php` for building the frontend report
link, for consistency.

## Staying in sync with upstream

```bash
git fetch upstream
git merge upstream/develop
```

Expect conflicts only in the small number of stock files listed in the first table above —
everything else is additive, isolated files.
