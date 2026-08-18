<?php
/**
 * Request context used by the Directorist bootstrap.
 *
 * @package Directorist
 */

namespace Directorist;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Describes the current WordPress request without coupling services to globals.
 */
final class Request_Context {
    /**
     * Whether WordPress considers the request administrative.
     *
     * @var bool
     */
    private $is_admin;

    /**
     * Whether this is an admin-ajax request.
     *
     * @var bool
     */
    private $is_ajax;

    /**
     * Whether this is a cron request.
     *
     * @var bool
     */
    private $is_cron;

    /**
     * Whether this is a WP-CLI request.
     *
     * @var bool
     */
    private $is_cli;

    /**
     * Sanitized admin-ajax action.
     *
     * @var string
     */
    private $ajax_action;

    /**
     * Create a request context.
     *
     * @param array $args Request properties.
     */
    public function __construct( array $args = [] ) {
        $args = wp_parse_args(
            $args,
            [
                'is_admin'    => false,
                'is_ajax'     => false,
                'is_cron'     => false,
                'is_cli'      => false,
                'ajax_action' => '',
            ]
        );

        $this->is_admin    = (bool) $args['is_admin'];
        $this->is_ajax     = (bool) $args['is_ajax'];
        $this->is_cron     = (bool) $args['is_cron'];
        $this->is_cli      = (bool) $args['is_cli'];
        $this->ajax_action = $this->is_ajax ? sanitize_key( $args['ajax_action'] ) : '';
    }

    /**
     * Build a context from the current WordPress request.
     *
     * @return self
     */
    public static function current() {
        $is_ajax     = wp_doing_ajax();
        $ajax_action = '';

        if ( $is_ajax && isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $ajax_action = sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        return new self(
            [
                'is_admin'    => is_admin(),
                'is_ajax'     => $is_ajax,
                'is_cron'     => wp_doing_cron(),
                'is_cli'      => defined( 'WP_CLI' ) && WP_CLI,
                'ajax_action' => $ajax_action,
            ]
        );
    }

    /**
     * Whether this is an AJAX request.
     *
     * @return bool
     */
    public function is_ajax() {
        return $this->is_ajax;
    }

    /**
     * Whether this is a cron request.
     *
     * @return bool
     */
    public function is_cron() {
        return $this->is_cron;
    }

    /**
     * Whether this is an administrative screen request.
     *
     * @return bool
     */
    public function is_admin_screen() {
        return $this->is_admin && ! $this->is_ajax;
    }

    /**
     * Whether this is a WP-CLI request.
     *
     * @return bool
     */
    public function is_cli() {
        return $this->is_cli;
    }

    /**
     * Return the requested admin-ajax action.
     *
     * @return string
     */
    public function ajax_action() {
        return $this->ajax_action;
    }
}
