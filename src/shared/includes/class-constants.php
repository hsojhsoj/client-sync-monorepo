<?php
/**
 * File: src/shared/includes/class-constants.php
 *
 * Centralised registry of every option name, meta key, post-type slug,
 * status value and cron hook used by Client Sync.
 *
 * Usage:  use DependentMedia\ClientSync\Constants;
 *         get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
 *
 * @package    ClientSync
 * @subpackage ClientSync/Constants
 */

namespace DependentMedia\ClientSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value-object that holds every magic string in one place.
 */
final class Constants {

	// ── Post Types ─────────────────────────────────────────────────────
	const POST_TYPE_APPOINTMENT = 'clisyc_appointment';
	const POST_TYPE_SERVICE     = 'clisyc_service';

	// ── Dimension Registry ─────────────────────────────────────────────
	const OPTION_DIMENSION_REGISTRY     = 'clisyc_dimension_registry';
	const OPTION_CUSTOM_DIMENSION_TYPES = 'clisyc_custom_dimension_types';
	const OPTION_DIMENSION_FIELDS       = 'clisyc_dimension_fields';
	const OPTION_AVAILABILITY_DIM_FIELDS = 'clisyc_availability_dimensions_fields';
	const OPTION_PRIMARY_SERVICE_DIM    = 'clisyc_primary_service_dimension_key';
	const OPTION_PERSONNEL_DIM_SLUG     = 'clisyc_personnel_dimension_slug';

	// ── Calendar Options ───────────────────────────────────────────────
	const OPTION_CALENDAR_SLOT_DURATION  = 'clisyc_calendar_slot_duration';
	const OPTION_CALENDAR_SLOT_HEIGHT    = 'clisyc_calendar_slot_height';
	const OPTION_CALENDAR_START_TIME     = 'clisyc_calendar_start_time';
	const OPTION_CALENDAR_END_TIME       = 'clisyc_calendar_end_time';
	const OPTION_CALENDAR_ENABLED_VIEWS  = 'clisyc_calendar_enabled_views';
	const OPTION_CALENDAR_WEEK_STARTS    = 'clisyc_calendar_week_starts_on';
	const OPTION_CALENDAR_COLOR_SETTINGS = 'clisyc_calendar_color_settings';
	const OPTION_CALENDAR_SMART_START    = 'clisyc_calendar_smart_start_date';
	const OPTION_CALENDAR_SMART_START_NA = 'clisyc_calendar_smart_start_date_next_available';
	const OPTION_FRONTEND_CALENDAR_STYLE = 'clisyc_frontend_calendar_style';

	// ── Page IDs ───────────────────────────────────────────────────────
	const OPTION_BOOKING_PAGE_ID       = 'clisyc_booking_page_id';
	const OPTION_BOOKING_SUCCESS_PAGE  = 'clisyc_booking_success_page_id';
	const OPTION_APPOINTMENT_VIEW_PAGE = 'clisyc_appointment_view_page_id';
	const OPTION_MY_APPOINTMENTS_PAGE  = 'clisyc_my_appointments_page_id';
	const OPTION_MANAGER_EDIT_PAGE     = 'clisyc_manager_edit_page_id';
	const OPTION_CONTACT_PAGE          = 'clisyc_contact_page_id';
	const OPTION_SEARCH_RESULTS_PAGE   = 'clisyc_search_results_page_id';
	const OPTION_APPOINTMENT_VIEW_SLUG = 'clisyc_appointment_view_page_slug';

	// ── Scheduling Options ─────────────────────────────────────────────
	const OPTION_AUTO_GEN_ENABLED    = 'clisyc_auto_generate_enabled';
	const OPTION_AUTO_GEN_LOOKAHEAD  = 'clisyc_auto_generate_lookahead';
	const OPTION_AUTO_GEN_DAYS       = 'clisyc_auto_generate_days_of_week';
	const OPTION_MIN_BOOKING_NOTICE  = 'clisyc_min_booking_notice_minutes';
	const OPTION_DEFAULT_BUFFER_DAYS = 'clisyc_default_buffer_days';
	const OPTION_DEFAULT_BOOKING_MODE = 'clisyc_default_booking_mode';
	const OPTION_GLOBAL_BUFFER_BEFORE = 'clisyc_global_buffer_before';
	const OPTION_GLOBAL_BUFFER_AFTER  = 'clisyc_global_buffer_after';
	const OPTION_SLOT_GEN_STATE       = 'clisyc_slot_gen_state';

