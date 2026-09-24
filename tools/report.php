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

/**
 * A key as a reader says it. The answers are the engine's JSON, whose keys are
 * English and snake_case; the page is French. A key not listed keeps its own
 * name, spaced, rather than vanish.
 */
function report_label( $key ) {
	static $labels = array(
		'dish_identity' => 'Identité du plat', 'name' => 'Nom', 'confidence' => 'Confiance', 'evidence' => 'Preuve',
		'recipe_outline' => 'Repères de la recette', 'servings' => 'Parts', 'prep_minutes' => 'Préparation (min)', 'cook_minutes' => 'Cuisson (min)',
		'total_minutes' => 'Total (min)', 'rest_minutes' => 'Repos (min)', 'category' => 'Catégorie', 'cuisine' => 'Cuisine', 'difficulty' => 'Difficulté',
		'source_url' => 'Source', 'ingredients' => 'Ingrédients', 'quantity' => 'Quantité', 'unit' => 'Unité', 'role' => 'Rôle', 'essential' => 'Essentiel',
		'preparation' => 'Préparation', 'step' => 'Étape', 'action' => 'Action', 'cue' => 'Signe de réussite', 'minutes' => 'Minutes', 'temperature_c' => 'Température (°C)',
		'substitutions' => 'Substitutions', 'ingredient' => 'Ingrédient', 'replacement' => 'Remplacement', 'note' => 'Note',
		'accompaniments' => 'Accompagnements', 'common_failures' => 'Erreurs courantes', 'problem' => 'Problème', 'cause' => 'Cause', 'remedy' => 'Remède',
		'storage' => 'Conservation', 'method' => 'Méthode', 'duration' => 'Durée', 'food_safety' => 'Sécurité alimentaire', 'text' => 'Texte',
		'references' => 'Références', 'visual_references' => 'Références visuelles', 'visual_observations' => 'Observations visuelles',
		'image_url' => 'Image', 'tier' => 'Niveau', 'title' => 'Titre', 'observable_details' => 'Ce qui est visible', 'composition' => 'Composition',
		'colours' => 'Couleurs', 'textures' => 'Textures', 'uncertainties' => 'Incertitudes', 'originality_notes' => 'Notes d’originalité',
		'pass' => 'Validé', 'findings' => 'Constats', 'severity' => 'Gravité', 'section' => 'Section', 'reason' => 'Raison', 'fix' => 'Correction proposée',
		'quote' => 'Passage cité', 'target' => 'Cible', 'corrections' => 'Corrections', 'replace' => 'Remplacer par', 'changes' => 'Modifications',
		'type' => 'Type', 'before' => 'Avant', 'after' => 'Après', 'clean' => 'Déjà propre', 'changes_not_applied' => 'Modifications non appliquées',
		'excerpt' => 'Extrait', 'seo_title' => 'Titre SEO', 'seo_description' => 'Description SEO', 'slug' => 'Identifiant d’URL', 'tags' => 'Étiquettes',
		'categories' => 'Catégories', 'faq' => 'FAQ', 'question' => 'Question', 'answer' => 'Réponse', 'recipe_meta' => 'Données de recette',
		'internal_links' => 'Liens internes', 'facebook_caption' => 'Légende Facebook', 'visual_final_notes' => 'Notes visuelles',
		'description' => 'Description', 'steps' => 'Étapes', 'equipment' => 'Ustensiles', 'nutrition' => 'Nutrition', 'calories' => 'Calories',
		'corrections_applied' => 'Corrections appliquées', 'corrections_for_the_editor' => 'Corrections laissées à l’éditeur',
		'major' => 'majeur', 'minor' => 'mineur', 'critical' => 'critique', 'blocking' => 'bloquant',
		'dish' => 'Plat', 'outline' => 'Repères',
		'valid JSON' => 'JSON valide', 'images inspected' => 'photographies lues', 'real image provenance' => 'provenance des photographies',
		'recipe outline' => 'repères de la recette', 'every step has its sign' => 'chaque étape a son signe de réussite',
		'every ingredient is sourced' => 'chaque ingrédient a sa source', 'photographs of this dish' => 'photographies de ce plat',
		'references carry a URL' => 'les références ont une adresse',
	);
	return $labels[ (string) $key ] ?? str_replace( '_', ' ', (string) $key );
}

function report_is_list( $value ) { return is_array( $value ) && array_keys( $value ) === range( 0, count( $value ) - 1 ); }

