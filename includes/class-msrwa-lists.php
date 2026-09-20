<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Filtered, paginated reads for the admin lists.
 *
 * Every query is scoped to the reader: an editor without the cross-editor
 * capability can only ever see the rows they own, whatever the request asks.
 */
final class MSRWA_Lists {
	const PER_PAGE_CHOICES = array( 20, 50, 100 );

	public static function quality_labels() {
		return array( 'good' => 'Bon', 'review' => 'À vérifier', 'bad' => 'Mauvais', 'pending' => 'Non évalué', 'none' => 'Aucun article' );
	}

	public static function state_labels() {
		return array( 'encours' => 'En cours', 'completed' => 'Terminé', 'error' => 'Erreur', 'canceled' => 'Annulé' );
	}

	/** Publication states of the WordPress post behind an article. */
	public static function post_status_labels() {
		return array( 'draft' => 'Brouillon', 'pending' => 'En attente de relecture', 'publish' => 'Publié', 'future' => 'Planifié', 'private' => 'Privé', 'trash' => 'Corbeille', 'missing' => 'Brouillon supprimé' );
	}

	public static function order_labels() {
		return array( 'recent' => 'Plus récents', 'oldest' => 'Plus anciens', 'updated' => 'Mise à jour récente', 'score' => 'Qualité contenu', 'cost' => 'Coût décroissant', 'title' => 'Titre A→Z' );
	}

	/** The pipeline owns the stage vocabulary; the filter follows it. */
	public static function stages() {
		return MSRWA_Recipe::stages();
	}

	public static function stage_labels() {
		$labels = array( 'intake' => 'Réception', 'association' => 'Association', 'research' => 'Recherche web', 'canonical_recipe' => 'Recette canonique', 'article' => 'Article', 'review' => 'Relecture', 'featured_image' => 'Image principale', 'facebook_image' => 'Image Facebook', 'final_review' => 'Contrôle final', 'draft' => 'Brouillon' );
		$out = array();
		foreach ( self::stages() as $stage ) { $out[ $stage ] = $labels[ $stage ] ?? $stage; }
		return $out;
	}

