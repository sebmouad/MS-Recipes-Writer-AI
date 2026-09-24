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
			echo '<div class="ms-figure"><dt>' . esc_html( $entry['label'] ) . '</dt><dd>' . esc_html( $entry['value'] );
			if ( ! empty( $entry['note'] ) ) { echo '<small>' . esc_html( $entry['note'] ) . '</small>'; }
			echo '</dd></div>';
		}
		echo '</dl>';
	}

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
			'review' => __( 'Relecture éditoriale', 'ms-recipes-writer-ai' ),
			'fact_check' => __( 'Vérification des faits', 'ms-recipes-writer-ai' ),
			'corrections' => __( 'Corrections', 'ms-recipes-writer-ai' ),
			'proofread' => __( 'Correction de la langue', 'ms-recipes-writer-ai' ),
			'final_approval' => __( 'Contrôle final', 'ms-recipes-writer-ai' ),
			'vision' => __( 'Lecture des photographies', 'ms-recipes-writer-ai' ),
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
			if ( ! (int) ( $run['draft_post_id'] ?? 0 ) ) { return array( 'warn', __( 'Le travail est terminé, mais aucun brouillon n’a pu être créé. Un administrateur doit regarder le détail.', 'ms-recipes-writer-ai' ) ); }
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
	 * One run on the rail.
	 *
	 * The spine at the leading edge carries the state before anything is read,
	 * which is the whole reason the rail exists: an editor glancing at the page
	 * should see that something went wrong without reading a word.
	 */
	public static function ticket( array $run, $url, $selectable = false ) {
		$state = self::state_of( $run );
		$spine = in_array( $state['tone'], array( 'live' ), true ) ? 'live' : ( 'stop' === $state['tone'] ? 'stop' : ( 'good' === $state['tone'] ? 'done' : '' ) );
		?>
		<article class="ms-ticket<?php echo $spine ? ' ms-ticket-' . esc_attr( $spine ) : ''; ?>" data-run="<?php echo esc_attr( $run['id'] ); ?>">
			<div class="ms-ticket-title">
				<?php if ( $selectable ) : ?>
					<input type="checkbox" class="ms-pick-run" value="<?php echo esc_attr( $run['id'] ); ?>"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s is a recipe title. */ __( 'Sélectionner %s', 'ms-recipes-writer-ai' ), $run['label'] ) ); ?>">
				<?php endif; ?>
				<span class="ms-ticket-no">#<?php echo esc_html( $run['id'] ); ?></span>
				<a class="ms-ticket-name" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $run['label'] ); ?></a>
			</div>
			<div class="ms-ticket-meta">
				<span data-field="steps"><?php echo esc_html( $run['steps_done'] . '/' . $run['steps_total'] ); ?></span>
				<?php if ( MSRWA_Rights::may_see_money() && isset( $run['cost_usd'] ) ) : ?>
					<span data-field="cost"><?php echo esc_html( MSRWA_I18N::money( $run['cost_usd'] ) ); ?></span>
				<?php endif; ?>
				<?php if ( isset( $run['seconds'] ) ) : ?>
					<span data-field="seconds"><?php echo esc_html( MSRWA_I18N::seconds( $run['seconds'] ) ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== (string) ( $run['step'] ?? '' ) && 'running' === $run['status'] ) : ?>
					<span class="ms-key" data-field="step"><?php echo esc_html( $run['step'] ); ?></span>
				<?php endif; ?>
				<?php if ( 'queued' === $run['status'] && (int) ( $run['priority'] ?? 0 ) > 0 ) : ?>
					<span class="ms-key"><?php esc_html_e( 'passe devant', 'ms-recipes-writer-ai' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="ms-ticket-side">
				<?php echo self::progress( $run['steps_done'], $run['steps_total'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php
				// Once the post has left the drafts its own state, below, says it;
				// the same word twice on one row reads as two different facts.
				$post_id = (int) ( $run['draft_post_id'] ?? 0 );
				$decided = $post_id && 'done' === ( $run['status'] ?? '' ) && ! in_array( array_key_exists( 'post_status', $run ) ? $run['post_status'] : get_post_status( $post_id ), array( 'draft', 'pending' ), true );
				?>
				<?php if ( ! $decided ) : ?><span data-field="state"><?php echo self::state( $run ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><?php endif; ?>
			</div>
			<?php self::post_state( $run ); ?>
		</article>
		<?php
	}

	/**
	 * What became of the article in WordPress, and what can be done with it.
	 *
	 * The run knows the post it wrote; the post's life is WordPress's — it is
	 * published, scheduled, sent to the bin or deleted there, and renamed on
	 * the way. So the state is read from the post, never remembered here.
	 */
	public static function post_state( array $run ) {
		$post_id = (int) ( $run['draft_post_id'] ?? 0 );
		if ( ! $post_id ) { return; }
		$post = get_post( $post_id );
		$states = array(
			'publish' => array( 'good', __( 'Publié', 'ms-recipes-writer-ai' ) ),
			'private' => array( 'good', __( 'Publié en privé', 'ms-recipes-writer-ai' ) ),
			'future' => array( 'live', __( 'Programmé', 'ms-recipes-writer-ai' ) ),
			'pending' => array( 'warn', __( 'En attente de relecture', 'ms-recipes-writer-ai' ) ),
			'draft' => array( 'idle', __( 'Brouillon', 'ms-recipes-writer-ai' ) ),
			'trash' => array( 'stop', __( 'Dans la corbeille', 'ms-recipes-writer-ai' ) ),
		);
		echo '<div class="ms-ticket-post">';
		if ( ! $post ) {
			echo '<span class="ms-state ms-state-stop">' . esc_html__( 'Supprimé de WordPress', 'ms-recipes-writer-ai' ) . '</span>';
			echo '<small class="ms-muted">' . esc_html__( 'L’article a été supprimé définitivement ; le suivi de la recette reste ici.', 'ms-recipes-writer-ai' ) . '</small></div>';
			return;
		}
		list( $tone, $label ) = $states[ $post->post_status ] ?? $states['draft'];
		echo '<span class="ms-state ms-state-' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';

		// The date that matters for that state: when it went out, or will.
		$when = 'publish' === $post->post_status || 'future' === $post->post_status || 'private' === $post->post_status ? $post->post_date_gmt : $post->post_modified_gmt;
		if ( $when && '0000-00-00 00:00:00' !== $when ) {
			echo '<small class="ms-muted">' . esc_html( sprintf(
				/* translators: %s is a date. */
				'future' === $post->post_status ? __( 'le %s', 'ms-recipes-writer-ai' ) : ( 'draft' === $post->post_status || 'pending' === $post->post_status || 'trash' === $post->post_status ? __( 'modifié le %s', 'ms-recipes-writer-ai' ) : __( 'le %s', 'ms-recipes-writer-ai' ) ),
				MSRWA_I18N::when( $when )
			) ) . '</small>';
		}
		$title = trim( (string) $post->post_title );
		if ( '' !== $title && $title !== (string) ( $run['label'] ?? '' ) ) {
			/* translators: %s is the article's current title in WordPress. */
			echo '<small class="ms-ticket-post-title">' . esc_html( sprintf( __( 'Intitulé dans WordPress : « %s »', 'ms-recipes-writer-ai' ), $title ) ) . '</small>';
		}

		$links = array();
		if ( 'trash' === $post->post_status ) {
			if ( current_user_can( 'delete_post', $post_id ) ) {
				$links[] = '<a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'post.php?post=' . $post_id . '&action=untrash' ), 'untrash-post_' . $post_id ) ) . '">' . esc_html__( 'Restaurer', 'ms-recipes-writer-ai' ) . '</a>';
			}
		} else {
			$edit = (string) get_edit_post_link( $post_id );
			if ( '' !== $edit ) { $links[] = '<a class="button button-small" href="' . esc_url( $edit ) . '">' . esc_html__( 'Modifier', 'ms-recipes-writer-ai' ) . '</a>'; }
			if ( in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
				$links[] = '<a class="button button-small" href="' . esc_url( (string) get_permalink( $post_id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Voir', 'ms-recipes-writer-ai' ) . '</a>';
			} elseif ( '' !== $edit ) {
				$links[] = '<a class="button button-small" href="' . esc_url( (string) get_preview_post_link( $post ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Prévisualiser', 'ms-recipes-writer-ai' ) . '</a>';
			}
		}
		if ( $links ) { echo '<span class="ms-ticket-post-links">' . implode( ' ', $links ) . '</span>'; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		echo '</div>';
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
	public static function owner( $owner_id ) {
		if ( ! MSRWA_Rights::may_see_everything() ) { return ''; }
		$user = get_userdata( (int) $owner_id );
		return $user ? $user->display_name : '—';
	}
}
