# Smart Start Date

## Overview

Smart Start Date is a user experience enhancement that automatically navigates the booking calendar to the first date with available time slots. Instead of showing today's date (which may have no availability), the calendar jumps ahead to where bookings can actually be made.

## How It Works

When a visitor loads a booking page:

1. **Detection**: The system queries the database for the earliest date that has at least one available time slot
2. **Notification**: If the next available date is in the future, a friendly modal appears: *"Heads Up! The first availability is [Date]. We've jumped ahead to that date for you."*
3. **Navigation**: After the visitor dismisses the modal, the calendar displays the week containing that available date

## Enabling Smart Start Date

1. Navigate to **Client Sync → Settings → Behavior**
2. Find the **Smart Start Date** toggle
3. Enable the setting
4. Save changes

## Technical Details

### Database Query

Smart Start Date uses an optimized database query that:
- Queries the `clisyc_time_slots` table for future slots where `is_block = 0`
- Joins with capacity data to ensure slots have remaining availability
- Converts UTC times to the site's configured timezone
- Caches results for 5 minutes to minimize database load

### Caching

The next available date is cached in two ways:
- **Transient cache**: 5-minute cache for the database query results
- **Option cache**: `clisyc_calendar_smart_start_date_next_available` stores the calculated date

The cache is automatically cleared when:
- New time slots are generated
- Existing slots are deleted
- A booking is made or cancelled

### Limitations

Smart Start Date finds slots that exist in the database but does not account for:

1. **Calendar visible hours**: A slot at 3:00 AM exists but won't display if the calendar is configured to show 8:00 AM - 6:00 PM

2. **Resource conflicts**: A slot may exist for Dr. Smith, but if the required room is already booked by another practitioner at that time, the slot won't be bookable

3. **Timezone edge cases**: Slots stored in UTC may fall on different dates when converted to the site timezone

### Best Practices

For optimal results with Smart Start Date:

- **Generate slots during business hours**: When creating schedules, ensure time slots fall within your calendar's visible range
- **Use consistent timezones**: Set your WordPress timezone (Settings → General) to match your business location
- **Regenerate after changes**: If you modify calendar hours, regenerate affected schedules

## Shortcode Usage

Smart Start Date works automatically with the booking form shortcode:

```
[clisyc_booking_form]
```

No additional shortcode attributes are required. The feature is controlled globally via the Settings → Behavior page.

## Troubleshooting

### Calendar jumps to a date but shows no available slots

This typically means:
- Slots exist but fall outside visible calendar hours
- All slots on that date have resource conflicts
- Slots were booked between the cache update and page load

**Solution**: Check your calendar hour settings and ensure schedules are generated within those hours.

### Smart Start Date shows today even though there's no availability

The feature may be disabled, or the cache contains stale data.

**Solution**: 
1. Verify Smart Start Date is enabled in Settings → Behavior
2. Clear the cache by saving any schedule or going to Tools → Clear Slot Cache

### The notification modal doesn't appear

The modal only appears when the smart start date is different from today.

**Solution**: If today has availability, no jump is needed, so no modal appears.

## API Reference

### PHP Filter

Filter the smart start date before it's sent to JavaScript:

```php
add_filter( 'clisyc_smart_start_date', function( $date ) {
    // $date is a string in 'Y-m-d' format or null
    // Return modified date or null to disable
    return $date;
}, 10, 1 );
```

### JavaScript Access

The smart start date is available in the localized data:

```javascript
const nextAvailableDate = window.clisycBookingFormData?.nextAvailableDate;
// Returns: "2025-12-24" or null
```

## Related Settings

- **Calendar Start Time**: First visible hour on the calendar (Settings → Calendars)
- **Calendar End Time**: Last visible hour on the calendar (Settings → Calendars)
- **Minimum Booking Notice**: How far in advance bookings must be made (Settings → Behavior)