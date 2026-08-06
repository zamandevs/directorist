<?php

if ( ! class_exists( 'ATBDP_Hooks' ) ) :
    class ATBDP_Hooks {
        public $hooks = [];

        private static $registered = false;

        public function __construct() {
            $this->hooks =  $this->get_hooks();
            self::register_hooks( $this->hooks );
        }

        /**
         * Get Action Hooks
         *
         * @return array
         */
        public function get_hooks() {
            return self::get_hook_definitions();
        }

        private static function get_hook_definitions() {
            return [
                'title_update' => [
                    'name'     => 'the_title',
                    'type'     => 'action',
                    'callback' => ATBDP_Title_Update::class,
                    'priority' => 10,
                    'args'     => 2,
                ],
            ];
        }

        /**
         * Register Hooks
         *
         * @return void
         */
        public static function register() {
            $hooks = self::get_hook_definitions();
            self::register_hooks( $hooks );

            return $hooks;
        }

        private static function register_hooks( array $hooks ) {
            if ( self::$registered ) {
                return;
            }

            self::$registered = true;

            if ( ! count( $hooks ) ) {
                return; }

            foreach ( $hooks as $hook ) {
                self::add_hook( $hook );
            }
        }

        /**
         * Add Hook
         *
         * @return void
         */
        public static function add_hook( array $hook = [] ) {
            if ( class_exists( $hook['callback'] ) ) {
                if ( method_exists( $hook['callback'], 'run' ) ) {
                    $class_name    = $hook['callback'];
                    $priority      = ( isset( $hook['priority'] ) ) ? $hook['priority'] : 10;
                    $accepted_args = ( isset( $hook['args'] ) ) ? $hook['args'] : 1;

                    if ( 'action' === $hook['type'] ) {
                        add_action( $hook['name'], [$class_name, 'run'], $priority, $accepted_args );
                    }

                    if ( 'filter' === $hook['type'] ) {
                        add_filter( $hook['name'], [$class_name, 'run'], $priority, $accepted_args );
                    }

                }
            }
        }
    }
endif;
