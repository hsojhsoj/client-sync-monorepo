# Client Sync Plugin — AI Session Handoff Guide

This document provides everything a new AI session needs to work on the Client Sync WordPress plugin monorepo effectively. It covers project structure, deployment, version management, testing, and key architectural decisions.

---

## Project Overview

**Client Sync** is a WordPress appointment booking plugin with a freemium model:
- **Free plugin** (`client-sync`) — listed on WordPress.org
- **Pro add-on** (`client-sync-pro`) — distributed separately, requires Free to be active

**Current Versions:** Free `3.7.1` / Pro `1.6.1`

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
| **Demo site (Therapist)** | https://clientsync-therapist.dependentmedia.com/ |
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
**SSH auth:** publickey only (password auth disabled on the server). Your local `~/.ssh/id_ed25519` is authorized — no `-i` flag needed.
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

## Demo Site: Therapist (Serenity Counseling)

A fully configured demo site showcasing Client Sync for a therapy/counseling practice. Uses the naming pattern `clientsync-{market}.dependentmedia.com` for future market-specific demos.

### Connection Details

| Detail | Value |
|--------|-------|
| **URL** | https://clientsync-therapist.dependentmedia.com/ |
| **WP Admin** | https://clientsync-therapist.dependentmedia.com/administratordmedia |
| **Admin user** | `cs-admin` |
| **Admin pass** | `BmEHiFkd7#9qPnhXXNT*8XVc` |
| **SSH user** | `clientsync-therapist_v1ddy1srhqf` |
| **SSH host** | `44.240.240.195` |
| **SSH key** | `~/.ssh/clientsync_demo` (ed25519, no passphrase) |
| **PHP path** | `/opt/plesk/php/8.3/bin/php` |
| **WP-CLI path** | `/usr/local/bin/wp` |
| **WP path** | `/var/www/vhosts/clientsync-therapist.dependentmedia.com/httpdocs/` |
| **Login URL plugin** | WPS Hide Login (login at `/administratordmedia`, not `/wp-admin`) |

### SSH Command Pattern

```bash
ssh -o StrictHostKeyChecking=no -i ~/.ssh/clientsync_demo \
  clientsync-therapist_v1ddy1srhqf@44.240.240.195 \
  '/opt/plesk/php/8.3/bin/php /usr/local/bin/wp --path=/var/www/vhosts/clientsync-therapist.dependentmedia.com/httpdocs <command>'
```

**Important:** No password-based SSH — publickey only. The key `~/.ssh/clientsync_demo` must exist on the local machine. The server does NOT have the same SSH user as the test site (`testblan`). Each vhost has its own isolated SSH user.

### Deploying Plugin Updates to Demo Site

The demo site does NOT use `build.sh deploy`. Plugins were installed manually. To update:

```bash
# 1. Build on test server first:
bash build.sh deploy   # builds on testblan server

# 2. Tar the built plugins on the test server and copy to demo site:
ssh testblan@44.240.240.195 'cd ~/projects/client-sync-monorepo/build && \
  tar czf /tmp/cs-plugins.tar.gz client-sync client-sync-pro'

# 3. Copy from /tmp (shared filesystem between vhosts) and extract:
ssh -i ~/.ssh/clientsync_demo clientsync-therapist_v1ddy1srhqf@44.240.240.195 \
  'cd /var/www/vhosts/clientsync-therapist.dependentmedia.com/httpdocs/wp-content/plugins && \
   tar xzf /tmp/cs-plugins.tar.gz'
```

Or update individual files via `scp`:

```bash
scp -i ~/.ssh/clientsync_demo <local-file> \
  clientsync-therapist_v1ddy1srhqf@44.240.240.195:<remote-path>
```

### Demo Site Configuration

- **Brand:** "Serenity Counseling" — therapy/counseling practice
- **Theme:** Beaver Builder Theme (`bb-theme`) with BB Theme Builder header/footer layouts
- **HIPAA mode:** Enabled
- **Dimensions:** `clisyc_service` (primary) + `clisyc_therapist`
- **Services:** Initial Assessment ($200/90min), Individual Therapy ($150/50min), Couples Counseling ($225/80min), Group Therapy ($65/90min, 8 capacity), EMDR Therapy ($175/60min)
- **Therapists:** Dr. Sarah Mitchell PsyD, James Rivera LMFT, Dr. Anika Patel PhD
- **Membership Plans:** Monthly Wellness ($149/mo), Intensive Care ($349/mo)
- **SEO:** Slim SEO plugin with custom meta titles/descriptions for all pages, services, therapists, and plans. JSON-LD schema markup (MedicalBusiness, Person, Service, BreadcrumbList). Site set to noindex (`blog_public = 0`) since it's a demo.

