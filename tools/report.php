<?php
/**
 * The human report: one self-contained HTML page from one engine run.
 *
 *   php tools/lab.php report --run=tools/runs/<run>.json --output=report.html
 *
 * The input is a MSRWA_Result as the engine returns it — artifacts, steps,
 * totals, errors and events — and nothing else. It used to be thirteen
 * separate files named on the command line, which meant the report could be
 * assembled from runs that never belonged to the same recipe.
 *
 * Nothing here decides anything. The corrections are applied by the engine and
 * the verdict is the judge's; this file only shows them, in the order the run
 * actually happened, with the detail folded away until a reader asks for it.
 */

function report_h( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }

/**
 * The article, as HTML this page is willing to render.
 *
 * Everything else on this page is escaped; the article was concatenated in
 * whole because it is meant to be read as formatted prose. But it is model
 * output that has travelled through a saved run, and this report gets opened
 * in a browser and shared. So it is rebuilt from an allowlist: the tags a
 * recipe needs, no attributes except a safe href, and nothing else survives.
 */
function report_article_html( $html ) {
	$html = (string) $html;
	if ( '' === trim( $html ) ) { return ''; }

	// Whole elements whose content is never prose, removed with their contents.
	$html = preg_replace( '#<(script|style|iframe|object|embed|form|template|svg|math)\b[^>]*>.*?</\1\s*>#is', '', $html );
	$html = preg_replace( '#<(script|style|iframe|object|embed|form|template|svg|math)\b[^>]*/?>#i', '', $html );
	$html = preg_replace( '#<!--(?!nextpage).*?-->#s', '', $html );

	$allowed = array( 'h2', 'h3', 'h4', 'p', 'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'br', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'a' );
	return preg_replace_callback( '#</?([a-z0-9]+)\b([^>]*)>#i', static function ( $tag ) use ( $allowed ) {
		$name = strtolower( $tag[1] );
		if ( ! in_array( $name, $allowed, true ) ) { return ''; }
		if ( '/' === substr( $tag[0], 1, 1 ) ) { return '</' . $name . '>'; }
		// One attribute survives, and only when its scheme is one a reader can trust.
		if ( 'a' === $name && preg_match( '#\bhref\s*=\s*("|\')(.*?)\1#is', $tag[2], $href ) ) {
			$url = trim( html_entity_decode( $href[2], ENT_QUOTES, 'UTF-8' ) );
			if ( preg_match( '#^(https?://|/|\#)#i', $url ) ) {
				return '<a href="' . report_h( $url ) . '" rel="nofollow noopener">';
			}
		}
		return '<' . $name . '>';
	}, $html );
}

function report_is_list( $value ) { return is_array( $value ) && array_keys( $value ) === range( 0, count( $value ) - 1 ); }

/** Any decoded answer as readable HTML, whatever shape it arrived in. */
function report_value( $value, $depth = 0 ) {
	if ( null === $value || '' === $value || array() === $value ) { return '<span class="muted">Non renseigné</span>'; }
	if ( is_bool( $value ) ) { return '<span class="pill ' . ( $value ? 'ok' : 'warn' ) . '">' . ( $value ? 'Oui' : 'Non' ) . '</span>'; }
	if ( is_scalar( $value ) ) { return '<span>' . nl2br( report_h( $value ) ) . '</span>'; }
	if ( report_is_list( $value ) ) {
		$html = '<div class="list">';
		foreach ( $value as $item ) { $html .= '<div class="list-item">' . report_value( $item, $depth + 1 ) . '</div>'; }
		return $html . '</div>';
	}
	$html = '<dl class="data">';
	foreach ( $value as $key => $item ) {
		$html .= '<div><dt>' . report_h( str_replace( '_', ' ', $key ) ) . '</dt><dd>' . report_value( $item, $depth + 1 ) . '</dd></div>';
	}
	return $html . '</dl>';
}

/** A link, but only to somewhere a reader can safely be sent. */
function report_link( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) { return '<span class="muted">Non renseigné</span>'; }
	if ( ! preg_match( '#^https?://#i', $url ) ) { return report_h( $url ) . ' <span class="pill warn">schéma refusé</span>'; }
	return '<a href="' . report_h( $url ) . '" rel="nofollow noopener">' . report_h( $url ) . '</a>';
}

/** A section the reader opens only if they want the detail. */
function report_fold( $summary, $body, $open = false ) {
	return '<details class="fold"' . ( $open ? ' open' : '' ) . '><summary>' . report_h( $summary ) . '</summary><div class="fold-body">' . $body . '</div></details>';
}

/**
 * The real photographs research found, and what the vision pass read from their
 * bytes. Both halves matter: the provenance shows the observations came from a
 * real source, and the observations show what was taken from it. Nothing here is
 * republished — the links point at the source, the pictures stay with their owners.
 */
