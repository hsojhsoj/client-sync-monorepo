# Client Sync Plugin — AI Session Handoff Guide

This document provides everything a new AI session needs to work on the Client Sync WordPress plugin monorepo effectively. It covers project structure, deployment, version management, testing, and key architectural decisions.

---

## Project Overview

**Client Sync** is a WordPress appointment booking plugin with a freemium model:
- **Free plugin** (`client-sync`) — listed on WordPress.org
- **Pro add-on** (`client-sync-pro`) — distributed separately, requires Free to be active

**Current Versions:** Free `3.7.0` / Pro `1.6.0`

**Local project path:**
```
/Users/joshuajordan/Projects/Code/dm-software/clientSync/___________cs/client-sync-monorepo/
```

---

## Key URLs & Repositories

| Resource | URL |
|----------|-----|
| **GitHub repo** | https://github.com/hsojhsoj/client-sync-monorepo |
| **GitHub Wiki (documentation)** | https://github.com/hsojhsoj/client-sync-monorepo/wiki |
| **WordPress.org listing** | https://wordpress.org/plugins/client-sync/ |
| **Test site** | https://testblankwp.dependentmedia.com/ |
| **Plugin homepage** | https://dependentmedia.com/client-sync/ |

---

## Monorepo Structure

```
src/
├── free/              # Free plugin entry point (client-sync.php, readme.txt)
├── pro/               # Pro plugin entry point + pro-only modules
│   └── includes/
│       └── modules/   # Google Sync, Forms, Outputs, Videoconf, Memberships, Packages, Seat Selection
├── shared/            # Core code shared by both plugins
│   └── includes/
│       ├── admin/     # Admin pages, settings, views, asset manager
│       ├── ajax/      # AJAX handlers (admin, booking, calendar, slots, appointments)
│       ├── core/      # Plugin bootstrap, CPTs, notifications, cron, DB manager
│       ├── frontend/  # Shortcodes, frontend views
│       ├── rest-api/  # REST controllers (slots, appointments, price, timeline, etc.)
│       ├── repositories/  # Data access layer
│       ├── services/  # Business logic (encryption, audit, availability, HIPAA, importer, etc.)
│       └── utility/   # Security helpers
│   └── setup-templates/  # 14 JSON setup templates for the onboarding wizard
├── assets/
│   ├── src/           # React components (booking-form, timeline, graph, manage-slots, etc.)
│   ├── js/            # Vanilla JS files (calendar, cards, booking-mode-toggle, etc.)
│   ├── css/           # Stylesheets
│   └── dist/          # Webpack output (gitignored, built by npm run build)
└── blocks/            # Gutenberg block (booking-form)

docs/
└── wiki/              # GitHub Wiki source files (13 markdown pages)

tests/
├── unit/              # PHPUnit test files
├── bootstrap.php      # WP test environment setup
build/                 # Build output (gitignored)
build.sh               # Master build/deploy/test script
```

---

## Deploying to the Test Website

**Test site:** https://testblankwp.dependentmedia.com/
**Server:** Plesk on `44.240.240.195`
**SSH user:** `testblan`
**SSH password:** `aP9sp7p*jcN3dA@z`
**Remote project path:** `~/projects/client-sync-monorepo`
**WP plugins dir:** `/var/www/vhosts/testblankwp.dependentmedia.com/httpdocs/wp-content/plugins/`

### Method 1: Using build.sh deploy (recommended — runs everything)

This is the built-in command in `build.sh`. Run it from the LOCAL machine:

```bash
# From the local project root:
bash build.sh deploy
```

This does two steps automatically:
1. Rsyncs source files to the server (excludes node_modules, vendor, build, tmp)
2. SSHes into the server and runs `./build.sh free-pro` (composer install + npm build + deploy to WP plugins dir)

### Method 2: Manual rsync + remote build

If `build.sh deploy` isn't available locally (no bash on macOS, etc.), do it in two commands:

```bash
# Step 1: Sync files to the server
rsync -avz --delete \
  --exclude='node_modules' \
  --exclude='.git' \
  --exclude='build' \
  --exclude='vendor' \
  --exclude='tmp' \
  -e "ssh -o StrictHostKeyChecking=no" \
  ./ testblan@44.240.240.195:~/projects/client-sync-monorepo/

# Step 2: Build and deploy on the server
ssh -o StrictHostKeyChecking=no testblan@44.240.240.195 \
  'cd ~/projects/client-sync-monorepo && ./build.sh free-pro'
```

