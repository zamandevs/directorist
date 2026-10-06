<?php
/**
 * Behavior locks for the Directorist email bootstrap boundary.
 */

class Directorist_Email_Bootstrap_Behavior_Test extends WP_UnitTestCase {
    private $original_options;

    public function set_up() {
        parent::set_up();

        $this->original_options = get_option( 'atbdp_option', [] );
    }

    public function tear_down() {
        update_option( 'atbdp_option', $this->original_options );

        parent::tear_down();
    }

    public function test_public_email_service_is_accessible_stable_and_registered_once() {
        $email = directorist()->email;

        $this->assertInstanceOf( ATBDP_Email::class, $email );
        $this->assertSame( $email, directorist()->email );

        $callbacks = [
            'atbdp_listing_inserted'             => [ 'notify_admin_listing_submitted', 'notify_owner_listing_submitted' ],
            'atbdp_listing_updated'              => [ 'send_email_after_listing_updated' ],
            'directorist_listing_status_updated' => [ 'send_email_after_listing_preview_status_updated' ],
            'atbdp_listing_published'            => [ 'notify_admin_listing_published', 'notify_owner_listing_published' ],
            'atbdp_order_created'                => [ 'notify_admin_order_created', 'notify_owner_order_created' ],
            'atbdp_order_completed'              => [ 'notify_owner_order_completed', 'notify_admin_order_completed' ],
            'atbdp_status_updated_to_renewal'    => [ 'notify_owner_listing_to_expire' ],
            'atbdp_listing_expired'              => [ 'notify_owner_listing_expired' ],
            'atbdp_send_renewal_reminder'        => [ 'notify_owner_to_renew' ],
            'atbdp_after_renewal'                => [ 'notify_owner_listing_renewed' ],
            'atbdp_deleted_expired_listings'     => [ 'notify_owner_listing_deleted', 'notify_admin_listing_deleted' ],
            'atbdp_become_author'                => [ 'notify_admin_become_author' ],
            'atbdp_listing_rejected'             => [ 'notify_owner_listing_rejected' ],
        ];

        $registration_count = 0;
        foreach ( $callbacks as $hook_name => $methods ) {
            foreach ( $methods as $method ) {
                $callback = [ $email, $method ];

                $this->assertSame( 10, has_action( $hook_name, $callback ) );
                $this->assertSame( 1, $this->callback_registration_count( $hook_name, $callback ) );
                ++$registration_count;
            }
        }

        $this->assertSame( 18, $registration_count );
    }

    public function test_disabled_notifications_still_execute_public_decision_filters() {
        $options                               = get_option( 'atbdp_option', [] );
        $options['disable_email_notification'] = 1;
        update_option( 'atbdp_option', $options );

        $admin_filter_calls = 0;
        $owner_filter_calls = 0;

        add_filter(
            'directorist_notify_admin_listing_submitted',
            static function ( $notify ) use ( &$admin_filter_calls ) {
                ++$admin_filter_calls;
                return $notify;
            }
        );
        add_filter(
            'directorist_notify_owner_listing_submitted',
            static function ( $notify ) use ( &$owner_filter_calls ) {
                ++$owner_filter_calls;
                return $notify;
            }
        );

        do_action( 'atbdp_listing_inserted', 0 );

        $this->assertSame( 1, $admin_filter_calls );
        $this->assertSame( 1, $owner_filter_calls );
    }

    public function test_send_mail_limits_temporary_wordpress_filters_to_the_send_call() {
        $email = directorist()->email;

        add_filter( 'pre_wp_mail', '__return_true' );

        $this->assertTrue( $email->send_mail( 'person@example.test', 'Subject', '<p>Body</p>', '' ) );
        $this->assertFalse( has_filter( 'wp_mail_from_name', [ $email, 'atbdp_wp_mail_from_name' ] ) );
        $this->assertFalse( has_filter( 'wp_mail_content_type', [ $email, 'html_content_type' ] ) );
        $this->assertSame( 'text/html', $email->html_content_type() );
    }

    private function callback_registration_count( $hook_name, $callback ) {
        global $wp_filter;

        if ( empty( $wp_filter[ $hook_name ]->callbacks ) ) {
            return 0;
        }

        $count = 0;
        foreach ( $wp_filter[ $hook_name ]->callbacks as $callbacks ) {
            foreach ( $callbacks as $registered ) {
                if ( $registered['function'] === $callback ) {
                    ++$count;
                }
            }
        }

        return $count;
    }
}