/** Any decoded answer as readable HTML, whatever shape it arrived in. */
function report_value( $value, $depth = 0 ) {
	if ( null === $value || '' === $value || array() === $value ) { return '<span class="muted">—</span>'; }
	if ( is_bool( $value ) ) { return '<span class="pill ' . ( $value ? 'ok' : 'warn' ) . '">' . ( $value ? 'Oui' : 'Non' ) . '</span>'; }
	if ( is_scalar( $value ) ) { return '<span>' . nl2br( report_h( $value ) ) . '</span>'; }
	if ( report_is_list( $value ) ) {
		$html = '<div class="list">';
		foreach ( $value as $item ) { $html .= '<div class="list-item">' . report_value( $item, $depth + 1 ) . '</div>'; }
		return $html . '</div>';
	}
	$html = '<dl class="data">';
	foreach ( $value as $key => $item ) {
		$html .= '<div><dt>' . report_h( report_label( $key ) ) . '</dt><dd>' . report_value( $item, $depth + 1 ) . '</dd></div>';
	}
	return $html . '</dl>';
}

/**
 * A link, but only to somewhere a reader can safely be sent. A source that is
 * not an address is one of the engine's own words for where a fact came from
 * — the writer's text, a photograph, established practice — and is named, not
 * flagged; only an address with another scheme is refused.
 */
function report_link( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) { return '<span class="muted">Non renseigné</span>'; }
	$named = array( 'brief' => 'texte du rédacteur', 'editor' => 'photographie du rédacteur', 'photograph' => 'photographie du rédacteur', 'culinary_practice' => 'pratique culinaire établie' );
	if ( isset( $named[ strtolower( $url ) ] ) ) { return '<span>' . report_h( $named[ strtolower( $url ) ] ) . '</span>'; }
	if ( ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) { return report_h( $url ); }
	if ( ! preg_match( '#^https?://#i', $url ) ) { return report_h( $url ) . ' <span class="pill warn">schéma refusé</span>'; }
	return '<a href="' . report_h( $url ) . '" rel="nofollow noopener">' . report_h( $url ) . '</a>';
}

/** A section the reader opens only if they want the detail. */
function report_fold( $summary, $body, $open = false ) {
	return '<details class="fold"' . ( $open ? ' open' : '' ) . '><summary>' . report_h( $summary ) . '</summary><div class="fold-body">' . $body . '</div></details>';
}

/**
 * The photographs the recipe was written from, and what was read from their
 * bytes. The writer's are shown, from the job's own folder; one the engine
 * found on the web is shown from the copy it kept, and linked to its page.
 *
 * $photos maps a writer's stored file name to an embeddable data URI;
 * $found maps a web photograph's address to one. Both are optional: the lab
 * has neither and gets the links alone.
 */
