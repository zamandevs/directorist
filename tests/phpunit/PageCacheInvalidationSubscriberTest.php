<?php
/**
 * Integration tests for WordPress/Directorist mutation subscribers.
 */

use Directorist\Cache\Cache_Manager;
use Directorist\Cache\Change_Set;
use Directorist\Cache\Change_Type;
use Directorist\Cache\Invalidation_Subscriber;

class Directorist_Page_Cache_Invalidation_Subscriber_Test extends WP_UnitTestCase {
    /** @var Change_Set */
    private $changes;

    /** @var Invalidation_Subscriber */
    private $subscriber;

    public function set_up() {
        parent::set_up();

        $this->changes    = new Change_Set( get_current_blog_id() );
        $this->subscriber = new Invalidation_Subscriber( $this->changes );
        $this->subscriber->register();
    }

    public function tear_down() {
        $this->subscriber->unregister();
        Cache_Manager::instance()->disable_invalidation();

        parent::tear_down();
    }

    public function test_successful_listing_insert_update_meta_and_terms_coalesce_to_one_listing_change() {
        $category = self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY ] );
        $listing  = self::factory()->post->create(
            [
                'post_type'   => ATBDP_POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => 'Original listing',
            ]
        );

        wp_set_object_terms( $listing, [ $category ], ATBDP_CATEGORY );
        update_post_meta( $listing, '_phone', '123' );
        wp_update_post( [ 'ID' => $listing, 'post_title' => 'Updated listing' ] );

        $change = $this->changes->get( Change_Type::LISTING, $listing );

        $this->assertSame( 1, $this->changes->count( Change_Type::LISTING ) );
        $this->assertContains( 'post', $change['context']['reasons'] );
        $this->assertContains( 'meta', $change['context']['reasons'] );
        $this->assertContains( 'terms', $change['context']['reasons'] );
        $this->assertContains( $category, $change['context']['after']['term_ids'] );
        $this->assertTrue( $change['context']['collection'] );
    }

    public function test_failed_or_irrelevant_post_mutations_do_not_create_changes() {
        $page_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

        $this->changes->reset();
        $failed = wp_update_post( [ 'ID' => 999999, 'post_title' => 'Missing' ], true );
        update_post_meta( $page_id, '_unrelated', 'value' );

        $this->assertWPError( $failed );
        $this->assertTrue( $this->changes->is_empty() );
    }

    public function test_listing_delete_uses_pre_delete_snapshot_only_after_success() {
        $listing = self::factory()->post->create(
            [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish' ]
        );
        $old_url = get_permalink( $listing );

        $this->changes->reset();
        $this->assertNotFalse( wp_delete_post( $listing, true ) );

        $change = $this->changes->get( Change_Type::LISTING, $listing );

        $this->assertSame( $old_url, $change['context']['before']['url'] );
        $this->assertSame( [], $change['context']['after'] );
        $this->assertContains( 'delete', $change['context']['reasons'] );
    }

    public function test_directorist_term_edit_records_old_and_new_url() {
        $term_id = self::factory()->term->create(
            [ 'taxonomy' => ATBDP_CATEGORY, 'slug' => 'old-category' ]
        );
        $old_url = ATBDP_Permalink::atbdp_get_category_page( get_term( $term_id, ATBDP_CATEGORY ) );

        $this->changes->reset();
        wp_update_term( $term_id, ATBDP_CATEGORY, [ 'slug' => 'new-category' ] );

        $change = $this->changes->get( Change_Type::TERM, $term_id );

        $this->assertSame( $old_url, $change['context']['before_url'] );
        $this->assertNotSame( $old_url, $change['context']['after_url'] );
        $this->assertTrue( $change['context']['collection'] );
    }

    public function test_location_and_tag_edits_record_their_directorist_old_and_new_urls() {
        foreach ( [ ATBDP_LOCATION, ATBDP_TAGS ] as $taxonomy ) {
            $term_id = self::factory()->term->create(
                [ 'taxonomy' => $taxonomy, 'slug' => 'old-' . sanitize_key( $taxonomy ) ]
            );

            $this->changes->reset();
            wp_update_term( $term_id, $taxonomy, [ 'slug' => 'new-' . sanitize_key( $taxonomy ) ] );

            $change = $this->changes->get( Change_Type::TERM, $term_id );

            $this->assertNotEmpty( $change['context']['before_url'] );
            $this->assertNotEmpty( $change['context']['after_url'] );
            $this->assertNotSame( $change['context']['before_url'], $change['context']['after_url'] );
        }
    }

    public function test_direct_term_reassignment_preserves_old_and_new_listing_term_dependencies() {
        $old_term = self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY ] );
        $new_term = self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY ] );
        $listing  = self::factory()->post->create(
            [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish' ]
        );
        wp_set_object_terms( $listing, [ $old_term ], ATBDP_CATEGORY );

        $this->changes->reset();
        wp_set_object_terms( $listing, [ $new_term ], ATBDP_CATEGORY );

        $change = $this->changes->get( Change_Type::LISTING, $listing );

        $this->assertContains( $old_term, $change['context']['before']['term_ids'] );
        $this->assertContains( $new_term, $change['context']['after']['term_ids'] );
    }

    public function test_pre_delete_short_circuit_does_not_record_a_listing_change() {
        $listing       = self::factory()->post->create(
            [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish' ]
        );
        $short_circuit = static function ( $delete, $post ) use ( $listing ) {
            return $post->ID === $listing ? false : $delete;
        };

        $this->changes->reset();
        add_filter( 'pre_delete_post', $short_circuit, 10, 2 );
        $result = wp_delete_post( $listing, true );
        remove_filter( 'pre_delete_post', $short_circuit, 10 );

        $this->assertFalse( $result );
        $this->assertTrue( $this->changes->is_empty() );
    }

    public function test_failed_duplicate_term_slug_update_does_not_record_a_change() {
        $first  = self::factory()->term->create(
            [ 'taxonomy' => ATBDP_CATEGORY, 'slug' => 'existing-category' ]
        );
        $second = self::factory()->term->create(
            [ 'taxonomy' => ATBDP_CATEGORY, 'slug' => 'other-category' ]
        );

        $this->changes->reset();
        $result = wp_update_term( $second, ATBDP_CATEGORY, [ 'slug' => get_term( $first )->slug ] );

        $this->assertWPError( $result );
        $this->assertTrue( $this->changes->is_empty() );
    }

    public function test_review_comment_and_rating_changes_coalesce_to_listing_review_change() {
        $listing = self::factory()->post->create(
            [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish' ]
        );

        $this->changes->reset();
        $comment_id = wp_insert_comment(
            [
                'comment_post_ID'  => $listing,
                'comment_content'  => 'Review',
                'comment_approved' => 1,
                'comment_type'     => 'review',
            ]
        );
        update_comment_meta( $comment_id, 'rating', 5 );

        $review = $this->changes->get( Change_Type::REVIEW, $listing );

        $this->assertNotEmpty( $review );
        $this->assertContains( 'comment', $review['context']['reasons'] );
        $this->assertContains( 'rating', $review['context']['reasons'] );
        $this->assertTrue( $review['context']['collection'] );
    }

    public function test_normal_wordpress_comment_is_not_a_directorist_change() {
        $post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

        $this->changes->reset();
        wp_insert_comment(
            [
                'comment_post_ID'  => $post_id,
                'comment_content'  => 'Normal comment',
                'comment_approved' => 1,
            ]
        );

        $this->assertTrue( $this->changes->is_empty() );
    }

    public function test_author_profile_update_records_author_dependency_and_url() {
        $user_id                        = self::factory()->user->create( [ 'user_login' => 'cache-author' ] );
        $page_id                        = self::factory()->post->create(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_content' => '[directorist_author_profile]',
            ]
        );
        $options                        = get_option( 'atbdp_option', [] );
        $options['author_profile_page'] = $page_id;
        update_option( 'atbdp_option', $options );

        $this->changes->reset();
        wp_update_user( [ 'ID' => $user_id, 'display_name' => 'Updated Author' ] );

        $change = $this->changes->get( Change_Type::AUTHOR, $user_id );

        $this->assertNotEmpty( $change );
        $this->assertNotEmpty( $change['context']['urls'] );
    }

    public function test_public_directorist_page_removal_invalidates_old_page_and_template_dependencies() {
        $page_id = self::factory()->post->create(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_content' => '[directorist_all_listing]',
            ]
        );

        $this->changes->reset();
        wp_update_post( [ 'ID' => $page_id, 'post_content' => 'Plain content' ] );

        $page     = $this->changes->get( Change_Type::PAGE, $page_id );
        $template = $this->changes->get( Change_Type::TEMPLATE, 'global' );

        $this->assertNotEmpty( $page );
        $this->assertNotEmpty( $template );
        $this->assertContains( get_permalink( $page_id ), $page['context']['urls'] );
    }

    public function test_private_directorist_page_is_not_a_public_cache_mutation() {
        $page_id = self::factory()->post->create(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_content' => '[directorist_user_dashboard]',
            ]
        );

        $this->changes->reset();
        wp_update_post( [ 'ID' => $page_id, 'post_title' => 'Private dashboard' ] );

        $this->assertTrue( $this->changes->is_empty() );
    }

    public function test_page_mixing_public_and_private_renderers_is_not_a_public_cache_mutation() {
        $page_id = self::factory()->post->create(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_content' => '[directorist_all_listing][directorist_user_dashboard]',
            ]
        );

        $this->changes->reset();
        wp_update_post( [ 'ID' => $page_id, 'post_title' => 'Mixed private page' ] );

        $this->assertTrue( $this->changes->is_empty() );
    }

    public function test_global_settings_and_directory_builder_meta_record_conservative_changes() {
        $directory = self::factory()->term->create( [ 'taxonomy' => ATBDP_DIRECTORY_TYPE ] );

        $this->changes->reset();
        update_option( 'atbdp_option', [ 'all_listing_page_items' => 12 ] );
        update_term_meta( $directory, 'search_form_fields', [ 'fields' => [] ] );

        $this->assertNotEmpty( $this->changes->get( Change_Type::SETTINGS, 'global' ) );
        $this->assertNotEmpty( $this->changes->get( Change_Type::DIRECTORY, $directory ) );
    }

    public function test_volatile_listing_view_meta_does_not_invalidate_by_default() {
        $listing = self::factory()->post->create(
            [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish' ]
        );

        $this->changes->reset();
        update_post_meta( $listing, directorist_get_listing_views_count_meta_key(), 10 );

        $this->assertTrue( $this->changes->is_empty() );
    }

    public function test_extension_api_is_inert_until_manager_enables_invalidation() {
        Cache_Manager::instance()->disable_invalidation();

        $this->assertFalse(
            directorist_page_cache_record_change(
                'booking',
                'availability',
                [ 'listing_ids' => [ 15 ], 'collection' => true ]
            )
        );
    }

    public function test_active_extension_api_sanitizes_and_coalesces_change_data() {
        $provider = new Directorist_Page_Cache_Test_Provider();
        $manager  = Cache_Manager::instance();
        $manager->disable_invalidation();
        $this->assertTrue( $manager->enable_invalidation( $provider ) );

        $this->assertTrue(
            directorist_page_cache_record_change(
                'Booking Extension',
                'Availability 8',
                [
                    'listing_ids' => [ 15, 15 ],
                    'collection'  => true,
                ]
            )
        );

        $change = $manager->get_change_set()->get( Change_Type::EXTENSION, 'booking-extension:availability-8' );

        $this->assertSame( [ 15 ], $change['context']['listing_ids'] );
        $this->assertTrue( $change['context']['collection'] );

        $result = $manager->dispatch_invalidation();

        $this->assertTrue( $result['success'] );
        $this->assertCount( 1, $provider->requests );
        $this->assertContains( 'directorist:1:extension:booking-extension:availability-8', $provider->requests[0]['dependencies'] );
    }

    public function test_unavailable_provider_cannot_enable_mutation_tracking() {
        $provider            = new Directorist_Page_Cache_Test_Provider();
        $provider->available = false;
        $manager             = Cache_Manager::instance();
        $manager->disable_invalidation();

        $this->assertFalse( $manager->enable_invalidation( $provider ) );
        $this->assertFalse( $manager->is_tracking_mutations() );
        $this->assertSame( 'none', $manager->get_provider()->get_id() );
    }

    public function test_unregister_stops_future_mutation_observation() {
        $this->subscriber->unregister();

        self::factory()->post->create(
            [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish' ]
        );

        $this->assertTrue( $this->changes->is_empty() );
    }
}
