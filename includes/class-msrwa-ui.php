<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The pieces every screen is built from.
 *
 * Kept in one place so a state means the same thing on every screen and is
 * worded the same way. The rule that shapes most of this: a finished run is
 * work waiting for an editor, never work that has been approved. The engine's
 * judge has an opinion about its own output; it has no opinion about whether
 * this site wants to publish it, and no screen here is allowed to suggest
 * otherwise.
 */
final class MSRWA_UI {

	/** The page head: what this is, one line about it, the figures, the actions. */
	public static function head( $title, $lead = '', array $figures = array(), $actions = '' ) {
		echo '<div class="ms-head"><div><h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== $lead ) { echo '<p>' . esc_html( $lead ) . '</p>'; }
		echo '</div>';
		if ( $figures ) {
			echo '<div class="ms-head-figures">';
			foreach ( $figures as $label => $value ) {
				echo '<div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
			}
			echo '</div>';
		}
		if ( '' !== $actions ) { echo '<div class="ms-head-actions">' . wp_kses_post( $actions ) . '</div>'; }
		echo '</div>';
	}

	/**
	 * A table too wide for the screen, made reachable rather than crushed.
	 *
	 * A region that scrolls with the mouse and not with the keyboard is a table
	 * some readers simply cannot see the end of, so it is focusable and named.
	 */
	/**
	 * A level as both model screens name it. `low`, `medium` and `high` are
	 * also thinking efforts and image qualities; a level picks a model.
	 */
	public static function tier_name( $tier ) {
		$names = array(
			'low' => __( 'économique', 'ms-recipes-writer-ai' ),
			'medium' => __( 'standard', 'ms-recipes-writer-ai' ),
			'high' => __( 'avancé', 'ms-recipes-writer-ai' ),
		);
		return $names[ (string) $tier ] ?? (string) $tier;
	}

	public static function provider_name( $provider ) {
		$names = array( 'openai' => 'OpenAI', 'gemini' => 'Google Gemini', 'claude' => 'Anthropic Claude' );
		return $names[ (string) $provider ] ?? (string) $provider;
	}

	public static function scroll( $label ) {
		return '<div class="ms-scroll" tabindex="0" role="region" aria-label="' . esc_attr( $label ) . '">';
	}

	public static function figures( array $figures ) {
		echo '<dl class="ms-figures">';
		foreach ( $figures as $entry ) {
			// A tone colours the figure the way the states are coloured; an image
			// shows what the figure is about, above what was said of it.
			$tone = in_array( $entry['tone'] ?? '', array( 'good', 'warn', 'stop', 'live', 'lamp' ), true ) ? $entry['tone'] : '';
			echo '<div class="ms-figure' . ( '' !== $tone ? ' ms-figure-' . esc_attr( $tone ) : '' ) . '"><dt>' . esc_html( $entry['label'] ) . '</dt>';
			if ( ! empty( $entry['image'] ) ) { echo '<div class="ms-figure-image">' . wp_get_attachment_image( (int) $entry['image'], 'medium' ) . '</div>'; }
			echo '<dd>' . esc_html( $entry['value'] );
			if ( ! empty( $entry['note'] ) ) { echo '<small>' . esc_html( $entry['note'] ) . '</small>'; }
			echo '</dd></div>';
		}
		echo '</dl>';
	}

	/** The judge's word for an image, in the reader's language. */
	public static function verdict_word( $verdict ) {
		$words = array(
			'good' => __( 'bon', 'ms-recipes-writer-ai' ),
			'reservations' => __( 'réserves', 'ms-recipes-writer-ai' ),
			'bad' => __( 'mauvais', 'ms-recipes-writer-ai' ),
		);
		return $words[ $verdict ] ?? $verdict;
	}

	/** How a verdict is coloured, the way the states are. */
	public static function verdict_tone( $verdict ) { return array( 'good' => 'good', 'reservations' => 'warn', 'bad' => 'stop' )[ $verdict ] ?? ''; }

