<?php
/**
 * What get_role() hands back, with only the part the plugin reads.
 *
 * The plugin asks a role one question — does it still carry this capability —
 * so that is the whole of this. Tests declare the roles they want through
 * msrwa_test_roles().
 */
final class MSRWA_Fake_Role {

	private $capabilities;

	public function __construct( array $capabilities ) {
		$this->capabilities = $capabilities;
	}

	public function has_cap( $capability ) {
		return in_array( $capability, $this->capabilities, true );
	}
}
