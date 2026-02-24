<?php
/**
 * File: src/pro/includes/modules/videoconf/class-videoconf-provider-factory.php
 * Factory that returns the correct video conferencing provider based on settings.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Videoconf
 */

namespace ClientSyncPro\Modules\Videoconf;

use DependentMedia\ClientSync\Constants;
use ClientSyncPro\Modules\Videoconf\Providers\Videoconf_Provider_Interface;
use ClientSyncPro\Modules\Videoconf\Providers\Google_Meet_Provider;
use ClientSyncPro\Modules\Videoconf\Providers\Zoom_Provider;

if ( ! defined( 'ABSPATH' ) ) exit;

class Videoconf_Provider_Factory {

    /**
     * Returns the configured video conferencing provider instance.
     *
     * @return Videoconf_Provider_Interface|null The provider, or null if disabled.
     */
    public static function get_provider(): ?Videoconf_Provider_Interface {
        $provider_key = get_option( Constants::OPTION_VIDEO_PROVIDER, 'none' );

        switch ( $provider_key ) {
            case 'google_meet':
                return new Google_Meet_Provider();
            case 'zoom':
                return new Zoom_Provider();
            default:
                return null;
        }
    }
}