function report_visual_provenance( $research ) {
	$references = (array) ( $research['visual_references'] ?? array() );
	$observations = (array) ( $research['visual_observations'] ?? array() );
	if ( ! $references && ! $observations ) { return '<p class="muted">Aucune photographie réelle n’a été trouvée pour cette recette.</p>'; }

	$by_url = array();
	foreach ( $observations as $observation ) {
		if ( is_array( $observation ) && ! empty( $observation['image_url'] ) ) { $by_url[ (string) $observation['image_url'] ] = $observation; }
	}

	$html = '<div class="list">';
	$index = 0;
	foreach ( $references as $reference ) {
		if ( ! is_array( $reference ) ) { continue; }
		$index++;
		$image = (string) ( $reference['image_url'] ?? '' );
		$source = (string) ( $reference['source_url'] ?? '' );
		$observation = $by_url[ $image ] ?? array();
		$inspected = ! empty( $observation );
		$tier = (int) ( $reference['tier'] ?? 0 );
		$html .= '<div class="list-item"><h3>Photographie ' . $index . ' <span class="pill ' . ( $inspected ? 'ok' : 'warn' ) . '">' . ( $inspected ? 'analysée' : 'non analysée' ) . '</span>'
			. ( $tier ? ' <span class="pill">' . ( 1 === $tier ? 'ce plat' : 'plat voisin' ) . '</span>' : '' ) . '</h3>';
		$html .= '<p><strong>' . report_h( $reference['title'] ?? 'Sans titre' ) . '</strong></p>';
		$html .= '<p class="muted">Page source : ' . report_link( $source ) . '</p>';
		$html .= '<p class="muted">Fichier image : ' . report_link( $image ) . '</p>';
		if ( $inspected ) {
			foreach ( array( 'observable_details' => 'Ce qui est visible', 'composition' => 'Composition', 'colours' => 'Couleurs', 'textures' => 'Textures', 'uncertainties' => 'Incertitudes' ) as $key => $label ) {
				$value = $observation[ $key ] ?? '';
				if ( is_array( $value ) ) { $value = implode( ' ', array_filter( array_map( 'strval', $value ) ) ); }
				$value = trim( (string) $value );
				if ( '' === $value ) { continue; }
				$html .= '<p><strong>' . report_h( $label ) . ' :</strong> ' . report_h( $value ) . '</p>';
			}
		}
		$html .= '</div>';
	}
	$html .= '</div>';
	$html .= '<p class="muted" style="margin-top:18px">' . count( $references ) . ' photographie(s) citée(s), ' . count( $observations ) . ' analysée(s) à partir des octets réels.</p>';
	return $html;
}

/**
 * The findings, written for the editor who has to act on them rather than as a
 * JSON dump. Blocking findings state what must change; minor ones are shown just
 * as prominently, because they are precisely the calls an editor makes and the
 * reason a minor finding exists at all is that a person should decide.
 */
function report_findings( $verdict ) {
	$findings = array_values( array_filter( (array) ( ( is_array( $verdict ) ? $verdict : array() )['findings'] ?? array() ), 'is_array' ) );
	$groups = array( 'blocking' => array(), 'minor' => array() );
	foreach ( $findings as $finding ) {
		$severity = 'blocking' === ( $finding['severity'] ?? '' ) ? 'blocking' : 'minor';
		$groups[ $severity ][] = $finding;
	}
	$targets = array( 'article' => 'Article', 'featured_image' => 'Image à la une', 'facebook_image' => 'Collage Facebook', 'consistency' => 'Cohérence' );

	$html = '';
	foreach ( array(
		'blocking' => array( 'Constats bloquants', 'À corriger avant publication.', 'bad' ),
		'minor' => array( 'Constats mineurs — à l’appréciation de l’éditeur', 'Rien n’empêche de publier. Chacun est un choix : corriger, ou accepter et passer.', 'warn' ),
	) as $severity => $meta ) {
		list( $title, $note, $tone ) = $meta;
		$rows = $groups[ $severity ];
		$html .= '<h3 class="finding-title">' . report_h( $title ) . ' <span class="pill ' . ( $rows ? $tone : 'ok' ) . '">' . count( $rows ) . '</span></h3>';
		if ( ! $rows ) {
			$html .= '<p class="muted">Aucun.</p>';
			continue;
		}
		$html .= '<p class="muted">' . report_h( $note ) . '</p><div class="findings">';
		foreach ( $rows as $finding ) {
			$target = (string) ( $finding['target'] ?? '' );
			$html .= '<article class="finding ' . report_h( $severity ) . '">';
			$html .= '<p class="finding-head"><span class="pill ' . ( 'blocking' === $severity ? 'bad' : 'warn' ) . '">' . ( 'blocking' === $severity ? 'Bloquant' : 'Mineur' ) . '</span> <strong>' . report_h( $targets[ $target ] ?? $target ) . '</strong></p>';
			$quote = trim( (string) ( $finding['quote'] ?? '' ) );
			if ( '' !== $quote ) { $html .= '<blockquote class="finding-quote">' . report_h( $quote ) . '</blockquote>'; }
			$html .= '<p><span class="finding-label">Ce qui ne va pas</span> ' . report_h( $finding['reason'] ?? '' ) . '</p>';
			$fix = trim( (string) ( $finding['fix'] ?? '' ) );
			if ( '' !== $fix ) { $html .= '<p><span class="finding-label">Correction proposée</span> ' . report_h( $fix ) . '</p>'; }
			$html .= '</article>';
		}
		$html .= '</div>';
	}
	return $html;
}