	// ── WooCommerce Integration ────────────────────────────────────────
	const OPTION_WC_ENABLED    = 'clisyc_wc_integration_enabled';
	const OPTION_WC_PRODUCT_ID = 'clisyc_wc_appointment_product_id';

	// ── Appointment Meta Keys ──────────────────────────────────────────
	const META_TIME_SLOT        = '_clisyc_time_slot';
	const META_APPOINTMENT_DATE = '_clisyc_appointment_date';
	const META_SLOT_DIMENSIONS  = '_clisyc_slot_dimensions';
	const META_BOOKING_MODE     = '_clisyc_booking_mode';
	const META_START_DATE       = '_clisyc_start_date';
	const META_END_DATE         = '_clisyc_end_date';
	const META_STATUS           = '_clisyc_status';
	const META_CLIENT_ID        = '_clisyc_client_id';
	const META_SERVICE_NAME     = '_clisyc_service_name';
	const META_NOTES            = '_clisyc_notes';
	const META_REMINDER_SENT    = '_clisyc_appointment_reminder_sent';
	const META_APPOINTMENT_DURATION = '_clisyc_appointment_duration';

	// ── Service / Dimension Meta Keys ──────────────────────────────────
	const META_DURATION_MINUTES  = '_clisyc_duration_minutes';
	const META_PADDING_MINUTES   = '_clisyc_padding_minutes';
	const META_PRICE             = '_clisyc_price';
	const META_CAPACITY          = '_clisyc_capacity';
	const META_BUFFER_BEFORE     = '_clisyc_buffer_before';
	const META_BUFFER_AFTER      = '_clisyc_buffer_after';
	const META_BUFFER_DAYS       = '_clisyc_buffer_days';
	const META_MIN_STAY          = '_clisyc_min_stay';
	const META_MAX_STAY          = '_clisyc_max_stay';
	const META_ALLOWED_CHECKIN   = '_clisyc_allowed_checkin_days';
	const META_ALLOWED_CHECKOUT  = '_clisyc_allowed_checkout_days';
	const META_SCHEDULE          = '_clisyc_schedule';
	const META_SCHEDULE_OVERRIDES = '_clisyc_schedule_overrides';
	const META_SCHEDULE_JSON     = '_clisyc_schedule_json';
	const META_COLOR             = '_clisyc_color';
	const META_EMPLOYEE_USER_ID  = '_clisyc_employee_user_id';
	const META_WC_PRODUCT_ID     = '_clisyc_wc_product_id';
	const META_ADDITIONAL_PRODUCTS = '_clisyc_additional_products';
	const META_ADDON_CALC_TYPE   = '_clisyc_addon_calc_type';
	const META_PRICING_RULES     = '_clisyc_pricing_rules';

	// ── Payment Meta Keys ──────────────────────────────────────────────
	const META_WC_ORDER_ID      = '_clisyc_wc_order_id';
	const META_WC_ORDER_LINK    = '_clisyc_wc_order_link';
	const META_PAYMENT_STATUS   = '_clisyc_payment_status';
	const META_PAYMENT_DUE_DATE = '_clisyc_payment_due_date';
	const META_WC_TOKEN_ID      = '_clisyc_wc_token_id';

	// ── Stripe Integration ────────────────────────────────────────────
	const OPTION_STRIPE_ENABLED        = 'clisyc_stripe_enabled';
	const OPTION_STRIPE_KEY_PUBLIC     = 'clisyc_stripe_key_public';
	const OPTION_STRIPE_KEY_SECRET     = 'clisyc_stripe_key_secret';
	const OPTION_STRIPE_WEBHOOK_SECRET = 'clisyc_stripe_webhook_secret';
	const META_STRIPE_SESSION_ID       = '_clisyc_stripe_session_id';
	const META_STRIPE_PAYMENT_INTENT   = '_clisyc_stripe_payment_intent';
	const META_STRIPE_CUSTOMER_ID      = '_clisyc_stripe_customer_id'; // User meta: Stripe Customer ID
	const META_STRIPE_REFUND_ID        = '_clisyc_stripe_refund_id';   // Post meta: Stripe Refund ID

	// ── Stripe Subscriptions (Pro) ───────────────────────────────────
	const META_STRIPE_SUBSCRIPTION_ID  = '_clisyc_stripe_subscription_id';  // User meta: Stripe sub ID (sub_xxx)
	const META_STRIPE_SUB_STATUS       = '_clisyc_stripe_sub_status';       // User meta: active|past_due|canceled|trialing
	const META_STRIPE_SUB_PLAN_ID      = '_clisyc_stripe_sub_plan_id';      // User meta: clisyc_member_plan post ID
	const META_SUBSCRIPTION_PERIOD_END = '_clisyc_sub_period_end';          // User meta: current billing period end
	const META_LINKED_STRIPE_PRICE_ID  = '_clisyc_linked_stripe_price_id';  // Plan post meta: Stripe Price ID (price_xxx)
	const META_STRIPE_TRIAL_DAYS       = '_clisyc_stripe_trial_days';       // Plan post meta: trial period in days

