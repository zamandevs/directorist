<?php
/**
 * Behavior locks for the Directorist review bootstrap boundary.
 */

class Directorist_Review_Bootstrap_Behavior_Test extends WP_UnitTestCase {
    public function test_public_review_symbols_and_callbacks_are_available() {
        $this->assertTrue( function_exists( 'directorist_get_listing_rating' ) );
        $this->assertTrue( function_exists( 'directorist_get_comment_form_ajax_url' ) );
        $this->assertTrue( class_exists( '\\Directorist\\Review\\Comment' ) );
        $this->assertTrue( class_exists( '\\Directorist\\Review\\Comment_Meta' ) );
        $this->assertTrue( class_exists( '\\Directorist\\Review\\Listing_Review_Meta' ) );
        $this->assertTrue( class_exists( '\\Directorist\\Review\\Comment_Form_Renderer' ) );
        $this->assertTrue( class_exists( '\\Directorist\\Review\\Comment_Form_Processor' ) );

        $this->assertSame(
            10,
            has_action(
                'wp_ajax_directorist_get_comment_edit_form',
                [ 'Directorist\\Review\\Comment_Form_Renderer', 'render' ]
            )
        );
        $this->assertSame(
            10,
            has_action(
                'wp_ajax_nopriv_directorist_process_comment_form',
                [ 'Directorist\\Review\\Comment_Form_Processor', 'process' ]
            )
        );
        $this->assertSame( 10, has_action( 'comment_post', [ 'Directorist\\Review\\Email', 'notify_owner' ] ) );
        $this->assertSame( 1, has_filter( 'preprocess_comment', [ 'Directorist\\Review\\Comment', 'preprocess_comment_data' ] ) );
    }

    public function test_review_metadata_helpers_preserve_values() {
        $listing_id = self::factory()->post->create(
            [
                'post_type'   => ATBDP_POST_TYPE,
                'post_status' => 'publish',
            ]
        );
        $comment_id = self::factory()->comment->create(
            [
                'comment_post_ID' => $listing_id,
                'comment_type'    => 'review',
            ]
        );

        \Directorist\Review\Comment_Meta::set_rating( $comment_id, 4.5 );
        \Directorist\Review\Listing_Review_Meta::update_rating( $listing_id, 4.25 );
        \Directorist\Review\Listing_Review_Meta::update_review_count( $listing_id, 3 );

        $this->assertSame( 4.5, (float) \Directorist\Review\Comment_Meta::get_rating( $comment_id ) );
        $this->assertSame( 4.3, directorist_get_listing_rating( $listing_id ) );
        $this->assertSame( 3, directorist_get_listing_review_count( $listing_id ) );
        $this->assertSame( '_directorist_listing_rating', directorist_get_rating_field_meta_key() );
    }

    public function test_comment_form_helper_preserves_callback_registration_and_url_shape() {
        $url = directorist_get_comment_form_ajax_url();

        $this->assertStringContainsString( 'admin-ajax.php', $url );
        $this->assertStringContainsString( 'action=directorist_get_comment_edit_form', $url );
        $this->assertStringContainsString( 'nonce=', $url );
        $this->assertSame(
            1,
            $this->callback_registration_count(
                'wp_ajax_directorist_get_comment_edit_form',
                [ 'Directorist\\Review\\Comment_Form_Renderer', 'render' ]
            )
        );
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