	/**
	 * What a run's state is called, and how it reads.
	 *
	 * `done` is deliberately not "terminé" on its own: a finished run is either
	 * waiting for an editor to read it or waiting for someone to fix what the
	 * judge objected to. Neither is a validation, and saying so plainly is the
	 * difference between a tool an editor trusts and one that flatters itself.
	 */
	public static function state_of( array $run ) {
		$status = (string) ( $run['status'] ?? '' );
		if ( 'queued' === $status ) { return array( 'tone' => '', 'label' => __( 'en attente', 'ms-recipes-writer-ai' ) ); }
		if ( 'running' === $status ) { return array( 'tone' => 'live', 'label' => __( 'en cours', 'ms-recipes-writer-ai' ) ); }
		if ( 'cancelled' === $status ) { return array( 'tone' => '', 'label' => __( 'arrêté', 'ms-recipes-writer-ai' ) ); }
		if ( 'failed' === $status ) { return array( 'tone' => 'stop', 'label' => __( 'échec', 'ms-recipes-writer-ai' ) ); }

		// Once the post has left the drafts, what WordPress did with it is the
		// state: "to review" and "to fix" were for the editor, who has decided.
		$post_id = (int) ( $run['draft_post_id'] ?? 0 );
		if ( $post_id ) {
			$post_status = array_key_exists( 'post_status', $run ) ? $run['post_status'] : get_post_status( $post_id );
			if ( in_array( $post_status, array( 'publish', 'private' ), true ) ) { return array( 'tone' => 'good', 'label' => __( 'publié', 'ms-recipes-writer-ai' ) ); }
			if ( 'future' === $post_status ) { return array( 'tone' => 'live', 'label' => __( 'programmé', 'ms-recipes-writer-ai' ) ); }
			if ( 'trash' === $post_status ) { return array( 'tone' => '', 'label' => __( 'à la corbeille', 'ms-recipes-writer-ai' ) ); }
			if ( null === $post_status || false === $post_status ) { return array( 'tone' => '', 'label' => __( 'supprimé', 'ms-recipes-writer-ai' ) ); }
		}

		$approved = $run['approved'] ?? null;
		// A refusal carries its findings, and the editor has to act on them:
		// that is what the label says, rather than how the check felt.
		if ( null !== $approved && ! $approved ) { return array( 'tone' => 'warn', 'label' => __( 'à corriger', 'ms-recipes-writer-ai' ) ); }
		return array( 'tone' => 'good', 'label' => __( 'à relire', 'ms-recipes-writer-ai' ) );
	}

	public static function state( array $run ) {
		$state = self::state_of( $run );
		return '<span class="ms-state' . ( $state['tone'] ? ' ms-state-' . $state['tone'] : '' ) . '">' . esc_html( $state['label'] ) . '</span>';
	}

	/** What each step is called for somebody who does not read the engine's names. */
	public static function step_name( $step ) {
		$names = array(
			'research' => __( 'Recherche', 'ms-recipes-writer-ai' ),
			'canonical_recipe' => __( 'Recette de référence', 'ms-recipes-writer-ai' ),
			'article' => __( 'Rédaction', 'ms-recipes-writer-ai' ),
			'featured_image' => __( 'Image à la une', 'ms-recipes-writer-ai' ),
			'facebook_image' => __( 'Collage Facebook', 'ms-recipes-writer-ai' ),
			'collage_reading' => __( 'Lecture du collage', 'ms-recipes-writer-ai' ),
			'review' => __( 'Relecture, faits et langue', 'ms-recipes-writer-ai' ),
			'corrections' => __( 'Corrections', 'ms-recipes-writer-ai' ),
			'proofread' => __( 'Correction de la langue', 'ms-recipes-writer-ai' ),
			'final_approval' => __( 'Contrôle final', 'ms-recipes-writer-ai' ),
			'vision' => __( 'Lecture des photographies', 'ms-recipes-writer-ai' ),
			'image_compose' => __( 'Consigne du collage', 'ms-recipes-writer-ai' ),
		);
		return $names[ (string) $step ] ?? (string) $step;
	}