	// ── Seat Selection (Pro) ─────────────────────────────────────────
	const POST_TYPE_VENUE             = 'clisyc_venue';
	const META_VENUE_LAYOUT           = '_clisyc_venue_layout';           // Venue post meta: parsed layout JSON
	const META_VENUE_SVG              = '_clisyc_venue_svg';              // Venue post meta: raw SVG string
	const META_VENUE_OVERVIEW_SVG     = '_clisyc_venue_overview_svg';     // Venue post meta: section overview SVG
	const META_VENUE_PARSING_MODE     = '_clisyc_venue_parsing_mode';     // Venue post meta: flat_ids | nested_groups
	const META_LINKED_VENUE_ID        = '_clisyc_linked_venue_id';        // Dimension post meta: links to venue CPT
	const META_SEAT_PRICING_TIERS     = '_clisyc_seat_pricing_tiers';     // Venue post meta: [{category, price}]
	const META_SEAT_CATEGORY_OVERRIDES = '_clisyc_seat_category_overrides'; // Venue post meta: admin overrides per section
	const META_SELECTED_SEATS         = '_clisyc_selected_seats';         // Appointment post meta: confirmed seat IDs
	const META_QR_TOKEN               = '_clisyc_qr_token';              // Appointment post meta: unique QR check-in token
	const META_CHECKED_IN_AT          = '_clisyc_checked_in_at';         // Appointment post meta: check-in timestamp
	const OPTION_SEAT_HOLD_TTL        = 'clisyc_seat_hold_ttl';          // Hold expiry in seconds (default 300)
	const META_VENUE_ADDRESS          = '_clisyc_venue_address';         // Venue post meta: street address
	const META_VENUE_CITY             = '_clisyc_venue_city';            // Venue post meta: city
	const META_VENUE_STATE            = '_clisyc_venue_state';           // Venue post meta: state/province
	const META_VENUE_POSTAL_CODE      = '_clisyc_venue_postal_code';    // Venue post meta: postal/zip code
	const META_VENUE_COUNTRY          = '_clisyc_venue_country';        // Venue post meta: country
	const META_VENUE_MAP_EMBED        = '_clisyc_venue_map_embed';      // Venue post meta: map embed URL (Google Maps/OSM)

	// ── Video Conferencing ────────────────────────────────────────────
	const META_MEETING_LINK       = '_clisyc_meeting_link';
	const META_MEETING_PROVIDER   = '_clisyc_meeting_provider';
	const META_MEETING_ID         = '_clisyc_meeting_id';
	const META_MEETING_PASSWORD   = '_clisyc_meeting_password';
	const META_VIDEO_CONF_ENABLED = '_clisyc_video_conferencing';

	const OPTION_VIDEO_PROVIDER       = 'clisyc_video_conferencing_provider';
	const OPTION_ZOOM_API_CREDENTIALS = 'clisyc_zoom_api_credentials';

	// ── Appointment Statuses ───────────────────────────────────────────
	const STATUS_PENDING         = 'pending';
	const STATUS_CONFIRMED       = 'confirmed';
	const STATUS_CANCELLED       = 'cancelled';
	const STATUS_COMPLETED       = 'completed';
	const STATUS_NO_SHOW         = 'no-show';
	const STATUS_PENDING_PAYMENT = 'clisyc_pending_pay';  // ≤20 chars (MySQL varchar limit)
	const STATUS_PAID_ON_DAY     = 'clisyc_paid_on_day';
	const STATUS_FAILED_ON_DAY   = 'clisyc_failed_on_day';
	const STATUS_WAITLISTED      = 'clisyc_waitlisted';   // ≤20 chars (MySQL varchar limit)
	const STATUS_CHECKED_IN      = 'clisyc_checked_in';   // ≤20 chars (MySQL varchar limit)

