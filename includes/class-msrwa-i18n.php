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
	public static function load() {
		load_plugin_textdomain( self::DOMAIN, false, dirname( plugin_basename( MSRWA_FILE ) ) . '/languages' );
	}

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
		/* translators: %s is a length of time, such as "4 minutes". */
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
		// Rounded once, to whole seconds, before splitting: 179.8 s used to
		// read "2 min 60 s".
		$whole = (int) round( $seconds );
		if ( $whole >= 3600 ) {
			$minutes = (int) round( $whole / 60 );
			/* translators: 1: whole hours, 2: remaining minutes. */
			return sprintf( __( '%1$d h %2$d min', 'ms-recipes-writer-ai' ), intdiv( $minutes, 60 ), $minutes % 60 );
		}
		/* translators: 1: whole minutes, 2: remaining seconds. */
		return sprintf( __( '%1$d min %2$d s', 'ms-recipes-writer-ai' ), intdiv( $whole, 60 ), $whole % 60 );
	}
}