### What `build.sh free-pro` does on the server:
1. `composer install --no-dev` — installs PHP dependencies
2. `npm install && npm run build` — webpack builds all React/JS assets
3. Copies `src/free/` + `src/shared/` + `vendor/` -> `build/client-sync/`
4. Copies `src/pro/` + relevant vendor -> `build/client-sync-pro/`
5. Deploys both to `/var/www/vhosts/.../wp-content/plugins/` with correct ownership

### Build output expectations:
- Webpack main build: compiles with **3 pre-existing size warnings** (vendor-fullcalendar, manage-slots, booking-form, timeline-viewer exceed 244 KiB). These are expected.
- Webpack block build: compiles with **0 warnings**
- Total build time: ~2 minutes end-to-end

---

## Pushing to GitHub

The local repo is a git repository with the remote `origin` pointing to GitHub.

```bash
# Standard workflow after making changes:
git add <files>
git commit -m "Description of changes"
git push
```

**Authentication:** GitHub CLI (`gh`) is installed and authenticated via `gh auth login`. Git uses HTTPS protocol with the `gh` credential helper.

**What to push:** All source code changes, documentation updates, and configuration files. The `.gitignore` excludes: `node_modules/`, `vendor/`, `build/`, `tmp/`, `*.zip`, `.claude/`, `playwright-report/`, `test-results/`.

---

## Updating GitHub Wiki Documentation

The wiki is a separate git repository. Source files are also stored in `docs/wiki/` in the main repo.

### Updating wiki pages:

```bash
# 1. Edit markdown files in docs/wiki/
# 2. Clone the wiki repo, copy files, push:
cd /tmp
rm -rf client-sync-monorepo.wiki
git clone https://github.com/hsojhsoj/client-sync-monorepo.wiki.git
cp /Users/joshuajordan/Projects/Code/dm-software/clientSync/___________cs/client-sync-monorepo/docs/wiki/*.md /tmp/client-sync-monorepo.wiki/
cd /tmp/client-sync-monorepo.wiki
git add -A && git commit -m "Update documentation" && git push
```

### Wiki page inventory (13 pages):

| File | Content |
|------|---------|
| `Home.md` | Landing page, quick links, architecture overview, system requirements |
| `Getting-Started.md` | Installation, setup wizard, first steps |
| `Onboarding-Wizard.md` | All 14 setup templates, import/export |
| `Dimensions-and-Scheduling.md` | Primary dimensions, resources, relationships, schedules |
| `Shortcodes-Reference.md` | All 20 shortcodes with attributes and examples |
| `Settings-Reference.md` | Every setting across all 9 tabs |
| `Stripe-Payments.md` | Stripe setup, webhooks, test mode, troubleshooting |
| `Membership-Plans.md` | Plans, access rules, frontend display |
| `Subscribers-Admin-Page.md` | Subscriber list table, filtering, Stripe links |
| `Notifications.md` | Email, SMS, webhooks, output templates |
| `WooCommerce-Integration.md` | WooCommerce payment setup, comparison with Stripe |
| `Pro-Features.md` | All Pro modules, Free vs Pro comparison table |
| `_Sidebar.md` | Navigation sidebar rendered on every wiki page |

### When to update the wiki:
- New shortcodes are added
- New settings or tabs are added
- New Pro modules are created
- Membership or payment features change
- New setup templates are added

---

## Version Bumping

When releasing a new version, update ALL of these locations:

| File | What to update |
|------|---------------|
| `build.sh` line 12 | `PLUGIN_VERSION="X.Y.Z"` |
| `build.sh` line 13 | `PRO_VERSION="X.Y.Z"` (if Pro changed) |
| `src/free/client-sync.php` line 13 | `Version: X.Y.Z` (plugin header) |
| `src/free/readme.txt` line 7 | `Stable tag: X.Y.Z` |
| `src/free/readme.txt` changelog | Add new `= X.Y.Z =` entry at top of changelog |
| `src/pro/client-sync-pro.php` line 9 | `Version: X.Y.Z` (if Pro changed) |

---

## Publishing to WordPress.org (SVN)

There is **no automated script** for this. It's a manual SVN process:

