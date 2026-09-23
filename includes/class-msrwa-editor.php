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
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_editor' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'state' ), 10, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'forget' ) );
	}

	/**
	 * The verdict as data, read once and shared by both editors.
	 *
	 * The classic meta box and the block editor's panels say the same thing, so
	 * they read the same thing: what the judge objected to, what the article
	 * wrote about itself, and which run it came from.
	 */
	public static function verdict( $post_id ) {
		$post_id = (int) $post_id;
		$report = json_decode( (string) get_post_meta( $post_id, '_msrwa_judge_report', true ), true );

		$findings = array();
		$blocking = 0;
		foreach ( (array) ( is_array( $report ) ? ( $report['findings'] ?? array() ) : array() ) as $finding ) {
			if ( ! is_array( $finding ) ) { continue; }
			$is_blocking = 'blocking' === (string) ( $finding['severity'] ?? '' );
			if ( $is_blocking ) { $blocking++; }
			$findings[] = array(
				'blocking' => $is_blocking,
				'target' => (string) ( $finding['target'] ?? '' ),
				'reason' => (string) ( $finding['reason'] ?? '' ),
				'quote' => (string) ( $finding['quote'] ?? '' ),
				'fix' => (string) ( $finding['fix'] ?? '' ),
			);
		}

		$uncertainties = array();
		foreach ( (array) ( is_array( $report ) ? ( $report['uncertainties'] ?? array() ) : array() ) as $uncertainty ) {
			if ( is_string( $uncertainty ) && '' !== trim( $uncertainty ) ) { $uncertainties[] = $uncertainty; }
		}

		return array(
			'judged' => is_array( $report ),
			'findings' => $findings,
			'blocking' => $blocking,
			'uncertainties' => $uncertainties,
			'seo' => self::seo_rows( $post_id ),
			'run_id' => (int) get_post_meta( $post_id, '_msrwa_run_id', true ),
		);
	}

	/** stop when something blocks publication, warn for the rest, '' when clean. */
	public static function tone( array $verdict ) {
		if ( $verdict['blocking'] ) { return 'stop'; }
		return $verdict['findings'] ? 'warn' : '';
	}

	/**
	 * The same verdict, in the editor most sites actually use.
	 *
	 * A classic meta box is folded away behind a collapsed drawer in the block
	 * editor — present in the page, `display:none` until somebody thinks to
	 * open it. An editor opening a generated draft therefore never met the
	 * judge's objections, which is the one thing this plugin promises to put in
	 * front of them before they publish. The panels below put it in the post
	 * sidebar and, more to the point, in the pre-publish check, which is the
	 * moment the question is actually being asked.
	 */
	public static function block_editor() {
		$post_id = (int) get_the_ID();
		if ( ! $post_id || ! get_post_meta( $post_id, '_msrwa_run_id', true ) ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) || ! MSRWA_Rights::may_write() ) { return; }

		$file = MSRWA_DIR . 'assets/editor.js';
		wp_enqueue_script(
			'msrwa-editor',
			MSRWA_URL . 'assets/editor.js',
			array( 'wp-plugins', 'wp-element', 'wp-components', 'wp-edit-post' ),
			MSRWA_VERSION . '.' . filemtime( $file ),
			true
		);

		$verdict = self::verdict( $post_id );
		$run = MSRWA_Run::get( $verdict['run_id'] );
		$verdict['tone'] = self::tone( $verdict );
		$verdict['runUrl'] = $run && MSRWA_Run::may_see( $run )
			? admin_url( 'admin.php?page=msrwa-run&run_id=' . $verdict['run_id'] )
			: '';
		$verdict['text'] = array(
			'title' => __( 'MS Recipes AI', 'ms-recipes-writer-ai' ),
			'lead' => __( 'Cet article a été généré. Les contrôles ci-dessous portent sur ce que la machine a produit ; ils ne remplacent pas votre relecture, et rien ici ne vaut approbation.', 'ms-recipes-writer-ai' ),
			'unjudged' => __( 'Aucun jugement final n’a été rendu pour cet article — le profil choisi ne l’exécutait pas, ou la recette s’est arrêtée avant.', 'ms-recipes-writer-ai' ),
			'clean' => __( 'Le juge n’a rien relevé. Votre relecture reste la seule validation.', 'ms-recipes-writer-ai' ),
			'minor' => __( 'Des remarques mineures, sans rien de bloquant.', 'ms-recipes-writer-ai' ),
			/* translators: %d is a number of findings. */
			'blocking' => sprintf( _n( '%d remarque bloquante à corriger avant publication.', '%d remarques bloquantes à corriger avant publication.', max( 1, $verdict['blocking'] ), 'ms-recipes-writer-ai' ), $verdict['blocking'] ),
			'blockingLabel' => __( 'Bloquant', 'ms-recipes-writer-ai' ),
			'minorLabel' => __( 'Mineur', 'ms-recipes-writer-ai' ),
			'fix' => __( 'Correction proposée :', 'ms-recipes-writer-ai' ),
			'uncertain' => __( 'Non vérifiable :', 'ms-recipes-writer-ai' ),
			'seo' => __( 'Référencement et réseaux sociaux', 'ms-recipes-writer-ai' ),
			/* translators: %d is a run number. */
			'run' => sprintf( __( 'Voir le détail de la recette %d', 'ms-recipes-writer-ai' ), $verdict['run_id'] ),
		);

		wp_add_inline_script( 'msrwa-editor', 'var MSRWA_VERDICT = ' . wp_json_encode( $verdict ) . ';', 'before' );
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
		if ( ! current_user_can( 'edit_post', $post->ID ) || ! MSRWA_Rights::may_write() ) { return; }
		add_meta_box( 'msrwa-verdict', __( 'MS Recipes AI — à vérifier avant publication', 'ms-recipes-writer-ai' ), array( __CLASS__, 'render' ), 'post', 'normal', 'high' );
	}

	/** A quiet marker in the posts list, so a generated draft is never a surprise. */
	public static function state( $states, $post ) {
		if ( get_post_meta( $post->ID, '_msrwa_run_id', true ) ) {
			$states['msrwa'] = __( 'généré', 'ms-recipes-writer-ai' );
		}
		return $states;
	}

	public static function render( $post ) {
		$verdict = self::verdict( $post->ID );
		$run = MSRWA_Run::get( $verdict['run_id'] );

		echo '<div class="msrwa">';
		echo '<p>' . esc_html__( 'Cet article a été généré. Les contrôles ci-dessous portent sur ce que la machine a produit ; ils ne remplacent pas votre relecture, et rien ici ne vaut approbation.', 'ms-recipes-writer-ai' ) . '</p>';

		if ( ! $verdict['judged'] ) {
			echo '<p class="ms-muted">' . esc_html__( 'Aucun jugement final n’a été rendu pour cet article — le profil choisi ne l’exécutait pas, ou la recette s’est arrêtée avant.', 'ms-recipes-writer-ai' ) . '</p>';
			self::extras( $verdict['seo'] );
			self::link( $verdict['run_id'], $run );
			echo '</div>';
			return;
		}

		if ( $verdict['blocking'] ) {
			echo '<div class="ms-note ms-note-stop"><p><strong>'
				. esc_html( sprintf(
					/* translators: %d is a number of findings. */
					_n( '%d remarque bloquante', '%d remarques bloquantes', $verdict['blocking'], 'ms-recipes-writer-ai' ),
					$verdict['blocking']
				) )
				. '</strong> — ' . esc_html__( 'à corriger avant publication.', 'ms-recipes-writer-ai' ) . '</p></div>';
		} elseif ( $verdict['findings'] ) {
			echo '<div class="ms-note ms-note-warn"><p>' . esc_html__( 'Des remarques mineures, sans rien de bloquant.', 'ms-recipes-writer-ai' ) . '</p></div>';
		} else {
			echo '<div class="ms-note"><p>' . esc_html__( 'Le juge n’a rien relevé. Votre relecture reste la seule validation.', 'ms-recipes-writer-ai' ) . '</p></div>';
		}

		if ( $verdict['findings'] ) {
			echo '<ul>';
			foreach ( $verdict['findings'] as $finding ) {
				echo '<li><strong>' . esc_html( $finding['blocking'] ? __( 'Bloquant', 'ms-recipes-writer-ai' ) : __( 'Mineur', 'ms-recipes-writer-ai' ) ) . '</strong> — '
					. esc_html( $finding['target'] ) . ' : ' . esc_html( $finding['reason'] );
				if ( '' !== $finding['quote'] ) { echo '<br><em>« ' . esc_html( $finding['quote'] ) . ' »</em>'; }
				if ( '' !== $finding['fix'] ) { echo '<br>' . esc_html__( 'Correction proposée :', 'ms-recipes-writer-ai' ) . ' ' . esc_html( $finding['fix'] ); }
				echo '</li>';
			}
			echo '</ul>';
		}

		foreach ( $verdict['uncertainties'] as $uncertainty ) {
			echo '<p class="ms-muted">' . esc_html__( 'Non vérifiable :', 'ms-recipes-writer-ai' ) . ' ' . esc_html( $uncertainty ) . '</p>';
		}

		self::extras( $verdict['seo'] );
		self::link( $verdict['run_id'], $run );
		echo '</div>';
	}

	/**
	 * What the article wrote about itself for search and social. An SEO plugin
	 * shows its own copy when it is installed; this is for every other site, and
	 * for the Facebook caption, which no plugin holds.
	 */
	private static function seo_rows( $post_id ) {
		$fields = array(
			'_msrwa_seo_title' => __( 'Titre SEO', 'ms-recipes-writer-ai' ),
			'_msrwa_seo_description' => __( 'Méta-description', 'ms-recipes-writer-ai' ),
			'_msrwa_facebook_caption' => __( 'Légende Facebook', 'ms-recipes-writer-ai' ),
		);
		$rows = array();
		foreach ( $fields as $key => $label ) {
			$value = (string) get_post_meta( (int) $post_id, $key, true );
			if ( '' !== $value ) { $rows[ $label ] = $value; }
		}
		return $rows;
	}

	private static function extras( array $rows ) {
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