	// ── Waitlist ──────────────────────────────────────────────────────
	const META_WAITLIST_POSITION  = '_clisyc_waitlist_pos';
	const META_WAITLIST_JOINED_AT = '_clisyc_waitlist_joined';
	const META_WAITLIST_SLOT_ID   = '_clisyc_waitlist_slot';
	const OPTION_WAITLIST_ENABLED      = 'clisyc_waitlist_enabled';
	const OPTION_WAITLIST_MAX_SIZE     = 'clisyc_waitlist_max_per_slot';
	const OPTION_WAITLIST_AUTO_PROMOTE = 'clisyc_waitlist_auto_promote';

	// ── Recurring Series ─────────────────────────────────────────────
	const META_SERIES_ID        = '_clisyc_series_id';
	const META_SERIES_INDEX     = '_clisyc_series_index';
	const META_SERIES_TOTAL     = '_clisyc_series_total';
	const META_SERIES_FREQUENCY = '_clisyc_series_frequency';

	// ── Email / Notifications ──────────────────────────────────────────
	const OPTION_EMAIL_FROM_NAME       = 'clisyc_email_from_name';
	const OPTION_EMAIL_FROM_ADDRESS    = 'clisyc_email_from_address';
	const OPTION_NOTIFICATION_SETTINGS = 'clisyc_notification_settings';
	const OPTION_NOTIFICATION_ADMINS   = 'clisyc_notification_admin_recipients';
	const OPTION_REMINDER_SETTINGS     = 'clisyc_reminder_settings';
	const OPTION_SMS_CREDENTIALS       = 'clisyc_sms_credentials';
	const OPTION_WEBHOOKS              = 'clisyc_webhooks';

	// ── HIPAA / Audit ──────────────────────────────────────────────────
	const OPTION_HIPAA_MODE          = 'clisyc_hipaa_mode_enabled';
	const OPTION_AUDIT_LOG_ENABLED   = 'clisyc_audit_logging_enabled';
	const OPTION_AUDIT_LOG_RETENTION = 'clisyc_audit_log_retention_days';

	// ── Cancellation ───────────────────────────────────────────────────
	const OPTION_CANCEL_CUTOFF_VALUE  = 'clisyc_cancellation_cutoff_value';
	const OPTION_CANCEL_CUTOFF_UNIT   = 'clisyc_cancellation_cutoff_unit';
	const OPTION_CANCEL_REFUND_POLICY = 'clisyc_cancellation_refund_policy';

	// ── Antispam ───────────────────────────────────────────────────────
	const OPTION_ANTISPAM_HONEYPOT   = 'clisyc_antispam_honeypot_enabled';
	const OPTION_ANTISPAM_TIME_CHECK = 'clisyc_antispam_time_check_enabled';

	// ── Misc Options ───────────────────────────────────────────────────
	const OPTION_LOGIN_PAGE_URL      = 'clisyc_login_page_url';
	const OPTION_ENABLE_SELF_SERVICE = 'clisyc_enable_self_service';
	const OPTION_SETUP_MILESTONES    = 'clisyc_setup_milestones_completed';
	const OPTION_DELETE_ON_UNINSTALL = 'clisyc_delete_user_data_on_uninstall';
	const OPTION_APPOINTMENT_FIELDS  = 'clisyc_appointment_custom_fields';
	const OPTION_CUSTOM_FIELDS       = 'clisyc_custom_fields';
	const OPTION_CUSTOM_FIELDS_ORDER = 'clisyc_custom_fields_order';
	const OPTION_GRAPH_VISUAL_STATE  = 'clisyc_graph_visual_state';
	const OPTION_UNIVERSAL_SHORTCODE_MODE = 'clisyc_universal_shortcode_default_mode';
	const OPTION_INITIAL_VIEW        = 'initial_view';
	const OPTION_ANONYMIZE_EXTERNAL_SYNC = 'clisyc_anonymize_external_sync';
	const OPTION_GLOBAL_BLOCKED_PERIODS  = 'clisyc_global_blocked_periods';
	const OPTION_CLIENT_LIST_CF_COLUMN   = 'clisyc_client_list_custom_field_column';

	// ── Messaging (Pro) ───────────────────────────────────────────────
	const OPTION_MESSAGING_ENABLED        = 'clisyc_messaging_enabled';
	const OPTION_MESSAGING_ROLES_INITIATE = 'clisyc_messaging_roles_initiate';
	const OPTION_MESSAGING_ROLES_REPLY    = 'clisyc_messaging_roles_reply';
	const OPTION_MESSAGING_MAX_FILE_SIZE  = 'clisyc_messaging_max_file_size';
	const OPTION_MESSAGING_ALLOWED_TYPES  = 'clisyc_messaging_allowed_file_types';
	const OPTION_MESSAGING_REALTIME       = 'clisyc_messaging_realtime_enabled';
	const OPTION_MESSAGING_RETENTION_DAYS = 'clisyc_messaging_retention_days';
	const OPTION_MESSAGING_POLL_INTERVAL  = 'clisyc_messaging_poll_interval';
	const OPTION_MESSAGING_DB_VERSION     = 'clisyc_messaging_db_version';

