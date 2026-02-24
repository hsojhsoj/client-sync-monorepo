# Client Sync Plugin — AI Session Handoff Guide

This document provides everything a new AI session needs to work on the Client Sync WordPress plugin monorepo effectively. It covers project structure, deployment, version management, testing, and key architectural decisions.

---

## Project Overview

**Client Sync** is a WordPress appointment booking plugin with a freemium model:
- **Free plugin** (`client-sync`) — listed on WordPress.org
- **Pro add-on** (`client-sync-pro`) — distributed separately, requires Free to be active

**Current Versions:** Free `3.4.0` / Pro `1.3.0`

**Local project path:**
```
/Users/joshuajordan/Projects/Code/dm-software/clientSync/___________cs/client-sync-monorepo/
```

---

## Monorepo Structure

```
src/
├── free/              # Free plugin entry point (client-sync.php, readme.txt)
├── pro/               # Pro plugin entry point + pro-only modules
│   └── includes/
│       └── modules/   # Google Sync, Forms, Outputs, Videoconf, Memberships, Packages
├── shared/            # Core code shared by both plugins
│   └── includes/
│       ├── admin/     # Admin pages, settings, views, asset manager
│       ├── ajax/      # AJAX handlers (admin, booking, calendar, slots, appointments)
│       ├── core/      # Plugin bootstrap, CPTs, notifications, cron, DB manager
│       ├── frontend/  # Shortcodes, frontend views
│       ├── rest-api/  # REST controllers (slots, appointments, price, timeline, etc.)
│       ├── repositories/  # Data access layer
│       ├── services/  # Business logic (encryption, audit, availability, HIPAA, etc.)
│       └── utility/   # Security helpers
├── assets/
│   ├── src/           # React components (booking-form, timeline, graph, manage-slots, etc.)
│   ├── js/            # Vanilla JS files (calendar, cards, booking-mode-toggle, etc.)
│   ├── css/           # Stylesheets
│   └── dist/          # Webpack output (gitignored, built by npm run build)
└── blocks/            # Gutenberg block (booking-form)

tests/
├── unit/              # 24 PHPUnit test files
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
./build.sh deploy
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
3. Copies `src/free/` + `src/shared/` + `vendor/` → `build/client-sync/`
4. Copies `src/pro/` + relevant vendor → `build/client-sync-pro/`
5. Deploys both to `/var/www/vhosts/.../wp-content/plugins/` with correct ownership

### Build output expectations:
- Webpack main build: compiles with **3 pre-existing size warnings** (vendor-fullcalendar, manage-slots, booking-form, timeline-viewer exceed 244 KiB). These are expected.
- Webpack block build: compiles with **0 warnings**
- Total build time: ~50-90 seconds on the server

---

## Version Bumping

When releasing a new version, update ALL of these locations:

| File | What to update |
|------|---------------|
| `build.sh` line 12 | `PLUGIN_VERSION="X.Y.Z"` |
| `build.sh` line 13 | `PRO_VERSION="X.Y.Z"` (if Pro changed) |
| `src/free/client-sync.php` line 13 | `Version: X.Y.Z` (plugin header) |
| `src/free/readme.txt` line 7 | `Stable tag: X.Y.Z` |
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

WordPress.org SVN credentials are separate from the test server credentials.

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
1. WordPress loads `client-sync.php` (Free) → defines `clisyc_PLUGIN_DIR`, `CLISYC_SHARED_DIR`
2. Autoloader at `shared/includes/autoloader.php` handles PSR-4 class loading
3. Pro loads at `plugins_loaded` priority 11 → checks Free is active → loads modules

### Constants
All option names, meta keys, post types, and status values are centralized in `src/shared/includes/class-constants.php` (`DependentMedia\ClientSync\Constants`).

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

### Vanilla JS (not webpack — loaded directly)
Files in `src/assets/js/` are loaded as-is (not bundled):
- `clisyc-frontend-appointment-calendar.js` — Main frontend calendar (FullCalendar jQuery)
- `clisyc-admin-booking-mode-toggle.js` — Admin booking mode switcher
- `clisyc-appointments-cards.js` — Card-style appointment view
- Various other admin/frontend scripts

---

## Recent Work Summary (Sessions 1-6)

Over 6 sessions, the following categories of improvements were made:

### Security
- XSS prevention (dangerouslySetInnerHTML removal, output escaping)
- SQL injection fixes (parameterized queries)
- Authorization hardening (capability checks)
- Input validation and sanitization
- Information disclosure prevention (WP_Error messages)
- Nonce verification

### Code Quality
- React Rules of Hooks compliance
- Unhandled promise rejections (`.catch()` added)
- Memory leak fixes (event listener cleanup, tooltip destroy)
- Race condition guards (AbortController, activeRequestRef pattern)
- localStorage try/catch safety
- `is_wp_error()` checks on `wp_insert_post` calls
- Deprecated `mime_content_type()` → `wp_check_filetype()`
- Console.log cleanup, debug flags set to false

### Testing
- Fixed `EncryptionServiceTest` key mismatch (`cipher_supported` → `gcm_supported`)
- FullCalendar package version alignment (all at `^6.1.20`)

### Features
- Plugin Information widget on the Testing page (version, environment, HIPAA/encryption status)

---

## Common Gotchas

1. **No PHP locally** — All PHP tests and composer operations must happen on the server
2. **`CLISYC_PRO_DIR` is NOT defined by the Pro plugin** — Don't rely on it for Pro detection. Use `is_plugin_active('client-sync-pro/client-sync-pro.php')` or check `WP_PLUGIN_DIR`
3. **`CLISYC_VERSION` is referenced but never defined** — The webhook service uses it but it's not set anywhere. It's a latent bug
4. **Build warnings are expected** — 3 webpack size warnings for FullCalendar bundles are normal
5. **File ownership on server** — The build script sets `testblan:psacln` ownership. If you manually copy files, run `chown -R testblan:psacln` on them
6. **The `build/` directory is ephemeral** — It's wiped at the start of each build. Never put anything important there
7. **Monorepo vs built layout** — In the monorepo, `src/shared/` is a sibling of `src/free/`. In the built plugin, shared code is merged into the `client-sync/` directory root. The `CLISYC_SHARED_DIR` constant handles this difference
