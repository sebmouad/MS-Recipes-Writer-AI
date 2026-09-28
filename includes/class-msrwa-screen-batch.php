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
		if ( ! $batch || ! MSRWA_Batch::may_see( $batch ) ) { MSRWA_UI::not_found( __( 'Lot introuvable.', 'ms-recipes-writer-ai' ), __( 'Il a peut-être été supprimé, ou il ne vous appartient pas.', 'ms-recipes-writer-ai' ), 'msrwa', __( 'Lots de recettes', 'ms-recipes-writer-ai' ) ); return; }

		$matching = MSRWA_Batch::matching( (int) $batch['id'] );
		$runs = MSRWA_Run::for_batch( (int) $batch['id'] );
		$settling = 'ready' === $batch['status'];
		$profile = MSRWA_Profile::get( $batch['profile'] );
		// The reader may see this lot, so they may see whose it is: their own,
		// or anyone's for somebody who sees everything.
		$owner = get_userdata( (int) $batch['owner_id'] );

		echo '<div class="wrap msrwa" data-batch="' . esc_attr( $batch['id'] ) . '">';
		MSRWA_UI::head(
			/* translators: %d is the lot's number. */
			sprintf( __( 'Lot %d', 'ms-recipes-writer-ai' ), (int) $batch['id'] ),
			MSRWA_Batch::title( $batch ),
			array_filter( array(
				__( 'recettes', 'ms-recipes-writer-ai' ) => number_format_i18n( $batch['recipes'] ),
				__( 'photographies', 'ms-recipes-writer-ai' ) => number_format_i18n( $batch['images'] ),
				__( 'langue', 'ms-recipes-writer-ai' ) => MSRWA_I18N::language_name( $batch['language'] ),
				MSRWA_Rights::may_see_money() ? __( 'plafond', 'ms-recipes-writer-ai' ) : '' => MSRWA_Rights::may_see_money() ? MSRWA_I18N::money( $batch['budget_usd'], 2 ) : '',
				// Its recipes and the reading of its photographs, together.
				MSRWA_Rights::may_see_money() ? __( 'dépensé', 'ms-recipes-writer-ai' ) : ' ' => MSRWA_Rights::may_see_money() ? MSRWA_I18N::money( MSRWA_Spend::for_batch( (int) $batch['id'] ) ) : '',
			) ),
			self::head_actions( $batch )
		);

		echo '<p class="ms-muted">' . esc_html( $profile['description'] ) . '</p>';

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

		self::summary( $batch, $runs, $owner );
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
		// A photograph the pairing does not mention is undecided, never quietly aside.
		foreach ( array_keys( $images ) as $index ) { $chosen[ $index ] = array( 'recipe' => null, 'confidence' => 'basse', 'why' => '', 'pending' => true ); }
		foreach ( (array) ( $matching['pairs'] ?? array() ) as $pair ) {
			if ( isset( $chosen[ (int) $pair['image'] ] ) ) { $chosen[ (int) $pair['image'] ] = $pair; }
		}

		echo '<section class="ms-card ms-card-flush ms-pairing"' . ( $settling ? ' data-settling="1"' : '' ) . '>';
		echo '<h2>' . esc_html__( 'Ce qui sera écrit', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html( $settling
			? __( 'Vérifiez à quelle recette va chaque photographie, puis lancez. Une recette illustrée par vos photographies est écrite d’après elles ; sans photographie, ses références sont cherchées sur le web.', 'ms-recipes-writer-ai' )
			: __( 'L’appariement de ce lot est arrêté : il est parti avec ce qui suit.', 'ms-recipes-writer-ai' ) ) . '</p>';

		// The brief the recipes were read from, beside them to check against.
		$brief = trim( (string) ( $matching['text'] ?? '' ) );
		if ( '' !== $brief ) {
			echo '<details class="ms-brief"><summary>' . esc_html__( 'Votre consigne', 'ms-recipes-writer-ai' ) . '</summary><div class="ms-brief-text">' . nl2br( esc_html( $brief ) ) . '</div></details>';
		}

		$named = array_filter( $recipes, static function ( $recipe ) { return ! empty( $recipe['from_photographs'] ); } );
		if ( $named ) {
			echo '<p class="ms-note ms-note-live">' . esc_html( sprintf(
				/* translators: %s lists the dish names read from the photographs. */
				__( 'Nommées d’après vos photographies, sans texte pour elles : %s. Chacune sera établie d’après ses photographies ; écartez une photographie pour ne pas en faire un article.', 'ms-recipes-writer-ai' ),
				implode( ', ', array_map( static function ( $recipe ) { return (string) $recipe['title']; }, $named ) )
			) ) . '</p>';
		}

		// The recipes first, as a table: they are what is being made. Each row
		// shows the photographs it will carry and what having them — or not —
		// changes, and before the lot leaves, the writer corrects, removes or
		// adds a recipe right there.
		echo '<div class="ms-table-scroll"><table class="ms-table ms-recipes-table" id="ms-dishes">';
		echo '<thead><tr><th class="ms-num" scope="col">#</th><th scope="col">' . esc_html__( 'Recette', 'ms-recipes-writer-ai' ) . '</th><th scope="col">' . esc_html__( 'Photographies', 'ms-recipes-writer-ai' ) . '</th><th scope="col">' . esc_html__( 'Écrite d’après', 'ms-recipes-writer-ai' ) . '</th>'
			. ( $settling ? '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'ms-recipes-writer-ai' ) . '</span></th>' : '' ) . '</tr></thead><tbody>';
		foreach ( $recipes as $recipe_index => $recipe ) {
			$mine = array_keys( array_filter( $chosen, static function ( $pair ) use ( $recipe_index ) { return null !== $pair['recipe'] && (int) $pair['recipe'] === (int) $recipe_index; } ) );
			// A recipe named after photographs has nothing to be written from
			// once they are all set aside, and is not sent.
			$from_photographs = ! empty( $recipe['from_photographs'] );
			$dropped = ! $mine && $from_photographs;
			if ( $dropped ) { $recipe_count--; }
			echo '<tr class="ms-dish' . ( $mine ? ' has-photos' : '' ) . ( $dropped ? ' is-dropped' : '' ) . '" data-recipe="' . esc_attr( $recipe_index ) . '"' . ( $from_photographs ? ' data-from-photographs="1"' : '' ) . '>';
			echo '<td class="ms-num">' . esc_html( number_format_i18n( $recipe_index + 1 ) ) . '</td>';
			echo '<td class="ms-dish-main"><strong class="ms-dish-title">' . esc_html( (string) $recipe['title'] ) . '</strong>';
			if ( $from_photographs ) { echo ' <span class="ms-state ms-state-idle ms-dish-origin">' . esc_html__( 'd’après vos photographies', 'ms-recipes-writer-ai' ) . '</span>'; }
			echo '<small class="ms-dish-excerpt">' . esc_html( self::excerpt( $recipe ) ) . '</small></td>';
			echo '<td><span class="ms-dish-thumbs">';
			foreach ( $mine as $image_index ) {
				if ( ! empty( $images[ $image_index ]['url'] ) ) { echo '<img src="' . esc_url( $images[ $image_index ]['url'] ) . '" alt="" loading="lazy">'; }
			}
			echo '</span></td>';
			echo '<td><small class="ms-dish-with">' . esc_html( $mine
				? sprintf( /* translators: %d is a number of photographs. */ _n( '%d photographie — écrite d’après elle', '%d photographies — écrite d’après elles', count( $mine ), 'ms-recipes-writer-ai' ), count( $mine ) )
				: ( $dropped ? __( 'Ne sera pas écrite : sa photographie est écartée', 'ms-recipes-writer-ai' ) : __( 'Sans photographie — références cherchées sur le web', 'ms-recipes-writer-ai' ) ) ) . '</small></td>';
			if ( $settling ) {
				echo '<td class="ms-dish-actions"><button type="button" class="button button-small ms-recipe-edit" data-recipe="' . esc_attr( $recipe_index ) . '" aria-controls="ms-recipe-editor-' . esc_attr( $recipe_index ) . '" aria-expanded="false">' . esc_html__( 'Modifier', 'ms-recipes-writer-ai' ) . '</button>'
					. ' <button type="button" class="button button-small ms-recipe-remove" data-recipe="' . esc_attr( $recipe_index ) . '">' . esc_html__( 'Retirer', 'ms-recipes-writer-ai' ) . '</button></td>';
			}
			echo '</tr>';
			if ( $settling ) {
				echo '<tr class="ms-recipe-editor-row" id="ms-recipe-editor-' . esc_attr( $recipe_index ) . '" hidden><td colspan="5">';
				self::editor( (string) $recipe_index, (string) $recipe['title'], $from_photographs ? '' : (string) $recipe['text'] );
				echo '</td></tr>';
			}
		}
		echo '</tbody></table></div>';
		if ( $settling ) {
			echo '<div class="ms-recipes-add"><button type="button" class="button" id="ms-recipe-add" aria-controls="ms-recipe-editor-new" aria-expanded="false"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ' . esc_html__( 'Ajouter une recette', 'ms-recipes-writer-ai' ) . '</button>';
			echo '<div id="ms-recipe-editor-new" hidden>';
			self::editor( 'new', '', '' );
			echo '</div></div>';
		}

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
		// A photograph no recipe took waits for the writer: none is dropped
		// without them, and the lot does not leave until each is decided.
		$loose = count( array_filter( $chosen, static function ( $pair ) { return ! empty( $pair['pending'] ); } ) );
		echo '<p class="ms-note ms-note-warn ms-loose" id="ms-loose"' . ( $loose ? '' : ' hidden' )
			/* translators: %d is a number of photographs. */
			. ' data-one="' . esc_attr( _n( 'Une photographie attend votre décision : associez-la à une recette, faites-en une recette ou écartez-la.', '%d photographies attendent votre décision : associez-les à une recette, faites-en des recettes ou écartez-les.', 1, 'ms-recipes-writer-ai' ) ) . '"'
			/* translators: %d is a number of photographs. */
			. ' data-many="' . esc_attr( _n( 'Une photographie attend votre décision : associez-la à une recette, faites-en une recette ou écartez-la.', '%d photographies attendent votre décision : associez-les à une recette, faites-en des recettes ou écartez-les.', 2, 'ms-recipes-writer-ai' ) ) . '">'
			/* translators: %d is a number of photographs. */
			. esc_html( sprintf( _n( 'Une photographie attend votre décision : associez-la à une recette, faites-en une recette ou écartez-la.', '%d photographies attendent votre décision : associez-les à une recette, faites-en des recettes ou écartez-les.', max( 1, $loose ), 'ms-recipes-writer-ai' ), $loose ) ) . '</p>';

		echo '<div class="ms-table-scroll"><table class="ms-table ms-pairs">';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Photographie', 'ms-recipes-writer-ai' ) . '</th><th scope="col">' . esc_html__( 'Ce qu’elle montre', 'ms-recipes-writer-ai' ) . '</th><th scope="col">' . esc_html__( 'Va avec', 'ms-recipes-writer-ai' ) . '</th></tr></thead><tbody>';
		foreach ( $images as $index => $image ) {
			$pair = $chosen[ $index ];
			// A photograph set aside is not a match, however sure the model was;
			// one nobody has decided on is the thing to look at.
			$pending = ! empty( $pair['pending'] );
			$aside = null === $pair['recipe'] && ! $pending;
			$mine = ! empty( $pair['by_writer'] );
			$tone = $pending ? 'stop' : ( $aside ? 'idle' : ( $mine ? 'good' : ( array( 'haute' => 'good', 'moyenne' => 'warn' )[ (string) $pair['confidence'] ] ?? 'stop' ) ) );
			$dish = (string) ( $image['dish'] ?? '' );
			$dish = '' !== $dish ? mb_strtoupper( mb_substr( $dish, 0, 1 ) ) . mb_substr( $dish, 1 ) : __( 'Plat non reconnu', 'ms-recipes-writer-ai' );
			$label = sprintf(
				/* translators: %s is a file name. */
				__( 'Recette pour la photographie %s', 'ms-recipes-writer-ai' ),
				MSRWA_Sources::label( $image )
			);
			$current = null === $pair['recipe'] ? '' : (string) ( $recipes[ (int) $pair['recipe'] ]['title'] ?? '' );
			?>
			<?php
			// A photograph set aside is deleted once the lot is sent: no recipe
			// took it. Its thumbnail would be a broken image from then on.
			$shown = ! empty( $image['url'] ) && ( $settling || ! $aside );
			?>
			<tr class="ms-pair ms-pair-<?php echo esc_attr( $tone ); ?><?php echo $shown ? '' : ' ms-pair-blind'; ?>">
				<td class="ms-pair-thumb">
				<?php if ( $shown ) : ?>
					<a class="ms-pair-photo" href="<?php echo esc_url( $image['url'] ); ?>" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e( 'Voir en grand', 'ms-recipes-writer-ai' ); ?>"><img src="<?php echo esc_url( $image['url'] ); ?>" alt="<?php echo esc_attr( (string) ( $image['dish'] ?? '' ) ); ?>" loading="lazy"></a>
				<?php endif; ?>
				</td>
				<td class="ms-pair-body">
					<p class="ms-pair-dish"><?php echo esc_html( $dish ); ?></p>
					<p class="ms-pair-says"><?php echo esc_html( (string) ( $image['describes'] ?? __( 'Non décrite.', 'ms-recipes-writer-ai' ) ) ); ?></p>
					<p class="ms-pair-why"><span class="ms-state ms-state-<?php echo esc_attr( $tone ); ?> ms-pair-badge"><?php echo esc_html( $pending ? __( 'À décider', 'ms-recipes-writer-ai' ) : ( $aside ? __( 'Mise de côté', 'ms-recipes-writer-ai' ) : ( ! empty( $pair['new_recipe'] ) && ! $mine ? __( 'Nouvelle recette', 'ms-recipes-writer-ai' ) : ( $mine ? __( 'Choisie par vous', 'ms-recipes-writer-ai' ) : self::confidence( (string) $pair['confidence'] ) ) ) ) ); ?></span>
						<?php $why = self::reason( $pair ); ?>
						<?php if ( '' !== $why ) : ?><span class="ms-pair-reason"><?php echo esc_html( $why ); ?></span><?php endif; ?></p>
					<p class="ms-pair-file"><?php echo esc_html( MSRWA_Sources::label( $image ) ); ?></p>
					<?php if ( ! empty( $image['collage'] ) ) : ?>
						<?php
						// A collage the writer made becomes the recipe's Facebook image and its
						// reference; unticked, it is an ordinary photograph and a collage is drawn.
						$kept = empty( $image['collage_off'] );
						?>
						<?php if ( $settling ) : ?>
							<label class="ms-pair-collage">
								<input type="checkbox" class="ms-pair-collage-use" data-image="<?php echo esc_attr( $index ); ?>" <?php checked( $kept ); ?>>
								<span><strong><?php esc_html_e( 'Votre collage Facebook', 'ms-recipes-writer-ai' ); ?></strong>
								<small><?php esc_html_e( 'Publié tel quel avec l’article, et référence de la recette, de l’article et de l’image à la une. Décoché, il sert de simple photographie et un nouveau collage est dessiné.', 'ms-recipes-writer-ai' ); ?></small></span>
							</label>
						<?php elseif ( $kept ) : ?>
							<p class="ms-pair-collage"><span class="dashicons dashicons-images-alt2" aria-hidden="true"></span> <strong><?php esc_html_e( 'Votre collage Facebook', 'ms-recipes-writer-ai' ); ?></strong></p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
				<td class="ms-pair-pick">
					<?php if ( $settling ) : ?>
						<label for="ms-pair-<?php echo esc_attr( $index ); ?>"><span class="screen-reader-text"><?php echo esc_html( $label ); ?></span><span aria-hidden="true"><?php esc_html_e( 'Va avec', 'ms-recipes-writer-ai' ); ?></span></label>
						<select id="ms-pair-<?php echo esc_attr( $index ); ?>" class="ms-pair-choice" data-image="<?php echo esc_attr( $index ); ?>" data-url="<?php echo esc_url( (string) ( $image['url'] ?? '' ) ); ?>">
							<?php if ( $pending ) : ?><option value="" selected><?php esc_html_e( '— à décider —', 'ms-recipes-writer-ai' ); ?></option><?php endif; ?>
							<?php foreach ( $recipes as $recipe_index => $recipe ) : ?>
								<option value="<?php echo esc_attr( $recipe_index ); ?>" <?php selected( null !== $pair['recipe'] && (int) $recipe_index === (int) $pair['recipe'] ); ?>><?php echo esc_html( $recipe['title'] ); ?></option>
							<?php endforeach; ?>
							<option value="new"><?php esc_html_e( 'Nouvelle recette…', 'ms-recipes-writer-ai' ); ?></option>
							<option value="aside" <?php selected( $aside ); ?>><?php esc_html_e( 'Écarter cette photographie', 'ms-recipes-writer-ai' ); ?></option>
						</select>
						<span class="ms-pair-new" hidden>
							<input type="text" class="ms-pair-title" maxlength="180" placeholder="<?php esc_attr_e( 'Nom de la recette', 'ms-recipes-writer-ai' ); ?>" value="<?php echo esc_attr( ucfirst( (string) ( $image['dish'] ?? '' ) ) ); ?>" aria-label="<?php esc_attr_e( 'Nom de la recette', 'ms-recipes-writer-ai' ); ?>">
							<button type="button" class="button ms-pair-create"><?php esc_html_e( 'Créer', 'ms-recipes-writer-ai' ); ?></button>
						</span>
					<?php else : ?>
						<span class="ms-pair-fixed"><?php echo esc_html( '' !== $current ? $current : __( 'écartée', 'ms-recipes-writer-ai' ) ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		}
		echo '</tbody></table></div>';

		self::actions( $settling, $recipe_count, $scheduled );
		echo '</section>';
	}

	/**
	 * A glimpse of what the writer gave for a recipe, under its title: the
	 * lines after the dish's name, or that the name alone is enough.
	 */
	private static function excerpt( array $recipe ) {
		if ( ! empty( $recipe['from_photographs'] ) ) { return __( 'Aucun texte : établie d’après les photographies et les sources.', 'ms-recipes-writer-ai' ); }
		// What the brief asks of every recipe is shown once, above the table.
		$text = preg_replace( '/\n\nConsignes du rédacteur pour tout le lot : .*$/su', '', (string) ( $recipe['text'] ?? '' ) );
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ), 'strlen' ) );
		if ( $lines && trim( (string) $recipe['title'] ) === MSRWA_Intake::title_of( $lines[0] ) ) { array_shift( $lines ); }
		if ( ! $lines ) { return __( 'Le nom du plat seul : le reste est établi d’après les sources.', 'ms-recipes-writer-ai' ); }
		$glimpse = implode( ' · ', array_slice( $lines, 0, 3 ) );
		return mb_strlen( $glimpse ) > 160 ? mb_substr( $glimpse, 0, 159 ) . '…' : $glimpse;
	}

	/** The form that corrects a recipe, or names a new one. */
	private static function editor( $key, $title, $text ) {
		?>
		<div class="ms-recipe-editor" data-recipe="<?php echo esc_attr( $key ); ?>">
			<label><span><?php esc_html_e( 'Nom de la recette', 'ms-recipes-writer-ai' ); ?></span>
				<input type="text" class="regular-text ms-edit-title" maxlength="180" value="<?php echo esc_attr( $title ); ?>"></label>
			<label><span><?php esc_html_e( 'Texte', 'ms-recipes-writer-ai' ); ?> <small class="ms-muted"><?php esc_html_e( 'facultatif : ingrédients, étapes, précisions', 'ms-recipes-writer-ai' ); ?></small></span>
				<textarea class="large-text ms-edit-text" rows="6"><?php echo esc_textarea( $text ); ?></textarea></label>
			<p class="ms-recipe-editor-actions">
				<button type="button" class="button button-primary ms-edit-save"><?php echo esc_html( 'new' === $key ? __( 'Ajouter', 'ms-recipes-writer-ai' ) : __( 'Enregistrer', 'ms-recipes-writer-ai' ) ); ?></button>
				<button type="button" class="button-link ms-edit-cancel"><?php esc_html_e( 'Annuler', 'ms-recipes-writer-ai' ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * Why a photograph is where it is. The plugin's own reasons are codes said
	 * in the reader's language; a model's reason is shown as it wrote it.
	 */
	private static function reason( array $pair ) {
		$own = array(
			'only_recipe' => __( 'Seule recette du lot.', 'ms-recipes-writer-ai' ),
			'new_recipe' => __( 'Plat absent du texte : une recette de plus, d’après la photographie.', 'ms-recipes-writer-ai' ),
			'no_dish' => __( 'Aucun plat reconnu sur la photographie : associez-la, faites-en une recette ou écartez-la.', 'ms-recipes-writer-ai' ),
			'not_mentioned' => __( 'Non mentionnée par l’appariement.', 'ms-recipes-writer-ai' ),
			'excluded' => __( 'Écartée selon votre consigne.', 'ms-recipes-writer-ai' ),
		);
		if ( isset( $own[ (string) ( $pair['reason'] ?? '' ) ] ) ) { return $own[ $pair['reason'] ]; }
		$why = (string) ( $pair['why'] ?? '' );
		// Lots paired before the codes existed carry the same sentences in French.
		foreach ( array( 'Seule recette du lot.' => 'only_recipe', 'Non mentionnée par l’appariement.' => 'not_mentioned' ) as $sentence => $code ) {
			if ( $sentence === $why ) { return $own[ $code ]; }
		}
		if ( 0 === strpos( $why, 'Aucun plat reconnu sur la photographie' ) ) { return $own['no_dish']; }
		return 'Confirmé par le rédacteur.' === $why ? '' : $why;
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
		$out = '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=msrwa' ) ) . '">' . esc_html__( 'Retour aux lots', 'ms-recipes-writer-ai' ) . '</a> ';
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
			<?php
			// The count follows the pairing on screen: a recipe whose photographs
			// were all set aside is not launched, and is not counted.
			?>
			<button type="button" class="button button-primary button-hero" id="ms-dispatch" data-one="<?php echo esc_attr( sprintf( _n( 'Lancer %d recette', 'Lancer %d recettes', 1, 'ms-recipes-writer-ai' ), 1 ) ); ?>" data-many="<?php echo esc_attr( _n( 'Lancer %d recette', 'Lancer %d recettes', 2, 'ms-recipes-writer-ai' ) ); ?>"><?php echo esc_html( sprintf(
				/* translators: %d is a number of recipes. */
				_n( 'Lancer %d recette', 'Lancer %d recettes', (int) $recipe_count, 'ms-recipes-writer-ai' ),
				(int) $recipe_count
			) ); ?></button>
		</div>
		<?php
	}

	/**
	 * Where the lot stands, at a glance: whose it is, when it was made, what
	 * it produces, when it leaves, and how far its recipes are. The header
	 * above keeps the counts, the language and, for an operator, the money. The
	 * counts carry data-lot hooks that admin.js keeps current while it runs.
	 */
	private static function summary( array $batch, array $runs, $owner ) {
		$tally = array( 'done' => 0, 'running' => 0, 'queued' => 0, 'failed' => 0, 'cancelled' => 0 );
		foreach ( $runs as $run ) {
			$status = (string) $run['status'];
			if ( isset( $tally[ $status ] ) ) { $tally[ $status ]++; }
		}
		$total = count( $runs );
		$settled = $tally['done'] + $tally['failed'] + $tally['cancelled'];

		if ( 'matching' === $batch['status'] ) {
			$state = array( 'live', __( 'lecture des photographies', 'ms-recipes-writer-ai' ) );
		} elseif ( 'ready' === $batch['status'] ) {
			$state = empty( $batch['dispatch_at'] )
				? array( 'warn', __( 'attend votre confirmation', 'ms-recipes-writer-ai' ) )
				: array( 'live', __( 'programmé', 'ms-recipes-writer-ai' ) );
		} elseif ( $total && $settled < $total ) {
			$state = array( 'live', __( 'en cours', 'ms-recipes-writer-ai' ) );
		} elseif ( $total && $tally['failed'] ) {
			$state = array( 'warn', __( 'terminé, avec des échecs', 'ms-recipes-writer-ai' ) );
		} elseif ( $total ) {
			$state = array( 'good', __( 'terminé', 'ms-recipes-writer-ai' ) );
		} else {
			$state = array( 'stop', __( 'arrêté', 'ms-recipes-writer-ai' ) );
		}

		$labels = array(
			'done' => __( 'terminées', 'ms-recipes-writer-ai' ),
			'running' => __( 'en cours', 'ms-recipes-writer-ai' ),
			'queued' => __( 'en attente', 'ms-recipes-writer-ai' ),
			'failed' => __( 'échecs', 'ms-recipes-writer-ai' ),
			'cancelled' => __( 'arrêtées', 'ms-recipes-writer-ai' ),
		);
		$profile = MSRWA_Profile::get( (string) $batch['profile'] );
		?>
		<section class="ms-card ms-lot-summary" data-lot-total="<?php echo esc_attr( $total ); ?>">
			<dl class="ms-lot-facts">
				<div><dt><?php esc_html_e( 'Rédacteur', 'ms-recipes-writer-ai' ); ?></dt><dd><?php echo $owner ? get_avatar( $owner->ID, 24, '', '', array( 'class' => 'ms-lot-avatar' ) ) . esc_html( $owner->display_name ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar escapes. ?></dd></div>
				<div><dt><?php esc_html_e( 'Créé', 'ms-recipes-writer-ai' ); ?></dt><dd><time datetime="<?php echo esc_attr( gmdate( 'c', (int) strtotime( $batch['created_at'] . ' UTC' ) ) ); ?>"><?php echo esc_html( MSRWA_I18N::when( (string) $batch['created_at'] ) ); ?></time></dd></div>
				<div><dt><?php esc_html_e( 'Formule', 'ms-recipes-writer-ai' ); ?></dt><dd><?php echo esc_html( (string) $profile['label'] ); ?></dd></div>
				<?php if ( ! empty( $batch['dispatch_at'] ) ) : ?>
					<div><dt><?php esc_html_e( 'Départ prévu', 'ms-recipes-writer-ai' ); ?></dt><dd><?php echo esc_html( MSRWA_I18N::when( (string) $batch['dispatch_at'] ) ); ?></dd></div>
				<?php endif; ?>
				<div><dt><?php esc_html_e( 'État', 'ms-recipes-writer-ai' ); ?></dt><dd><span class="ms-state ms-state-<?php echo esc_attr( $state[0] ); ?>" data-lot="state"><?php echo esc_html( $state[1] ); ?></span></dd></div>
			</dl>
			<?php if ( $total ) : ?>
				<div class="ms-lot-progress">
					<div class="ms-lot-progress-head">
						<strong><?php esc_html_e( 'Avancement', 'ms-recipes-writer-ai' ); ?></strong>
						<span class="ms-muted"><span data-lot="settled"><?php echo esc_html( number_format_i18n( $settled ) ); ?></span>/<?php echo esc_html( number_format_i18n( $total ) ); ?></span>
					</div>
					<span class="ms-progress<?php echo $settled >= $total ? ' ms-progress-done' : ''; ?>" data-lot="bar"><i style="inline-size:<?php echo (int) round( 100 * $settled / $total ); ?>%"></i></span>
					<ul class="ms-lot-tally">
						<?php foreach ( $labels as $key => $label ) : ?>
							<li class="ms-lot-tally-<?php echo esc_attr( $key ); ?>"<?php echo $tally[ $key ] ? '' : ' hidden'; ?>><strong data-lot="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( number_format_i18n( $tally[ $key ] ) ); ?></strong> <?php echo esc_html( $label ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function runs( array $runs ) {
		if ( ! $runs ) { return; }
		echo '<section class="ms-card ms-card-flush"><h2>' . esc_html__( 'Recettes', 'ms-recipes-writer-ai' ) . '</h2>';
		MSRWA_UI::run_table( $runs );
		echo '<p class="ms-muted" style="padding:14px 20px">' . esc_html__( 'Les recettes avancent en parallèle, trois à la fois, sans attendre une visite du site. Vous pouvez fermer cet onglet ; laissé ouvert, il relance aussi une recette que le cron du site aurait oubliée.', 'ms-recipes-writer-ai' ) . '</p>';
		echo '</section>';
	}
}