### Must-Use Plugins (mu-plugins)

Custom functionality deployed as must-use plugins to survive theme/plugin updates:

| File | Purpose |
|------|---------|
| `serenity-demo-enqueue.php` | Enqueues custom CSS from `wp-content/uploads/serenity-demo-style.css` |
| `serenity-demo-lockdown.php` | Demo lockdown: `demo_viewer` role, auto-login (`?demo-login=1`), read-only admin, hidden sensitive menus, frontend demo bar with CTA, Client Sync plugin deactivation protection |
| `serenity-demo-schema.php` | JSON-LD structured data (MedicalBusiness on homepage, Person on therapists, Service on services, BreadcrumbList on all inner pages) |
| `serenity-fix-membership-shortcode.php` | Workaround for CPT load-order bug (fixed in v3.7.1 source but mu-plugin remains as safety net) |

### Demo Bar & Auto-Login

- **Frontend demo bar:** Fixed bottom bar on all pages with "Get Client Sync Free" and "Try Admin Demo" buttons. Dismissible.
- **Auto-login URL:** `https://clientsync-therapist.dependentmedia.com/?demo-login=1` — instantly logs visitor in as `demo-visitor` user with `demo_viewer` role (read-only admin access).
- **Demo viewer restrictions:** No POST requests in admin (except AJAX), hidden Plugins/Themes/Users/Settings menus, can see Client Sync admin pages but not change anything.

### Key Pages

| Page | URL | Shortcode |
|------|-----|-----------|
| Home | `/` | Custom HTML (hero, services, therapists, HIPAA banner, CTA) |
| Book a Session | `/book/` | `[clisyc_booking_form]` |
| Our Therapists | `/our-therapists/` | Custom HTML (therapist cards with bios) |
| Membership Plans | `/membership-plans/` | `[clisyc_membership_plans columns="2"]` |
| My Account | `/my-account/` | `[clisyc_user_account]` |
| My Appointments | `/my-appointments/` | `[clisyc_appointments_cards]` |

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

Use the `bump-version.sh` script — it rewrites every version location atomically and fails loudly if any file is out of sync before editing:

```bash
bash bump-version.sh --free 3.7.4 --pro 1.6.3   # bump both
bash bump-version.sh --free 3.7.4                # free only
bash bump-version.sh --pro 1.6.3                 # pro only
bash bump-version.sh --free 3.7.4 --dry-run      # preview, no writes
```

The script handles all 8 version locations (see `bash bump-version.sh --help`). What it does NOT do: write changelog entries — prose doesn't automate well, so you still need to manually add a `= X.Y.Z =` block to the relevant `readme.txt` and an `<h4>X.Y.Z</h4>` block to `src/pro/update-server/update-info.php`.

Locations the script manages (kept here for reference in case the script is ever unavailable):

| File | What it updates |
|------|---------------|
| `build.sh` | `PLUGIN_VERSION` and/or `PRO_VERSION` |
| `src/free/client-sync.php` | plugin-header `Version:` and `CLISYC_VERSION` constant |
| `src/free/readme.txt` | `Stable tag:` |
| `src/pro/client-sync-pro.php` | plugin-header `Version:` |
| `src/pro/readme.txt` | `Stable tag:` |
| `src/pro/update-server/update-info.php` | `version`, `last_updated` (set to today) |

---

## Pro Plugin Auto-Update Server

Client Sync Pro uses a self-hosted update server at `pass.dependentmedia.com` for automatic updates in WordPress. The update system follows the same pattern as the DM Mobile Location plugin.

### Server Connection

| Detail | Value |
|--------|-------|
| **Update endpoint** | https://pass.dependentmedia.com/plugin-updates/client-sync-pro/update-info.php |
| **Zip download URL** | https://pass.dependentmedia.com/plugin-updates/client-sync-pro/client-sync-pro.zip |
| **SSH user** | `updates.dependentmedia.com_43485` |
| **SSH host** | `44.240.240.195` |
| **SSH key** | `~/.ssh/pass_dm` (ed25519, no passphrase) |
| **Web root** | `/var/www/vhosts/pass.dependentmedia.com/httpdocs/` |
| **Update files dir** | `httpdocs/plugin-updates/client-sync-pro/` |

### How It Works

