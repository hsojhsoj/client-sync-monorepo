# Onboarding Wizard & Setup Templates

The onboarding wizard helps you configure Client Sync in minutes by choosing a pre-configured template that matches your industry. Each template sets up dimensions, schedules, sample data, relationships, notification templates, and frontend pages automatically.

---

## Accessing the Wizard

- **First activation:** The wizard runs automatically after activating the plugin
- **Anytime:** Go to **Client Sync > Guide** and click **Run Setup Wizard**

> **Warning:** Running the wizard with a template will replace your existing Client Sync data (dimensions, schedules, appointments, membership plans, and notification templates). You'll see a confirmation prompt before any data is removed.

---

## Available Templates

### Simple Booking
**Best for:** Solo practitioners, tutors, single-service businesses

A minimal setup with one service dimension. Great starting point if you offer a single type of appointment. Configured with standard weekday hours and a straightforward booking calendar.

---

### Hair Salon
**Best for:** Salons, barbershops, beauty studios

Configured with:
- **Dimensions:** Services (Haircut, Coloring, Styling) and Stylists
- **Relationships:** Which stylists offer which services
- **Schedules:** Standard salon hours with lunch breaks
- **Membership Plans:** Monthly styling packages with booking limits and discounts
- **Notifications:** Booking confirmation, reminders, and cancellation notices

---

### Fitness Class
**Best for:** Gyms, yoga studios, dance schools, group fitness

Configured with:
- **Dimensions:** Classes (Yoga, HIIT, Spin) and Instructors
- **Schedules:** Class-specific time blocks throughout the week
- **Capacity:** Multiple spots per class for group bookings
- **Membership Plans:** Unlimited class passes with member discounts
- **Notifications:** Class confirmation and reminder templates

---

### Medical Clinic
**Best for:** Small clinics, dental offices, therapy practices

A simpler clinic setup with:
- **Dimensions:** Services (Consultation, Follow-up) and Doctors
- **Schedules:** Standard clinic hours with appointment slots

---

### Advanced Clinic (with Resources) `New`
**Best for:** Multi-room clinics, radiology centers, facilities with shared equipment

Demonstrates the resource intersection model:
- **Dimensions:** Services (Consultation, X-Ray), Practitioners (Dr. Carter, Dr. Evans), and Rooms (Consulting Room, Radiology Suite)
- **Resources:** Rooms are marked as resources, meaning a booking is only possible when the service, practitioner, AND room are all available simultaneously
- **Relationships:** Service-to-practitioner and practitioner-to-room mappings
- **Membership Plans:** Wellness Plan ($89/month) and Comprehensive Care ($249/month)
- **Notifications:** Full suite including booking confirmation, reminders, cancellation, payment receipt, and admin alerts

---

### Modern Consultant / Coach `New`
**Best for:** Consultants, coaches, freelancers, agencies

Configured with:
- **Dimensions:** Services (Strategy Session, Implementation Workshop, Monthly Retainer Call) and Consultants
- **Schedules:** Business hours with varying availability per service
- **Membership Plans:** Monthly Retainer ($199/month) and VIP Access ($499/month) with booking limits and percentage discounts
- **Notifications:** Professional booking and payment notification templates

---

### Event Venue `New`
**Best for:** Event spaces, theaters, conference centers, community halls

Configured with:
- **Dimensions:** Event Types (Corporate Workshop, Social Event, Performance) and Venues (Main Hall, Garden Terrace, Board Room)
- **Schedules:** Venue-specific availability with morning and afternoon blocks
- **Capacity:** Different capacities per venue
- **Membership Plans:** Season Pass ($199/month) and Patron Circle ($499/month)
- **Notifications:** Event confirmation and management templates

---

### Boat Rental
**Best for:** Simple rental businesses, single-fleet operations

Basic rental setup with:
- **Dimensions:** Boats with daily availability slots

---

### Boat Rental (Advanced)
**Best for:** Marina operators, multi-boat rental companies

Extended rental setup with:
- **Dimensions:** Boat types and individual boats
- **Schedules:** Multi-day rental support with check-in/check-out times

---

### Equipment Rental
**Best for:** Tool rental, camera gear, party supplies, AV equipment

Configured with:
- **Dimensions:** Equipment categories and individual items
- **Schedules:** Rental period availability

---

### Hotel Booking
**Best for:** Hotels, B&Bs, hostels

Configured with:
- **Dimensions:** Room types with date-range booking mode
- **Schedules:** Daily availability with check-in/check-out

---

### Vacation Rental
**Best for:** Airbnb-style rentals, cabin rentals, holiday homes

Configured with:
- **Dimensions:** Properties with date-range booking
- **Schedules:** Seasonal availability

---

### Accommodations
**Best for:** Campgrounds, retreat centers, multi-unit properties

Configured with:
- **Dimensions:** Accommodation types and units
- **Schedules:** Flexible date-range availability

---

### Employee Scheduling
**Best for:** Shift management, staff rostering, work scheduling

Configured with:
- **Dimensions:** Departments, Positions, and Employees
- **Schedules:** Shift-based availability patterns

---

## Template Features

Each template can include:

| Feature | Description |
|---------|-------------|
| **Dimensions** | Custom post types for your booking entities |
| **Schedules** | Weekly availability patterns with time slots |
| **Relationships** | Which dimensions work together (e.g., which doctor is in which room) |
| **Sample Data** | Pre-created dimension items with names, colors, and prices |
| **Membership Plans** | Pre-configured subscription plans with access rules |
| **Pages** | Frontend pages with shortcodes (booking, membership plans) |
| **Output Templates** | Notification templates for emails (Pro) |

---

## After Template Installation

1. **Edit dimension items** to match your real business (rename sample services, staff, etc.)
2. **Adjust schedules** to match your actual operating hours
3. **Update prices** if applicable
4. **Configure payments** in Settings > Payments
5. **Review notification templates** in Settings > Notifications
6. **Test the booking flow** on your frontend pages

---

## Importing & Exporting Configurations

### Export
Go to **Client Sync > Settings > Import / Export** to download your current configuration as a `.json` file. This captures all dimensions, schedules, options, and relationships.

### Import
You can import a configuration file:
- During the setup wizard (Step 2, "Import from File")
- From **Client Sync > Settings > Import / Export**

This is useful for:
- Migrating between sites (staging to production)
- Sharing configurations between team members
- Backing up before making major changes

---

## Related Pages

- [Getting Started](Getting-Started) — Installation and first steps
- [Dimensions & Scheduling](Dimensions-and-Scheduling) — Understanding dimensions in depth
- [Membership Plans](Membership-Plans) — Configuring subscription plans
