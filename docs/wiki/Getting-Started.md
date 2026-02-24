# Getting Started

This guide walks you through installing Client Sync, running the setup wizard, and making your first booking page live.

---

## Installation

### Free Plugin (WordPress.org)

1. In your WordPress admin, go to **Plugins > Add New**
2. Search for **"Client Sync"**
3. Click **Install Now**, then **Activate**
4. You'll be redirected to the Setup Wizard automatically

### Pro Add-On

1. Purchase a Pro license from [dependentmedia.com/client-sync](https://dependentmedia.com/client-sync/)
2. Download the `client-sync-pro.zip` file
3. Go to **Plugins > Add New > Upload Plugin**
4. Upload the zip file and click **Install Now**
5. Activate the plugin
6. Go to **Client Sync > License** to enter your license key

> **Note:** The Pro add-on requires the free Client Sync plugin to be active. It extends the free plugin with additional modules.

---

## Setup Wizard

After activation, the setup wizard guides you through initial configuration. You can also access it anytime from **Client Sync > Guide** by clicking "Run Setup Wizard."

### Step 1: Welcome
Introduction to the plugin. Click **Let's Get Started** to proceed.

### Step 2: Choose Your Setup

You have three options:

- **Pre-Configured Template** — Choose from 14 industry-specific templates that automatically configure dimensions, schedules, sample data, and frontend pages. This is the fastest way to get started. See [Onboarding Wizard & Setup Templates](Onboarding-Wizard) for details on each template.

- **Start from Scratch** — Begin with a blank slate. Best for advanced users who want full control over their configuration.

- **Import from File** — Upload a `.json` configuration file to restore a previous setup or migrate between sites.

### Step 3: Page Setup

The wizard creates essential frontend pages with the correct shortcodes pre-configured:

| Page | Shortcode | Purpose |
|------|-----------|---------|
| Booking Calendar | `[clisyc_booking_form]` | Main booking interface |
| My Account | `[clisyc_user_account]` | User profile and account management |
| Appointment Details | `[clisyc_appointment_detail]` | View a single appointment |
| Booking Confirmed | `[clisyc_booking_confirmation]` | Post-booking confirmation page |
| Search Results | `[clisyc_search_results]` | Filtered search results for dimensions |
| Edit Appointment | `[clisyc_manager_edit_appointment]` | Staff appointment editing |
| Contact Us | *(template)* | Fallback contact page |

### Step 4: Finish

Setup is complete. You're directed to the **Guide page** which shows a progress checklist for remaining configuration tasks.

---

## Setup Checklist (Guide Page)

After the wizard, visit **Client Sync > Guide** to see a visual progress bar and checklist of remaining tasks:

- Create your first dimension items (services, staff, etc.)
- Configure schedules and availability
- Set up payment processing (Stripe or WooCommerce)
- Configure email notifications
- Add the booking page to your site navigation
- Test the booking flow end-to-end

Each checklist item includes a link to the relevant settings page and a description of what to do.

---

## First Steps After Setup

### 1. Review Your Dimensions
Go to the dimension menus in the Client Sync sidebar (e.g., Services, Staff, Rooms). If you chose a template, sample items are already created. Edit them to match your real business.

### 2. Set Schedules
Each dimension item can have its own weekly schedule. Edit a dimension item and configure the schedule in the meta box. Availability is calculated by intersecting all dimension schedules.

### 3. Configure Payments (Optional)
If you want to accept payments:
- **Stripe Direct:** Go to **Client Sync > Settings > Payments** and enter your Stripe API keys
- **WooCommerce:** Enable WooCommerce integration and link an appointment product

### 4. Test Your Booking Page
Visit the Booking Calendar page created by the wizard. Try making a test booking to verify everything works.

### 5. Go Live
Add your booking page to your site's navigation menu. You're ready to accept appointments.

---

## Next Steps

- [Onboarding Wizard & Setup Templates](Onboarding-Wizard) — Detailed guide to each template
- [Dimensions & Scheduling](Dimensions-and-Scheduling) — Configure services, staff, rooms, and schedules
- [Settings Reference](Settings-Reference) — All configuration options
- [Shortcodes Reference](Shortcodes-Reference) — Customize your frontend pages