```bash
# 1. Build the zip
./build.sh zip
# Creates: build/client-sync-vX.Y.Z.zip

# 2. Checkout the SVN repo (first time only)
svn checkout https://plugins.svn.wordpress.org/client-sync/ svn-client-sync

# 3. Copy built plugin to trunk
rm -rf svn-client-sync/trunk/*
cp -R build/client-sync/* svn-client-sync/trunk/

# 4. Tag the release
svn copy svn-client-sync/trunk svn-client-sync/tags/X.Y.Z

# 5. Commit
cd svn-client-sync
svn add --force .
svn commit -m "Release vX.Y.Z"
```

WordPress.org SVN credentials are separate from the test server and GitHub credentials.

**Important:** The `readme.txt` in `src/free/readme.txt` is what WordPress.org uses for the plugin listing page. Changes to the description, FAQ, changelog, or "Tested up to" value are deployed via SVN.

---

## Full Release Checklist

When releasing a new version to all three targets:

1. **Bump versions** in all locations (see Version Bumping above)
2. **Update changelog** in `src/free/readme.txt`
3. **Build & deploy to test site:** `bash build.sh deploy`
4. **Test on the test site** at https://testblankwp.dependentmedia.com/
5. **Push to GitHub:** `git add . && git commit -m "Release vX.Y.Z" && git push`
6. **Update wiki** if features/settings/shortcodes changed (see wiki update steps above)
7. **Publish to WordPress.org** via SVN (see SVN steps above)

---

## Running Tests

### PHPUnit (on the server only — no PHP locally)

```bash
# SSH into the server first, or:
ssh testblan@44.240.240.195 'cd ~/projects/client-sync-monorepo && ./build.sh test'

# With code coverage:
ssh testblan@44.240.240.195 'cd ~/projects/client-sync-monorepo && ./build.sh test-coverage'

# All tests (PHP + JS):
ssh testblan@44.240.240.195 'cd ~/projects/client-sync-monorepo && ./build.sh test-all'
```

**Important:** There is no PHP or Composer installed on the local Mac. Tests can ONLY run on the remote server. The test environment requires a MySQL database (already configured in `build.sh`).

### Jest (JavaScript — can run locally)

```bash
npm run test:js
```

### Webpack build verification (can run locally)

```bash
npm run build
# Or individual configs:
npx wp-scripts build
npx wp-scripts build --config webpack.block.config.js
```

---

## Key Architecture Notes

### Plugin Loading Order
1. WordPress loads `client-sync.php` (Free) -> defines `clisyc_PLUGIN_DIR`, `CLISYC_SHARED_DIR`
2. Autoloader at `shared/includes/autoloader.php` handles PSR-4 class loading
3. Pro loads at `plugins_loaded` priority 11 -> checks Free is active -> loads modules

### Constants
All option names, meta keys, post types, and status values are centralized in `src/shared/includes/class-constants.php` (`DependentMedia\ClientSync\Constants`).

### Custom Post Types
- `clisyc_appointment` — Appointments (Free)
- `clisyc_service` — Services (Free, dynamic via dimension system)
- `clisyc_form` — Custom forms (Pro)
- `clisyc_output_tmpl` — Notification output templates (Pro)
- `clisyc_member_plan` — Membership plans (Pro)
- `clisyc_package` — Booking packages (Pro)
- `clisyc_venue` — Seat selection venues (Pro)
- Dynamic dimension CPTs — Created per setup via dimension registry

### Subscription User Meta (Stripe Memberships)
All stored as WordPress user meta:
- `_clisyc_stripe_subscription_id` — `sub_xxx`
- `_clisyc_stripe_sub_status` — `active|past_due|canceled|trialing`
- `_clisyc_stripe_sub_plan_id` — Plan post ID
- `_clisyc_sub_period_end` — Unix timestamp
- `_clisyc_stripe_customer_id` — `cus_xxx`

### HIPAA/Encryption
- AES-256-GCM encryption via `class-encryption-service.php` (singleton)
- Key must be defined in `wp-config.php` as `CLISYC_ENCRYPTION_KEY`
- HIPAA mode toggles audit logging and disables data export tools
- Audit logger at `class-audit-logger.php` with 6-year minimum retention

### React Apps (webpack entry points)
Built with `@wordpress/scripts`. Key React apps in `src/assets/src/`:
- `booking-form/` — Main customer-facing booking flow
- `timeline-viewer/` — Appointment timeline
- `graph/` — Dimension relationship graph (uses @xyflow/react)
- `manage-slots/` — Admin slot management
- `schedule-editor/` — FullCalendar-based schedule editor
- `pro-forms/` — Custom form builder (Pro)
- `pro-outputs/` — Template output builder (Pro)
- `pro-seat-selection/` — Seat selection interface (Pro)

