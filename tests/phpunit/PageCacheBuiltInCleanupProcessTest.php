<?php
/**
 * Built-in asynchronous cleanup behavior locks.
 */

use Directorist\Cache\Built_In\Cleanup_Background_Process;

final class Directorist_Page_Cache_Built_In_Cleanup_Process_Test extends WP_UnitTestCase {
    private $worker;

    protected function tearDown(): void {
        if ( $this->worker instanceof Cleanup_Background_Process ) {
            $this->worker->reset();
        }

        parent::tearDown();
    }

    public function test_enqueue_is_deduplicated_and_dispatches_one_background_job() {
        $dispatches   = 0;
        $this->worker = new Cleanup_Background_Process(
            [
                'dispatcher' => static function () use ( &$dispatches ) {
                    ++$dispatches;

                    return true;
                },
            ]
        );
        $this->worker->reset();

        $first  = $this->worker->enqueue();
        $second = $this->worker->enqueue();

        $this->assertSame( 'queued', $first['code'] );
        $this->assertSame( 'already_queued', $second['code'] );
        $this->assertSame( 1, $dispatches );
        $this->assertTrue( $this->worker->status()['pending'] );
        $this->assertTrue( $this->worker->status()['queued'] );
    }

    public function test_process_item_uses_a_fixed_cleanup_limit_and_normalizes_completion() {
        $limits       = [];
        $this->worker = new Cleanup_Background_Process(
            [
                'runner' => static function ( $limit ) use ( &$limits ) {
                    $limits[] = $limit;

                    return [ 'success' => true, 'code' => 'cleaned', 'complete' => true, 'removed' => 3 ];
                },
            ]
        );

        $result = $this->worker->process_item( [ 'type' => 'cleanup', 'attempts' => 0 ] );

        $this->assertSame( [ Cleanup_Background_Process::BATCH_LIMIT ], $limits );
        $this->assertTrue( $result['success'] );
        $this->assertTrue( $result['complete'] );
        $this->assertSame( 3, $result['removed'] );
    }

    public function test_invalid_and_throwing_cleanup_jobs_fail_closed() {
        $this->worker = new Cleanup_Background_Process(
            [
                'runner' => static function () {
                    throw new RuntimeException( 'failure' );
                },
            ]
        );

        $this->assertSame( 'invalid_item', $this->worker->process_item( [] )['code'] );
        $this->assertSame( 'cleanup_exception', $this->worker->process_item( [ 'type' => 'cleanup' ] )['code'] );
    }
}
