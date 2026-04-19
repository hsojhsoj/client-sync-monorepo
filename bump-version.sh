#!/bin/bash
#
# bump-version.sh — single command to bump Client Sync version numbers in all the
# places that have to agree with each other. Replaces the multi-step manual
# checklist in AI_HANDOFF.md.
#
# Usage:
#   bash bump-version.sh --free 3.7.4            # bump only the free plugin
#   bash bump-version.sh --pro 1.6.3             # bump only the pro plugin
#   bash bump-version.sh --free 3.7.4 --pro 1.6.3  # bump both together
#   bash bump-version.sh --free 3.7.4 --dry-run  # show what would change, change nothing
#
# The script:
#   - Reads the current versions from the authoritative sources (build.sh)
#   - Verifies every secondary location matches the current version before editing
#     (so a partial earlier edit fails loudly instead of producing a split brain)
#   - Rewrites all locations in one go
#   - Updates `last_updated` in the pro update-info.php to today's date when --pro is given
#   - Does NOT add changelog entries (keep those manual — prose doesn't automate well)
#   - Does NOT commit, push, deploy, or touch the update server
#
# After running, you still need to:
#   1. Add a changelog entry to src/free/readme.txt (and src/pro/readme.txt if --pro)
#   2. Add a changelog <h4>X.Y.Z</h4> block to src/pro/update-server/update-info.php
#   3. Commit, push, and deploy

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

FREE_NEW=""
PRO_NEW=""
DRY_RUN=0

# --- Argument parsing ------------------------------------------------------

while [[ $# -gt 0 ]]; do
    case "$1" in
        --free)
            FREE_NEW="$2"; shift 2 ;;
        --pro)
            PRO_NEW="$2"; shift 2 ;;
        --dry-run)
            DRY_RUN=1; shift ;;
        -h|--help)
            grep -E '^#( |$)' "$0" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *)
            echo "ERROR: unknown argument: $1" >&2
            echo "Run: bash bump-version.sh --help" >&2
            exit 1 ;;
    esac
done

if [[ -z "$FREE_NEW" && -z "$PRO_NEW" ]]; then
    echo "ERROR: must specify at least one of --free X.Y.Z or --pro X.Y.Z" >&2
    exit 1
fi