	/**
	 * One sentence that says where a recipe stands and what, if anything, the
	 * reader should do — in words, never in the engine's vocabulary.
	 *
	 * An editor used to meet "No API key for claude." or a raw HTTP status. Those
	 * stay on the diagnostics for whoever can act on them; everybody gets the
	 * sentence. Returns array( tone, text ).
	 */
	public static function reason( array $run, array $steps = array() ) {
		$status = (string) ( $run['status'] ?? '' );
		if ( 'queued' === $status ) {
			return class_exists( 'MSRWA_Queue' ) && MSRWA_Queue::held()
				? array( 'warn', __( 'En attente : la file est suspendue par un administrateur. Elle repartira d’elle-même quand la file reprendra.', 'ms-recipes-writer-ai' ) )
				: array( '', __( 'En attente de son tour. Elle partira d’elle-même ; rien à faire.', 'ms-recipes-writer-ai' ) );
		}
		if ( 'running' === $status ) {
			/* translators: 1: steps finished, 2: steps in total. */
			return array( 'live', sprintf( __( 'En cours d’écriture : %1$d étape(s) sur %2$d. Vous pouvez quitter cette page, le travail continue.', 'ms-recipes-writer-ai' ), (int) ( $run['steps_done'] ?? 0 ), (int) ( $run['steps_total'] ?? 0 ) ) );
		}
		if ( 'cancelled' === $status ) { return array( '', __( 'Arrêtée à la demande. Elle peut être reprise là où elle s’était arrêtée.', 'ms-recipes-writer-ai' ) ); }
		if ( 'done' === $status ) {
			$post_id = (int) ( $run['draft_post_id'] ?? 0 );
			if ( ! $post_id ) { return array( 'warn', __( 'Le travail est terminé, mais aucun brouillon n’a pu être créé. Un administrateur doit regarder le détail.', 'ms-recipes-writer-ai' ) ); }
			// Once the post has left the drafts the decision is made: the lot
			// kept asking for a review of articles already published.
			$post_status = array_key_exists( 'post_status', $run ) ? $run['post_status'] : get_post_status( $post_id );
			if ( in_array( $post_status, array( 'publish', 'private' ), true ) ) { return array( 'good', __( 'L’article est publié. Rien d’autre à faire.', 'ms-recipes-writer-ai' ) ); }
			if ( 'future' === $post_status ) { return array( 'live', __( 'L’article est programmé ; il paraîtra à la date choisie.', 'ms-recipes-writer-ai' ) ); }
			if ( 'trash' === $post_status ) { return array( '', __( 'L’article est à la corbeille.', 'ms-recipes-writer-ai' ) ); }
			if ( null === $post_status || false === $post_status ) { return array( '', __( 'L’article a été supprimé de WordPress.', 'ms-recipes-writer-ai' ) ); }
			$approved = $run['approved'] ?? null;
			if ( null !== $approved && ! $approved ) {
				return array( 'warn', __( 'Le brouillon est prêt, mais le contrôle final a relevé des points à vérifier. Lisez-les avant de publier.', 'ms-recipes-writer-ai' ) );
			}
			return array( 'good', __( 'Le brouillon est prêt à être relu. Rien n’est publié tant que vous ne le décidez pas.', 'ms-recipes-writer-ai' ) );
		}

		$said = (string) ( $run['error_message'] ?? '' );
		$where = '';
		foreach ( $steps as $step ) {
			if ( '' !== (string) ( $step['error'] ?? '' ) ) { $said .= ' ' . $step['error']; $where = $where ? $where : (string) $step['step']; }
		}
		// Checked first: a provider out of credit answers 400 or 429 with a
		// message about billing, and no amount of retrying fixes that.
		//
		// The reading is MSRWA_Keys', so that this sentence and the one the
		// diagnostic screen prints about the same account cannot disagree.
		if ( 'credit' === MSRWA_Keys::refusal( $said ) ) {
			return array( 'stop', __( 'Le compte du service d’écriture n’a plus de crédit. Un administrateur doit le recharger, puis reprendre la recette : rien de ce qui est fait n’est perdu.', 'ms-recipes-writer-ai' ) );
		}
		if ( preg_match( '/no api key|api key|clé/i', $said ) && ! preg_match( '/HTTP 40[13]/', $said ) ) {
			return array( 'stop', __( 'Le site n’est relié à aucun service d’écriture pour une étape de cette recette. Un administrateur doit enregistrer la clé, puis la reprendre.', 'ms-recipes-writer-ai' ) );
		}
		if ( preg_match( '/HTTP 40[13]/', $said ) ) {
			return array( 'stop', __( 'Le service d’écriture a refusé la clé du site. Un administrateur doit la vérifier dans les réglages, puis reprendre la recette.', 'ms-recipes-writer-ai' ) );
		}
		if ( preg_match( '/plafond|budget|ceiling/i', $said ) ) {
			return array( 'stop', __( 'Le plafond de dépense de cette recette a été atteint avant la fin. Ce qui est fait est gardé ; un administrateur peut relever le plafond et la reprendre.', 'ms-recipes-writer-ai' ) );
		}
		// A burst limit clears in minutes; a daily or free-tier quota does not,
		// and the difference is invisible from here — so the sentence covers
		// both and names the one thing an administrator can actually do.
		if ( preg_match( '/HTTP 429|RESOURCE_EXHAUSTED|rate.?limit|rate|quota/i', $said ) ) {
			return array( 'stop', __( 'Le service d’écriture refuse d’en traiter davantage pour le moment : c’est une limite de son côté, pas du site. Reprendre la recette plus tard suffit souvent ; si cela se répète, la limite du compte doit être relevée chez le fournisseur.', 'ms-recipes-writer-ai' ) );
		}
		if ( preg_match( '/timed? ?out|cURL|HTTP 5\d\d|resolve|connect/i', $said ) ) {
			return array( 'stop', __( 'Le service d’écriture n’a pas répondu à temps. Reprendre la recette suffit en général : rien de ce qui est fait n’est perdu.', 'ms-recipes-writer-ai' ) );
		}
		if ( '' !== $where ) {
			/* translators: %s is the name of a step, such as "Rédaction". */
			return array( 'stop', sprintf( __( 'La recette s’est arrêtée à l’étape « %s ». La reprendre relance seulement ce qui a échoué.', 'ms-recipes-writer-ai' ), self::step_name( $where ) ) );
		}
		return array( 'stop', __( 'La recette s’est arrêtée avant la fin. La reprendre relance seulement ce qui a échoué.', 'ms-recipes-writer-ai' ) );
	}