	// ── Thread Status Values ──────────────────────────────────────────
	const THREAD_STATUS_OPEN            = 'open';
	const THREAD_STATUS_AWAITING_REPLY  = 'awaiting_reply';
	const THREAD_STATUS_RESOLVED        = 'resolved';

	// ── Cron / Maintenance ─────────────────────────────────────────────
	const ACTION_FREQUENT_MAINTENANCE = 'clisyc_frequent_maintenance_tasks';
	const OPTION_LAST_MAINTENANCE_TS  = 'clisyc_last_frequent_maintenance_run_timestamp';
	const OPTION_LAST_SUCCESS_TS      = 'clisyc_last_successful_maintenance_run_timestamp';
	const OPTION_MYSQL_CONVERT_TZ     = 'clisyc_mysql_convert_tz_status';
	const OPTION_MYSQL_CONVERT_TZ_OR  = 'clisyc_mysql_convert_tz_override';

	// ── Admin Page Slugs ───────────────────────────────────────────────
	const PAYMENTS_PAGE_SLUG = 'clisyc-payments';

	// ── Calendar Color Defaults ────────────────────────────────────────
	const COLOR_DEFAULTS = [
		'available_bg'   => '#d1fae5',
		'available_text' => '#065f46',
		'booked_bg'      => '#fee2e2',
		'booked_text'    => '#991b1b',
		'blocked_bg'     => '#e5e7eb',
		'blocked_text'   => '#374151',
	];

	// ── Rate Limits ───────────────────────────────────────────────────
	const RATE_LIMIT_PRICE_CALC              = 30;  // requests per window
	const RATE_LIMIT_BASKET_CALC             = 30;
	const RATE_LIMIT_SLOT_FETCH              = 60;
	const RATE_LIMIT_STRIPE_HOOK             = 120;
	const RATE_LIMIT_WINDOW_SECS             = 60;  // window size in seconds
	const RATE_LIMIT_BOOKING_SUBMIT          = 5;   // max bookings per window
	const RATE_LIMIT_BOOKING_WINDOW_SECS     = 300;  // 5-minute window
	const RATE_LIMIT_WAITLIST_JOIN            = 10;
	const RATE_LIMIT_WAITLIST_WINDOW_SECS    = 300;
	const RATE_LIMIT_FILTER_OPTIONS          = 60;
	const RATE_LIMIT_BOOKED_DATES            = 60;
	const RATE_LIMIT_DIMENSION_POSTS         = 60;
	const RATE_LIMIT_APPOINTMENTS            = 30;
	const RATE_LIMIT_TIMELINE                = 30;
	const RATE_LIMIT_USER_SEARCH             = 20;
	const RATE_LIMIT_SEAT_HOLD               = 30;
	const RATE_LIMIT_MESSAGE_SEND            = 10;
	const RATE_LIMIT_MESSAGE_WINDOW_SECS     = 300;

	// ── Transient TTLs (seconds) ──────────────────────────────────────
	const TRANSIENT_ACTIVATION_REDIRECT_TTL = 30;
	const TRANSIENT_ADMIN_FEEDBACK_TTL      = 30;
	const TRANSIENT_BOOKING_FEEDBACK_TTL    = 60;
	const TRANSIENT_NOTIFICATION_RETRY_INTERVAL = 300;  // 5 minutes

	// ── UI Constraints ────────────────────────────────────────────────
	const TIMELINE_MIN_HEIGHT_PX = 300;
	const TIMELINE_MAX_HEIGHT_PX = 2000;

	// ── HIPAA Audit Retention Bounds ──────────────────────────────────
	const HIPAA_AUDIT_MIN_RETENTION_DAYS = 2190;  // 6 years (HIPAA requirement)
	const HIPAA_AUDIT_MAX_RETENTION_DAYS = 7300;  // 20 years

	// ── Encryption Key Length Bounds ───────────────────────────────────
	const ENCRYPTION_KEY_MIN_LENGTH = 32;
	const ENCRYPTION_KEY_MAX_LENGTH = 128;

	// ── DB Schema Version ─────────────────────────────────────────────
	const OPTION_DB_SCHEMA_VERSION = 'clisyc_db_schema_version';

	// Not instantiable.
	private function __construct() {}
}