1. WordPress checks for plugin updates (every 12 hours or on "Check Again")
2. `Update_Manager` in the Pro plugin fetches JSON from the endpoint above
3. If remote version > installed version, WordPress shows an update notice
4. If the site has an **active license**: one-click update works (download URL provided)
5. If **no active license**: update notice shows but download is blocked (empty package URL)

### Deploying a Pro Plugin Update

```bash
# 1. Build the Pro plugin zip (on the test server):
ssh testblan@44.240.240.195 'cd ~/projects/client-sync-monorepo && ./build.sh zip'
# Creates: build/client-sync-pro.zip

# 2. Copy the zip to /tmp (shared filesystem):
ssh testblan@44.240.240.195 'cp ~/projects/client-sync-monorepo/build/client-sync-pro.zip /tmp/'

# 3. Move to the update server directory:
ssh -i ~/.ssh/pass_dm updates.dependentmedia.com_43485@44.240.240.195 \
  'cp /tmp/client-sync-pro.zip httpdocs/plugin-updates/client-sync-pro/'

# 4. Update update-info.php with new version/changelog:
scp -i ~/.ssh/pass_dm src/pro/update-server/update-info.php \
  updates.dependentmedia.com_43485@44.240.240.195:httpdocs/plugin-updates/client-sync-pro/

# 5. Verify endpoint:
curl -s https://pass.dependentmedia.com/plugin-updates/client-sync-pro/update-info.php | python3 -m json.tool
```

### Key Files

| File | Purpose |
|------|---------|
| `src/pro/update-server/update-info.php` | Server-side JSON endpoint (uploaded to pass.dependentmedia.com). Update version + changelog here on each release |
| `src/pro/includes/class-update-manager.php` | Client-side update checker. Hooks `site_transient_update_plugins` + `plugins_api`. Caches for 12 hours. Requires active license for downloads |

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
2. **Update changelogs** in `src/free/readme.txt` and `src/pro/readme.txt`
3. **Update Pro update server** — bump version + changelog in `src/pro/update-server/update-info.php`
4. **Build & deploy to test site:** `bash build.sh deploy`
5. **Test on the test site** at https://testblankwp.dependentmedia.com/
6. **Push to GitHub:** `git add . && git commit -m "Release vX.Y.Z" && git push`
7. **Update wiki** if features/settings/shortcodes changed (see wiki update steps above)
8. **Publish Free to WordPress.org** via SVN (see SVN steps above)
9. **Publish Pro to update server** — upload zip + update-info.php (see Pro Auto-Update Server above)
10. **Update demo site** if needed (see Demo Site section above)

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

### Session 10: Demo Site & Bug Fix (v3.7.1 / v1.6.1)
- **Demo Site Built:** Created `clientsync-therapist.dependentmedia.com` — a fully configured therapy practice demo showcasing Client Sync. Includes 5 services, 3 therapists, 2 membership plans, custom-styled homepage and therapist pages, HIPAA mode, calming teal/lavender palette
- **Demo Lockdown:** Created `demo_viewer` role with read-only admin access, auto-login URL (`?demo-login=1`), frontend demo bar with "Get Client Sync Free" CTA, plugin deactivation protection
- **SEO Setup:** Installed Slim SEO, set custom meta titles/descriptions for all 13 pages + 5 services + 3 therapists + 2 plans. Added JSON-LD schema markup (MedicalBusiness, Person, Service, BreadcrumbList). Set utility pages to noindex
- **Bug Fix — Membership Plans Shortcode:** Fixed load-order bug where `[clisyc_membership_plans]` shortcode failed to register because `post_type_exists('clisyc_member_plan')` returned false during the free plugin's shortcode initialization (Pro hadn't registered the CPT yet). Removed the guard in `class-shortcodes.php`

---

## Common Gotchas