	public static function can_view_all() {
		return current_user_can( 'msrwa_view_all' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Normalizes request input. Unknown values fall back to the neutral default
	 * instead of reaching a query, and the author filter is pinned to the
	 * current user when they may not read other editors.
	 */
	public static function sanitize_args( $raw, $can_view_all = null ) {
		$raw = is_array( $raw ) ? $raw : array();
		$can_view_all = null === $can_view_all ? self::can_view_all() : (bool) $can_view_all;
		$value = static function ( $key ) use ( $raw ) { return isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? sanitize_text_field( wp_unslash( $raw[ $key ] ) ) : ''; };
		$quality = sanitize_key( $value( 'msrwa_quality' ) );
		$state = sanitize_key( $value( 'msrwa_state' ) );
		$stage = sanitize_key( $value( 'msrwa_stage' ) );
		$status = sanitize_key( $value( 'msrwa_status' ) );
		$order = sanitize_key( $value( 'msrwa_order' ) );
		$per_page = absint( $value( 'msrwa_per_page' ) );
		$days = absint( $value( 'msrwa_days' ) );
		$view = 'jobs' === sanitize_key( $value( 'msrwa_view' ) ) ? 'jobs' : 'articles';
		// A filter the current list does not expose must not keep filtering it.
		if ( 'jobs' === $view ) { $status = ''; $quality = ''; } else { $stage = ''; }
		return array(
			'view'        => $view,
			'search'      => trim( mb_substr( $value( 'msrwa_search' ), 0, 120 ) ),
			'post_status' => isset( self::post_status_labels()[ $status ] ) ? $status : '',
			'quality'     => in_array( $quality, array( 'good', 'review', 'bad', 'pending' ), true ) ? $quality : '',
			'state'       => isset( self::state_labels()[ $state ] ) ? $state : '',
			'stage'       => in_array( $stage, self::stages(), true ) ? $stage : '',
			'author'      => $can_view_all ? absint( $value( 'msrwa_author' ) ) : get_current_user_id(),
			'batch'       => absint( $value( 'msrwa_batch' ) ),
			'days'        => in_array( $days, array( 1, 7, 30, 90, 365 ), true ) ? $days : 0,
			'order'       => isset( self::order_labels()[ $order ] ) ? $order : 'recent',
			'paged'       => max( 1, absint( $value( 'msrwa_paged' ) ) ),
			'per_page'    => in_array( $per_page, self::PER_PAGE_CHOICES, true ) ? $per_page : self::PER_PAGE_CHOICES[0],
		);
	}

	/** Shared WHERE fragments. Values always travel as prepared parameters. */
	public static function conditions( $args ) {
		global $wpdb;
		$where = array();
		$params = array();
		if ( ! empty( $args['author'] ) ) { $where[] = 'j.owner_id = %d'; $params[] = absint( $args['author'] ); }
		if ( ! empty( $args['batch'] ) ) { $where[] = 'j.batch_id = %d'; $params[] = absint( $args['batch'] ); }
		if ( ! empty( $args['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(j.title LIKE %s OR p.post_title LIKE %s OR j.id = %d)';
			$params[] = $like; $params[] = $like; $params[] = absint( $args['search'] );
		}
		if ( ! empty( $args['stage'] ) ) { $where[] = 'j.stage = %s'; $params[] = $args['stage']; }
		if ( ! empty( $args['days'] ) ) { $where[] = 'j.created_at >= UTC_TIMESTAMP() - INTERVAL %d DAY'; $params[] = absint( $args['days'] ); }
		if ( ! empty( $args['state'] ) ) {
			$states = array(
				'completed' => "j.status IN ('completed','needs_review')",
				'error'     => "j.status IN ('failed','uncertain')",
				'canceled'  => "j.status IN ('cancelled','canceled')",
				'encours'   => "j.status NOT IN ('completed','needs_review','failed','uncertain','cancelled','canceled')",
			);
			$where[] = $states[ $args['state'] ];
		}
		if ( ! empty( $args['quality'] ) ) {
			$quality = array(
				'good'       => "j.article_quality = 'good'",
				'review'     => "j.article_quality = 'needs_review'",
				'bad'        => "j.article_quality = 'bad'",
				'pending'    => "(j.article_quality IS NULL OR j.article_quality = 'unknown')",
			);
			$where[] = $quality[ $args['quality'] ];
		}
		if ( ! empty( $args['post_status'] ) ) {
			if ( 'missing' === $args['post_status'] ) { $where[] = 'p.ID IS NULL'; }
			else { $where[] = 'p.post_status = %s'; $params[] = $args['post_status']; }
		}
		return array( 'where' => $where, 'params' => $params );
	}

	private static function order_clause( $order ) {
		$clauses = array(
			'recent'  => 'j.id DESC',
			'oldest'  => 'j.id ASC',
			'updated' => 'j.updated_at DESC, j.id DESC',
			'score'   => "FIELD(j.article_quality,'good','needs_review','bad','unknown','not_generated') ASC, j.id DESC",
			'cost'    => 'j.cost_estimate DESC, j.id DESC',
			'title'   => 'j.title ASC, j.id DESC',
		);
		return $clauses[ $order ] ?? $clauses['recent'];
	}

	private static function query( $args, $base ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$conditions = self::conditions( $args );
		$where = array_merge( $base, $conditions['where'] );
		$clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$from = "FROM {$t['jobs']} j LEFT JOIN {$wpdb->posts} p ON p.ID = j.draft_post_id";
		$count_sql = "SELECT COUNT(*) {$from} {$clause}";
		$total = (int) ( $conditions['params'] ? $wpdb->get_var( $wpdb->prepare( $count_sql, $conditions['params'] ) ) : $wpdb->get_var( $count_sql ) );
		$per_page = (int) $args['per_page'];
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$paged = min( max( 1, (int) $args['paged'] ), $pages );
		$columns = 'j.id,j.batch_id,j.owner_id,j.title,j.status,j.stage,j.draft_post_id,j.article_quality,j.featured_quality,j.facebook_quality,j.quality_score,j.quality_passed,j.quality_checked_at,j.cost_estimate,j.correction_cycles,j.correction_cycles_json,j.attempts,j.retry_attempts,j.error_code,j.error_message,j.selected_models_json,j.created_at,j.updated_at,p.post_status,p.post_title,p.post_date';
		$sql = "SELECT {$columns} {$from} {$clause} ORDER BY " . self::order_clause( $args['order'] ) . ' LIMIT %d OFFSET %d';
		$params = array_merge( $conditions['params'], array( $per_page, ( $paged - 1 ) * $per_page ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return array( 'rows' => is_array( $rows ) ? $rows : array(), 'total' => $total, 'pages' => $pages, 'paged' => $paged, 'per_page' => $per_page );
	}

	/** Articles: one row per article the plugin produced, with its own quality. */
	public static function articles( $args ) {
		$result = self::query( $args, array( 'j.draft_post_id > 0' ) );
		foreach ( $result['rows'] as &$row ) { $row['quality'] = MSRWA_Presentation::quality( $row ); }
		unset( $row );
		return $result;
	}

	/** Jobs: the processing record, enriched with everything a diagnosis needs. */
	public static function jobs( $args ) {
		global $wpdb;
		$result = self::query( $args, array() );
		if ( empty( $result['rows'] ) ) { return $result; }
		$t = MSRWA_DB::tables();
		$ids = implode( ',', array_map( 'absint', array_column( $result['rows'], 'id' ) ) );
		$calls = $wpdb->get_results( "SELECT job_id, COUNT(*) AS calls, COALESCE(SUM(input_tokens),0) AS input_tokens, COALESCE(SUM(output_tokens),0) AS output_tokens, COALESCE(SUM(cost_estimate),0) AS cost, SUM(uncertain) AS uncertain, SUM(status <> 'success') AS failures, MIN(started_at) AS first_call, MAX(COALESCE(finished_at, started_at)) AS last_call FROM {$t['calls']} WHERE job_id IN ({$ids}) GROUP BY job_id", ARRAY_A );
		$by_job = array();
		foreach ( $calls as $call ) { $by_job[ (int) $call['job_id'] ] = $call; }
		foreach ( $result['rows'] as &$row ) {
			$row['calls'] = $by_job[ (int) $row['id'] ] ?? array( 'calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0, 'uncertain' => 0, 'failures' => 0, 'first_call' => '', 'last_call' => '' );
			$row['quality'] = MSRWA_Presentation::quality( $row );
			$row['models'] = json_decode( (string) $row['selected_models_json'], true );
			$row['models'] = is_array( $row['models'] ) ? $row['models'] : array();
			$row['cycles'] = json_decode( (string) $row['correction_cycles_json'], true );
			$row['cycles'] = is_array( $row['cycles'] ) ? $row['cycles'] : array();
			$row['duration_seconds'] = max( 0, strtotime( (string) $row['updated_at'] ) - strtotime( (string) $row['created_at'] ) );
		}
		unset( $row );
		return $result;
	}

	/** Editors that own at least one job, for the administrator filter. */
	public static function authors() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$rows = $wpdb->get_results( "SELECT owner_id, COUNT(*) AS jobs FROM {$t['jobs']} GROUP BY owner_id ORDER BY jobs DESC LIMIT 100", ARRAY_A );
		$authors = array();
		foreach ( $rows as $row ) {
			$user = get_userdata( (int) $row['owner_id'] );
			$authors[ (int) $row['owner_id'] ] = ( $user ? $user->display_name : sprintf( 'Utilisateur #%d', (int) $row['owner_id'] ) ) . ' (' . number_format_i18n( (int) $row['jobs'] ) . ')';
		}
		return $authors;
	}

	/** Counts per publication state for the article tabs, within the current scope. */
	public static function article_status_counts( $args ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$scope = self::conditions( array( 'author' => $args['author'] ?? 0 ) );
		$where = array_merge( array( 'j.draft_post_id > 0' ), $scope['where'] );
		$sql = "SELECT COALESCE(p.post_status,'missing') AS post_status, COUNT(*) AS count FROM {$t['jobs']} j LEFT JOIN {$wpdb->posts} p ON p.ID = j.draft_post_id WHERE " . implode( ' AND ', $where ) . ' GROUP BY COALESCE(p.post_status,\'missing\')';
		$rows = $scope['params'] ? $wpdb->get_results( $wpdb->prepare( $sql, $scope['params'] ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		$counts = array();
		foreach ( $rows as $row ) { $counts[ (string) $row['post_status'] ] = (int) $row['count']; }
		return $counts;
	}
}
