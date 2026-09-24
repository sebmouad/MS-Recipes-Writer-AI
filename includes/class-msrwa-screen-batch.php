<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * One submission: the pairing to settle, then the recipes running.
 *
 * The pairing is the only moment a person is asked to decide something, and it
 * is the one decision the machine cannot be trusted with alone — a photograph
 * on the wrong article is not a small mistake. So it is shown large, with what
 * the model saw and how sure it was, and nothing is dispatched until it is
 * confirmed.
 */
final class MSRWA_Screen_Batch {

	public static function render() {
		if ( ! MSRWA_Rights::may_write() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }

		$batch = MSRWA_Batch::get( isset( $_GET['batch_id'] ) ? absint( $_GET['batch_id'] ) : 0 );
		if ( ! $batch || ! MSRWA_Batch::may_see( $batch ) ) { wp_die( esc_html__( 'Lot introuvable.', 'ms-recipes-writer-ai' ) ); }

		$matching = MSRWA_Batch::matching( (int) $batch['id'] );
		$runs = MSRWA_Run::for_batch( (int) $batch['id'] );
		$settling = 'ready' === $batch['status'];
		$profile = MSRWA_Profile::get( $batch['profile'] );

		echo '<div class="wrap msrwa" data-batch="' . esc_attr( $batch['id'] ) . '">';
		MSRWA_UI::head(
			/* translators: %d is the lot's number. */
			sprintf( __( 'Lot %d', 'ms-recipes-writer-ai' ), (int) $batch['id'] ),
			$batch['label'],
			array_filter( array(
				__( 'recettes', 'ms-recipes-writer-ai' ) => number_format_i18n( $batch['recipes'] ),
				__( 'photographies', 'ms-recipes-writer-ai' ) => number_format_i18n( $batch['images'] ),
				__( 'langue', 'ms-recipes-writer-ai' ) => MSRWA_I18N::language_name( $batch['language'] ),
				MSRWA_Rights::may_see_money() ? __( 'plafond', 'ms-recipes-writer-ai' ) : '' => MSRWA_Rights::may_see_money() ? MSRWA_I18N::money( $batch['budget_usd'], 2 ) : '',
			) ),
			self::head_actions( $batch )
		);

		echo '<p class="ms-muted">' . esc_html( $profile['label'] ) . ' — ' . esc_html( $profile['description'] ) . '</p>';

		if ( '' !== (string) $batch['error_message'] ) {
			MSRWA_UI::note( esc_html( $batch['error_message'] ), 'warn' );
		}
		if ( 'matching' === $batch['status'] ) {
			MSRWA_UI::note( esc_html__( 'Les photographies sont en cours de description. Rechargez dans un instant.', 'ms-recipes-writer-ai' ) );
		}
		$waiting = MSRWA_Schedule::waiting_for( $batch );
		if ( '' !== $waiting ) { MSRWA_UI::note( esc_html( $waiting ) ); }

		// The form speaks the site's timezone; the column is UTC.
		$scheduled = '';
		if ( ! empty( $batch['dispatch_at'] ) ) {
			$stamp = strtotime( $batch['dispatch_at'] . ' UTC' );
			if ( $stamp ) { $scheduled = wp_date( 'Y-m-d\TH:i', $stamp ); }
		}

		self::pairing( $matching, $settling, (int) $batch['recipes'], $scheduled );
		self::runs( $runs );
		echo '</div>';
	}