/**
 * The approval verdict: the decision, then one card per artifact it covers.
 *
 * The decision is not a fifth card. It is what the other four add up to, so it
 * reads as the banner above them rather than as their peer.
 */
function report_approval_cards( $verdict ) {
	$verdict = is_array( $verdict ) ? $verdict : array();
	// Only a real boolean approves. The string "false" is truthy in PHP, and a
	// report that shows it as "Approuvé" is worse than one that shows nothing.
	$approved = true === ( $verdict['approved'] ?? null );
	$malformed = array_key_exists( 'approved', $verdict ) && ! is_bool( $verdict['approved'] );
	$cards = '';
	$labels = array( 'article' => 'Article', 'featured_image' => 'Image à la une', 'facebook_image' => 'Collage Facebook', 'consistency' => 'Cohérence des trois' );
	foreach ( $labels as $key => $label ) {
		$part = isset( $verdict[ $key ] ) && is_array( $verdict[ $key ] ) ? $verdict[ $key ] : array();
		$mark = (string) ( $part['verdict'] ?? '—' );
		$words = array( 'good' => 'Conforme', 'reservations' => 'Réserves', 'bad' => 'Non conforme' );
		$realism = isset( $part['realism'] ) ? '<p class="muted">Réalisme photographique : <strong>' . report_h( $words[ (string) $part['realism'] ] ?? $part['realism'] ) . '</strong></p>' : '';
		$cards .= '<div class="card"><h3>' . report_h( $label ) . '</h3><p><span class="pill ' . ( 'good' === $mark ? 'ok' : ( 'bad' === $mark ? 'bad' : 'warn' ) ) . '">' . report_h( $words[ $mark ] ?? $mark ) . '</span></p>' . $realism . '<p class="muted">' . report_h( $part['summary'] ?? '' ) . '</p></div>';
	}
	$label = $malformed ? 'Verdict illisible' : ( $approved ? 'Approuvé' : 'Refusé' );
	return '<p class="decision ' . ( $approved ? 'ok' : 'warn' ) . '">' . report_h( $label ) . '</p>'
		. ( $malformed ? '<p class="muted">Le champ « approved » n’est pas un booléen ; ce verdict ne vaut pas approbation.</p>' : '' )
		. '<div class="summary-grid">' . $cards . '</div>';
}

/** Corrections the engine applied to the text, and the ones only a person can make. */
function report_corrections( $corrected ) {
	$applied = (array) ( $corrected['corrections_applied'] ?? array() );
	$pending = (array) ( $corrected['corrections_for_the_editor'] ?? array() );
	if ( ! $applied && ! $pending ) { return '<p class="muted">La vérification des faits n’a demandé aucune correction.</p>'; }

	$html = '';
	foreach ( array(
		array( $applied, 'Appliquées automatiquement', 'La phrase citée a été remplacée mot pour mot. Aucun modèle n’est intervenu.', 'ok' ),
		array( $pending, 'À appliquer à la main', 'La phrase citée est introuvable telle quelle dans le HTML — une balise la coupe. À vous de décider.', 'warn' ),
	) as $group ) {
		list( $rows, $title, $note, $tone ) = $group;
		$html .= '<h3 class="finding-title">' . report_h( $title ) . ' <span class="pill ' . ( $rows ? $tone : 'ok' ) . '">' . count( $rows ) . '</span></h3>';
		if ( ! $rows ) { $html .= '<p class="muted">Aucune.</p>'; continue; }
		$html .= '<p class="muted">' . report_h( $note ) . '</p><div class="findings">';
		foreach ( $rows as $row ) {
			$html .= '<article class="finding"><blockquote class="finding-quote">' . report_h( $row['before'] ?? '' ) . '</blockquote>'
				. '<p><span class="finding-label">Remplacée par</span> ' . report_h( $row['after'] ?? '' ) . '</p>'
				. '<p><span class="finding-label">Pourquoi</span> ' . report_h( $row['reason'] ?? '' ) . '</p>'
				. ( empty( $row['source'] ) ? '' : '<p class="muted">Source : ' . report_link( $row['source'] ) . '</p>' )
				. '</article>';
		}
		$html .= '</div>';
	}
	return $html;
}

