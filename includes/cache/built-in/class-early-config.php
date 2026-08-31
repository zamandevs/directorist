<?php

namespace Directorist\Cache\Built_In;

/**
 * Generated early-loader configuration.
 */
final class Early_Config {
    const SCHEMA = 1;

    /** @var string */
    private $content_dir;

    /** @var string */
    private $plugin_dir;

    /**
     * @param string $content_dir WordPress content directory.
     * @param string $plugin_dir Directorist plugin directory.
     */
    public function __construct( $content_dir, $plugin_dir ) {
        $this->content_dir = rtrim( (string) $content_dir, '/\\' );
        $this->plugin_dir  = rtrim( (string) $plugin_dir, '/\\' );
    }

    /** @return array */
    public function data() {
        $cookie_policy = function_exists( 'directorist_page_cache_cookie_policy' )
            ? directorist_page_cache_cookie_policy()
            : Cookie_Policy::defaults();
        $settings       = function_exists( 'directorist_page_cache_performance_settings' )
            ? directorist_page_cache_performance_settings()
            : null;
        $ttl            = is_object( $settings ) && is_callable( [ $settings, 'get_cache_ttl' ] )
            ? $settings->get_cache_ttl()
            : 6 * HOUR_IN_SECONDS;
        $refresh_policy = is_object( $settings ) && is_callable( [ $settings, 'get_cache_policy' ] )
            ? $settings->get_cache_policy()
            : [
                'mode'    => 'fixed',
                'default' => [ 'soft_ttl' => $ttl, 'hard_ttl' => $ttl + HOUR_IN_SECONDS, 'jitter' => 0 ],
                'routes'  => [],
            ];

        return [
            '_marker'        => Ownership::CONFIG_MARKER,
            'owner_id'       => Ownership::OWNER_LINE,
            'schema'         => self::SCHEMA,
            'owner'          => 'directorist-page-cache',
            'plugin_dir'     => $this->plugin_dir,
            'bootstrap_file' => $this->plugin_dir . '/includes/cache/built-in/early-bootstrap.php',
            'engine_file'    => $this->plugin_dir . '/includes/cache/built-in/class-cache-engine.php',
            'cache_dir'      => $this->content_dir . '/cache/directorist-page-cache',
            'ttl'            => $ttl,
            'stale_ttl'      => HOUR_IN_SECONDS,
            'refresh_policy' => $refresh_policy,
            'refresh_endpoint' => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '',
            'refresh_token'  => function_exists( 'directorist_page_cache_refresh_token' ) ? directorist_page_cache_refresh_token() : '',
            'cache_filtered_results' => true,
            'debug'          => false,
            'enabled'        => true,
            'cookie_policy'  => ( new Cookie_Policy( is_array( $cookie_policy ) ? $cookie_policy : [] ) )->to_array(),
        ];
    }

    /** @return string */
    public function render() {
        $encoded = json_encode( $this->data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        return false === $encoded ? '' : $encoded . "\n";
    }

    /**
     * @param mixed $config Candidate config.
     * @return bool
     */
    public static function is_valid( $config ) {
        return is_array( $config )
            && isset( $config['_marker'], $config['owner_id'], $config['schema'], $config['owner'], $config['bootstrap_file'] )
            && Ownership::CONFIG_MARKER === $config['_marker']
            && Ownership::OWNER_LINE === $config['owner_id']
            && self::SCHEMA === $config['schema']
            && 'directorist-page-cache' === $config['owner']
            && is_string( $config['bootstrap_file'] )
            && '' !== $config['bootstrap_file'];
    }
}
