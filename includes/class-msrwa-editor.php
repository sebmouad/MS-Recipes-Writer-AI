<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What the editor sees where they actually work: on the draft itself.
 *
 * An editor opens the post, not this plugin's screens. If the machine had
 * reservations about an article, the place that has to say so is the editing
 * screen — a warning on a dashboard nobody opened has warned nobody.
 *
 * Nothing here is phrased as approval. The engine judged its own output; the
 * decision to publish is the editor's, and the box says which is which.
 */
final class MSRWA_Editor {

	public static function hooks() {
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'register' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'state' ), 10, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'forget' ) );
	}

	/**
	 * A draft deleted for good is no longer the run's draft.
	 *
	 * Without this the run would keep pointing at a post that no longer exists,
	 * and every screen reading through to WordPress would find a hole. The run
	 * itself is untouched: it still holds what the machine produced, which is
	 * why that copy was kept.
	 */
	public static function forget( $post_id ) {
		global $wpdb;
		$run_id = (int) get_post_meta( (int) $post_id, '_msrwa_run_id', true );
		if ( ! $run_id ) { return; }
		$t = MSRWA_DB::tables();
		$wpdb->update( $t['runs'], array( 'draft_post_id' => 0, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $run_id ) );
	}

	public static function register( $post ) {
		if ( ! get_post_meta( $post->ID, '_msrwa_run_id', true ) ) { return; }
		if ( ! current_user_can( 'edit_post', $post->ID ) ) { return; }
		add_meta_box( 'msrwa-verdict', __( 'MS Recipes Writer — à vérifier avant publication', 'ms-recipes-writer-ai' ), array( __CLASS__, 'render' ), 'post', 'normal', 'high' );
	}

	/** A quiet marker in the posts list, so a generated draft is never a surprise. */
	public static function state( $states, $post ) {
		if ( get_post_meta( $post->ID, '_msrwa_run_id', true ) ) {
			$states['msrwa'] = __( 'généré', 'ms-recipes-writer-ai' );
		}
		return $states;
	}

	public static function render( $post ) {
		$run_id = (int) get_post_meta( $post->ID, '_msrwa_run_id', true );
		$run = MSRWA_Run::get( $run_id );
		$report = json_decode( (string) get_post_meta( $post->ID, '_msrwa_judge_report', true ), true );

		echo '<div class="msrwa">';
		echo '<p>' . esc_html__( 'Cet article a été généré. Les contrôles ci-dessous portent sur ce que la machine a produit ; ils ne remplacent pas votre relecture, et rien ici ne vaut approbation.', 'ms-recipes-writer-ai' ) . '</p>';

		if ( ! is_array( $report ) ) {
			echo '<p class="ms-muted">' . esc_html__( 'Aucun jugement final n’a été rendu pour cet article — le profil choisi ne l’exécutait pas, ou la recette s’est arrêtée avant.', 'ms-recipes-writer-ai' ) . '</p>';
			self::extras( $post->ID );
			self::link( $run_id, $run );
			echo '</div>';
			return;
		}

		$findings = (array) ( $report['findings'] ?? array() );
		$blocking = array_values( array_filter( $findings, static function ( $finding ) { return 'blocking' === ( $finding['severity'] ?? '' ); } ) );

		if ( $blocking ) {
			echo '<div class="ms-note ms-note-stop"><p><strong>'
				. esc_html( sprintf(
					/* translators: %d is a number of findings. */
					_n( '%d remarque bloquante', '%d remarques bloquantes', count( $blocking ), 'ms-recipes-writer-ai' ),
					count( $blocking )
				) )
				. '</strong> — ' . esc_html__( 'à corriger avant publication.', 'ms-recipes-writer-ai' ) . '</p></div>';
		} elseif ( $findings ) {
			echo '<div class="ms-note ms-note-warn"><p>' . esc_html__( 'Des remarques mineures, sans rien de bloquant.', 'ms-recipes-writer-ai' ) . '</p></div>';
		} else {
			echo '<div class="ms-note"><p>' . esc_html__( 'Le juge n’a rien relevé. Votre relecture reste la seule validation.', 'ms-recipes-writer-ai' ) . '</p></div>';
		}

		if ( $findings ) {
			echo '<ul>';
			foreach ( $findings as $finding ) {
				$is_blocking = 'blocking' === ( $finding['severity'] ?? '' );
				echo '<li><strong>' . esc_html( $is_blocking ? __( 'Bloquant', 'ms-recipes-writer-ai' ) : __( 'Mineur', 'ms-recipes-writer-ai' ) ) . '</strong> — '
					. esc_html( (string) ( $finding['target'] ?? '' ) ) . ' : ' . esc_html( (string) ( $finding['reason'] ?? '' ) );
				if ( ! empty( $finding['quote'] ) ) { echo '<br><em>« ' . esc_html( (string) $finding['quote'] ) . ' »</em>'; }
				if ( ! empty( $finding['fix'] ) ) { echo '<br>' . esc_html__( 'Correction proposée :', 'ms-recipes-writer-ai' ) . ' ' . esc_html( (string) $finding['fix'] ); }
				echo '</li>';
			}
			echo '</ul>';
		}

		foreach ( (array) ( $report['uncertainties'] ?? array() ) as $uncertainty ) {
			if ( is_string( $uncertainty ) && '' !== $uncertainty ) {
				echo '<p class="ms-muted">' . esc_html__( 'Non vérifiable :', 'ms-recipes-writer-ai' ) . ' ' . esc_html( $uncertainty ) . '</p>';
			}
		}

		self::extras( $post->ID );
		self::link( $run_id, $run );
		echo '</div>';
	}

	/**
	 * What the article wrote about itself for search and social. An SEO plugin
	 * shows its own copy when it is installed; this is for every other site, and
	 * for the Facebook caption, which no plugin holds.
	 */
	private static function extras( $post_id ) {
		$fields = array(
			'_msrwa_seo_title' => __( 'Titre SEO', 'ms-recipes-writer-ai' ),
			'_msrwa_seo_description' => __( 'Méta-description', 'ms-recipes-writer-ai' ),
			'_msrwa_facebook_caption' => __( 'Légende Facebook', 'ms-recipes-writer-ai' ),
		);
		$rows = array();
		foreach ( $fields as $key => $label ) {
			$value = (string) get_post_meta( $post_id, $key, true );
			if ( '' !== $value ) { $rows[ $label ] = $value; }
		}
		if ( ! $rows ) { return; }
		echo '<details class="ms-extras"><summary>' . esc_html__( 'Référencement et réseaux sociaux', 'ms-recipes-writer-ai' ) . '</summary><dl>';
		foreach ( $rows as $label => $value ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd><textarea readonly rows="' . ( mb_strlen( $value ) > 180 ? 4 : 2 ) . '" class="widefat">' . esc_textarea( $value ) . '</textarea></dd>';
		}
		echo '</dl></details>';
	}

	private static function link( $run_id, $run ) {
		if ( ! $run || ! MSRWA_Run::may_see( $run ) ) { return; }
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=msrwa-run&run_id=' . (int) $run_id ) ) . '">'
			/* translators: %d is a run number. */
			. esc_html( sprintf( __( 'Voir le détail de la recette %d', 'ms-recipes-writer-ai' ), (int) $run_id ) ) . '</a></p>';
	}
}