/** A generated image, embedded so the page needs no companion file. */
function report_image( $artifact, $title, $caption ) {
	$path = (string) ( $artifact['path'] ?? '' );
	if ( '' === $path || ! is_readable( $path ) ) { return '<figure class="image-card"><figcaption class="muted">' . report_h( $title ) . ' — fichier introuvable.</figcaption></figure>'; }
	$data = 'data:' . report_h( $artifact['mime'] ?? 'image/webp' ) . ';base64,' . base64_encode( (string) file_get_contents( $path ) );
	return '<figure class="image-card"><img src="' . $data . '" alt="' . report_h( $title ) . '"><figcaption><strong>' . report_h( $title ) . '</strong> ' . report_h( $caption ) . '</figcaption></figure>';
}

/** One row per step, in the order the run performed them. */
function report_steps( $steps ) {
	$rows = '';
	foreach ( (array) $steps as $step ) {
		$label = MSRWA_Engine_Steps::get( $step['step'] )['label'] ?? $step['step'];
		$failed = '' !== (string) ( $step['error'] ?? '' );
		$scored = null !== ( $step['passed'] ?? null );
		$verdict = $failed ? 'Échec' : ( $scored ? $step['passed'] . '/' . $step['total'] : 'Produit' );
		$tone = $failed ? 'bad' : ( $scored && $step['passed'] < $step['total'] ? 'warn' : 'ok' );
		// data-label carries the column name so the same markup can stack on a phone
		// instead of forcing the page sideways.
		$rows .= '<tr>'
			. '<td data-label="Étape">' . report_h( $label ) . ' <span class="muted">(' . report_h( $step['step'] ) . ')</span></td>'
			. '<td data-label="Modèle">' . report_h( '' !== (string) $step['model'] ? $step['model'] : 'aucun' ) . '</td>'
			. '<td data-label="Essais">' . (int) ( $step['attempts'] ?? 1 ) . '</td>'
			. '<td data-label="Temps">' . report_h( $step['seconds'] ) . ' s</td>'
			. '<td data-label="Entrée">' . number_format( (int) ( $step['usage']['input_tokens'] ?? 0 ) ) . '</td>'
			. '<td data-label="Sortie">' . number_format( (int) ( $step['usage']['output_tokens'] ?? 0 ) ) . '</td>'
			. '<td data-label="Coût">$' . number_format( (float) $step['cost_usd'], 4 ) . '</td>'
			. '<td data-label="Contrat"><span class="pill ' . $tone . '">' . report_h( $verdict ) . '</span></td>'
			. '</tr>';
	}
	return $rows;
}

/** Every check a step ran, so a score can be read rather than trusted. */
function report_scorecards( $steps ) {
	$html = '';
	foreach ( (array) $steps as $step ) {
		if ( empty( $step['checks'] ) ) { continue; }
		$label = MSRWA_Engine_Steps::get( $step['step'] )['label'] ?? $step['step'];
		$rows = '';
		foreach ( $step['checks'] as $name => $check ) {
			$rows .= '<div><dt>' . ( ! empty( $check['pass'] ) ? '<span class="pill ok">ok</span>' : '<span class="pill warn">non</span>' ) . ' ' . report_h( $name ) . '</dt><dd>' . report_h( $check['detail'] ) . '</dd></div>';
		}
		$html .= report_fold( $label . ' — ' . $step['passed'] . '/' . $step['total'], '<dl class="data">' . $rows . '</dl>' );
	}
	return $html;
}

/** What the run did, second by second: waves, retries, warnings and failures. */
function report_timeline( $events ) {
	$rows = '';
	foreach ( (array) $events as $event ) {
		// The call and input detail have their own tables; the timeline is the story.
		if ( in_array( $event['kind'], array( 'start', 'attempt', 'call', 'input', 'config' ), true ) ) { continue; }
		$tone = array( 'error' => 'bad', 'retry' => 'warn', 'warning' => 'warn', 'decision' => 'warn' );
		$rows .= '<div><dt><span class="pill ' . ( $tone[ $event['kind'] ] ?? 'ok' ) . '">' . report_h( $event['kind'] ) . '</span> ' . report_h( $event['at'] ) . ' s</dt><dd>' . report_h( $event['message'] ) . '</dd></div>';
	}
	return '<dl class="data">' . $rows . '</dl>';
}


/**
 * The canonical recipe as a recipe, not as a key/value dump.
 *
 * It is the reference every figure in the article and every element in the
 * images is measured against, so it earns being read at a glance. The fields
 * shown are the ones the rich result needs; the rest stays in the raw JSON.
 */
