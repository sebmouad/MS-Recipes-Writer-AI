<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The plugin speaks the language its reader does.
 *
 * Two different languages live in here and must not be confused. The interface
 * language is the reader's, and WordPress already knows it. The article
 * language is the batch's, chosen per submission and sent to the engine — a
 * French editor may perfectly well commission an article in Arabic.
 */
final class MSRWA_I18N {

	const DOMAIN = 'ms-recipes-writer-ai';

	/** The interface languages shipped with the plugin. */
	public static function interface_languages() {
		return array(
			'fr_FR' => 'Français',
			'en_US' => 'English',
			'ar'    => 'العربية',
		);
	}

	public static function load() {
		load_plugin_textdomain( self::DOMAIN, false, dirname( plugin_basename( MSRWA_FILE ) ) . '/languages' );
	}

	/**
	 * Whether the interface is being read right to left.
	 *
	 * Arabic is one of the three shipped languages, so every screen has to work
	 * mirrored. WordPress sets the body class; this is for the few places that
	 * have to decide something themselves.
	 */
	public static function is_rtl() { return function_exists( 'is_rtl' ) && is_rtl(); }

	/**
	 * A language name in the reader's own language.
	 *
	 * The article language is data, not interface: it is stored as `fr`, `en` or
	 * `ar` and shown as a word the reader recognises.
	 */
	public static function language_name( $code ) {
		$names = MSRWA_Profile::languages();
		return isset( $names[ $code ] ) ? $names[ $code ] : strtoupper( (string) $code );
	}

	/** A short, readable date in the site's own timezone and format. */
	public static function when( $mysql_utc ) {
		$timestamp = strtotime( (string) $mysql_utc . ' UTC' );
		if ( ! $timestamp ) { return ''; }
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/** "il y a 4 minutes", in the reader's language. */
	public static function ago( $mysql_utc ) {
		$timestamp = strtotime( (string) $mysql_utc . ' UTC' );
		if ( ! $timestamp ) { return ''; }
		/* translators: %s is a human-readable duration, e.g. "4 minutes". */
		return sprintf( __( 'il y a %s', 'ms-recipes-writer-ai' ), human_time_diff( $timestamp, time() ) );
	}

	/**
	 * An amount in dollars, written the way the reader's language writes it.
	 *
	 * French puts the sign after the number and English before it, and hard
	 * coding either makes the other look like a translation nobody finished.
	 */
	public static function money( $amount, $decimals = 4 ) {
		/* translators: %s is an amount of money. Put the currency sign where your language puts it. */
		return sprintf( __( '%s $', 'ms-recipes-writer-ai' ), number_format_i18n( (float) $amount, $decimals ) );
	}

	public static function seconds( $seconds ) {
		$seconds = (float) $seconds;
		if ( $seconds < 60 ) {
			/* translators: %s is a number of seconds. */
			return sprintf( __( '%s s', 'ms-recipes-writer-ai' ), number_format_i18n( $seconds, 1 ) );
		}
		/* translators: 1: whole minutes, 2: remaining seconds. */
		return sprintf( __( '%1$d min %2$d s', 'ms-recipes-writer-ai' ), floor( $seconds / 60 ), round( fmod( $seconds, 60 ) ) );
	}
}
