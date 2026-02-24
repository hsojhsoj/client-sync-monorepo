# Dimensions & Scheduling

Dimensions are the core building blocks of Client Sync. They represent the entities that participate in a booking — services, staff, rooms, equipment, or any custom category your business needs.

---

## What Are Dimensions?

A **dimension** is a custom post type that represents one axis of your booking system. For example:

- A **hair salon** might have two dimensions: **Services** (Haircut, Coloring) and **Stylists** (Alice, Bob)
- A **medical clinic** might have three: **Services** (Consultation, X-Ray), **Practitioners** (Dr. Carter, Dr. Evans), and **Rooms** (Room 1, Radiology Suite)
- A **boat rental** might have just one: **Boats** (Speedboat, Sailboat)

You can create as many dimensions as your business requires. Each dimension becomes its own section in the WordPress admin with its own list of items.

---

## Key Concepts

### Primary Dimension

One dimension is designated as the **primary dimension**. This is the main entity being booked — typically "Services" or whatever your customers are primarily choosing. The primary dimension:

- Determines the base schedule and slot duration for bookings
- Is the first filter shown in the booking interface
- Controls pricing (the price field lives on the primary dimension)

### Resources (Pro)

A dimension can be marked as a **resource**. Resources add an availability constraint: a booking can only happen when the resource is available AND the other selected dimensions are available. This creates an **intersection** of schedules.

**Example:** In a clinic, "Consulting Room 1" is a resource. A booking with Dr. Carter for a Consultation can only happen during time slots where:
1. The Consultation service is scheduled
2. Dr. Carter is available
3. Consulting Room 1 is free

### Relationships

Relationships define which dimension items can be booked together. If Dr. Carter only performs Consultations (not X-Rays), you create a relationship between the "Consultation" service and "Dr. Carter." The booking form will then only show Dr. Carter as an option when the customer selects "Consultation."

### Filter Order

The filter order determines the sequence in which customers select dimensions during booking. A typical order might be: Service first, then Practitioner, then Room. Each selection narrows the available options for the next filter.

---

## Managing Dimensions

### Creating a Dimension Type

1. Go to **Client Sync > Dimensions**
2. Click **Add New Dimension Type**
3. Configure:
   - **Singular Name** — e.g., "Service"
   - **Plural Name** — e.g., "Services"
   - **Icon** — Choose a Dashicon for the admin menu
   - **Primary** — Check if this is the main bookable entity
   - **Resource** — Check if this dimension constrains availability (Pro)
   - **Frontend Visible** — Whether customers can see and filter by this dimension

### Creating Dimension Items

Once a dimension type is created, it appears in the Client Sync admin menu. Click on it to manage items:

1. Click **Add New** (e.g., "Add New Service")
2. Set the **title** (e.g., "Haircut")
3. Configure the meta fields:
   - **Color** — Used in the calendar display
   - **Price** — Displayed to customers (primary dimension only)
   - **Capacity** — How many concurrent bookings this item supports (default: 1)
   - **Booking Mode** — "Slot" (time slots) or "Range" (date ranges like hotel bookings)
   - **Schedule** — Weekly availability pattern

### Managing Relationships

1. Go to **Client Sync > Dimensions**
2. Use the relationship matrix to define which items from different dimensions can be booked together
3. Check the boxes to create or remove relationships

---

## Scheduling

### Weekly Schedule Pattern

Each dimension item can have its own weekly schedule. The schedule editor lets you define:

- **Day-by-day availability** — Different hours for each day of the week
- **Multiple time blocks per day** — e.g., 9:00-12:00 and 13:00-17:00 (with a lunch break)
- **Days off** — Simply leave a day empty to mark it as unavailable

### Schedule Intersection

When multiple dimensions are selected for a booking, Client Sync calculates the **intersection** of all their schedules. A time slot is only available if ALL selected dimensions are free during that time.

**Example:**
- Service "Consultation" is available Mon-Fri 9:00-17:00
- Dr. Carter works Mon-Wed 9:00-15:00
- Room 1 is available Mon-Fri 8:00-12:00, 13:00-17:30

A booking for Consultation + Dr. Carter + Room 1 on Monday is only available during: **9:00-12:00 and 13:00-15:00** (the overlap of all three schedules).

### Pattern Scheduling (Advanced)

For businesses with rotating schedules, the pattern system supports:
- **Multi-week patterns** — Define different schedules for alternating weeks (Week A, Week B, etc.)
- **Pattern start date** — When the rotation begins
- **Pattern sequence** — The order of pattern templates

### Auto-Generation

Client Sync can automatically generate available time slots based on schedules:

1. Go to **Client Sync > Settings > Automation**
2. Enable **Auto-Generation**
3. Set the **Lookahead** period (how many days ahead to generate slots)

The system runs on WordPress cron and creates slots for the configured number of days in advance.

---

## Booking Modes

### Slot Mode
The default mode. Customers pick a specific time slot on a specific date. Best for:
- Appointments (doctor visits, consultations)
- Classes (yoga, fitness)
- Services (haircuts, spa treatments)

### Range Mode
Customers select a check-in and check-out date. Best for:
- Hotels and accommodations
- Equipment and vehicle rentals
- Vacation properties

The booking mode is set per dimension item and determines how the frontend booking form behaves.

---

## Capacity

Each dimension item has a capacity setting:

- **Capacity 1** (default) — Only one booking per time slot. Standard for one-on-one appointments.
- **Capacity > 1** — Multiple bookings per time slot. Used for group classes, events, or shared resources.

When capacity is reached, the slot shows as fully booked. If the [waitlist feature](Settings-Reference) is enabled, additional customers can join a waitlist.

---

## Buffer Times

Buffer times add padding before and/or after appointments to prevent back-to-back scheduling:

- **Buffer Before** — Minutes reserved before each appointment (e.g., setup time)
- **Buffer After** — Minutes reserved after each appointment (e.g., cleanup time)

Configure buffer times in **Client Sync > Settings > Behavior > Booking Rules**.

---

## Related Pages

- [Onboarding Wizard & Setup Templates](Onboarding-Wizard) — Pre-configured dimension setups
- [Shortcodes Reference](Shortcodes-Reference) — Displaying booking forms on the frontend
- [Settings Reference](Settings-Reference) — Scheduling and automation settings