function report_recipe_card( $recipe ) {
	if ( ! $recipe ) { return '<p class="muted">Aucune recette canonique n’a été produite.</p>'; }

	$figures = '';
	foreach ( array( 'servings' => 'Parts', 'prep_minutes' => 'Préparation', 'cook_minutes' => 'Cuisson', 'total_minutes' => 'Total', 'calories_estimate' => 'Calories' ) as $key => $label ) {
		if ( ! isset( $recipe[ $key ] ) || '' === $recipe[ $key ] || null === $recipe[ $key ] ) { continue; }
		$unit = false !== strpos( $key, 'minutes' ) ? ' min' : ( 'calories_estimate' === $key ? ' kcal' : '' );
		$figures .= '<div class="card"><div class="muted">' . report_h( $label ) . '</div><div class="kpi">' . report_h( $recipe[ $key ] ) . report_h( $unit ) . '</div></div>';
	}

	$ingredients = '';
	foreach ( (array) ( $recipe['ingredients'] ?? array() ) as $item ) {
		if ( ! is_array( $item ) ) { continue; }
		$unit = trim( (string) ( $item['unit'] ?? '' ) );
		$name = trim( (string) ( $item['name'] ?? '' ) );
		// Models name the unit after the thing itself — "1 pâte, pâte sablée",
		// "2 œufs, œufs". Printing both reads as a stutter, so the unit goes when
		// the name already opens with it.
		if ( '' !== $unit && 0 === mb_stripos( $name, $unit ) ) { $unit = ''; }
		$measure = trim( (string) ( $item['quantity'] ?? '' ) . ' ' . $unit );
		$ingredients .= '<li>' . ( '' !== $measure ? '<strong>' . report_h( $measure ) . '</strong> ' : '' ) . report_h( $name ) . '</li>';
	}

	$steps = '';
	foreach ( (array) ( $recipe['steps'] ?? array() ) as $step ) {
		$text = is_array( $step ) ? (string) ( $step['text'] ?? '' ) : (string) $step;
		if ( '' === trim( $text ) ) { continue; }
		$steps .= '<li>' . report_h( $text ) . '</li>';
	}

	$meta = array();
	foreach ( array( 'cuisine' => 'Cuisine', 'recipe_category' => 'Catégorie', 'difficulty' => 'Difficulté' ) as $key => $label ) {
		if ( ! empty( $recipe[ $key ] ) ) { $meta[] = report_h( $label ) . ' : <strong>' . report_h( $recipe[ $key ] ) . '</strong>'; }
	}

	return '<div class="recipe-card">'
		. '<h3>' . report_h( $recipe['title'] ?? 'Sans titre' ) . '</h3>'
		. ( empty( $recipe['description'] ) ? '' : '<p>' . report_h( $recipe['description'] ) . '</p>' )
		. ( $meta ? '<p class="muted">' . implode( ' · ', $meta ) . '</p>' : '' )
		. ( $figures ? '<div class="summary-grid">' . $figures . '</div>' : '' )
		. ( $ingredients ? '<h4>Ingrédients</h4><ul class="ingredients">' . $ingredients . '</ul>' : '' )
		. ( $steps ? '<h4>Préparation</h4><ol class="steps">' . $steps . '</ol>' : '' )
		. '</div>';
}

/**
 * What the run was configured with, and who decided it.
 *
 * Every value the engine uses is a configuration key, so "what was this run
 * with" is answerable — but only if the answer is shown. `engine` means the
 * measured default; `caller` and `run` mean somebody overrode it.
 */
function report_configuration( $config ) {
	$provenance = (array) ( $config['_provenance'] ?? array() );
	unset( $config['_provenance'] );
	if ( ! $config ) { return '<p class="muted">La configuration de ce passage n’a pas été enregistrée.</p>'; }

	$words = array( 'engine' => 'valeur par défaut du moteur', 'caller' => 'définie par l’appelant', 'run' => 'demandée pour ce passage' );
	$rows = '';
	foreach ( $config as $key => $value ) {
		$layer = (string) ( $provenance[ $key ] ?? 'engine' );
		$tone = 'engine' === $layer ? '' : 'warn';
		$rows .= '<div><dt>' . report_h( str_replace( '_', ' ', $key ) ) . ' <span class="pill ' . $tone . '">' . report_h( $words[ $layer ] ?? $layer ) . '</span></dt><dd>' . report_value( $value ) . '</dd></div>';
	}
	$overridden = array_keys( array_filter( $provenance, static function ( $layer ) { return 'engine' !== $layer; } ) );
	return '<p class="muted">' . ( $overridden ? report_h( implode( ', ', $overridden ) ) . ' — le reste est la valeur par défaut du moteur.' : 'Rien n’a été surchargé : ce passage a tourné entièrement sur les valeurs par défaut du moteur.' ) . '</p>'
		. '<dl class="data">' . $rows . '</dl>';
}