1. **No PHP locally** — All PHP tests and composer operations must happen on the server
2. **`CLISYC_PRO_DIR` is NOT defined by the Pro plugin** — Don't rely on it for Pro detection. Use `is_plugin_active('client-sync-pro/client-sync-pro.php')` or check `WP_PLUGIN_DIR`
3. **`CLISYC_VERSION` constant** — Defined in `src/free/client-sync.php`. Must be bumped alongside the plugin header version
4. **Build warnings are expected** — 3 webpack size warnings for FullCalendar bundles are normal
5. **File ownership on server** — The build script sets `testblan:psacln` ownership. If you manually copy files, run `chown -R testblan:psacln` on them
6. **The `build/` directory is ephemeral** — It's wiped at the start of each build. Never put anything important there
7. **Monorepo vs built layout** — In the monorepo, `src/shared/` is a sibling of `src/free/`. In the built plugin, shared code is merged into the `client-sync/` directory root. The `CLISYC_SHARED_DIR` constant handles this difference
8. **Wiki is a separate git repo** — The GitHub Wiki has its own git repository (`*.wiki.git`). Edit files in `docs/wiki/`, then clone the wiki repo and copy them over to push
9. **GitHub auth via `gh` CLI** — Installed via Homebrew (`brew install gh`). Authenticated with `gh auth login`. Uses HTTPS protocol
10. **WordPress.org readme.txt** — The `src/free/readme.txt` file is what drives the WordPress.org listing page. It contains the plugin description, FAQ, changelog, and external service disclosures. Update it for any user-facing changes
11. **Demo site SSH is key-only** — The demo site at `clientsync-therapist.dependentmedia.com` uses SSH key auth only (no password). The private key is at `~/.ssh/clientsync_demo`. Each Plesk vhost has its own isolated SSH user — do NOT use the `testblan` user for the demo site
12. **Demo site login URL is hidden** — WPS Hide Login plugin moves `wp-login.php` to `/administratordmedia`. Standard `/wp-admin` will 404 for logged-out users
13. **Demo site cross-vhost file transfer** — The `testblan` SSH user cannot access the demo site's directory. Use the shared `/tmp` directory to transfer files between vhosts (tar on testblan, extract on demo user)
14. **Membership plans shortcode load-order** — The `[clisyc_membership_plans]` shortcode in `class-shortcodes.php` must NOT be gated behind `post_type_exists()` — the Pro plugin registers the CPT after the free plugin's shortcode init. Fixed in v3.7.1
15. **Pro update server is separate from WHMCS** — The auto-update endpoint at `pass.dependentmedia.com` is a simple JSON file, not connected to WHMCS. When releasing a new Pro version, you must manually update `update-info.php` AND upload the zip. The client-side `Update_Manager` handles license gating independently via `License_Manager::is_license_active()`
16. **pass.dependentmedia.com SSH key** — At `~/.ssh/pass_dm`. Different vhost user than testblan or the demo site. Use the cross-vhost `/tmp` trick to move built zips from testblan's build output to the update server directory

---

## Pro update signing

**Status: soft-launch. The public key constant in `src/pro/includes/class-update-manager.php` is empty, so signature verification is currently SKIPPED and a `Debug_Logger` warning is emitted on each update check. Until the keypair is generated and the public key pasted in, `pass.dependentmedia.com` is an unauthenticated RCE channel into every Pro install. This section is the runbook for closing that gap.**

### Threat model

`Update_Manager::get_remote_info()` fetches JSON from `https://pass.dependentmedia.com/plugin-updates/client-sync-pro/update-info.php`, follows the `download_url` it finds there, and hands the resulting ZIP to `WP_Upgrader` for install. Before signing, the only integrity gate was TLS. So a compromise of the update server, DNS, or any CA in the chain would let an attacker push arbitrary PHP to every licensed site on the next cron-driven update check — RCE blast radius = every Pro customer.

Ed25519 signing moves the root of trust from "whoever controls TLS termination at pass.dependentmedia.com" to "whoever holds the offline secret key". A compromised update server can still DoS updates, but it cannot silently ship code.

### One-time setup: generate the keypair

**Do this ONCE, on a machine you trust, in a clean shell session with no transcript logging.** Generate the keypair with a local PHP CLI:

```bash
php -r '
$kp  = sodium_crypto_sign_keypair();
$pk  = sodium_crypto_sign_publickey( $kp );
$sk  = sodium_crypto_sign_secretkey( $kp );
echo "PUBLIC  (paste into UPDATE_SIGNING_PUBKEY_BASE64): " . base64_encode( $pk ) . "\n";
echo "SECRET  (store offline, NEVER commit):             " . base64_encode( $sk ) . "\n";
'
```

Then:

1. Copy the PUBLIC line and paste into `src/pro/includes/class-update-manager.php`:
   ```php
   const UPDATE_SIGNING_PUBKEY_BASE64 = '<paste base64 public key here>';
   ```
   Commit this change with message `pro: enable update signature enforcement`.
2. Copy the SECRET line into your offline secret store (1Password → Vault → "Client Sync Pro release signing key"). **The secret key must never touch this repository, the test/demo servers, the update server, or any shell history file.** If it ends up in any of those places, rotate: generate a new keypair, replace the public key, and re-sign the current release.
3. Wipe your terminal scrollback (`clear && history -c` in zsh, or restart the terminal).

