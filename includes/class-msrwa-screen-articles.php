<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Every run, filtered the way a person filters: what needs me, whose is it,
 * which batch, what was it called.
 *
 * The scoping is not applied here. It is in the query, in MSRWA_Ledger, so a
 * screen cannot forget it — a writer without view_all sees their own work and
 * the filter for other people's simply is not offered.
 */
final class MSRWA_Screen_Articles {

	public static function render() {
		if ( ! MSRWA_Rights::may_write() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }

		$filters = array(
			'status' => isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '',
			'owner' => isset( $_GET['owner'] ) ? absint( $_GET['owner'] ) : 0,
			'batch' => isset( $_GET['batch'] ) ? absint( $_GET['batch'] ) : 0,
			'search' => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'post' => isset( $_GET['post_state'] ) ? sanitize_key( wp_unslash( $_GET['post_state'] ) ) : '',
			'page' => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1,
			'per_page' => 25,
		);
		$found = MSRWA_Ledger::runs( $filters );
		$counts = MSRWA_Ledger::post_counts( $filters );
		// One query for every post the page shows, rather than one per row.
		$ids = array_filter( array_map( 'intval', array_column( $found['runs'], 'draft_post_id' ) ) );
		if ( $ids && function_exists( '_prime_post_caches' ) ) { _prime_post_caches( $ids, false, false ); }

		echo '<div class="wrap msrwa">';
		MSRWA_UI::head(
			__( 'Articles', 'ms-recipes-writer-ai' ),
			MSRWA_Rights::may_see_everything()
				? __( 'Toutes les recettes passées par le moteur, avec leur état et ce qu’elles ont coûté.', 'ms-recipes-writer-ai' )
				: __( 'Vos recettes passées par le moteur, avec leur état et leur brouillon.', 'ms-recipes-writer-ai' ),
			array( __( 'résultats', 'ms-recipes-writer-ai' ) => number_format_i18n( $found['total'] ) ),
			'<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=msrwa-compose' ) ) . '">' . esc_html__( 'Nouveau lot', 'ms-recipes-writer-ai' ) . '</a>'
		);
		?>
		<section class="ms-card ms-card-flush">
			<?php self::tabs( $filters, $counts ); ?>
			<form class="ms-filters" method="get">
				<input type="hidden" name="page" value="msrwa-articles">
				<?php if ( '' !== $filters['post'] ) : ?><input type="hidden" name="post_state" value="<?php echo esc_attr( $filters['post'] ); ?>"><?php endif; ?>
				<div>
					<label for="ms-filter-state"><?php esc_html_e( 'État', 'ms-recipes-writer-ai' ); ?></label>
					<select id="ms-filter-state" name="state">
						<?php foreach ( self::states() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php if ( MSRWA_Rights::may_see_everything() ) : ?>
					<div>
						<label for="ms-filter-owner"><?php esc_html_e( 'Rédacteur', 'ms-recipes-writer-ai' ); ?></label>
						<?php
						wp_dropdown_users( array(
							'name' => 'owner', 'id' => 'ms-filter-owner', 'selected' => $filters['owner'],
							'show_option_all' => __( 'Tous', 'ms-recipes-writer-ai' ), 'capability' => 'edit_posts',
						) );
						?>
					</div>
				<?php endif; ?>
				<div>
					<label for="ms-filter-search"><?php esc_html_e( 'Titre', 'ms-recipes-writer-ai' ); ?></label>
					<input type="search" id="ms-filter-search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>">
				</div>
				<div><button class="button"><?php esc_html_e( 'Filtrer', 'ms-recipes-writer-ai' ); ?></button></div>
			</form>

			<?php if ( ! $found['runs'] ) : ?>
				<?php
				MSRWA_UI::nothing(
					'' !== $filters['search'] || '' !== $filters['status'] || '' !== $filters['post'] ? __( 'Rien ne correspond', 'ms-recipes-writer-ai' ) : __( 'Aucune recette pour l’instant', 'ms-recipes-writer-ai' ),
					'' !== $filters['search'] || '' !== $filters['status'] || '' !== $filters['post']
						? __( 'Élargissez le filtre, ou repartez de la liste complète.', 'ms-recipes-writer-ai' )
						: __( 'Déposez des recettes et des photographies pour lancer un premier lot.', 'ms-recipes-writer-ai' ),
					'<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=msrwa-compose' ) ) . '">' . esc_html__( 'Nouveau lot', 'ms-recipes-writer-ai' ) . '</a>'
				);
				?>
			<?php else : ?>
				<?php self::bulk_bar(); ?>
				<?php MSRWA_UI::run_table( $found['runs'], true, 'ms-bulk-rail' ); ?>
				<?php self::pages( $found, $filters ); ?>
			<?php endif; ?>
		</section>
		<?php
		echo '</div>';
	}

	/**
	 * One decision applied to several recipes.
	 *
	 * The first time a lot of ten fails on a bad key, cancelling them one at a
	 * time is the difference between a tool somebody uses and one they dread.
	 * Deleting is an administrator's, because it destroys the record of what
	 * was spent.
	 */
	private static function bulk_bar() {
		?>
		<div class="ms-filters" id="ms-bulk">
			<div>
				<label for="ms-bulk-all"><?php esc_html_e( 'Sélection', 'ms-recipes-writer-ai' ); ?></label>
				<label><input type="checkbox" id="ms-bulk-all"> <?php esc_html_e( 'tout', 'ms-recipes-writer-ai' ); ?></label>
			</div>
			<div>
				<label for="ms-bulk-do"><?php esc_html_e( 'Action', 'ms-recipes-writer-ai' ); ?></label>
				<select id="ms-bulk-do">
					<option value="" selected><?php esc_html_e( 'Choisir…', 'ms-recipes-writer-ai' ); ?></option>
					<option value="cancel"><?php esc_html_e( 'Arrêter', 'ms-recipes-writer-ai' ); ?></option>
					<option value="retry"><?php esc_html_e( 'Reprendre', 'ms-recipes-writer-ai' ); ?></option>
					<?php if ( MSRWA_Rights::may_manage() ) : ?>
						<option value="prioritise"><?php esc_html_e( 'Faire passer devant', 'ms-recipes-writer-ai' ); ?></option>
					<?php endif; ?>
					<?php if ( MSRWA_Rights::may_delete() ) : ?>
						<option value="delete"><?php esc_html_e( 'Supprimer', 'ms-recipes-writer-ai' ); ?></option>
					<?php endif; ?>
				</select>
			</div>
			<div>
				<button class="button" id="ms-bulk-go" disabled><?php esc_html_e( 'Appliquer', 'ms-recipes-writer-ai' ); ?></button>
				<span id="ms-bulk-status" class="ms-muted" aria-live="polite"></span>
			</div>
		</div>
		<?php
	}

	/**
	 * What became of the articles in WordPress, as tabs with their counts:
	 * the question an editor asks first is "what is published, what is not".
	 */
	private static function tabs( array $filters, array $counts ) {
		$labels = array(
			'' => __( 'Tous', 'ms-recipes-writer-ai' ),
			'draft' => __( 'Brouillons', 'ms-recipes-writer-ai' ),
			'future' => __( 'Programmés', 'ms-recipes-writer-ai' ),
			'publish' => __( 'Publiés', 'ms-recipes-writer-ai' ),
			'trash' => __( 'Corbeille', 'ms-recipes-writer-ai' ),
			'deleted' => __( 'Supprimés de WordPress', 'ms-recipes-writer-ai' ),
			'none' => __( 'Sans article', 'ms-recipes-writer-ai' ),
		);
		// The other filters stay as they are; the page goes back to the first.
		$base = add_query_arg( array_filter( array(
			'page' => 'msrwa-articles',
			'state' => $filters['status'],
			'owner' => $filters['owner'] ? $filters['owner'] : '',
			's' => $filters['search'],
		), 'strlen' ), admin_url( 'admin.php' ) );
		echo '<nav class="ms-post-tabs" aria-label="' . esc_attr__( 'État dans WordPress', 'ms-recipes-writer-ai' ) . '"><ul>';
		foreach ( $labels as $bucket => $label ) {
			$count = '' === $bucket ? array_sum( $counts ) : (int) ( $counts[ $bucket ] ?? 0 );
			// An empty state is not offered, except the one being looked at.
			if ( '' !== $bucket && ! $count && $filters['post'] !== $bucket ) { continue; }
			$current = $filters['post'] === $bucket;
			$url = '' === $bucket ? $base : add_query_arg( 'post_state', $bucket, $base );
			echo '<li><a href="' . esc_url( $url ) . '"' . ( $current ? ' aria-current="page"' : '' ) . ' class="ms-post-tab ms-post-tab-' . esc_attr( '' === $bucket ? 'all' : $bucket ) . '">'
				. esc_html( $label ) . ' <span class="ms-count">' . esc_html( number_format_i18n( $count ) ) . '</span></a></li>';
		}
		echo '</ul></nav>';
	}

	private static function states() {
		return array(
			'' => __( 'Tous', 'ms-recipes-writer-ai' ),
			'moving' => __( 'En cours', 'ms-recipes-writer-ai' ),
			'attention' => __( 'Demande une décision', 'ms-recipes-writer-ai' ),
			'done' => __( 'Terminés', 'ms-recipes-writer-ai' ),
			'failed' => __( 'Échecs', 'ms-recipes-writer-ai' ),
			'cancelled' => __( 'Arrêtés', 'ms-recipes-writer-ai' ),
		);
	}

	private static function pages( array $found, array $filters ) {
		$pages = (int) ceil( $found['total'] / max( 1, $found['per_page'] ) );
		if ( $pages < 2 ) { return; }
		echo '<div class="ms-filters" style="justify-content:flex-end">';
		echo wp_kses_post( paginate_links( array(
			'base' => add_query_arg( 'paged', '%#%' ),
			'format' => '',
			'current' => max( 1, $found['page'] ),
			'total' => $pages,
			'prev_text' => '‹',
			'next_text' => '›',
		) ) );
		echo '</div>';
	}
}