/**
 * One line per provider call: which endpoint answered, on what, how many tokens
 * went each way, how many the provider served from its own cache, and what it
 * cost. A bill that surprises somebody is explained from this table.
 */
function report_calls( $events ) {
	$rows = '';
	foreach ( (array) $events as $event ) {
		if ( 'call' !== $event['kind'] ) { continue; }
		$data = (array) $event['data'];
		$usage = (array) ( $data['usage'] ?? array() );
		$cached = (int) ( $usage['cached_input_tokens'] ?? 0 );
		$rows .= '<tr>'
			. '<td data-label="Étape">' . report_h( $event['step'] ) . '</td>'
			. '<td data-label="Modèle">' . report_h( $data['model'] ?? '' ) . ( empty( $data['tier'] ) ? '' : ' <span class="muted">(' . report_h( $data['tier'] ) . ')</span>' ) . '</td>'
			. '<td data-label="Point d’accès">' . report_h( preg_replace( '#^https?://([^/]+).*$#', '$1', (string) ( $data['endpoint'] ?? '' ) ) ) . '</td>'
			. '<td data-label="Temps">' . report_h( $data['seconds'] ?? 0 ) . ' s</td>'
			. '<td data-label="Entrée">' . number_format( (int) ( $usage['input_tokens'] ?? 0 ) ) . '</td>'
			. '<td data-label="Depuis le cache">' . ( $cached ? number_format( $cached ) . ' (' . round( 100 * (float) ( $data['cached_ratio'] ?? 0 ) ) . '%)' : '—' ) . '</td>'
			. '<td data-label="Sortie">' . number_format( (int) ( $usage['output_tokens'] ?? 0 ) ) . '</td>'
			. '<td data-label="Coût">' . ( empty( $data['priced'] ) ? '<span class="pill warn">tarif inconnu</span>' : '$' . number_format( (float) $data['cost_usd'], 4 ) ) . '</td>'
			. '</tr>';
	}
	if ( '' === $rows ) { return '<p class="muted">Aucun appel fournisseur n’a été enregistré pour ce passage.</p>'; }
	return '<div class="table-wrap"><table><thead><tr><th>Étape</th><th>Modèle</th><th>Point d’accès</th><th>Temps</th><th>Entrée</th><th>Depuis le cache</th><th>Sortie</th><th>Coût</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
}

/** What each step was handed before it ran: its prompt, its size, its artifacts. */
function report_inputs( $events ) {
	$rows = '';
	foreach ( (array) $events as $event ) {
		if ( 'input' !== $event['kind'] ) { continue; }
		$data = (array) $event['data'];
		$attached = (array) ( $data['attached'] ?? array() );
		$rows .= '<div><dt>' . report_h( $event['step'] ) . '</dt><dd>' . report_h( $event['message'] )
			. ( $attached ? '<br><span class="muted">Artefacts : ' . report_h( implode( ', ', $attached ) ) . '</span>' : '' )
			. '</dd></div>';
	}
	return $rows ? '<dl class="data">' . $rows . '</dl>' : '<p class="muted">Rien n’a été enregistré.</p>';
}

