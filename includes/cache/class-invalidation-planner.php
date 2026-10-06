<?php

namespace Directorist\Cache;

/**
 * Converts semantic changes into a bounded provider-neutral plan.
 */
final class Invalidation_Planner {
    const DEFAULT_EXACT_LIMIT = 100;

    /** @var int */
    private $exact_limit;

    /**
     * @param int $exact_limit Maximum exact semantic changes per request.
     */
    public function __construct( $exact_limit = self::DEFAULT_EXACT_LIMIT ) {
        $this->exact_limit = max( 1, absint( $exact_limit ) );
    }

    /**
     * @param Change_Set $changes Completed request mutations.
     * @return Invalidation_Plan
     */
    public function build( Change_Set $changes ) {
        $plan = new Invalidation_Plan( $changes->get_site_id() );

        if ( $this->exact_item_count( $changes ) > $this->exact_limit ) {
            $plan->force_conservative( 'exact_limit_exceeded' );

            return $plan;
        }

        foreach ( $changes->all() as $change ) {
            $this->add_change( $plan, $change );
        }

        return $plan;
    }

    /**
     * Count bounded exact entities supplied directly or through one extension.
     *
     * @param Change_Set $changes Request mutations.
     * @return int
     */
    private function exact_item_count( Change_Set $changes ) {
        $count = $changes->count();

        foreach ( $changes->all() as $change ) {
            $context = $change['context'];

            foreach ( [ 'listing_ids', 'term_ids', 'directory_ids', 'author_ids', 'page_ids', 'urls', 'dependencies' ] as $key ) {
                if ( ! empty( $context[ $key ] ) && is_array( $context[ $key ] ) ) {
                    $count += count( $context[ $key ] );
                }
            }
        }

        return $count;
    }

    /**
     * @param Invalidation_Plan $plan Plan being built.
     * @param array             $change Semantic change.
     * @return void
     */
    private function add_change( Invalidation_Plan $plan, array $change ) {
        $type       = $change['type'];
        $identifier = $change['identifier'];
        $context    = $change['context'];

        switch ( $type ) {
            case Change_Type::LISTING:
                $this->add_listing( $plan, $identifier, $context );
                break;

            case Change_Type::TERM:
                $this->add_term( $plan, $identifier, $context );
                break;

            case Change_Type::DIRECTORY:
                $plan->add_dependency( 'directory', $identifier );
                $plan->add_generation( 'settings' );
                $plan->add_generation( 'template' );
                $this->add_collection_generation( $plan, $context );
                $this->add_urls( $plan, isset( $context['urls'] ) ? $context['urls'] : [] );
                break;

            case Change_Type::AUTHOR:
                $plan->add_dependency( 'author', $identifier );
                $this->add_urls( $plan, isset( $context['urls'] ) ? $context['urls'] : [] );
                break;

            case Change_Type::PAGE:
                $plan->add_dependency( 'page', $identifier );
                $this->add_urls( $plan, isset( $context['urls'] ) ? $context['urls'] : [] );
                break;

            case Change_Type::SETTINGS:
                $plan->add_generation( 'settings' );
                $plan->add_generation( 'template' );
                $this->add_urls( $plan, isset( $context['urls'] ) ? $context['urls'] : [] );
                break;

            case Change_Type::TEMPLATE:
                $plan->add_generation( 'template' );
                break;

            case Change_Type::REVIEW:
                $plan->add_dependency( 'review', $identifier );
                $plan->add_dependency( 'listing', $identifier );
                $this->add_urls( $plan, isset( $context['urls'] ) ? $context['urls'] : [] );
                $this->add_collection_generation( $plan, $context );
                break;

            case Change_Type::EXTENSION:
                $this->add_extension( $plan, $identifier, $context );
                break;
        }
    }

    /**
     * @param Invalidation_Plan $plan Plan being built.
     * @param int               $listing_id Listing ID.
     * @param array             $context Mutation context.
     * @return void
     */
    private function add_listing( Invalidation_Plan $plan, $listing_id, array $context ) {
        $plan->add_dependency( 'listing', $listing_id );

        foreach ( [ 'before', 'after' ] as $state_name ) {
            if ( empty( $context[ $state_name ] ) || ! is_array( $context[ $state_name ] ) ) {
                continue;
            }

            $state = $context[ $state_name ];

            if ( ! empty( $state['url'] ) ) {
                $plan->add_url( $state['url'] );
            }

            if ( ! empty( $state['author_id'] ) ) {
                $plan->add_dependency( 'author', $state['author_id'] );
            }

            foreach ( isset( $state['term_ids'] ) ? (array) $state['term_ids'] : [] as $term_id ) {
                $plan->add_dependency( 'term', $term_id );
            }

            foreach ( isset( $state['directory_ids'] ) ? (array) $state['directory_ids'] : [] as $directory_id ) {
                $plan->add_dependency( 'directory', $directory_id );
            }
        }

        if ( ! isset( $context['collection'] ) || $context['collection'] ) {
            $plan->add_generation( 'collection', 'listings' );
        }
    }

    /**
     * @param Invalidation_Plan $plan Plan being built.
     * @param int               $term_id Term ID.
     * @param array             $context Mutation context.
     * @return void
     */
    private function add_term( Invalidation_Plan $plan, $term_id, array $context ) {
        $plan->add_dependency( 'term', $term_id );

        if ( ! empty( $context['taxonomy'] ) ) {
            $plan->add_generation( 'taxonomy', $context['taxonomy'] );
        }

        foreach ( [ 'before_url', 'after_url' ] as $url_key ) {
            if ( ! empty( $context[ $url_key ] ) ) {
                $plan->add_url( $context[ $url_key ] );
            }
        }

        foreach ( isset( $context['listing_ids'] ) ? (array) $context['listing_ids'] : [] as $listing_id ) {
            $plan->add_dependency( 'listing', $listing_id );
        }

        $this->add_collection_generation( $plan, $context );
    }

    /**
     * @param Invalidation_Plan $plan Plan being built.
     * @param string            $identifier Extension identifier.
     * @param array             $context Mutation context.
     * @return void
     */
    private function add_extension( Invalidation_Plan $plan, $identifier, array $context ) {
        $plan->add_dependency( 'extension', $identifier );
        $this->add_urls( $plan, isset( $context['urls'] ) ? $context['urls'] : [] );

        foreach ( isset( $context['listing_ids'] ) ? (array) $context['listing_ids'] : [] as $listing_id ) {
            $plan->add_dependency( 'listing', $listing_id );
        }

        foreach ( isset( $context['dependencies'] ) ? (array) $context['dependencies'] : [] as $dependency ) {
            if ( ! is_array( $dependency ) || empty( $dependency['domain'] ) ) {
                continue;
            }

            $plan->add_dependency(
                $dependency['domain'],
                isset( $dependency['identifier'] ) ? $dependency['identifier'] : ''
            );
        }

        $this->add_collection_generation( $plan, $context );

        if ( ! empty( $context['site'] ) ) {
            $plan->add_generation( 'site' );
        }
    }

    /**
     * @param Invalidation_Plan $plan Plan being built.
     * @param array             $context Mutation context.
     * @return void
     */
    private function add_collection_generation( Invalidation_Plan $plan, array $context ) {
        if ( ! empty( $context['collection'] ) ) {
            $plan->add_generation( 'collection', 'listings' );
        }
    }

    /**
     * @param Invalidation_Plan $plan Plan being built.
     * @param array             $urls URLs to add.
     * @return void
     */
    private function add_urls( Invalidation_Plan $plan, array $urls ) {
        foreach ( $urls as $url ) {
            $plan->add_url( $url );
        }
    }
}
