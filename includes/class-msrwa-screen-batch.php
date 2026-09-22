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
			'<a class="button" href="' . esc_url( admin_url( 'admin.php?page=msrwa' ) ) . '">' . esc_html__( 'Retour au pass', 'ms-recipes-writer-ai' ) . '</a>'
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
		$recipes = (array) ( $matching['recipes'] ?? array() );

		echo '<section class="ms-card ms-card-flush"><h2>' . esc_html__( 'Appariement', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Chaque photographie a été décrite depuis ses propres pixels, puis rapprochée d’une recette. Dans le doute, le modèle n’associe pas : une photographie laissée de côté coûte moins cher qu’une photographie attribuée au mauvais plat.', 'ms-recipes-writer-ai' ) . '</p>';

		if ( ! $images ) {
			MSRWA_UI::nothing(
				__( 'Aucune photographie', 'ms-recipes-writer-ai' ),
				__( 'Les recettes seront générées sans photographie fournie : le moteur cherchera ses propres références.', 'ms-recipes-writer-ai' )
			);
			self::actions( $settling, $recipe_count, $scheduled );
			echo '</section>';
			return;
		}

		foreach ( $images as $index => $image ) {
			$pair = array( 'recipe' => null, 'confidence' => 'basse', 'why' => '' );
			foreach ( (array) ( $matching['pairs'] ?? array() ) as $candidate ) {
				if ( (int) $candidate['image'] === (int) $index ) { $pair = $candidate; }
			}
			$label = sprintf(
				/* translators: %s is a file name. */
				__( 'Recette pour la photographie %s', 'ms-recipes-writer-ai' ),
				(string) $image['file']
			);
			?>
			<div class="ms-pair<?php echo empty( $image['url'] ) ? ' ms-pair-blind' : ''; ?>">
				<?php if ( ! empty( $image['url'] ) ) : ?>
					<div><img src="<?php echo esc_url( $image['url'] ); ?>" alt="<?php echo esc_attr( (string) ( $image['dish'] ?? '' ) ); ?>" loading="lazy"></div>
				<?php endif; ?>
				<div>
					<p class="ms-pair-says">
						<?php if ( '' !== (string) ( $image['dish'] ?? '' ) ) : ?><strong><?php echo esc_html( $image['dish'] ); ?></strong> — <?php endif; ?>
						<?php echo esc_html( (string) ( $image['describes'] ?? __( 'Non décrite.', 'ms-recipes-writer-ai' ) ) ); ?>
					</p>
					<p class="ms-pair-why">
						<?php echo esc_html( self::confidence( (string) $pair['confidence'] ) ); ?>
						<?php if ( '' !== (string) $pair['why'] ) : ?> — <?php echo esc_html( $pair['why'] ); ?><?php endif; ?>
					</p>
					<p class="ms-pair-file"><?php echo esc_html( $image['file'] ); ?></p>
				</div>
				<div>
					<label class="screen-reader-text" for="ms-pair-<?php echo esc_attr( $index ); ?>"><?php echo esc_html( $label ); ?></label>
					<select id="ms-pair-<?php echo esc_attr( $index ); ?>" class="ms-pair-choice" data-image="<?php echo esc_attr( $index ); ?>" <?php disabled( ! $settling ); ?>>
						<option value=""><?php esc_html_e( '— aucune —', 'ms-recipes-writer-ai' ); ?></option>
						<?php foreach ( $recipes as $recipe_index => $recipe ) : ?>
							<option value="<?php echo esc_attr( $recipe_index ); ?>" <?php selected( (int) $recipe_index, (int) $pair['recipe'] ); ?>><?php echo esc_html( $recipe['title'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<?php
		}

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

	private static function actions( $settling, $recipe_count, $scheduled = '' ) {
		if ( ! $settling ) { return; }
		echo '<div class="ms-filters" style="justify-content:space-between">';
		echo '<div><button class="button" id="ms-save-pairs">' . esc_html__( 'Enregistrer l’appariement', 'ms-recipes-writer-ai' ) . '</button> '
			. '<span id="ms-batch-status" class="ms-muted" aria-live="polite"></span></div>';
		echo '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">';
		echo '<div><label for="ms-dispatch-at">' . esc_html__( 'Plus tard', 'ms-recipes-writer-ai' ) . '</label>'
			. '<input type="datetime-local" id="ms-dispatch-at" value="' . esc_attr( $scheduled ) . '"></div>';
		echo '<button class="button" id="ms-schedule">' . esc_html__( 'Programmer', 'ms-recipes-writer-ai' ) . '</button>';
		echo '<button class="button button-primary" id="ms-dispatch">'
			. esc_html( sprintf(
				/* translators: %d is a number of recipes. */
				_n( 'Lancer %d recette', 'Lancer %d recettes', (int) $recipe_count, 'ms-recipes-writer-ai' ),
				(int) $recipe_count
			) ) . '</button>';
		echo '</div>';
		echo '</div>';
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