/** The whole page. */
function report_render( array $run ) {
	$artifacts = (array) ( $run['artifacts'] ?? array() );
	$totals = (array) ( $run['totals'] ?? array() );
	$brief = (array) ( $artifacts['brief'] ?? array() );
	$research = (array) ( $artifacts['research'] ?? array() );
	$canonical = (array) ( $artifacts['canonical'] ?? array() );
	$article = (array) ( $artifacts['article'] ?? array() );
	$corrected = (array) ( $artifacts['corrected'] ?? array() );
	$proofread = (array) ( $artifacts['proofread'] ?? array() );
	$approval = (array) ( $artifacts['approval'] ?? array() );

	// What a reader would get: the latest version of the text that exists.
	$content = report_article_html( (string) ( $proofread['content_html'] ?? $corrected['content_html'] ?? $article['content_html'] ?? '' ) );
	$content = str_replace( '<!--nextpage-->', '<div class="page-break"><span>Deuxième partie</span></div>', $content );

	$title = (string) ( $canonical['title'] ?? $article['title'] ?? $brief['title'] ?? 'Recette' );
	$editor = array(
		'Titre demandé' => $brief['title'] ?? '',
		'Consigne de l’éditeur' => $brief['text'] ?? '',
		'Point de départ' => $brief['type'] ?? 'title',
		'Images fournies par l’éditeur' => count( (array) ( $brief['images'] ?? array() ) ),
	);

	$metadata = $article;
	unset( $metadata['content_html'], $metadata['content_html_part2'] );
	$language = $proofread;
	unset( $language['content_html'] );

	$buckets = (array) ( $totals['buckets'] ?? array() );
	$bucket_cards = '';
	foreach ( array( 'article' => 'Texte', 'featured' => 'Image à la une', 'facebook' => 'Collage', 'other' => 'Recherche et contrôles' ) as $key => $label ) {
		$bucket_cards .= '<div class="card"><div class="muted">' . report_h( $label ) . '</div><div class="kpi">$' . number_format( (float) ( $buckets[ $key ] ?? 0 ), 4 ) . '</div></div>';
	}

	$errors = '';
	foreach ( (array) ( $run['errors'] ?? array() ) as $error ) {
		$errors .= '<article class="finding blocking"><p class="finding-head"><span class="pill bad">Échec</span> <strong>' . report_h( $error['step'] ) . '</strong></p><p>' . report_h( $error['message'] ) . '</p></article>';
	}

	$raw = '';
	foreach ( array( 'Recherche' => $research, 'Recette canonique' => $canonical, 'Métadonnées article' => $metadata, 'Corrections factuelles' => $corrected, 'Correction de langue' => $language, 'Revue éditoriale' => $artifacts['review'] ?? array(), 'Vérification des faits' => $artifacts['fact_check'] ?? array(), 'Approbation finale' => $approval, 'Configuration effective' => $artifacts['config'] ?? array() ) as $label => $data ) {
		$raw .= '<details><summary>' . report_h( $label ) . ' — JSON complet</summary><pre>' . report_h( json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
	}

	$prompts = '';
	foreach ( array( 'featured' => 'Image à la une', 'facebook' => 'Collage Facebook' ) as $key => $label ) {
		if ( empty( $artifacts[ $key ]['prompt'] ) ) { continue; }
		$prompts .= '<details><summary>' . report_h( $label ) . ' — prompt exact</summary><pre>' . report_h( $artifacts[ $key ]['prompt'] ) . '</pre></details>';
	}

	$css = trim( (string) file_get_contents( __DIR__ . '/report.css' ) );

	return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . report_h( $title ) . ' — Rapport complet</title><style>' . $css . '</style></head><body><main>'
		. '<header class="hero"><div class="eyebrow">Test laboratoire · une exécution du moteur</div><h1>' . report_h( $title ) . '</h1><p>Rapport autonome : le brief, la recherche, la recette canonique, l’article final, les contrôles, les coûts, les temps et les deux visuels. Aucun fichier externe n’est nécessaire.</p></header>'

		. '<section class="section"><h2>Résumé de l’exécution</h2><div class="summary-grid">'
		. '<div class="card"><div class="muted">Temps cumulé</div><div class="kpi">' . number_format( (float) ( $totals['seconds'] ?? 0 ), 1 ) . ' s</div></div>'
		. '<div class="card"><div class="muted">Coût cumulé</div><div class="kpi">$' . number_format( (float) ( $totals['cost_usd'] ?? 0 ), 4 ) . '</div></div>'
		. '<div class="card"><div class="muted">Jetons</div><div class="kpi">' . number_format( (int) ( $totals['input_tokens'] ?? 0 ) + (int) ( $totals['output_tokens'] ?? 0 ) ) . '</div><div class="muted">' . number_format( (int) ( $totals['input_tokens'] ?? 0 ) ) . ' entrée · ' . number_format( (int) ( $totals['output_tokens'] ?? 0 ) ) . ' sortie</div></div>'
		. '<div class="card"><div class="muted">Issue</div><div class="kpi"><span class="pill ' . ( empty( $run['ok'] ) ? 'warn' : 'ok' ) . '">' . ( empty( $run['ok'] ) ? 'Avec erreurs' : 'Complet' ) . '</span></div></div>'
		. '</div>'
		. '<h3>Où va l’argent</h3><div class="summary-grid">' . $bucket_cards . '</div>'
		. '<p class="muted">Les coûts sont des estimations calculées avec les tarifs publiés, jamais une facture. Chaque essai est compté, y compris ceux qu’un nouvel essai a remplacés.</p>'
		. '<div class="table-wrap"><table><thead><tr><th>Étape</th><th>Modèle</th><th>Essais</th><th>Temps</th><th>Entrée</th><th>Sortie</th><th>Coût</th><th>Contrat</th></tr></thead><tbody>' . report_steps( $run['steps'] ?? array() ) . '</tbody></table></div>'
		. ( '' === $errors ? '' : '<div class="findings-wrap"><h3 class="finding-title">Échecs</h3><div class="findings">' . $errors . '</div></div>' )
		. '</section>'

		. '<section class="section"><h2>1 · Brief de l’éditeur</h2><p class="muted">Le point de départ : ce que l’éditeur a demandé, avant tout appel.</p>' . report_value( $editor ) . '</section>'

		. '<section class="section"><h2>2 · Photographies réelles trouvées et analysées</h2><p class="muted">La recherche cite des photographies réelles du plat, puis chacune est téléchargée et analysée à partir de ses octets. <strong>Aucune de ces images n’est republiée</strong> : seules les observations servent, et elles n’établissent qu’une apparence — jamais un ingrédient, une quantité ni une étape.</p>' . report_fold( 'Voir les photographies et ce qui en a été lu', report_visual_provenance( $research ) ) . '</section>'

		. '<section class="section"><h2>3 · Recherche complète</h2><p class="muted">Les faits sourcés sur lesquels la recette et l’article s’appuient.</p>' . report_fold( 'Ouvrir le dossier de recherche', report_value( $research ) ) . '</section>'

		. '<section class="section"><h2>4 · Recette canonique</h2><p class="muted">La référence : tout chiffre de l’article et tout élément des images s’y mesure. Ce sont les champs que lit le résultat enrichi.</p>'
		. report_recipe_card( $canonical )
		. report_fold( 'Tous les champs de la recette', report_value( $canonical ) ) . '</section>'

		. '<section class="section"><h2>5 · Article final prêt à publier</h2><article class="article">' . $content . '</article></section>'

		. '<section class="section"><h2>6 · SEO, publication et données éditoriales</h2>' . report_fold( 'Ouvrir les métadonnées de publication', report_value( $metadata ) ) . '</section>'

		. '<section class="section"><h2>7 · Revue, vérification des faits et corrections</h2><p class="muted">Deux relectures indépendantes, puis ce que le moteur a réellement changé dans le texte.</p>'
		. '<div class="findings-wrap">' . report_corrections( $corrected ) . '</div>'
		. report_fold( 'Revue éditoriale (review)', report_value( $artifacts['review'] ?? array() ) )
		. report_fold( 'Vérification des faits (fact_check)', report_value( $artifacts['fact_check'] ?? array() ) )
		. report_fold( 'Correction de langue (proofread)', report_value( $language ) )
		. '</section>'

		. '<section class="section"><h2>8 · Visuels générés</h2><div class="image-grid">'
		. report_image( $artifacts['featured'] ?? array(), 'Image à la une', 'Photographie culinaire éditoriale, ' . report_h( $artifacts['featured']['size'] ?? '' ) . '.' )
		. report_image( $artifacts['facebook'] ?? array(), 'Collage Facebook', 'Progression culinaire en panneaux, ' . report_h( $artifacts['facebook']['size'] ?? '' ) . ', sans texte.' )
		. '</div></section>'

		. '<section class="section"><h2>9 · Approbation finale</h2>'
		. '<p class="muted">Un seul appel voit l’article et les deux images ensemble, le seul moment où les trois peuvent être confrontés. Le réalisme photographique et les ingrédients principaux décident pour les images ; la recette et l’article sont tenus à ce que la recherche documente.</p>'
		. report_approval_cards( $approval )
		. '<div class="findings-wrap">' . report_findings( $approval ) . '</div></section>'

		. '<section class="section"><h2>Contrôles, étape par étape</h2><p class="muted">Chaque vérification qu’une étape a passée, avec ce qu’elle a mesuré. Un score se lit, il ne se croit pas.</p>' . report_scorecards( $run['steps'] ?? array() ) . '</section>'

		. '<section class="section"><h2>Appels aux fournisseurs</h2><p class="muted">Ce que chaque appel a réellement fait. Les coûts sont des estimations calculées avec les tarifs configurés, jamais une facture.</p>' . report_calls( $run['events'] ?? array() ) . '</section>'

		. '<section class="section"><h2>Ce que chaque étape a reçu</h2><p class="muted">D’où venait son prompt, sa taille, son plafond de sortie et les artefacts qui l’accompagnaient.</p>' . report_fold( 'Ouvrir le détail des entrées', report_inputs( $run['events'] ?? array() ) ) . '</section>'

		. '<section class="section"><h2>Configuration de ce passage</h2><p class="muted">Rien n’est figé dans le moteur : chaque valeur ci-dessous est une clé de configuration, et chacune dit qui l’a décidée.</p>' . report_fold( 'Ouvrir la configuration effective', report_configuration( (array) ( $artifacts['config'] ?? array() ) ) ) . '</section>'

		. '<section class="section"><h2>Déroulé de l’exécution</h2><p class="muted">Les vagues, les reprises et les avertissements, dans l’ordre où ils se sont produits.</p>' . report_fold( 'Ouvrir le déroulé', report_timeline( $run['events'] ?? array() ) ) . '</section>'

		. '<section class="section"><h2>Prompts et données brutes</h2><p>Les clés d’API ne figurent jamais dans ce rapport.</p>' . $prompts . report_fold( 'Ouvrir les données brutes', $raw ) . '</section>'

		. '<footer class="footer">Rapport généré le ' . report_h( gmdate( 'Y-m-d H:i:s' ) ) . ' UTC · MS Recipes Writer AI</footer></main></body></html>';
}
