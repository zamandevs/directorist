<?php

namespace Directorist\Cache;

/**
 * Server-rendered Directorist Performance admin screen.
 */
final class Performance_Admin {
    const PAGE_SLUG    = 'directorist-performance';
    const HOOK_SUFFIX  = 'at_biz_dir_page_directorist-performance';
    const STYLE_HANDLE = 'directorist-performance-admin';
    const NONCE_ACTION = 'directorist_performance_operation';
    const NONCE_NAME   = 'directorist_performance_nonce';

    /** @var Performance_Operations */
    private $operations;

    /** @var Performance_Status */
    private $status;

    /** @var Performance_Settings */
    private $settings;

    /** @var bool */
    private $registered = false;

    /**
     * @param Performance_Operations $operations Operation service.
     * @param Performance_Status     $status Status service.
     * @param Performance_Settings   $settings Settings service.
     */
    public function __construct( Performance_Operations $operations, Performance_Status $status, Performance_Settings $settings ) {
        $this->operations = $operations;
        $this->status     = $status;
        $this->settings   = $settings;
    }

    /** @return bool */
    public function register() {
        if ( $this->registered ) {
            return false;
        }

        add_action( 'admin_menu', [ $this, 'add_menu' ], 80 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_directorist_performance_action', [ $this, 'handle_action' ] );
        $this->registered = true;

        return true;
    }

    /** @return void */
    public function add_menu() {
        add_submenu_page(
            'edit.php?post_type=' . ATBDP_POST_TYPE,
            __( 'Directorist Performance', 'directorist' ),
            __( 'Performance', 'directorist' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ],
            14
        );
    }

    /**
     * @param string $hook_suffix Current admin hook.
     * @return bool
     */
    public function enqueue_assets( $hook_suffix ) {
        if ( self::HOOK_SUFFIX !== $hook_suffix ) {
            return false;
        }

        wp_enqueue_style( self::STYLE_HANDLE, ATBDP_URL . 'assets/css/performance-admin.css', [], ATBDP_VERSION );

        return true;
    }

    /**
     * Testable request authorization and dispatch boundary.
     *
     * @param array $request Operation request.
     * @param bool  $authorized Capability state.
     * @param bool  $nonce_valid Nonce state.
     * @return array
     */
    public function process_request( array $request, $authorized, $nonce_valid ) {
        if ( ! $authorized ) {
            return [ 'success' => false, 'code' => 'forbidden' ];
        }

        if ( ! $nonce_valid ) {
            return [ 'success' => false, 'code' => 'invalid_nonce' ];
        }

        $operation = isset( $request['operation'] ) ? sanitize_key( (string) $request['operation'] ) : '';

        if ( 'save_settings' === $operation && ! array_key_exists( 'enabled', $request ) ) {
            $request['enabled'] = 0;
        }

        return $this->operations->execute( $operation, $request );
    }

    /** @return void */
    public function handle_action() {
        $request = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the explicit token below.
        $nonce   = isset( $request[ self::NONCE_NAME ] ) ? sanitize_text_field( $request[ self::NONCE_NAME ] ) : '';
        $result  = $this->process_request(
            is_array( $request ) ? $request : [],
            current_user_can( 'manage_options' ),
            (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION )
        );

        if ( 'forbidden' === $result['code'] ) {
            wp_die( esc_html__( 'You are not allowed to manage Directorist performance.', 'directorist' ), 403 );
        }

        $url = add_query_arg(
            [
                'post_type'                      => ATBDP_POST_TYPE,
                'page'                           => self::PAGE_SLUG,
                'directorist_performance_result' => sanitize_key( $result['code'] ),
            ],
            admin_url( 'edit.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /** @return void */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $snapshot    = $this->status->snapshot();
        $settings    = $snapshot['settings'];
        $provider    = $snapshot['provider'];
        $route_flow  = $snapshot['route_flow'];
        $events      = $snapshot['events'];
        $extensions  = isset( $snapshot['extensions'] ) && is_array( $snapshot['extensions'] ) ? $snapshot['extensions'] : [];
        $result_code = isset( $_GET['directorist_performance_result'] ) ? sanitize_key( wp_unslash( $_GET['directorist_performance_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only operation result notice.
        ?>
        <div class="wrap directorist-performance">
            <header class="directorist-performance__header">
                <div>
                    <h1><?php esc_html_e( 'Directorist Performance', 'directorist' ); ?></h1>
                    <p><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
                </div>
                <span class="directorist-performance__state directorist-performance__state--<?php echo $settings['enabled'] ? 'healthy' : 'disabled'; ?>">
                    <?php echo esc_html( $settings['enabled'] ? __( 'Integration enabled', 'directorist' ) : __( 'Fail-open disabled', 'directorist' ) ); ?>
                </span>
            </header>

            <?php if ( '' !== $result_code ) : ?>
                <div class="notice notice-info is-dismissible"><p><?php echo esc_html( str_replace( '-', ' ', $result_code ) ); ?></p></div>
            <?php endif; ?>

            <section class="directorist-performance__section" aria-labelledby="directorist-provider-heading">
                <div class="directorist-performance__section-heading">
                    <h2 id="directorist-provider-heading"><?php esc_html_e( 'Provider ownership', 'directorist' ); ?></h2>
                    <span class="directorist-performance__signal <?php echo ! empty( $provider['available'] ) ? 'is-healthy' : 'is-warning'; ?>">
                        <?php echo esc_html( ! empty( $provider['available'] ) ? __( 'Available', 'directorist' ) : __( 'Unavailable', 'directorist' ) ); ?>
                    </span>
                </div>
                <dl class="directorist-performance__ledger">
                    <div><dt><?php esc_html_e( 'Provider', 'directorist' ); ?></dt><dd><?php echo esc_html( $provider['id'] ?: 'none' ); ?></dd></div>
                    <div><dt><?php esc_html_e( 'Capabilities', 'directorist' ); ?></dt><dd><?php echo esc_html( empty( $provider['capabilities'] ) ? __( 'None', 'directorist' ) : implode( ', ', $provider['capabilities'] ) ); ?></dd></div>
                    <div><dt><?php esc_html_e( 'Sampling', 'directorist' ); ?></dt><dd><?php echo esc_html( $settings['sample_rate'] . '%' ); ?></dd></div>
                </dl>
            </section>

            <section class="directorist-performance__section" aria-labelledby="directorist-route-heading">
                <div class="directorist-performance__section-heading">
                    <h2 id="directorist-route-heading"><?php esc_html_e( 'Route state', 'directorist' ); ?></h2>
                    <span><?php esc_html_e( 'Recent bounded samples', 'directorist' ); ?></span>
                </div>
                <ol class="directorist-performance__route-flow">
                    <?php
                    $labels = [
                        'eligible'    => __( 'Eligible', 'directorist' ),
                        'bypassed'    => __( 'Bypassed', 'directorist' ),
                        'queued'      => __( 'Warm queue', 'directorist' ),
                        'cached'      => __( 'Cached', 'directorist' ),
                        'invalidated' => __( 'Invalidated', 'directorist' ),
                    ];
                    foreach ( $labels as $key => $label ) :
                        ?>
                        <li><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( isset( $route_flow[ $key ] ) ? (string) $route_flow[ $key ] : '0' ); ?></strong></li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <?php foreach ( $extensions as $extension_id => $extension ) : ?>
                <section class="directorist-performance__section" aria-labelledby="directorist-extension-<?php echo esc_attr( sanitize_html_class( $extension_id ) ); ?>">
                    <div class="directorist-performance__section-heading">
                        <h2 id="directorist-extension-<?php echo esc_attr( sanitize_html_class( $extension_id ) ); ?>"><?php echo esc_html( isset( $extension['label'] ) ? $extension['label'] : $extension_id ); ?></h2>
                    </div>
                    <?php $this->render_extension_status( $extension ); ?>
                </section>
            <?php endforeach; ?>

            <section class="directorist-performance__section directorist-performance__controls" aria-labelledby="directorist-controls-heading">
                <div class="directorist-performance__section-heading"><h2 id="directorist-controls-heading"><?php esc_html_e( 'Operations', 'directorist' ); ?></h2></div>
                <div class="directorist-performance__actions">
                    <?php $this->operation_form( 'verify', __( 'Verify', 'directorist' ), 'button-secondary' ); ?>
                    <?php $this->operation_form( 'purge', __( 'Purge cache', 'directorist' ), 'button-secondary' ); ?>
                    <?php $this->operation_form( 'warm', __( 'Warm routes', 'directorist' ), 'button-primary' ); ?>
                    <?php $this->operation_form( 'pause', __( 'Pause queue', 'directorist' ), 'button-secondary' ); ?>
                    <?php $this->operation_form( 'resume', __( 'Resume queue', 'directorist' ), 'button-secondary' ); ?>
                    <?php $this->operation_form( 'cancel', __( 'Cancel queue', 'directorist' ), 'button-secondary' ); ?>
                    <?php $this->operation_form( $settings['enabled'] ? 'disable' : 'enable', $settings['enabled'] ? __( 'Emergency disable', 'directorist' ) : __( 'Enable integration', 'directorist' ), $settings['enabled'] ? 'button-link-delete' : 'button-primary' ); ?>
                </div>
            </section>

            <section class="directorist-performance__section" aria-labelledby="directorist-settings-heading">
                <div class="directorist-performance__section-heading"><h2 id="directorist-settings-heading"><?php esc_html_e( 'Diagnostics settings', 'directorist' ); ?></h2></div>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="directorist-performance__settings">
                    <input type="hidden" name="action" value="directorist_performance_action">
                    <input type="hidden" name="operation" value="save_settings">
                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
                    <label><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?>> <?php esc_html_e( 'Directorist cache integration', 'directorist' ); ?></label>
                    <label><?php esc_html_e( 'Eligibility sample rate', 'directorist' ); ?>
                        <select name="sample_rate">
                            <?php foreach ( [ 0, 1, 5, 10, 25, 100 ] as $rate ) : ?>
                                <option value="<?php echo esc_attr( $rate ); ?>" <?php selected( $settings['sample_rate'], $rate ); ?>><?php echo esc_html( $rate . '%' ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><?php esc_html_e( 'History limit', 'directorist' ); ?> <input type="number" name="history_limit" min="10" max="100" step="5" value="<?php echo esc_attr( $settings['history_limit'] ); ?>"></label>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'directorist' ); ?></button>
                </form>
            </section>

            <section class="directorist-performance__section" aria-labelledby="directorist-history-heading">
                <div class="directorist-performance__section-heading">
                    <h2 id="directorist-history-heading"><?php esc_html_e( 'Event history', 'directorist' ); ?></h2>
                    <?php $this->operation_form( 'clear_history', __( 'Clear', 'directorist' ), 'button-link' ); ?>
                </div>
                <div class="directorist-performance__table-wrap">
                    <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Time', 'directorist' ); ?></th><th><?php esc_html_e( 'Level', 'directorist' ); ?></th><th><?php esc_html_e( 'Code', 'directorist' ); ?></th><th><?php esc_html_e( 'Context', 'directorist' ); ?></th></tr></thead>
                        <tbody>
                        <?php if ( empty( $events ) ) : ?>
                            <tr><td colspan="4"><?php esc_html_e( 'No recorded events', 'directorist' ); ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ( $events as $event ) : ?>
                            <tr><td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $event['time'] ) ); ?></td><td><?php echo esc_html( $event['level'] ); ?></td><td><code><?php echo esc_html( $event['code'] ); ?></code></td><td><?php echo esc_html( wp_json_encode( $event['context'] ) ); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <?php
    }

    /**
     * @param string $operation Operation name.
     * @param string $label Button label.
     * @param string $class Button class.
     * @return void
     */
    private function operation_form( $operation, $label, $class ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="directorist_performance_action">
            <input type="hidden" name="operation" value="<?php echo esc_attr( $operation ); ?>">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
            <button type="submit" class="button <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }

    /**
     * @param array $extension Extension status.
     * @return void
     */
    private function render_extension_status( array $extension ) {
        $values = isset( $extension['values'] ) && is_array( $extension['values'] ) ? $extension['values'] : [];
        echo '<dl class="directorist-performance__ledger">';

        foreach ( $values as $label => $value ) {
            if ( ! is_scalar( $value ) ) {
                continue;
            }

            echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd></div>';
        }

        echo '</dl>';
    }
}