	/** How far through its steps a run is. */
	public static function progress( $done, $total ) {
		$total = max( 1, (int) $total );
		$share = min( 100, round( 100 * (int) $done / $total ) );
		$complete = (int) $done >= (int) $total;
		return '<span class="ms-progress' . ( $complete ? ' ms-progress-done' : '' ) . '" role="img" aria-label="'
			. esc_attr( sprintf( /* translators: 1: steps finished, 2: steps in total. */ __( '%1$d étapes sur %2$d', 'ms-recipes-writer-ai' ), (int) $done, $total ) )
			. '"><i style="inline-size:' . (int) $share . '%"></i></span>';
	}

	/**
	 * Recipes as a table: the pass, Articles and a lot's page share it.
	 *
	 * Each row is one ticket under the pass lamp — its state is the stripe at
	 * the leading edge, read before any cell. The recipe cell says what it is;
	 * the cells after it say where it stands, figures aligned at the end.
	 * Columns a reader may not be shown are not drawn at all: a writer sees
	 * neither author nor money. The body keeps `ms-rail` and each row
	 * `data-run`, so the lot's page and the pass refresh rows in place.
	 */
	public static function run_table( array $runs, $selectable = false, $body_id = '' ) {
		$author = MSRWA_Rights::may_see_everything();
		$money = MSRWA_Rights::may_see_money();
		?>
		<table class="ms-runs">
			<thead>
				<tr>
					<?php if ( $selectable ) : ?><th class="ms-runs-pick"><span class="screen-reader-text"><?php esc_html_e( 'Sélection', 'ms-recipes-writer-ai' ); ?></span></th><?php endif; ?>
					<th><?php esc_html_e( 'Recette', 'ms-recipes-writer-ai' ); ?></th>
					<?php if ( $author ) : ?><th><?php esc_html_e( 'Rédacteur', 'ms-recipes-writer-ai' ); ?></th><?php endif; ?>
					<th><?php esc_html_e( 'État', 'ms-recipes-writer-ai' ); ?></th>
					<th><?php esc_html_e( 'Article', 'ms-recipes-writer-ai' ); ?></th>
					<th><?php esc_html_e( 'Créée', 'ms-recipes-writer-ai' ); ?></th>
					<th class="ms-runs-num"><?php esc_html_e( 'Étapes', 'ms-recipes-writer-ai' ); ?></th>
					<?php if ( $money ) : ?>
						<th class="ms-runs-num"><?php esc_html_e( 'Durée', 'ms-recipes-writer-ai' ); ?></th>
						<th class="ms-runs-num"><?php esc_html_e( 'Coût', 'ms-recipes-writer-ai' ); ?></th>
					<?php endif; ?>
					<th class="ms-runs-act"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'ms-recipes-writer-ai' ); ?></span></th>
				</tr>
			</thead>
			<tbody class="ms-rail"<?php echo '' !== $body_id ? ' id="' . esc_attr( $body_id ) . '"' : ''; ?>>
				<?php foreach ( $runs as $run ) { self::run_row( $run, $selectable, $author, $money ); } ?>
			</tbody>
		</table>
		<?php
	}

	private static function run_row( array $run, $selectable, $author, $money ) {
		$url = admin_url( 'admin.php?page=msrwa-run&run_id=' . (int) $run['id'] );
		$state = self::state_of( $run );
		$tone = '' !== $state['tone'] ? $state['tone'] : 'idle';
		$lot = self::lot_of( $run );
		$post_id = (int) ( $run['draft_post_id'] ?? 0 );
		$thumb = $post_id ? (int) get_post_thumbnail_id( $post_id ) : 0;
		$icon = array( MSRWA_Profile::ARTICLE => 'media-text', MSRWA_Profile::FEATURED => 'format-image', MSRWA_Profile::FULL => 'images-alt2' )[ $lot['profile'] ] ?? 'food';
		$post = $post_id ? get_post( $post_id ) : null;
		$wp_title = $post ? trim( (string) $post->post_title ) : '';
		$languages = MSRWA_Profile::languages();
		?>
		<tr class="ms-run ms-run-<?php echo esc_attr( $tone ); ?>" data-run="<?php echo esc_attr( $run['id'] ); ?>">
			<?php if ( $selectable ) : ?>
				<td class="ms-runs-pick"><input type="checkbox" class="ms-pick-run" value="<?php echo esc_attr( $run['id'] ); ?>"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %s is a recipe title. */ __( 'Sélectionner %s', 'ms-recipes-writer-ai' ), $run['label'] ) ); ?>"></td>
			<?php endif; ?>
			<th scope="row" class="ms-run-recipe">
				<a class="ms-run-thumb<?php echo $thumb ? '' : ' is-empty'; ?>" href="<?php echo esc_url( $url ); ?>" tabindex="-1" aria-hidden="true">
					<?php echo $thumb ? wp_get_attachment_image( $thumb, 'thumbnail', false, array( 'alt' => '', 'loading' => 'lazy' ) ) : '<span class="dashicons dashicons-' . esc_attr( $icon ) . '"></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</a>
				<span class="ms-run-id">
					<a class="ms-run-name" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $run['label'] ); ?></a>
					<span class="ms-run-about">
						<span class="ms-run-no">#<?php echo esc_html( $run['id'] ); ?></span>
						<?php if ( (int) ( $run['batch_id'] ?? 0 ) ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=msrwa-batch&batch_id=' . (int) $run['batch_id'] ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d is a lot number. */ __( 'Lot %d', 'ms-recipes-writer-ai' ), (int) $run['batch_id'] ) ); ?></a>
						<?php endif; ?>
						<?php if ( '' !== MSRWA_Profile::short( $lot['profile'] ) ) : ?><span title="<?php echo esc_attr( (string) ( MSRWA_Profile::all()[ $lot['profile'] ]['label'] ?? '' ) ); ?>"><?php echo esc_html( MSRWA_Profile::short( $lot['profile'] ) ); ?></span><?php endif; ?>
						<?php if ( '' !== $lot['language'] ) : ?><span><?php echo esc_html( (string) ( $languages[ $lot['language'] ] ?? strtoupper( $lot['language'] ) ) ); ?></span><?php endif; ?>
					</span>
					<?php if ( '' !== $wp_title && $wp_title !== (string) $run['label'] ) : ?>
						<span class="ms-run-wptitle"><?php echo esc_html( sprintf( /* translators: %s is the article's current title in WordPress. */ __( 'Intitulé dans WordPress : « %s »', 'ms-recipes-writer-ai' ), $wp_title ) ); ?></span>
					<?php endif; ?>
				</span>
			</th>
			<?php if ( $author ) : ?><td data-label="<?php esc_attr_e( 'Rédacteur', 'ms-recipes-writer-ai' ); ?>"><?php echo esc_html( self::owner( (int) ( $run['owner_id'] ?? 0 ) ) ); ?></td><?php endif; ?>
			<td data-label="<?php esc_attr_e( 'État', 'ms-recipes-writer-ai' ); ?>" class="ms-run-state">
				<span data-field="state"><?php echo self::state( $run ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php if ( 'done' !== ( $run['status'] ?? '' ) ) { echo self::progress( $run['steps_done'], $run['steps_total'] ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( '' !== (string) ( $run['step'] ?? '' ) && 'running' === $run['status'] ) : ?><small data-field="step"><?php echo esc_html( self::step_name( (string) $run['step'] ) ); ?></small><?php endif; ?>
				<?php if ( 'queued' === $run['status'] && (int) ( $run['priority'] ?? 0 ) > 0 ) : ?><small><?php esc_html_e( 'passe devant', 'ms-recipes-writer-ai' ); ?></small><?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Article', 'ms-recipes-writer-ai' ); ?>"><?php self::post_badge( $run, $post ); ?></td>
			<td data-label="<?php esc_attr_e( 'Créée', 'ms-recipes-writer-ai' ); ?>">
				<?php if ( ! empty( $run['created_at'] ) ) : ?><time datetime="<?php echo esc_attr( gmdate( 'c', (int) strtotime( $run['created_at'] . ' UTC' ) ) ); ?>" title="<?php echo esc_attr( MSRWA_I18N::when( $run['created_at'] ) ); ?>"><?php echo esc_html( MSRWA_I18N::ago( $run['created_at'] ) ); ?></time><?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Étapes', 'ms-recipes-writer-ai' ); ?>" class="ms-runs-num"><span data-field="steps"><?php echo esc_html( (int) $run['steps_done'] . '/' . (int) $run['steps_total'] ); ?></span></td>
			<?php if ( $money ) : ?>
				<td data-label="<?php esc_attr_e( 'Durée', 'ms-recipes-writer-ai' ); ?>" class="ms-runs-num"><span data-field="seconds"><?php echo isset( $run['seconds'] ) ? esc_html( MSRWA_I18N::seconds( $run['seconds'] ) ) : '—'; ?></span></td>
				<td data-label="<?php esc_attr_e( 'Coût', 'ms-recipes-writer-ai' ); ?>" class="ms-runs-num ms-run-cost"><span data-field="cost"><?php echo isset( $run['cost_usd'] ) ? esc_html( MSRWA_I18N::money( $run['cost_usd'] ) ) : '—'; ?></span></td>
			<?php endif; ?>
			<td class="ms-runs-act"><?php self::post_links( $post_id, $post ); ?></td>
		</tr>
		<?php
	}

	/**
	 * What became of the article in WordPress. The post's life is WordPress's —
	 * published, scheduled, binned or deleted there — so it is read from the
	 * post, never remembered here.
	 */
	private static function post_badge( array $run, $post ) {
		if ( ! (int) ( $run['draft_post_id'] ?? 0 ) ) { echo '<span class="ms-muted">—</span>'; return; }
		if ( ! $post ) {
			echo '<span class="ms-state ms-state-stop" title="' . esc_attr__( 'L’article a été supprimé définitivement ; le suivi de la recette reste ici.', 'ms-recipes-writer-ai' ) . '">' . esc_html__( 'Supprimé de WordPress', 'ms-recipes-writer-ai' ) . '</span>';
			return;
		}
		$states = array(
			'publish' => array( 'good', __( 'Publié', 'ms-recipes-writer-ai' ) ),
			'private' => array( 'good', __( 'Publié en privé', 'ms-recipes-writer-ai' ) ),
			'future' => array( 'live', __( 'Programmé', 'ms-recipes-writer-ai' ) ),
			'pending' => array( 'warn', __( 'En attente de relecture', 'ms-recipes-writer-ai' ) ),
			'draft' => array( 'idle', __( 'Brouillon', 'ms-recipes-writer-ai' ) ),
			'trash' => array( 'stop', __( 'Dans la corbeille', 'ms-recipes-writer-ai' ) ),
		);
		list( $tone, $label ) = $states[ $post->post_status ] ?? $states['draft'];
		echo '<span class="ms-state ms-state-' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
		// The date that matters for that state: when it went out, or was last touched.
		$when = in_array( $post->post_status, array( 'publish', 'future', 'private' ), true ) ? $post->post_date_gmt : $post->post_modified_gmt;
		if ( $when && '0000-00-00 00:00:00' !== $when ) {
			echo '<small title="' . esc_attr( MSRWA_I18N::when( $when ) ) . '">' . esc_html( MSRWA_I18N::ago( $when ) ) . '</small>';
		}
	}

	/** Edit, preview or view, restore from the bin — as icon buttons that name themselves. */
	private static function post_links( $post_id, $post ) {
		if ( ! $post ) { return; }
		$button = static function ( $href, $icon, $label, $blank = false ) {
			return '<a class="ms-run-action" href="' . esc_url( $href ) . '" title="' . esc_attr( $label ) . '"' . ( $blank ? ' target="_blank" rel="noopener"' : '' ) . '>'
				. '<span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $label ) . '</span></a>';
		};
		$links = array();
		if ( 'trash' === $post->post_status ) {
			if ( current_user_can( 'delete_post', $post_id ) ) { $links[] = $button( wp_nonce_url( admin_url( 'post.php?post=' . $post_id . '&action=untrash' ), 'untrash-post_' . $post_id ), 'undo', __( 'Restaurer', 'ms-recipes-writer-ai' ) ); }
		} else {
			$edit = (string) get_edit_post_link( $post_id );
			if ( '' !== $edit ) { $links[] = $button( $edit, 'edit', __( 'Modifier', 'ms-recipes-writer-ai' ) ); }
			if ( in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
				$links[] = $button( (string) get_permalink( $post_id ), 'external', __( 'Voir', 'ms-recipes-writer-ai' ), true );
			} elseif ( '' !== $edit ) {
				$links[] = $button( (string) get_preview_post_link( $post ), 'visibility', __( 'Prévisualiser', 'ms-recipes-writer-ai' ), true );
			}
		}
		echo implode( '', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
	}

	/**
	 * The lot's kind and language for a row. The lists read them with the run;
	 * a lot's own page passes runs without them, and asks its lot once.
	 */
	private static function lot_of( array $run ) {
		static $lots = array();
		if ( array_key_exists( 'profile', $run ) ) { return array( 'profile' => (string) $run['profile'], 'language' => (string) ( $run['language'] ?? '' ) ); }
		$id = (int) ( $run['batch_id'] ?? 0 );
		if ( ! isset( $lots[ $id ] ) ) {
			$batch = $id && class_exists( 'MSRWA_Batch' ) ? MSRWA_Batch::get( $id ) : null;
			$lots[ $id ] = array( 'profile' => (string) ( $batch['profile'] ?? '' ), 'language' => (string) ( $batch['language'] ?? '' ) );
		}
		return $lots[ $id ];
	}

	/** An empty screen says what to do next, never just that there is nothing. */
	public static function nothing( $title, $text, $action = '' ) {
		echo '<div class="ms-empty"><strong>' . esc_html( $title ) . '</strong><p>' . esc_html( $text ) . '</p>';
		if ( '' !== $action ) { echo wp_kses_post( $action ); }
		echo '</div>';
	}

	public static function note( $text, $tone = '' ) {
		echo '<div class="ms-note' . ( $tone ? ' ms-note-' . esc_attr( $tone ) : '' ) . '"><p>' . wp_kses_post( $text ) . '</p></div>';
	}

	/** The author's name, or a dash when the reader may not see whose it is. */
	/**
	 * Who sent a recipe and what became of it, for the top of its own page:
	 * the writer and the article's author, the lot and its kind, the dates,
	 * the article in WordPress, its categories and length, and what the
	 * writer sent. Nothing here is money or engine vocabulary: writers read it.
	 */
	public static function facts( array $run ) {
		$lot = self::lot_of( $run );
		$post_id = (int) ( $run['draft_post_id'] ?? 0 );
		$post = $post_id ? get_post( $post_id ) : null;
		$person = static function ( $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( ! $user ) { return '<span class="ms-muted">—</span>'; }
			// Initials drawn here, not a Gravatar: no request to another site
			// for every name on the screen, and nothing that fails offline.
			$words = preg_split( '/\s+/u', trim( (string) $user->display_name ) );
			$initials = mb_strtoupper( mb_substr( (string) ( $words[0] ?? '' ), 0, 1 ) . ( count( $words ) > 1 ? mb_substr( (string) end( $words ), 0, 1 ) : '' ) );
			$hue = abs( crc32( (string) $user->ID ) ) % 360;
			return '<span class="ms-fact-person"><span class="ms-fact-avatar" style="--ms-hue:' . esc_attr( (string) $hue ) . '" aria-hidden="true">' . esc_html( '' !== $initials ? $initials : '?' ) . '</span>' . esc_html( $user->display_name ) . '</span>';
		};
		$date = static function ( $gmt ) {
			if ( empty( $gmt ) || '0000-00-00 00:00:00' === $gmt ) { return '<span class="ms-muted">—</span>'; }
			return '<time datetime="' . esc_attr( gmdate( 'c', (int) strtotime( $gmt . ' UTC' ) ) ) . '">' . esc_html( MSRWA_I18N::when( $gmt ) ) . '</time><small>' . esc_html( MSRWA_I18N::ago( $gmt ) ) . '</small>';
		};
		$facts = array();
		$facts[ __( 'Rédacteur', 'ms-recipes-writer-ai' ) ] = $person( (int) ( $run['owner_id'] ?? 0 ) );
		if ( $post && (int) $post->post_author !== (int) ( $run['owner_id'] ?? 0 ) ) {
			$facts[ __( 'Auteur de l’article', 'ms-recipes-writer-ai' ) ] = $person( (int) $post->post_author );
		}
		$languages = MSRWA_Profile::languages();
		$kind = (string) ( MSRWA_Profile::all()[ $lot['profile'] ]['label'] ?? '' );
		$batch = (int) ( $run['batch_id'] ?? 0 );
		$facts[ __( 'Lot', 'ms-recipes-writer-ai' ) ] = ( $batch ? '<a href="' . esc_url( admin_url( 'admin.php?page=msrwa-batch&batch_id=' . $batch ) ) . '">' . esc_html( sprintf( /* translators: %d is a lot number. */ __( 'Lot %d', 'ms-recipes-writer-ai' ), $batch ) ) . '</a>' : '—' )
			. ( '' !== $kind ? '<small>' . esc_html( $kind ) . ( '' !== $lot['language'] ? ' · ' . esc_html( (string) ( $languages[ $lot['language'] ] ?? strtoupper( $lot['language'] ) ) ) : '' ) . '</small>' : '' );
		$facts[ __( 'Créée', 'ms-recipes-writer-ai' ) ] = $date( (string) ( $run['created_at'] ?? '' ) );
		if ( in_array( (string) ( $run['status'] ?? '' ), array( 'done', 'failed', 'cancelled' ), true ) ) {
			$facts[ __( 'Terminée', 'ms-recipes-writer-ai' ) ] = $date( (string) ( $run['updated_at'] ?? '' ) );
		}
		if ( $post_id ) {
			ob_start();
			self::post_badge( $run, $post );
			$facts[ __( 'Article', 'ms-recipes-writer-ai' ) ] = '<span class="ms-fact-post">' . ob_get_clean() . '</span>';
		}
		if ( $post ) {
			$names = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
			$facts[ __( 'Catégories', 'ms-recipes-writer-ai' ) ] = $names && ! is_wp_error( $names ) ? esc_html( implode( ', ', $names ) ) : '<span class="ms-muted">—</span>';
			$words = str_word_count( wp_strip_all_tags( (string) $post->post_content ), 0, 'àâäéèêëîïôöùûüçœæÀÂÄÉÈÊËÎÏÔÖÙÛÜÇŒÆ’\'-' );
			$facts[ __( 'Longueur', 'ms-recipes-writer-ai' ) ] = esc_html( sprintf( /* translators: %s is a number of words. */ _n( '%s mot', '%s mots', $words, 'ms-recipes-writer-ai' ), number_format_i18n( $words ) ) );
		}
		$brief = (array) json_decode( (string) ( $run['brief_json'] ?? '' ), true );
		$photos = count( (array) ( $brief['images'] ?? array() ) );
		$lead = (string) ( $brief['collage_lead'] ?? '' );
		$sent = $photos ? sprintf( /* translators: %d is a number of photographs. */ _n( '%d photographie', '%d photographies', $photos, 'ms-recipes-writer-ai' ), $photos ) : __( 'texte seul', 'ms-recipes-writer-ai' );
		$collage = 'provided' === $lead ? __( 'votre collage Facebook', 'ms-recipes-writer-ai' ) : ( 'drawn' === $lead ? __( 'collage dessiné en premier', 'ms-recipes-writer-ai' ) : '' );
		$facts[ __( 'Envoyé', 'ms-recipes-writer-ai' ) ] = esc_html( $sent ) . ( '' !== $collage ? '<small>' . esc_html( $collage ) . '</small>' : '' );

		echo '<section class="ms-card ms-facts" aria-label="' . esc_attr__( 'La recette', 'ms-recipes-writer-ai' ) . '"><dl>';
		// Each value above is built from escaped parts.
		foreach ( $facts as $label => $html ) { echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . $html . '</dd></div>'; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</dl>';
		if ( $post ) { echo '<div class="ms-facts-links">'; self::post_links( $post_id, $post ); echo '</div>'; }
		echo '</section>';
	}

	public static function owner( $owner_id ) {
		if ( ! MSRWA_Rights::may_see_everything() ) { return ''; }
		$user = get_userdata( (int) $owner_id );
		return $user ? $user->display_name : '—';
	}
}