function report_visual_provenance( $research, array $photos = array(), array $found = array() ) {
	$references = (array) ( $research['visual_references'] ?? array() );
	$observations = (array) ( $research['visual_observations'] ?? array() );
	if ( ! $references && ! $observations ) { return '<p class="muted">Aucune photographie de référence : ni le rédacteur ni la recherche n’en ont fourni.</p>'; }

	$by_url = array();
	foreach ( $observations as $observation ) {
		if ( is_array( $observation ) && ! empty( $observation['image_url'] ) ) { $by_url[ (string) $observation['image_url'] ] = $observation; }
	}

	$html = '<div class="list">';
	$index = 0;
	$writer = 0;
	foreach ( $references as $reference ) {
		if ( ! is_array( $reference ) ) { continue; }
		$index++;
		$image = (string) ( $reference['image_url'] ?? '' );
		$source = (string) ( $reference['source_url'] ?? '' );
		$from_writer = 'editor' === $source;
		$writer += $from_writer ? 1 : 0;
		$observation = $by_url[ $image ] ?? array();
		$inspected = ! empty( $observation );
		$tier = (int) ( $reference['tier'] ?? 0 );
		$name = preg_match( '/([a-f0-9]{32}\.(?:jpg|png|webp))/', $image, $m ) ? $m[1] : '';
		$picture = $from_writer ? ( $photos[ $name ] ?? '' ) : ( $found[ $image ] ?? '' );

		$html .= '<div class="list-item"><h3>Photographie ' . $index
			. ' <span class="pill">' . ( $from_writer ? 'fournie par le rédacteur' : 'trouvée par le moteur' ) . '</span>'
			. ' <span class="pill ' . ( $inspected ? 'ok' : 'warn' ) . '">' . ( $inspected ? 'lue' : 'non lue' ) . '</span>'
			. ( $tier && ! $from_writer ? ' <span class="pill">' . ( 1 === $tier ? 'ce plat' : 'plat voisin' ) . '</span>' : '' ) . '</h3>';
		if ( '' !== $picture ) { $html .= '<p><img class="reference" src="' . report_h( $picture ) . '" alt="" style="max-width:240px;border-radius:8px"></p>'; }
		if ( ! $from_writer ) {
			$html .= '<p><strong>' . report_h( $reference['title'] ?? 'Sans titre' ) . '</strong></p>';
			$html .= '<p class="muted">Page source : ' . report_link( $source ) . '</p>';
		}
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
	$html .= '<p class="muted" style="margin-top:18px">' . sprintf( '%d photographie(s) du rédacteur, %d trouvée(s) par le moteur ; %d lue(s) à partir de leurs octets.', $writer, count( $references ) - $writer, count( $observations ) ) . '</p>';
	return $html;
}

/**
 * What happened before the engine ran: what the writer provided, how the
 * photographs were read and paired, the brief the engine was handed and what
 * it completed. Read from the job's history; empty for a lab run, which has
 * none.
 */
function report_history( array $history, array $photos = array() ) {
	$html = '';
	$thumb = static function ( $file ) use ( $photos ) {
		return isset( $photos[ $file ] ) ? '<img src="' . report_h( $photos[ $file ] ) . '" alt="" style="max-width:160px;border-radius:8px;display:block;margin:6px 0">' : '';
	};
	foreach ( $history as $row ) {
		$stage = (string) ( $row['stage'] ?? '' );
		$c = (array) ( $row['content'] ?? array() );
		$at = '<span class="muted">' . report_h( $row['created_at'] ?? '' ) . ' UTC</span>';
		if ( 'provided' === $stage ) {
			$body = '';
			foreach ( (array) ( $c['recipes'] ?? array() ) as $i => $recipe ) {
				$body .= '<div class="list-item"><h3>Recette ' . ( $i + 1 ) . ' — ' . report_h( $recipe['title'] ?? '' ) . '</h3><p>' . nl2br( report_h( $recipe['text'] ?? '' ) ) . '</p></div>';
			}
			if ( ! ( $c['recipes'] ?? array() ) ) { $body .= '<p class="muted">Aucun texte : les recettes ont été nommées d’après les photographies.</p>'; }
			foreach ( (array) ( $c['photos'] ?? array() ) as $photo ) {
				$body .= '<div class="list-item">' . $thumb( $photo['file'] ?? '' ) . '<span class="muted">' . report_h( $photo['name'] ?? '' ) . '</span></div>';
			}
			if ( ! ( $c['photos'] ?? array() ) ) { $body .= '<p class="muted">Aucune photographie envoyée.</p>'; }
			$html .= '<div class="history-stage"><h3>Ce que le rédacteur a fourni ' . $at . '</h3><div class="list">' . $body . '</div></div>';
		} elseif ( 'matching' === $stage ) {
			$body = '';
			foreach ( (array) ( $c['readings'] ?? array() ) as $i => $reading ) {
				$pair = array();
				foreach ( (array) ( $c['pairs'] ?? array() ) as $one ) { if ( (int) ( $one['image'] ?? -1 ) === $i ) { $pair = $one; } }
				$recipe = isset( $pair['recipe'] ) && null !== $pair['recipe'] ? ( $c['recipes'][ (int) $pair['recipe'] ]['title'] ?? '#' . ( (int) $pair['recipe'] + 1 ) ) : 'aucune recette';
				$body .= '<div class="list-item">' . $thumb( $reading['file'] ?? '' )
					. '<p><strong>' . report_h( '' !== (string) ( $reading['dish'] ?? '' ) ? $reading['dish'] : 'plat non reconnu' ) . '</strong> — ' . report_h( $reading['description'] ?? '' ) . '</p>'
					. '<p>→ ' . report_h( $recipe ) . ' <span class="pill">' . report_h( $pair['confidence'] ?? '' ) . '</span> <span class="muted">' . report_h( $pair['why'] ?? '' ) . '</span></p></div>';
			}
			if ( ! ( $c['readings'] ?? array() ) ) { $body .= '<p class="muted">Aucune photographie : rien à lire ni à apparier, rien de facturé.</p>'; }
			$html .= '<div class="history-stage"><h3>Lecture des photographies et appariement ' . $at . '</h3>'
				. ( '' !== (string) ( $c['reasoning'] ?? '' ) ? '<p>' . report_h( $c['reasoning'] ) . '</p>' : '' )
				. '<div class="list">' . $body . '</div>'
				. '<p class="muted">Coût du lot : $' . number_format( (float) ( $c['cost_usd'] ?? 0 ), 4 ) . ' · ' . report_h( $c['seconds'] ?? 0 ) . ' s</p></div>';
		} elseif ( 'pairing' === $stage ) {
			$moved = array_filter( (array) ( $c['pairs'] ?? array() ), static function ( $pair ) { return ! empty( $pair['by_writer'] ); } );
			$html .= '<div class="history-stage"><h3>Appariement corrigé par le rédacteur ' . $at . '</h3><p>' . count( $moved ) . ' photographie(s) déplacée(s) à la main.</p></div>';
		} elseif ( 'brief' === $stage ) {
			$visual = (array) ( $c['visual_brief'] ?? array() );
			$body = '<div class="list-item"><h3>Référence texte</h3><p>' . nl2br( report_h( $c['text_brief']['text'] ?? '' ) ) . '</p></div>';
			$body .= '<div class="list-item"><h3>Référence visuelle</h3>';
			foreach ( (array) ( $visual['photos'] ?? array() ) as $photo ) { $body .= $thumb( $photo['file'] ?? '' ); }
			$body .= '<p class="muted">' . ( 'writer' === ( $visual['source'] ?? '' ) ? 'Les photographies du rédacteur : la recherche s’en sert sans chercher sur le web.' : 'Aucune photographie : la recherche en trouve une sur le web.' ) . '</p></div>';
			$html .= '<div class="history-stage"><h3>Brief remis au moteur ' . $at . '</h3><div class="list">' . $body . '</div></div>';
		} elseif ( 'engine_brief' === $stage ) {
			$text = (array) ( $c['text_brief'] ?? array() );
			$html .= '<div class="history-stage"><h3>Brief complété par le moteur ' . $at . '</h3>'
				. '<p><strong>' . report_h( $text['dish']['name'] ?? '' ) . '</strong> — ' . count( (array) ( $text['ingredients'] ?? array() ) ) . ' ingrédients, ' . count( (array) ( $text['preparation'] ?? array() ) ) . ' étapes, '
				. count( (array) ( $c['visual_brief']['references'] ?? array() ) ) . ' référence(s) visuelle(s).</p>'
				. report_fold( 'Référence texte complétée', report_value( $text ) ) . '</div>';
		}
	}
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
	// Since 0.28.0 the judge sees the images alone; an earlier verdict still
	// carries its article card. Only what it judged is shown.
	$labels = array( 'article' => 'Article', 'featured_image' => 'Image à la une', 'facebook_image' => 'Collage Facebook', 'consistency' => 'Cohérence des deux images' );
	foreach ( $labels as $key => $label ) {
		if ( empty( $verdict[ $key ] ) || ! is_array( $verdict[ $key ] ) ) { continue; }
		$part = $verdict[ $key ];
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

/**
 * How each image came to be: drawn once, or again from the judge's findings,
 * and what the collage's prompt was written from.
 */
function report_redraws( $steps, $artifacts ) {
	$counts = array( 'featured_image' => 0, 'facebook_image' => 0 );
	foreach ( (array) $steps as $step ) {
		$name = (string) ( $step['step'] ?? '' );
		if ( isset( $counts[ $name ] ) && '' === (string) ( $step['error'] ?? '' ) ) { $counts[ $name ]++; }
	}
	$lines = array();
	foreach ( array( 'featured_image' => 'L’image à la une', 'facebook_image' => 'Le collage' ) as $name => $label ) {
		if ( $counts[ $name ] > 1 ) { $lines[] = sprintf( '%s a été dessiné %d fois : les dessins suivants reprennent les remarques du juge, et le dernier n’a pas été rejugé.', $label, $counts[ $name ] ); }
	}
	$featured = (string) ( $artifacts['featured']['reference'] ?? '' );
	if ( '' !== $featured ) { $lines[] = 'L’image à la une a été dessinée d’après la consigne et ' . ( 'editor' === $featured ? 'la photographie envoyée par le rédacteur' : 'une photographie trouvée par la recherche' ) . ', pour le plat seulement.'; }
	$composed = (array) ( $artifacts['facebook_composed'] ?? array() );
	if ( ! empty( $composed['prompt'] ) ) {
		$style = 'le collage de référence' . ( 'facebook-reference.jpg' === ( $composed['reference_file'] ?? '' ) ? ' fourni avec l’extension' : ( ! empty( $composed['reference_file'] ) ? ' du site' : '' ) );
		$from = array( 'editor' => 'la photographie envoyée par le rédacteur', 'research' => 'une photographie trouvée par la recherche', 'style' => $style, 'style+editor' => $style . ' pour le rendu, et la photographie du rédacteur pour le plat', 'style+research' => $style . ' pour le rendu, et une photographie trouvée par la recherche pour le plat' );
		$lines[] = 'La consigne du collage a été rédigée par ' . ( $composed['model'] ?? 'le modèle' ) . ' d’après la recette' . ( isset( $from[ $composed['reference'] ?? '' ] ) ? ' et ' . $from[ $composed['reference'] ] : ', sans image de référence' ) . '.';
	}
	if ( ! $lines ) { return ''; }
	$html = '';
	foreach ( $lines as $line ) { $html .= '<p class="muted">' . report_h( $line ) . '</p>'; }
	return $html . ( ! empty( $composed['prompt'] ) ? report_fold( 'Consigne du collage', '<pre>' . report_h( $composed['prompt'] ) . '</pre>' ) : '' );
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

/**
 * The editorial review's findings, and the language changes applied after them.
 *
 * The review returns its findings beside the language changes; a finding is
 * advice for the editor and is not applied, so the page lists both and leaves
 * the pairing to the reader.
 */
function report_review( $review, $proofread ) {
	$findings = array_values( array_filter( (array) ( ( is_array( $review ) ? $review : array() )['findings'] ?? array() ), 'is_array' ) );
	$changes = array_values( array_filter( (array) ( ( is_array( $proofread ) ? $proofread : array() )['changes'] ?? array() ), 'is_array' ) );
	if ( ! $findings ) { return '<p class="muted">La revue éditoriale n’a relevé aucun constat.</p>'; }
	$html = '<div class="findings">';
	foreach ( $findings as $finding ) {
		$severity = (string) ( $finding['severity'] ?? '' );
		$html .= '<article class="finding ' . ( in_array( $severity, array( 'major', 'critical', 'blocking' ), true ) ? 'blocking' : 'minor' ) . '">'
			. '<p class="finding-head"><span class="pill ' . ( 'minor' === $severity ? 'warn' : 'bad' ) . '">' . report_h( report_label( $severity ) ) . '</span> <strong>' . report_h( $finding['section'] ?? '' ) . '</strong></p>'
			. '<p>' . report_h( $finding['reason'] ?? '' ) . '</p>'
			. ( '' !== (string) ( $finding['fix'] ?? '' ) ? '<p class="muted"><strong>Correction proposée :</strong> ' . report_h( $finding['fix'] ) . '</p>' : '' )
			. '<p class="muted">→ laissé à l’éditeur</p></article>';
	}
	$html .= '</div>';
	$html .= '<p class="muted">' . ( $proofread
		? sprintf( 'La même relecture a corrigé la langue de %d passage(s) ; ces constats-ci ne sont pas appliqués et restent à traiter par l’éditeur.', count( $changes ) )
		: 'La correction de la langue n’a pas eu lieu : ces constats restent à traiter par l’éditeur.' ) . '</p>';
	if ( $changes ) {
		$rows = '';
		foreach ( $changes as $change ) { $rows .= '<div class="list-item"><p class="muted">' . report_h( $change['type'] ?? '' ) . '</p><p><del>' . report_h( $change['before'] ?? '' ) . '</del></p><p><ins>' . report_h( $change['after'] ?? '' ) . '</ins></p></div>'; }
		$html .= report_fold( 'Ce que la relecture a changé', '<div class="list">' . $rows . '</div>' );
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
			$rows .= '<div><dt>' . ( ! empty( $check['pass'] ) ? '<span class="pill ok">ok</span>' : '<span class="pill warn">non</span>' ) . ' ' . report_h( report_label( $name ) ) . '</dt><dd>' . report_h( $check['detail'] ) . '</dd></div>';
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
	$history = array_values( array_filter( (array) ( $run['history'] ?? array() ), 'is_array' ) );
	$photos = (array) ( $run['photos'] ?? array() );
	$found = (array) ( $run['found'] ?? array() );

	// What the job set out to do. A lab run names nothing and shows every
	// section; a site job names its profile's steps, and a section for a step
	// it never planned is not shown as missing or refused. A planned step that
	// did not run still shows, and says it was not reached.
	$planned = array_values( (array) ( $run['planned'] ?? array() ) );
	$ran = array_column( (array) ( $run['steps'] ?? array() ), 'step' );
	$wants = static function ( $step ) use ( $planned ) { return ! $planned || in_array( $step, $planned, true ); };
	$reached = static function ( $step ) use ( $ran ) { return in_array( $step, $ran, true ); };
	$not_reached = '<p class="muted">Étape prévue, non atteinte : la tâche s’est arrêtée avant.</p>';
	$images = $wants( 'featured_image' ) || $wants( 'facebook_image' );

	$editor = array(
		'Titre demandé' => $brief['title'] ?? '',
		'Consigne du rédacteur' => $brief['text'] ?? '',
		'Photographies fournies' => count( (array) ( $brief['images'] ?? array() ) ),
	);

	$metadata = $article;
	unset( $metadata['content_html'], $metadata['content_html_part2'] );

	// The lot's photographs were read and paired once for all its recipes;
	// this recipe's share belongs in what it cost.
	$matching = 0.0;
	$recipes_in_lot = 1;
	foreach ( $history as $row ) {
		if ( 'matching' === ( $row['stage'] ?? '' ) ) {
			$matching = (float) ( $row['content']['cost_usd'] ?? 0 );
			$recipes_in_lot = max( 1, count( (array) ( $row['content']['recipes'] ?? array() ) ) );
		}
	}
	$share = round( $matching / $recipes_in_lot, 6 );
	$total = (float) ( $totals['cost_usd'] ?? 0 ) + $share;

	// Input the provider served from its cache, billed at a fraction of the rate.
	$cached_total = 0;
	foreach ( (array) ( $run['events'] ?? array() ) as $event ) {
		if ( 'call' === ( $event['kind'] ?? '' ) ) { $cached_total += (int) ( $event['data']['usage']['cached_input_tokens'] ?? 0 ); }
	}

	$buckets = (array) ( $totals['buckets'] ?? array() );
	$bucket_cards = '';
	foreach ( array( 'article' => array( 'Texte', 'article' ), 'featured' => array( 'Image à la une', 'featured_image' ), 'facebook' => array( 'Collage', 'facebook_image' ), 'other' => array( 'Recherche et contrôles', 'research' ) ) as $key => $bucket ) {
		if ( ! $wants( $bucket[1] ) && 0.0 === (float) ( $buckets[ $key ] ?? 0 ) ) { continue; }
		$bucket_cards .= '<div class="card"><div class="muted">' . report_h( $bucket[0] ) . '</div><div class="kpi">$' . number_format( (float) ( $buckets[ $key ] ?? 0 ), 4 ) . '</div></div>';
	}
	if ( $matching > 0 ) {
		$bucket_cards .= '<div class="card"><div class="muted">Lecture des photographies</div><div class="kpi">$' . number_format( $share, 4 ) . '</div>' . ( $recipes_in_lot > 1 ? '<div class="muted">part de ' . number_format( $matching, 4 ) . ' $ pour ' . $recipes_in_lot . ' recettes</div>' : '' ) . '</div>';
	}

	$errors = '';
	foreach ( (array) ( $run['errors'] ?? array() ) as $error ) {
		$errors .= '<article class="finding blocking"><p class="finding-head"><span class="pill bad">Échec</span> <strong>' . report_h( $error['step'] ) . '</strong></p><p>' . report_h( $error['message'] ) . '</p></article>';
	}

	$raw = '';
	foreach ( array( 'Recherche' => $research, 'Recette canonique' => $canonical, 'Métadonnées article' => $metadata, 'Corrections factuelles' => $corrected, 'Correction de la langue' => array_diff_key( $proofread, array( 'content_html' => 1 ) ), 'Revue éditoriale' => $artifacts['review'] ?? array(), 'Vérification des faits' => $artifacts['fact_check'] ?? array(), 'Approbation finale' => $approval, 'Configuration effective' => $artifacts['config'] ?? array() ) as $label => $data ) {
		if ( ! $data ) { continue; }
		$raw .= '<details><summary>' . report_h( $label ) . ' — JSON complet</summary><pre>' . report_h( json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
	}

	$prompts = '';
	foreach ( array( 'featured' => 'Image à la une', 'facebook' => 'Collage Facebook' ) as $key => $label ) {
		if ( empty( $artifacts[ $key ]['prompt'] ) ) { continue; }
		$prompts .= '<details><summary>' . report_h( $label ) . ' — prompt exact</summary><pre>' . report_h( $artifacts[ $key ]['prompt'] ) . '</pre></details>';
	}

	$css = trim( (string) file_get_contents( __DIR__ . '/report.css' ) );
	$n = 0;
	$section = static function ( $heading, $body ) use ( &$n ) {
		$n++;
		return '<section class="section"><h2>' . $n . ' · ' . report_h( $heading ) . '</h2>' . $body . '</section>';
	};
	$covers = array_filter( array( 'le brief', $history ? 'son historique' : '', 'la recherche', 'la recette canonique', 'l’article final', 'les contrôles', 'les coûts', 'les temps', $images ? 'les visuels' : '' ) );

	$page = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . report_h( $title ) . ' — Rapport complet</title><style>' . $css . '</style></head><body><main>'
		. '<header class="hero"><div class="eyebrow">' . report_h( $run['eyebrow'] ?? 'Test laboratoire · une exécution du moteur' ) . '</div><h1>' . report_h( $title ) . '</h1><p>Rapport autonome : ' . report_h( implode( ', ', $covers ) ) . '. Aucun fichier externe n’est nécessaire.</p></header>'

		. '<section class="section"><h2>Résumé de l’exécution</h2><div class="summary-grid">'
		. '<div class="card"><div class="muted">Temps cumulé</div><div class="kpi">' . number_format( (float) ( $totals['seconds'] ?? 0 ), 1 ) . ' s</div></div>'
		. '<div class="card"><div class="muted">Coût cumulé</div><div class="kpi">$' . number_format( $total, 4 ) . '</div></div>'
		. '<div class="card"><div class="muted">Jetons</div><div class="kpi">' . number_format( (int) ( $totals['input_tokens'] ?? 0 ) + (int) ( $totals['output_tokens'] ?? 0 ) ) . '</div><div class="muted">' . number_format( (int) ( $totals['input_tokens'] ?? 0 ) ) . ' entrée · ' . number_format( (int) ( $totals['output_tokens'] ?? 0 ) ) . ' sortie' . ( $cached_total ? ' · ' . number_format( $cached_total ) . ' relus depuis le cache' : '' ) . '</div></div>'
		. '<div class="card"><div class="muted">Issue</div><div class="kpi"><span class="pill ' . ( empty( $run['ok'] ) ? 'warn' : 'ok' ) . '">' . ( empty( $run['ok'] ) ? 'Avec erreurs' : 'Complet' ) . '</span></div></div>'
		. '</div>'
		. '<h3>Où va l’argent</h3><div class="summary-grid">' . $bucket_cards . '</div>'
		. '<p class="muted">Les coûts sont des estimations calculées avec les tarifs publiés, jamais une facture. Chaque essai est compté, y compris ceux qu’un nouvel essai a remplacés.</p>'
		. '<div class="table-wrap"><table><thead><tr><th>Étape</th><th>Modèle</th><th>Essais</th><th>Temps</th><th>Entrée</th><th>Sortie</th><th>Coût</th><th>Contrat</th></tr></thead><tbody>' . report_steps( $run['steps'] ?? array() ) . '</tbody></table></div>'
		. ( '' === $errors ? '' : '<div class="findings-wrap"><h3 class="finding-title">Échecs</h3><div class="findings">' . $errors . '</div></div>' )
		. '</section>'

		. ( $history
			? $section( 'Historique : de ce qui a été fourni au brief', '<p class="muted">Ce que le rédacteur a envoyé, comment les photographies ont été lues et appariées, le brief remis au moteur et ce qu’il en a complété — dans l’ordre où c’est arrivé.</p>' . report_history( $history, $photos ) )
			: $section( 'Brief du rédacteur', '<p class="muted">Le point de départ : ce qui a été demandé, avant tout appel.</p>' . report_value( $editor ) ) )

		. $section( 'Références visuelles', '<p class="muted">Les photographies d’où la recette tient l’apparence du plat, et ce qui a été lu sur chacune. Elles ne sont jamais republiées ; elles n’établissent qu’une apparence — jamais un ingrédient, une quantité ni une étape.</p>' . report_fold( 'Voir les photographies et ce qui en a été lu', report_visual_provenance( $research, $photos, $found ), (bool) $photos ) )

		. $section( 'Recherche complète', $research ? '<p class="muted">Les faits sur lesquels la recette et l’article s’appuient.</p>' . report_fold( 'Ouvrir le dossier de recherche', report_value( $research ) ) : $not_reached )

		. $section( 'Recette canonique', '<p class="muted">La référence : tout chiffre de l’article et tout élément des images s’y mesure. Ce sont les champs que lit le résultat enrichi.</p>'
			. report_recipe_card( $canonical )
			. ( $canonical ? report_fold( 'Tous les champs de la recette', report_value( $canonical ) ) : '' ) )

		. $section( 'Article final — à relire avant publication', '' !== $content ? '<article class="article">' . $content . '</article>' : $not_reached )

		. $section( 'SEO, publication et données éditoriales', $metadata ? report_fold( 'Ouvrir les métadonnées de publication', report_value( $metadata ) ) : '<p class="muted">Aucune métadonnée : l’article n’a pas été écrit, ou sa version de travail a été libérée une fois le brouillon créé.</p>' )

		. ( $wants( 'review' ) || $wants( 'proofread' )
			? $section( 'Revue, vérification des faits et corrections', '<p class="muted">Une relecture — constats, faits et langue — puis ce que le moteur a réellement changé dans le texte.</p>'
				. '<h3>Vérification des faits</h3><div class="findings-wrap">' . report_corrections( $corrected ) . '</div>'
				. '<h3>Revue éditoriale</h3>' . ( $reached( 'review' ) ? report_review( $artifacts['review'] ?? array(), $proofread ) : $not_reached )
				. ( ! empty( $artifacts['review']['unsupported'] ) ? report_fold( 'Affirmations qu’aucune source ne couvre', report_value( $artifacts['review']['unsupported'] ) ) : '' ) )
			: '' )

		. ( $images
			? $section( 'Visuels générés', '<div class="image-grid">'
				. ( $wants( 'featured_image' ) ? report_image( $artifacts['featured'] ?? array(), 'Image à la une', 'Photographie culinaire éditoriale, ' . report_h( $artifacts['featured']['size'] ?? '' ) . '.' ) : '' )
				. ( $wants( 'facebook_image' ) ? report_image( $artifacts['facebook'] ?? array(), 'Collage Facebook', 'Progression culinaire en panneaux, ' . report_h( $artifacts['facebook']['size'] ?? '' ) . ', sans texte.' ) : '' )
				. '</div>' )
			: '' )

		. ( $wants( 'final_approval' )
			? $section( 'Approbation finale', $reached( 'final_approval' ) || $approval
				? '<p class="muted">Un appel regarde les deux images ensemble, face à la recette et aux photographies de référence : le réalisme photographique et les ingrédients principaux décident. Le texte a déjà été tenu à la recherche par la relecture. Une image refusée n’est pas redessinée d’office : l’éditeur la fait redessiner s’il le juge utile. Une approbation n’est pas une validation éditoriale.</p>'
					. report_approval_cards( $approval )
					. '<div class="findings-wrap">' . report_findings( $approval ) . '</div>'
					. report_redraws( $run['steps'] ?? array(), $artifacts )
				: $not_reached )
			: '' )

		. '<section class="section"><h2>Contrôles, étape par étape</h2><p class="muted">Chaque vérification qu’une étape a passée, avec ce qu’elle a mesuré. Un score se lit, il ne se croit pas.</p>' . report_scorecards( $run['steps'] ?? array() ) . '</section>'

		. '<section class="section"><h2>Appels aux fournisseurs</h2><p class="muted">Ce que chaque appel a réellement fait. Les coûts sont des estimations calculées avec les tarifs configurés, jamais une facture.</p>' . report_calls( $run['events'] ?? array() ) . '</section>'

		. '<section class="section"><h2>Ce que chaque étape a reçu</h2><p class="muted">D’où venait son prompt, sa taille, son plafond de sortie et les artefacts qui l’accompagnaient.</p>' . report_fold( 'Ouvrir le détail des entrées', report_inputs( $run['events'] ?? array() ) ) . '</section>'

		. '<section class="section"><h2>Configuration de ce passage</h2><p class="muted">Rien n’est figé dans le moteur : chaque valeur ci-dessous est une clé de configuration, et chacune dit qui l’a décidée.</p>' . report_fold( 'Ouvrir la configuration effective', report_configuration( (array) ( $artifacts['config'] ?? array() ) ) ) . '</section>'

		. '<section class="section"><h2>Déroulé de l’exécution</h2><p class="muted">Les vagues, les reprises et les avertissements, dans l’ordre où ils se sont produits.</p>' . report_fold( 'Ouvrir le déroulé', report_timeline( $run['events'] ?? array() ) ) . '</section>'

		. '<section class="section"><h2>Prompts et données brutes</h2><p>Les clés d’API ne figurent jamais dans ce rapport.</p>' . $prompts . report_fold( 'Ouvrir les données brutes', $raw ) . '</section>'

		. '<footer class="footer">Rapport généré le ' . report_h( gmdate( 'Y-m-d H:i:s' ) ) . ' UTC · MS Recipes Writer AI</footer></main></body></html>';
	return $page;
}
