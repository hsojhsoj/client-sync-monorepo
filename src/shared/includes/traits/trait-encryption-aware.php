<?php
namespace DependentMedia\ClientSync\Traits;

use DependentMedia\ClientSync\Services\Encryption_Service;

trait Encryption_Aware {
    
    /**
     * Encrypt a meta value if HIPAA mode is active and field is sensitive.
     */
    protected function encrypt_meta_value( $value, string $meta_key, array $field_def = [] ) {
        $encryption = Encryption_Service::get_instance();
        
        $should_encrypt = $encryption->should_encrypt_field( $meta_key );
        if ( ! $should_encrypt && ! empty( $field_def['sensitive'] ) ) {
            $should_encrypt = true;
        }
        
        if ( $should_encrypt ) {
            return $encryption->maybe_encrypt( $value, $meta_key );
        }
        
        return $value;
    }
    
    /**
     * Decrypt a meta value if it's encrypted.
     */
    protected function decrypt_meta_value( $value, string $meta_key ) {
        $encryption = Encryption_Service::get_instance();
        return $encryption->maybe_decrypt( $value, $meta_key );
    }
}