### Per-release signing procedure

Run this after `bash build.sh zip` produces `build/client-sync-pro-v<VERSION>.zip`:

```bash
# 1. Find the ZIP and compute its sha256
ZIP="build/client-sync-pro-v1.6.3.zip"
ZIP_HASH=$(shasum -a 256 "$ZIP" | awk '{print $1}')
VERSION="1.6.3"
RELEASED_AT=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

# 2. Build the exact JSON bytes to sign. Key order matters — the client
#    re-parses these bytes, but tampered reorderings would still fail sig check.
SIGNED_PAYLOAD=$(printf '{"version":"%s","zip_sha256":"%s","released_at":"%s"}' \
  "$VERSION" "$ZIP_HASH" "$RELEASED_AT")

# 3. Sign the payload with the offline secret key. Paste the base64 secret key
#    at the prompt — do NOT pass it as a CLI argument or env var (it would end
#    up in shell history / /proc).
php -r '
$payload = $argv[1];
fwrite( STDERR, "Paste base64 secret key (input hidden): " );
system( "stty -echo" );
$sk_b64 = trim( fgets( STDIN ) );
system( "stty echo" );
fwrite( STDERR, "\n" );
$sk  = base64_decode( $sk_b64, true );
if ( false === $sk || strlen( $sk ) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ) {
    fwrite( STDERR, "Invalid secret key.\n" ); exit( 1 );
}
$sig = sodium_crypto_sign_detached( $payload, $sk );
echo base64_encode( $sig ) . "\n";
sodium_memzero( $sk );
' "$SIGNED_PAYLOAD"
```

4. Paste both values into `src/pro/update-server/update-info.php`:
   ```php
   $signed_payload = '<the exact JSON from step 2>';
   $signature      = '<the base64 output from step 3>';
   ```
   The `$signed_payload` must be byte-for-byte identical to what was signed. Do NOT let an IDE reformat it or add trailing newlines.
5. Also bump the outer `'version' => '1.6.3'` in the same file so it matches the signed payload. The client verifies that outer and signed versions match and rejects mismatches.
6. Upload the updated `update-info.php` AND the new `client-sync-pro.zip` to `pass.dependentmedia.com` per the standard flow (see "Pro update server" in the gotchas list above).

### Optional helper: `bash build.sh sign`

A `sign` subcommand is wired into `build.sh` that walks through steps 1–3 interactively and prints the two values to paste. It never writes the secret key to disk. See `build.sh` for source.

### Transition / soft-launch behaviour

The first signed release must remain installable by the existing (unsigned) client generation — otherwise every site already running ≤ 1.6.2 bricks the moment 1.6.3 ships. The design handles this by construction:

- **Existing clients (≤ 1.6.2)**: ignore the new `signature` / `signed_payload` fields (they just iterate keys they know about). They install 1.6.3 the old way.
- **New client (1.6.3+) with empty `UPDATE_SIGNING_PUBKEY_BASE64`**: soft-launch mode — accepts the update and logs a warning via `Debug_Logger`. This is the state the very first 1.6.3 build ships in.
- **New client (1.6.3+) with a populated public key**: enforces the signature. Any response missing or failing verification is rejected, and the last known-good response (stored in the `clisyc_pro_update_last_good` option) is returned instead.

Recommended rollout:
1. Ship 1.6.3 with the public key constant EMPTY and the server `update-info.php` still unsigned. Nothing changes behaviourally; the codepath is inert.
2. Once ≥ 95% of customer sites have auto-updated to 1.6.3, cut 1.6.4 with the populated public key constant. Sign the 1.6.4 release. Enforcement is now real.
3. From 1.6.4 onward every release must be signed, or sites running ≥ 1.6.4 will stop updating and fall back to the last-good cached response.

### Files involved

| File | Role |
|------|------|
| `src/pro/includes/class-update-manager.php` | Client-side verifier. Holds the public-key constant and the `verify_and_process()` + `verify_package_before_install()` methods. |
| `src/pro/update-server/update-info.php` | Server-side template. Has `signature` + `signed_payload` fields. |
| `tests/unit/UpdateManagerSignatureTest.php` | Unit tests for accept / tamper-payload / tamper-signature / soft-launch. |
| `build.sh` (`sign` subcommand) | Interactive signing helper. |
| Offline secret store | The secret key. Nothing else. |