### Vanilla JS (not webpack — loaded directly)
Files in `src/assets/js/` are loaded as-is (not bundled):
- `clisyc-frontend-appointment-calendar.js` — Main frontend calendar (FullCalendar jQuery)
- `clisyc-admin-booking-mode-toggle.js` — Admin booking mode switcher
- `clisyc-appointments-cards.js` — Card-style appointment view
- Various other admin/frontend scripts

### Admin Menu Structure
Managed by `class-menu-manager.php` with a `$desired_order` array controlling submenu display order. Pro modules register their pages via the `clisyc_register_extra_submenu_items` action.

### Setup Templates
14 JSON files in `src/shared/setup-templates/`. Each contains `template_meta` (title, description, recommended, new), `options`, `cpts`, `pages`, `relationships`, and `output_templates`. The importer (`class-importer.php`) handles installation with data wipe for all CPTs including `clisyc_member_plan` and `clisyc_output_tmpl`.

---

## Recent Work Summary

### Sessions 1-6: Security, Code Quality & Testing
- XSS prevention, SQL injection fixes, authorization hardening
- Input validation, nonce verification, information disclosure prevention
- React Rules of Hooks compliance, memory leak fixes, race condition guards
- PHPUnit test fixes, FullCalendar version alignment

### Sessions 7-9: Features & Documentation (v3.7.0 / v1.6.0)
- **Membership Plans:** Added `clisyc_member_plan` CPT, access rules (booking limits + discounts), pricing cards shortcode, starter plan templates, Stripe subscription billing
- **Frontend Shortcodes:** `[clisyc_membership_plans]` with columns/highlight attributes, `[clisyc_user_account]` with Stripe billing portal link
- **Subscribers Admin Page:** WP_List_Table with WP_User_Query, status badges, plan/status filters, search, Stripe Dashboard links
- **Setup Templates Updated:** Added membership plans and pages to 5 templates (hair-salon, fitness-class, advanced-clinic, event-venue, modern-consultant). Added "New" badge + green border + key legend to template UI
- **Importer Fix:** Added `clisyc_member_plan` and `clisyc_output_tmpl` cleanup to data wipe in both `class-importer.php` and `class-onboarding-wizard.php`
- **GitHub Repo:** Initialized git, pushed to https://github.com/hsojhsoj/client-sync-monorepo (public)
- **GitHub Wiki:** Created 13-page documentation wiki covering all features, shortcodes, settings, payments, memberships, and Pro modules
- **Documentation Links:** Added wiki link to `readme.txt` description/installation sections and admin Guide page

---

## Common Gotchas

1. **No PHP locally** — All PHP tests and composer operations must happen on the server
2. **`CLISYC_PRO_DIR` is NOT defined by the Pro plugin** — Don't rely on it for Pro detection. Use `is_plugin_active('client-sync-pro/client-sync-pro.php')` or check `WP_PLUGIN_DIR`
3. **`CLISYC_VERSION` is referenced but never defined** — The webhook service uses it but it's not set anywhere. It's a latent bug
4. **Build warnings are expected** — 3 webpack size warnings for FullCalendar bundles are normal
5. **File ownership on server** — The build script sets `testblan:psacln` ownership. If you manually copy files, run `chown -R testblan:psacln` on them
6. **The `build/` directory is ephemeral** — It's wiped at the start of each build. Never put anything important there
7. **Monorepo vs built layout** — In the monorepo, `src/shared/` is a sibling of `src/free/`. In the built plugin, shared code is merged into the `client-sync/` directory root. The `CLISYC_SHARED_DIR` constant handles this difference
8. **Wiki is a separate git repo** — The GitHub Wiki has its own git repository (`*.wiki.git`). Edit files in `docs/wiki/`, then clone the wiki repo and copy them over to push
9. **GitHub auth via `gh` CLI** — Installed via Homebrew (`brew install gh`). Authenticated with `gh auth login`. Uses HTTPS protocol
10. **WordPress.org readme.txt** — The `src/free/readme.txt` file is what drives the WordPress.org listing page. It contains the plugin description, FAQ, changelog, and external service disclosures. Update it for any user-facing changes