	private static function pairing( array $matching, $settling, $recipe_count, $scheduled = '' ) {
		$images = (array) ( $matching['images'] ?? array() );
		// A lot's photographs are served by the REST API, which reads the
		// logged-in writer from this nonce; an <img> sends no header.
		foreach ( $images as &$image ) {
			if ( ! empty( $image['url'] ) && ! is_int( $image['id'] ?? null ) ) { $image['url'] = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), $image['url'] ); }
		}
		unset( $image );
		$recipes = (array) ( $matching['recipes'] ?? array() );
		$chosen = array();
		foreach ( array_keys( $images ) as $index ) { $chosen[ $index ] = array( 'recipe' => null, 'confidence' => 'basse', 'why' => '' ); }
		foreach ( (array) ( $matching['pairs'] ?? array() ) as $pair ) {
			if ( isset( $chosen[ (int) $pair['image'] ] ) ) { $chosen[ (int) $pair['image'] ] = $pair; }
		}

		echo '<section class="ms-card ms-card-flush ms-pairing"' . ( $settling ? ' data-settling="1"' : '' ) . '>';
		echo '<h2>' . esc_html__( 'Ce qui sera écrit', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html( $settling
			? __( 'Vérifiez à quelle recette va chaque photographie, puis lancez. Une recette illustrée par vos photographies est écrite d’après elles ; sans photographie, ses références sont cherchées sur le web.', 'ms-recipes-writer-ai' )
			: __( 'L’appariement de ce lot est arrêté : il est parti avec ce qui suit.', 'ms-recipes-writer-ai' ) ) . '</p>';

		$named = array_filter( $recipes, static function ( $recipe ) { return ! empty( $recipe['from_photographs'] ); } );
		if ( $named ) {
			echo '<p class="ms-note ms-note-live">' . esc_html( sprintf(
				/* translators: %s lists the dish names read from the photographs. */
				__( 'Aucun texte fourni : ces recettes ont été nommées d’après les photographies — %s. Chacune sera établie d’après les sources ; vérifiez-les avant de lancer.', 'ms-recipes-writer-ai' ),
				implode( ', ', array_map( static function ( $recipe ) { return (string) $recipe['title']; }, $named ) )
			) ) . '</p>';
		}

		// The recipes first: they are what is being made. Each shows the
		// photographs it will carry, and what having them — or not — changes.
		echo '<ol class="ms-dishes" id="ms-dishes">';
		foreach ( $recipes as $recipe_index => $recipe ) {
			$mine = array_keys( array_filter( $chosen, static function ( $pair ) use ( $recipe_index ) { return null !== $pair['recipe'] && (int) $pair['recipe'] === (int) $recipe_index; } ) );
			echo '<li class="ms-dish' . ( $mine ? ' has-photos' : '' ) . '" data-recipe="' . esc_attr( $recipe_index ) . '">';
			echo '<strong class="ms-dish-title">' . esc_html( (string) $recipe['title'] ) . '</strong>';
			echo '<span class="ms-dish-thumbs">';
			foreach ( $mine as $image_index ) {
				if ( ! empty( $images[ $image_index ]['url'] ) ) { echo '<img src="' . esc_url( $images[ $image_index ]['url'] ) . '" alt="" loading="lazy">'; }
			}
			echo '</span>';
			echo '<small class="ms-dish-with">' . esc_html( $mine
				? sprintf( /* translators: %d is a number of photographs. */ _n( '%d photographie — écrite d’après elle', '%d photographies — écrite d’après elles', count( $mine ), 'ms-recipes-writer-ai' ), count( $mine ) )
				: __( 'Sans photographie — références cherchées sur le web', 'ms-recipes-writer-ai' ) ) . '</small>';
			echo '</li>';
		}
		echo '</ol>';

		if ( ! $images ) {
			MSRWA_UI::nothing(
				__( 'Aucune photographie', 'ms-recipes-writer-ai' ),
				__( 'Les recettes seront écrites d’après les références trouvées sur le web.', 'ms-recipes-writer-ai' )
			);
			self::actions( $settling, $recipe_count, $scheduled );
			echo '</section>';
			return;
		}

		echo '<h3 class="ms-pairing-sub">' . esc_html__( 'Vos photographies', 'ms-recipes-writer-ai' ) . '</h3>';
		$loose = count( array_filter( $chosen, static function ( $pair ) { return null === $pair['recipe']; } ) );
		echo '<p class="ms-note ms-note-warn ms-loose" id="ms-loose"' . ( $loose ? '' : ' hidden' ) . ' data-one="' . esc_attr__( 'Une photographie ne va avec aucune recette : elle ne sera pas utilisée.', 'ms-recipes-writer-ai' ) . '" data-many="' . esc_attr__( '%d photographies ne vont avec aucune recette : elles ne seront pas utilisées.', 'ms-recipes-writer-ai' ) . '">'
			. esc_html( 1 === $loose ? __( 'Une photographie ne va avec aucune recette : elle ne sera pas utilisée.', 'ms-recipes-writer-ai' ) : sprintf( /* translators: %d is a number of photographs. */ __( '%d photographies ne vont avec aucune recette : elles ne seront pas utilisées.', 'ms-recipes-writer-ai' ), $loose ) ) . '</p>';

		echo '<ul class="ms-pairs">';
		foreach ( $images as $index => $image ) {
			$pair = $chosen[ $index ];
			// A photograph set aside is not a match, however sure the model was.
			$aside = null === $pair['recipe'];
			$mine = ! empty( $pair['by_writer'] );
			$tone = $aside ? 'idle' : ( $mine ? 'good' : ( array( 'haute' => 'good', 'moyenne' => 'warn' )[ (string) $pair['confidence'] ] ?? 'stop' ) );
			$dish = (string) ( $image['dish'] ?? '' );
			$dish = '' !== $dish ? mb_strtoupper( mb_substr( $dish, 0, 1 ) ) . mb_substr( $dish, 1 ) : __( 'Plat non reconnu', 'ms-recipes-writer-ai' );
			$label = sprintf(
				/* translators: %s is a file name. */
				__( 'Recette pour la photographie %s', 'ms-recipes-writer-ai' ),
				(string) $image['file']
			);
			$current = null === $pair['recipe'] ? '' : (string) ( $recipes[ (int) $pair['recipe'] ]['title'] ?? '' );
			?>
			<li class="ms-pair ms-pair-<?php echo esc_attr( $tone ); ?><?php echo empty( $image['url'] ) ? ' ms-pair-blind' : ''; ?>">
				<?php if ( ! empty( $image['url'] ) ) : ?>
					<a class="ms-pair-photo" href="<?php echo esc_url( $image['url'] ); ?>" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e( 'Voir en grand', 'ms-recipes-writer-ai' ); ?>"><img src="<?php echo esc_url( $image['url'] ); ?>" alt="<?php echo esc_attr( (string) ( $image['dish'] ?? '' ) ); ?>" loading="lazy"></a>
				<?php endif; ?>
				<div class="ms-pair-body">
					<p class="ms-pair-dish"><?php echo esc_html( $dish ); ?></p>
					<p class="ms-pair-says"><?php echo esc_html( (string) ( $image['describes'] ?? __( 'Non décrite.', 'ms-recipes-writer-ai' ) ) ); ?></p>
					<p class="ms-pair-why"><span class="ms-state ms-state-<?php echo esc_attr( $tone ); ?> ms-pair-badge"><?php echo esc_html( $aside ? __( 'Mise de côté', 'ms-recipes-writer-ai' ) : ( $mine ? __( 'Choisie par vous', 'ms-recipes-writer-ai' ) : self::confidence( (string) $pair['confidence'] ) ) ); ?></span>
						<?php if ( '' !== (string) $pair['why'] && 'Confirmé par le rédacteur.' !== (string) $pair['why'] ) : ?><span class="ms-pair-reason"><?php echo esc_html( $pair['why'] ); ?></span><?php endif; ?></p>
					<p class="ms-pair-file"><?php echo esc_html( $image['file'] ); ?></p>
				</div>
				<div class="ms-pair-pick">
					<?php if ( $settling ) : ?>
						<label for="ms-pair-<?php echo esc_attr( $index ); ?>"><span class="screen-reader-text"><?php echo esc_html( $label ); ?></span><span aria-hidden="true"><?php esc_html_e( 'Va avec', 'ms-recipes-writer-ai' ); ?></span></label>
						<select id="ms-pair-<?php echo esc_attr( $index ); ?>" class="ms-pair-choice" data-image="<?php echo esc_attr( $index ); ?>" data-url="<?php echo esc_url( (string) ( $image['url'] ?? '' ) ); ?>">
							<option value=""><?php esc_html_e( 'aucune recette', 'ms-recipes-writer-ai' ); ?></option>
							<?php foreach ( $recipes as $recipe_index => $recipe ) : ?>
								<option value="<?php echo esc_attr( $recipe_index ); ?>" <?php selected( null !== $pair['recipe'] && (int) $recipe_index === (int) $pair['recipe'] ); ?>><?php echo esc_html( $recipe['title'] ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<span class="ms-pair-fixed"><?php echo esc_html( '' !== $current ? $current : __( 'aucune recette', 'ms-recipes-writer-ai' ) ); ?></span>
					<?php endif; ?>
				</div>
			</li>
			<?php
		}
		echo '</ul>';

		self::actions( $settling, $recipe_count, $scheduled );
		echo '</section>';
	}

	/** How sure the model was, said in words rather than as a bare label. */
	private static function confidence( $level ) {
		$words = array(
			'haute' => __( 'Association sûre', 'ms-recipes-writer-ai' ),
			'moyenne' => __( 'Association probable — vérifiez', 'ms-recipes-writer-ai' ),
			'basse' => __( 'Peu sûr — à confirmer', 'ms-recipes-writer-ai' ),
		);
		return $words[ $level ] ?? $words['basse'];
	}

	/**
	 * Leaving, and — for a lot that is not moving — throwing it away.
	 *
	 * A lot decided against had nowhere to go: it sat on the pass waiting for a
	 * confirmation that was never coming. Deleting removes the lot and what the
	 * engine reported about it; the drafts it produced are ordinary posts and
	 * are left exactly where they are.
	 */
	private static function head_actions( array $batch ) {
		$out = '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=msrwa' ) ) . '">' . esc_html__( 'Retour au pass', 'ms-recipes-writer-ai' ) . '</a> ';
		if ( MSRWA_Rights::may_delete() && 'running' !== (string) $batch['status'] ) {
			$out .= '<button class="button ms-danger" id="ms-batch-delete" data-batch="' . esc_attr( $batch['id'] ) . '">'
				. esc_html__( 'Supprimer le lot', 'ms-recipes-writer-ai' ) . '</button> ';
		}
		return $out . '<span id="ms-batch-head-status" class="ms-muted" aria-live="polite"></span>';
	}

	/**
	 * Launching, now or later. The pairing saves itself on every change, so
	 * there is no save button to forget; launching or scheduling saves it too.
	 */
	private static function actions( $settling, $recipe_count, $scheduled = '' ) {
		if ( ! $settling ) { return; }
		?>
		<div class="ms-launch">
			<span id="ms-batch-status" class="ms-muted" aria-live="polite"></span>
			<details class="ms-later"<?php echo '' !== $scheduled ? ' open' : ''; ?>>
				<summary class="button"><?php esc_html_e( 'Plus tard…', 'ms-recipes-writer-ai' ); ?></summary>
				<div class="ms-later-body">
					<label for="ms-dispatch-at"><?php esc_html_e( 'Partir le', 'ms-recipes-writer-ai' ); ?></label>
					<input type="datetime-local" id="ms-dispatch-at" value="<?php echo esc_attr( $scheduled ); ?>">
					<button type="button" class="button" id="ms-schedule"><?php esc_html_e( 'Programmer', 'ms-recipes-writer-ai' ); ?></button>
				</div>
			</details>
			<button type="button" class="button button-primary button-hero" id="ms-dispatch"><?php echo esc_html( sprintf(
				/* translators: %d is a number of recipes. */
				_n( 'Lancer %d recette', 'Lancer %d recettes', (int) $recipe_count, 'ms-recipes-writer-ai' ),
				(int) $recipe_count
			) ); ?></button>
		</div>
		<?php
	}

	private static function runs( array $runs ) {
		if ( ! $runs ) { return; }
		echo '<section class="ms-card ms-card-flush"><h2>' . esc_html__( 'Recettes', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<div class="ms-rail">';
		foreach ( $runs as $run ) {
			MSRWA_UI::ticket( $run, admin_url( 'admin.php?page=msrwa-run&run_id=' . (int) $run['id'] ) );
		}
		echo '</div>';
		echo '<p class="ms-muted" style="padding:14px 20px">' . esc_html__( 'Les recettes avancent ensemble, une vague par tick de cron. Vous pouvez fermer cet onglet.', 'ms-recipes-writer-ai' ) . '</p>';
		echo '</section>';
	}
}
