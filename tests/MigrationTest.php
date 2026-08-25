<?php
/**
 * Migration behavior tests.
 *
 * @package ExtraChillLinkPages
 */

use PHPUnit\Framework\TestCase;

/** Test the concrete migration contracts available in the standalone fixture. */
final class MigrationTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
	}

	public function test_plan_is_read_only_and_fingerprint_is_deterministic(): void {
		$before = $GLOBALS['ec_test'];
		$first  = ec_plan_link_page_storage_migration( 4, 7 );
		$second = ec_plan_link_page_storage_migration( 4, 7 );

		$this->assertFalse( is_wp_error( $first ) );
		$this->assertTrue( $first['ready'] );
		$this->assertSame( $first['fingerprint'], $second['fingerprint'] );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( $before['site_options'] ?? array(), $GLOBALS['ec_test']['site_options'] ?? array() );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][7]['posts'] );
	}

	public function test_canonical_hash_ignores_associative_insertion_order_but_detects_drift(): void {
		$this->assertSame(
			ec_link_page_migration_hash(
				array(
					'b' => 2,
					'a' => array(
						'y' => 2,
						'x' => 1,
					),
				)
			),
			ec_link_page_migration_hash(
				array(
					'a' => array(
						'x' => 1,
						'y' => 2,
					),
					'b' => 2,
				)
			)
		);
		$this->assertNotSame( ec_link_page_migration_hash( array( 'a' => 1 ) ), ec_link_page_migration_hash( array( 'a' => 2 ) ) );
	}

	public function test_participant_registration_is_named_complete_and_append_only(): void {
		$callbacks = array_fill_keys( array( 'plan', 'apply', 'validate', 'rollback' ), '__return_true' );
		$this->assertTrue( ec_register_link_page_migration_participant( 'fixture', $callbacks ) );
		$this->assertSame( 'duplicate_link_page_migration_participant', ec_register_link_page_migration_participant( 'fixture', $callbacks )->get_error_code() );
		unset( $callbacks['rollback'] );
		$this->assertSame( 'invalid_link_page_migration_participant', ec_register_link_page_migration_participant( 'incomplete', $callbacks )->get_error_code() );
	}

	public function test_nested_blog_context_is_restored_after_exception(): void {
		$result = ec_link_page_migration_in_blog(
			7,
			static function () {
				throw new RuntimeException( 'failure' );
			}
		);
		$this->assertSame( 'link_page_migration_exception', $result->get_error_code() );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] );
	}
}