# Validate the version format for any supplied versions. semver-ish, no
# pre-release suffix support — keep it simple, the plugin doesn't use them.
for v in "$FREE_NEW" "$PRO_NEW"; do
    if [[ -n "$v" && ! "$v" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        echo "ERROR: version '$v' is not in X.Y.Z format" >&2
        exit 1
    fi
done

# --- Read current versions from build.sh (the authoritative source) --------

FREE_OLD="$(grep -E '^PLUGIN_VERSION=' build.sh | head -1 | sed -E 's/.*"([^"]+)".*/\1/')"
PRO_OLD="$(grep -E '^PRO_VERSION='    build.sh | head -1 | sed -E 's/.*"([^"]+)".*/\1/')"

if [[ -z "$FREE_OLD" || -z "$PRO_OLD" ]]; then
    echo "ERROR: failed to read current versions from build.sh" >&2
    exit 1
fi

echo "Current: free=$FREE_OLD  pro=$PRO_OLD"
echo "New:     free=${FREE_NEW:-$FREE_OLD}  pro=${PRO_NEW:-$PRO_OLD}"
[[ $DRY_RUN -eq 1 ]] && echo "(dry run — no files will be modified)"
echo

# --- Verify all secondary locations currently match build.sh ---------------
#
# Each entry is: TAG|FILE|REGEX  — REGEX must match exactly one line in FILE,
# and the captured version must equal the expected OLD value.

check_location() {
    local tag="$1" file="$2" pattern="$3" expected="$4"
    local found
    found="$(grep -E "$pattern" "$file" | head -1 || true)"
    if [[ -z "$found" ]]; then
        echo "ERROR: [$tag] no line matching /$pattern/ in $file" >&2
        return 1
    fi
    # Extract the first X.Y.Z from the matched line.
    local actual
    actual="$(echo "$found" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"
    if [[ "$actual" != "$expected" ]]; then
        echo "ERROR: [$tag] expected version $expected in $file, found $actual" >&2
        echo "       line: $found" >&2
        return 1
    fi
    echo "  ok  [$tag] $file ($actual)"
}

do_bump_free=0
do_bump_pro=0
[[ -n "$FREE_NEW" && "$FREE_NEW" != "$FREE_OLD" ]] && do_bump_free=1
[[ -n "$PRO_NEW"  && "$PRO_NEW"  != "$PRO_OLD"  ]] && do_bump_pro=1

if [[ $do_bump_free -eq 0 && $do_bump_pro -eq 0 ]]; then
    echo "Nothing to do — supplied versions match current." >&2
    exit 0
fi

echo "Verifying current state…"
if [[ $do_bump_free -eq 1 ]]; then
    check_location "build.sh (free)"       build.sh                            '^PLUGIN_VERSION='       "$FREE_OLD"
    check_location "client-sync.php hdr"    src/free/client-sync.php            '^ \* Version:'          "$FREE_OLD"
    check_location "CLISYC_VERSION"         src/free/client-sync.php            "define\\( 'CLISYC_VERSION'"        "$FREE_OLD"
    check_location "free readme.txt"        src/free/readme.txt                 '^Stable tag:'           "$FREE_OLD"
fi
if [[ $do_bump_pro -eq 1 ]]; then
    check_location "build.sh (pro)"         build.sh                            '^PRO_VERSION='          "$PRO_OLD"
    check_location "client-sync-pro.php hdr" src/pro/client-sync-pro.php         '^ \* Version:'          "$PRO_OLD"
    check_location "pro readme.txt"         src/pro/readme.txt                  '^Stable tag:'           "$PRO_OLD"
    check_location "update-info.php ver"    src/pro/update-server/update-info.php "'version'"            "$PRO_OLD"
fi
echo

# --- Apply edits -----------------------------------------------------------

# BSD sed (macOS) and GNU sed (Linux) differ on -i; use a portable wrapper.
sed_inplace() {
    if [[ "$(uname)" == "Darwin" ]]; then
        sed -i '' "$@"
    else
        sed -i "$@"
    fi
}

apply() {
    local description="$1" file="$2" pattern="$3"
    echo "  edit $description  ($file)"
    if [[ $DRY_RUN -eq 0 ]]; then
        sed_inplace -E "$pattern" "$file"
    fi
}

echo "Applying edits…"
if [[ $do_bump_free -eq 1 ]]; then
    apply "build.sh PLUGIN_VERSION"           build.sh                            "s/^PLUGIN_VERSION=\"$FREE_OLD\"/PLUGIN_VERSION=\"$FREE_NEW\"/"
    apply "client-sync.php header"            src/free/client-sync.php            "s|^( \* Version: +)$FREE_OLD|\1$FREE_NEW|"
    apply "client-sync.php CLISYC_VERSION"    src/free/client-sync.php            "s/'CLISYC_VERSION', '$FREE_OLD'/'CLISYC_VERSION', '$FREE_NEW'/"
    apply "free readme.txt Stable tag"        src/free/readme.txt                 "s/^Stable tag: $FREE_OLD/Stable tag: $FREE_NEW/"
fi
TODAY="$(date +%Y-%m-%d)"
if [[ $do_bump_pro -eq 1 ]]; then
    apply "build.sh PRO_VERSION"              build.sh                            "s/^PRO_VERSION=\"$PRO_OLD\"/PRO_VERSION=\"$PRO_NEW\"/"
    apply "client-sync-pro.php header"        src/pro/client-sync-pro.php         "s|^( \* Version: +)$PRO_OLD|\1$PRO_NEW|"
    apply "pro readme.txt Stable tag"         src/pro/readme.txt                  "s/^Stable tag: $PRO_OLD/Stable tag: $PRO_NEW/"
    apply "update-info.php version"           src/pro/update-server/update-info.php "s/'version'      => '$PRO_OLD'/'version'      => '$PRO_NEW'/"
    apply "update-info.php last_updated"      src/pro/update-server/update-info.php "s/'last_updated' => '[0-9-]+'/'last_updated' => '$TODAY'/"
fi
echo

# --- Verify the edits actually landed --------------------------------------

if [[ $DRY_RUN -eq 0 ]]; then
    echo "Verifying new state…"
    if [[ $do_bump_free -eq 1 ]]; then
        check_location "build.sh (free)"       build.sh                            '^PLUGIN_VERSION='       "$FREE_NEW"
        check_location "client-sync.php hdr"    src/free/client-sync.php            '^ \* Version:'          "$FREE_NEW"
        check_location "CLISYC_VERSION"         src/free/client-sync.php            "define\\( 'CLISYC_VERSION'"        "$FREE_NEW"
        check_location "free readme.txt"        src/free/readme.txt                 '^Stable tag:'           "$FREE_NEW"
    fi
    if [[ $do_bump_pro -eq 1 ]]; then
        check_location "build.sh (pro)"         build.sh                            '^PRO_VERSION='          "$PRO_NEW"
        check_location "client-sync-pro.php hdr" src/pro/client-sync-pro.php         '^ \* Version:'          "$PRO_NEW"
        check_location "pro readme.txt"         src/pro/readme.txt                  '^Stable tag:'           "$PRO_NEW"
        check_location "update-info.php ver"    src/pro/update-server/update-info.php "'version'"            "$PRO_NEW"
    fi
    echo
fi

echo "Done."
echo
echo "Next steps:"
[[ $do_bump_free -eq 1 ]] && echo "  - Add a changelog entry to src/free/readme.txt for $FREE_NEW"
[[ $do_bump_pro -eq 1  ]] && echo "  - Add a changelog entry to src/pro/readme.txt for $PRO_NEW"
[[ $do_bump_pro -eq 1  ]] && echo "  - Add an <h4>$PRO_NEW</h4> block to src/pro/update-server/update-info.php's changelog"
echo "  - git diff, review, commit"
echo "  - bash build.sh deploy"
