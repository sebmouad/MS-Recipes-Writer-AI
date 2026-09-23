<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Which model may serve which step.
 *
 * Three answers must all be yes. The model can do what the step needs — the
 * research searches the web, the final approval and the photograph reading
 * look at images, the images are drawn. It has not been measured failing the
 * step. And the owner has not taken it off that step on the Modèles screen.
 *
 * Having a capability on paper is not enough: gpt-5-nano searches the web,
 * yet on the research step it spends its whole answer reasoning between
 * searches and is cut at the ceiling. A route like that used to be offered
 * as `openai:low`, saved without a word, and fail on the first recipe.
 */
final class MSRWA_Compat {

	/**
	 * Models measured unable to do a step, with what was measured. Keyed by
	 * provider:model; a reason is shown wherever the choice is refused.
	 */
	public static function unfit() {
		return array(
			'openai:gpt-5-nano' => array(
				'research' => __( 'Mesuré le 23/09/2026 : il consacre toute sa réponse à réfléchir entre deux recherches et s’arrête au plafond (0/14). Avec un plafond de 32 000 jetons, 9/14 sans aucune photographie, pour 2,7 fois le coût de GPT-5.6 Luna.', 'ms-recipes-writer-ai' ),
			),
			'openai:gpt-5.4-mini' => array(
				'research' => __( 'Mesuré le 23/09/2026 : coupé au plafond sans réponse (0/14), pour 0,11 $.', 'ms-recipes-writer-ai' ),
			),
		);
	}

	/** What a routed step needs of its model. */
	public static function needs( $step ) {
		$step = (string) $step;
		if ( in_array( $step, array( 'featured_image', 'facebook_image', 'image' ), true ) ) { return array( 'image_generation' ); }
		if ( 'research' === $step ) { return array( 'text', 'web_search' ); }
		// Reading a photograph means answering about it in words: an image model
		// takes images in but cannot describe them.
		if ( 'vision' === $step ) { return array( 'text', 'vision' ); }
		if ( 'final_approval' === $step ) { return array( 'text', 'vision' ); }
		return array( 'text' );
	}

	/** A capability, as a person reads it. */
	private static function capability_name( $capability ) {
		$names = array(
			'text' => __( 'rédiger du texte', 'ms-recipes-writer-ai' ),
			'web_search' => __( 'chercher sur le web', 'ms-recipes-writer-ai' ),
			'vision' => __( 'lire des images', 'ms-recipes-writer-ai' ),
			'image_generation' => __( 'dessiner des images', 'ms-recipes-writer-ai' ),
		);
		return $names[ $capability ] ?? $capability;
	}

	/**
	 * Why a model may not serve a step, or '' when it may.
	 *
	 * A capability must be known to be there: a model nobody has established
	 * can search the web is not handed the research, because the first sign
	 * would be a paid run that produced nothing.
	 */
	public static function refusal( $provider, $model, $step, $with_owner = true ) {
		$provider = sanitize_key( (string) $provider );
		$model = (string) $model;
		if ( '' === $model ) { return __( 'aucun modèle ne répond à ce choix', 'ms-recipes-writer-ai' ); }
		$unfit = self::unfit()[ $provider . ':' . $model ][ (string) $step ] ?? '';
		if ( '' !== $unfit ) { return $unfit; }

		$capabilities = self::capabilities( $provider, $model );
		foreach ( self::needs( $step ) as $need ) {
			$known = $capabilities[ $need ] ?? null;
			if ( false === $known ) {
				/* translators: %s is what the step needs the model to do, such as "search the web". */
				return sprintf( __( 'ce modèle ne sait pas %s, ce que cette étape demande', 'ms-recipes-writer-ai' ), self::capability_name( $need ) );
			}
			if ( true !== $known ) {
				/* translators: %s is what the step needs the model to do, such as "search the web". */
				return sprintf( __( 'rien n’établit que ce modèle sait %s, ce que cette étape demande', 'ms-recipes-writer-ai' ), self::capability_name( $need ) );
			}
		}

		if ( ! $with_owner ) { return ''; }
		$row = class_exists( 'MSRWA_Catalog' ) ? MSRWA_Catalog::row( $provider, $model ) : null;
		if ( $row && empty( $row['enabled'] ) ) { return __( 'désactivé sur l’écran Modèles', 'ms-recipes-writer-ai' ); }
		// The image steps are listed on the Modèles screen as the two images; the
		// shared `image` route answers to either.
		$owner_step = 'image' === $step ? 'featured_image' : (string) $step;
		if ( $row && ! MSRWA_Catalog::allows( $row, $owner_step ) && ( 'image' !== $step || ! MSRWA_Catalog::allows( $row, 'facebook_image' ) ) ) {
			return __( 'retiré de cette étape sur l’écran Modèles', 'ms-recipes-writer-ai' );
		}
		return '';
	}

	public static function allowed( $provider, $model, $step ) { return '' === self::refusal( $provider, $model, $step ); }

	/**
	 * A model's capabilities: what the plugin ships, overridden by what the
	 * catalogue's row states. A fetch records only what the provider's list
	 * says, so a row alone would forget that a shipped model reads images.
	 */
	public static function capabilities( $provider, $model ) {
		$row = class_exists( 'MSRWA_Catalog' ) ? MSRWA_Catalog::row( $provider, $model ) : null;
		$shipped = class_exists( 'MSRWA_Catalog' ) ? ( MSRWA_Catalog::defaults()[ $provider ][ $model ] ?? array() ) : array();
		$stated = $row && is_array( $row['capabilities'] ?? null ) ? $row['capabilities'] : array();
		$out = array();
		foreach ( array( 'text', 'vision', 'web_search', 'image_generation' ) as $capability ) {
			if ( array_key_exists( $capability, $stated ) ) { $out[ $capability ] = (bool) $stated[ $capability ]; } elseif ( array_key_exists( $capability, $shipped ) ) { $out[ $capability ] = (bool) $shipped[ $capability ]; }
		}
		return $out;
	}

