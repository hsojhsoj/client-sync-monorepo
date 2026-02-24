# Client Sync Monorepo

This repository contains the source code for the **Client Sync** WordPress plugin. It is structured as a monorepo to manage and build two separate distributable plugins from a single, unified codebase:

1.  **`client-sync` (Core/Free):** The free version available on WordPress.org.
2.  **`client-sync-pro` (Paid Add-on):** A lightweight paid add-on that unlocks advanced features within the free version.

---

## Architectural Overview

The core philosophy of this monorepo is to keep all functional code within a shared directory. The free and pro plugins act primarily as loaders for this shared code, with the pro plugin also handling licensing.

-   **`src/shared/`**: This is the heart of the plugin. **All core PHP logic, classes, services, templates, and assets reside here.** This code is included in the free version but may contain features that are dormant until the Pro add-on is activated.
-   **`src/free/`**: Contains only the essential bootstrap files for the free plugin (`client-sync.php`, `readme.txt`, `uninstall.php`). Its main purpose is to load the shared code.
-   **`src/pro/`**: Contains the bootstrap file for the Pro add-on (`client-sync-pro.php`) and the license management system. It checks if the free version is active and then enables Pro features within the shared code via a license key.
-   **`src/assets/`**: Holds all static assets (CSS, JS, images, etc.) that are used by the shared codebase.
-   **`build/`**: This is the output directory where the final, installable plugin `.zip` files are generated. This directory should be added to `.gitignore`.
-   **`build.sh`**: A shell script that compiles the source files from the `src/` directory into the final, distributable plugins in the `build/` directory.

---

## Prerequisites

To work with this repository and build the plugins, you will need:

-   A Unix-like environment (Linux, macOS, or WSL on Windows).
-   **Bash** to run the build script.
-   **`zip`** command-line utility.
-   **Node.js and npm** to manage JavaScript dependencies for the React components (`npm install`).

---

## Build Process

The `build.sh` script in the root of the project is used to assemble the final plugins.

> **Note:** For local deployment, you must edit `build.sh` and set the `LOCAL_WP_PLUGINS_DIR` variable to the path of your local WordPress installation's `plugins` directory.

You can run the script with the following commands from your terminal:

| Command                  | Description                                                                                             |
| ------------------------ | ------------------------------------------------------------------------------------------------------- |
| `bash build.sh`          | (Default) Cleans the build directory, builds both plugins, creates `.zip` files, and deploys locally.     |
| `bash build.sh zip`      | Builds both the free and pro plugins and creates distributable `.zip` files in the `build/` directory.    |
| `bash build.sh free`     | Builds only the free plugin and deploys it to your local WordPress plugins directory.                     |
| `bash build.sh pro`      | Builds only the pro plugin and deploys it to your local WordPress plugins directory.                      |
| `bash build.sh free-pro` | Builds both plugins and deploys them locally, without creating `.zip` files.                              |

---

## Development Workflow

### Adding a Core Feature (Free)

All new features that are available to everyone should be added directly to the `src/shared/` directory.

1.  Add new PHP classes, functions, or hooks to the relevant files in `src/shared/includes/`.
2.  Add new assets to `src/assets/`.
3.  The feature will automatically be included in the free build.

### Adding a Pro Feature

The key to adding a Pro feature is to implement the code in the shared directory but "gate" it so it only becomes active when a valid Pro license is present.

1.  **Add the UI Element:** Place the UI element (e.g., a form field, a button, a settings section) in the shared codebase (e.g., a view file in `src/shared/includes/admin/views/`).
2.  **Gate the UI:** Wrap the UI element in a conditional check using the global function `clisyc_pro_is_license_active()`. Use the `disabled` attribute for form inputs and add a visual "(Pro)" indicator for clarity.

    **Example (PHP View):**
    ```php
    <input 
        type="checkbox" 
        name="my_pro_feature" 
        <?php disabled( ! function_exists( 'clisyc_pro_is_license_active' ) || ! clisyc_pro_is_license_active() ); ?>
    >
    <label>
        <?php esc_html_e( 'Enable Awesome Pro Feature', 'client-sync' ); ?>
        <?php if ( ! function_exists( 'clisyc_pro_is_license_active' ) ) : ?>
            <a href="..." target="_blank">(Pro)</a>
        <?php endif; ?>
    </label>
    ```

3.  **Add the Backend Logic:** Place the backend logic for the feature in the shared codebase as well.
4.  **Gate the Logic:** Wrap the execution of the backend logic with the same `clisyc_pro_is_license_active()` check. This is often done where a hook is registered.

    **Example (PHP Class):**
    ```php
    class My_Feature_Manager {
        public function register_hooks() {
            // This hook is available to everyone
            add_action( 'some_hook', [ $this, 'free_functionality' ] );

            // This hook is only added if the Pro license is active
            if ( function_exists( 'clisyc_pro_is_license_active' ) && clisyc_pro_is_license_active() ) {
                add_action( 'some_pro_hook', [ $this, 'pro_functionality' ] );
            }
        }
        // ...
    }
    ```

---

## License

This project is licensed under the GPLv2 or later. See the `readme.txt` file for details.