	/**
	 * Every routed step the configuration cannot serve, step => reason.
	 * The same resolution the engine will make, so what is refused here is
	 * what would have failed there.
	 */
	public static function problems( array $overrides ) {
		$config = MSRWA_Engine_Config::create( $overrides );
		$problems = array();
		foreach ( self::routed_steps( $config ) as $step ) {
			$image = in_array( $step, array( 'featured_image', 'facebook_image' ), true );
			$route = $config->model_for( $image ? $config->image_route( $step ) : $step );
			$why = self::refusal( (string) ( $route['provider'] ?? '' ), (string) ( $route['model'] ?? '' ), $step );
			if ( '' !== $why ) { $problems[ $step ] = array( 'route' => (string) ( $route['provider'] ?? '' ) . ':' . (string) ( $route['model'] ?? '' ), 'reason' => $why ); }
		}
		return $problems;
	}

	/** The steps that call a model, plus the photograph reading the pairing uses. */
	public static function routed_steps( MSRWA_Engine_Config $config ) {
		$steps = array();
		foreach ( (array) $config->steps() as $name => $step ) {
			if ( 'none' !== (string) ( $step['capability'] ?? 'text' ) ) { $steps[] = (string) $name; }
		}
		$steps[] = 'vision';
		return array_values( array_unique( $steps ) );
	}

	/**
	 * The steps a model is chosen for, in the order they run, as both the
	 * Modèles and the Moteur screens show them. The shared `image` route is
	 * two rows: each image is judged, priced and routed on its own.
	 */
	public static function steps() {
		$config = MSRWA_Engine_Config::create( class_exists( 'MSRWA_Engine_Settings' ) ? MSRWA_Engine_Settings::stored() : array() );
		$registry = (array) $config->steps();
		$out = array();
		foreach ( self::routed_steps( $config ) as $key ) {
			$name = MSRWA_UI::step_name( $key );
			$out[ $key ] = array(
				'label' => $name !== $key ? $name : (string) ( $registry[ $key ]['label'] ?? $key ),
				'image' => self::draws( $key ),
			);
		}
		return $out;
	}

	/** Whether a step is served by a model that draws rather than one that writes. */
	public static function draws( $step ) { return in_array( 'image_generation', self::needs( $step ), true ); }

	/** Whether a model draws: the family it is offered for, on both screens. */
	public static function is_image_model( $provider, $model ) {
		return ! empty( self::capabilities( $provider, $model )['image_generation'] );
	}

	/**
	 * What a step may be given, per provider: every model of the right family
	 * that the Modèles screen keeps switched on and the provider still serves.
	 * A model that is a level's pick is offered as that level, so the route
	 * keeps following the level when the catalogue moves it. Each choice
	 * carries the reason it is refused, if it is.
	 */
	public static function choices( $step, MSRWA_Engine_Config $config ) {
		$image = self::draws( $step );
		$tiers = (array) $config->get( 'tiers', array() );
		$out = array();
		foreach ( self::catalogue() as $row ) {
			if ( empty( $row['enabled'] ) || false === $row['served'] ) { continue; }
			$provider = (string) $row['provider'];
			$model = (string) $row['model_id'];
			if ( self::is_image_model( $provider, $model ) !== $image ) { continue; }
			$tier = '';
			foreach ( array( 'low', 'medium', 'high' ) as $name ) {
				if ( $model === (string) ( $tiers[ $name ][ $provider ] ?? '' ) ) { $tier = $name; break; }
			}
			$out[ $provider ][] = array(
				'value' => $provider . ':' . ( '' !== $tier && ! $image ? $tier : $model ),
				'model' => $model,
				'tier' => $image ? '' : $tier,
				'price' => null === $row['input_usd'] ? null : (float) $row['input_usd'],
				'blocked' => self::refusal( $provider, $model, $step ),
			);
		}
		// Levels first, cheapest first; then the rest by price.
		$rank = array( 'low' => 0, 'medium' => 1, 'high' => 2, '' => 3 );
		foreach ( $out as $provider => $list ) {
			usort( $list, static function ( $a, $b ) use ( $rank ) {
				return array( $rank[ $a['tier'] ], (float) $a['price'] ) <=> array( $rank[ $b['tier'] ], (float) $b['price'] );
			} );
			$out[ $provider ] = $list;
		}
		return $out;
	}

	/**
	 * The catalogue's rows, or what the plugin ships when the table is empty —
	 * the same fallback MSRWA_Catalog::models() makes, so a site that never
	 * seeded its catalogue is still offered the models it will run.
	 */
	private static function catalogue() {
		$rows = MSRWA_Catalog::rows();
		if ( $rows ) { return $rows; }
		foreach ( MSRWA_Catalog::defaults() as $provider => $models ) {
			foreach ( $models as $model => $info ) {
				$rows[] = array(
					'provider' => $provider, 'model_id' => $model,
					'enabled' => ! array_key_exists( 'enabled', $info ) || ! empty( $info['enabled'] ),
					'served' => null,
					'input_usd' => isset( $info['input'] ) ? (float) $info['input'] : null,
				);
			}
		}
		return $rows;
	}

	/** Problems as one sentence, for a notice or an error. */
	public static function describe( array $problems ) {
		$parts = array();
		foreach ( $problems as $step => $problem ) {
			$parts[] = sprintf( '%s (%s) : %s', MSRWA_UI::step_name( $step ), $problem['route'], $problem['reason'] );
		}
		return implode( ' ; ', $parts );
	}
}
