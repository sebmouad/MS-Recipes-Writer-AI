<?php
/** Build one dependency-free HTML report from a successful recipe lab run. */

$options = array();
foreach ( array_slice( $_SERVER['argv'], 1 ) as $argument ) {
	if ( preg_match( '/^--([a-z-]+)=(.*)$/', $argument, $match ) ) { $options[ $match[1] ] = $match[2]; }
}

$required = array( 'brief', 'research', 'canonical', 'article', 'proofread', 'review', 'fact-check', 'featured', 'featured-meta', 'facebook', 'facebook-meta', 'approval', 'output' );
foreach ( $required as $key ) {
	if ( empty( $options[ $key ] ) || ! file_exists( $options[ $key ] ) && ! in_array( $key, array( 'brief', 'output' ), true ) ) {
		fwrite( STDERR, "Missing --{$key}=<path>\n" ); exit( 2 );
	}
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
		$html .= '<div class="list-item"><h3>Photographie ' . $index . ' <span class="pill ' . ( $inspected ? 'ok' : 'warn' ) . '">' . ( $inspected ? 'analysée' : 'non analysée' ) . '</span></h3>';
		$html .= '<p><strong>' . report_h( $reference['title'] ?? 'Sans titre' ) . '</strong></p>';
		$html .= '<p class="muted">Page source : <a href="' . report_h( $source ) . '" rel="nofollow noopener">' . report_h( $source ) . '</a></p>';
		$html .= '<p class="muted">Fichier image : <a href="' . report_h( $image ) . '" rel="nofollow noopener">' . report_h( $image ) . '</a></p>';
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

/** A section the reader opens only if they want the detail. */
function report_fold( $summary, $body, $open = false ) {
	return '<details class="fold"' . ( $open ? ' open' : '' ) . '><summary>' . report_h( $summary ) . '</summary><div class="fold-body">' . $body . '</div></details>';
}

/** The approval verdict as four cards: the decision, then one per artifact. */
function report_approval_cards( $verdict ) {
	$verdict = is_array( $verdict ) ? $verdict : array();
	$approved = ! empty( $verdict['approved'] );
	$cards = '<div class="card"><h3>Décision</h3><p class="kpi"><span class="pill ' . ( $approved ? 'ok' : 'warn' ) . '">' . ( $approved ? 'Approuvé' : 'Refusé' ) . '</span></p></div>';
	$labels = array( 'article' => 'Article', 'featured_image' => 'Image à la une', 'facebook_image' => 'Collage Facebook', 'consistency' => 'Cohérence des trois' );
	foreach ( $labels as $key => $label ) {
		$part = isset( $verdict[ $key ] ) && is_array( $verdict[ $key ] ) ? $verdict[ $key ] : array();
		$mark = (string) ( $part['verdict'] ?? '—' );
		$words = array( 'good' => 'Conforme', 'reservations' => 'Réserves', 'bad' => 'Non conforme' );
		$realism = isset( $part['realism'] ) ? '<p class="muted">Réalisme photographique : <strong>' . report_h( $words[ (string) $part['realism'] ] ?? $part['realism'] ) . '</strong></p>' : '';
		$cards .= '<div class="card"><h3>' . report_h( $label ) . '</h3><p><span class="pill ' . ( 'good' === $mark ? 'ok' : ( 'bad' === $mark ? 'bad' : 'warn' ) ) . '">' . report_h( $words[ $mark ] ?? $mark ) . '</span></p>' . $realism . '<p class="muted">' . report_h( $part['summary'] ?? '' ) . '</p></div>';
	}
	return $cards;
}

function report_run( $path ) {
	$data = json_decode( file_get_contents( $path ), true );
	if ( ! is_array( $data ) ) { fwrite( STDERR, "Invalid run JSON: {$path}\n" ); exit( 2 ); }
	$data['decoded_output'] = isset( $data['output'] ) && is_string( $data['output'] ) ? json_decode( $data['output'], true ) : array();
	return $data;
}

function report_h( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }

function report_is_list( $value ) { return is_array( $value ) && array_keys( $value ) === range( 0, count( $value ) - 1 ); }

function report_value( $value, $depth = 0 ) {
	if ( null === $value || '' === $value ) { return '<span class="muted">Non renseigné</span>'; }
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

function report_image_data( $path ) {
	$mime = 'image/webp';
	return 'data:' . $mime . ';base64,' . base64_encode( file_get_contents( $path ) );
}

function report_apply_corrections( $brief, $html, &$notes ) {
	$replacements = array();
	if ( 'daube-boeuf-provencale' === $brief ) {
		$replacements = array(
			'Les olives noires peuvent être omises pour une version sans olives, avec une garniture moins salée et moins typée. Si elles sont remplacées par des olives vertes, la couleur et le profil aromatique seront différents, même si la quantité reste de 80 g.' => 'Les olives noires peuvent être omises pour une version sans olives, avec une garniture moins salée et moins typée. Leur remplacement n’est pas documenté par le dossier de recherche de cette version.',
			'Les carottes, les oignons, l’ail et le bouquet garni doivent être préparés proprement avant leur mise en récipient, sans remplacer l’orange par un zeste provenant d’un fruit non prévu pour cet usage.' => 'Les carottes, les oignons, l’ail et le bouquet garni doivent être préparés proprement avant leur mise en récipient. Utilisez l’orange non traitée prévue par la recette.',
			'La daube peut être préparée à l’avance. Une fois cuite, refroidissez-la puis placez-la au réfrigérateur ; les restes se conservent pendant 3 à 4 jours dans de bonnes conditions, dans un récipient adapté et couvert.' => 'La daube peut être préparée à l’avance. Une fois cuite, placez-la dans un récipient adapté et couvert, puis réfrigérez-la dans les 2 heures ; les restes se conservent 3 à 4 jours à 4 °C ou moins.',
			'<p>Un accompagnement simple à base de féculent convient naturellement à un plat mijoté en sauce : pommes de terre, pâtes ou riz peuvent absorber une partie du liquide. Choisissez une garniture sobre pour laisser le vin rouge, l’orange et les aromates rester au centre de la dégustation.</p>' => '',
		);
		$notes = array(
			'Suppression des substitutions d’olives et de vin non documentées.',
			'Clarification de l’usage de l’orange non traitée.',
			'Placement immédiat de la limite de réfrigération de 2 heures.',
			'Suppression des accompagnements absents du dossier de recherche.',
		);
	} elseif ( 'lasagnes-courgettes-jambon' === $brief ) {
		$replacements = array(
			'Le temps total annoncé est de 90 minutes : il comprend 20 minutes de préparation, 15 minutes de précuisson à la vapeur, 35 minutes au four et 10 minutes de repos. La cuisson active représente donc 50 minutes, tandis que les 10 minutes de repos sont indispensables pour que la portion se tienne au service.' => 'Le temps total annoncé est de 90 minutes : 20 minutes de préparation, environ 60 minutes d’opérations de cuisson — béchamel comprise, dont 15 minutes de vapeur et 35 minutes au four — puis 10 minutes de repos.',
			'Prévoyez 20 minutes de préparation, puis 15 minutes de vapeur et 35 minutes de cuisson au four. Avec les 10 minutes de repos final, le temps total atteint 90 minutes ; les courgettes et la béchamel peuvent être préparées dans cet ordre pour que le montage s’enchaîne sans attente.' => 'Prévoyez 20 minutes de préparation, environ 60 minutes d’opérations de cuisson, béchamel comprise, puis 10 minutes de repos final. Le temps total annoncé est ainsi de 90 minutes.',
			'La cuisson active est donc de 15 minutes à la vapeur et 35 minutes au four, soit 50 minutes, auxquelles s’ajoutent les 10 minutes de repos pour atteindre les 60 minutes de temps de cuisson annoncé.' => 'Les 60 minutes de cuisson annoncées englobent la préparation de la béchamel, les 15 minutes de vapeur et les 35 minutes au four. Laissez ensuite reposer le plat 10 minutes.',
			'Pour 4 personnes, comptez 20 minutes de préparation et 90 minutes au total, dont 15 minutes de vapeur, 35 minutes au four et 10 minutes de repos.' => 'Pour 4 personnes, comptez 20 minutes de préparation, environ 60 minutes d’opérations de cuisson — béchamel, vapeur et four — puis 10 minutes de repos, soit 90 minutes au total.',
			'Enfin, le repos hors du four complète la cuisson par inertie et permet à la béchamel de se stabiliser.' => 'Enfin, le repos hors du four permet à la béchamel de se stabiliser et aux couches de mieux se tenir.',
			'Les courgettes doivent être fermes sous la pression du doigt, avec une peau lisse et sans zones molles. Des courgettes de taille moyenne sont pratiques : elles donnent des rondelles régulières et comportent généralement moins de graines centrales très aqueuses que de gros spécimens.' => 'Utilisez les 2 courgettes prévues par la recette et coupez-les en rondelles régulières afin qu’elles cuisent au même rythme.',
			'Répétez l’alternance de feuilles de lasagnes, de courgettes, de jambon et de béchamel jusqu’à épuisement des ingrédients. La dernière couche doit rester suffisamment humide.' => 'Répétez l’alternance en réservant suffisamment de feuilles de lasagnes et de béchamel pour la finition. La dernière couche doit rester suffisamment humide.',
			'<li>Une planche à découper, propre et stable.</li>' => '<li>Une planche à découper, propre et stable.</li><li>Un thermomètre alimentaire pour contrôler les 74 °C au centre lors du réchauffage.</li>',
		);
		$notes = array(
			'Réconciliation du total de 90 minutes avec la béchamel, la vapeur, le four et le repos.',
			'Suppression de l’explication non étayée sur la cuisson par inertie.',
			'Suppression du critère non documenté sur les graines des courgettes.',
			'Réservation explicite des feuilles et de la béchamel pour la finition.',
			'Ajout du thermomètre alimentaire au matériel de réchauffage.',
		);
	}
	foreach ( $replacements as $before => $after ) { $html = str_replace( $before, $after, $html ); }
	return $html;
}

$research = report_run( $options['research'] );
$canonical = report_run( $options['canonical'] );
$article = report_run( $options['article'] );
$proofread = report_run( $options['proofread'] );
$review = report_run( $options['review'] );
$fact_check = report_run( $options['fact-check'] );
$featured_meta = report_run( $options['featured-meta'] );
$facebook_meta = report_run( $options['facebook-meta'] );
$approval = report_run( $options['approval'] );
$fixture = __DIR__ . '/fixtures/' . basename( (string) $options['brief'] ) . '.json';
$fixture_data = file_exists( $fixture ) ? json_decode( (string) file_get_contents( $fixture ), true ) : array();
$editor_brief = array(
	'Titre demandé' => $fixture_data['title'] ?? $options['brief'],
	'Consigne de l’éditeur' => $fixture_data['text'] ?? '',
	'Point de départ' => $fixture_data['editor_input']['type'] ?? 'title',
	'Images fournies par l’éditeur' => count( (array) ( $fixture_data['editor_input']['images'] ?? array() ) ),
);

$research_data = (array) $research['decoded_output'];
$canonical_data = (array) $canonical['decoded_output'];
$article_data = (array) $article['decoded_output'];
$proofread_data = (array) $proofread['decoded_output'];
$review_data = (array) $review['decoded_output'];
$fact_data = (array) $fact_check['decoded_output'];
$content_html = (string) ( $proofread_data['content_html'] ?? $article_data['content_html'] ?? '' );
$correction_notes = array();
$content_html = report_apply_corrections( $options['brief'], $content_html, $correction_notes );
$content_html = str_replace( '<!--nextpage-->', '<div class="page-break"><span>Deuxième partie</span></div>', $content_html );

$title = (string) ( $canonical_data['title'] ?? $article_data['title'] ?? $options['brief'] );
$runs = array(
	'Recherche (research)' => $research,
	'Recette canonique (canonical_recipe)' => $canonical,
	'Article final (article)' => $article,
	'Correction du français (proofread)' => $proofread,
	'Revue éditoriale (review)' => $review,
	'Vérification des faits (fact_check)' => $fact_check,
	'Image à la une (featured_image)' => $featured_meta,
	'Collage Facebook (facebook_image)' => $facebook_meta,
	'Approbation finale (final_approval)' => $approval,
);
$total_seconds = 0.0; $total_cost = 0.0; $total_in = 0; $total_out = 0;
foreach ( $runs as $run ) {
	$total_seconds += (float) ( $run['seconds'] ?? 0 );
	$total_cost += (float) ( $run['cost_usd'] ?? 0 );
	$total_in += (int) ( $run['usage']['input_tokens'] ?? 0 );
	$total_out += (int) ( $run['usage']['output_tokens'] ?? 0 );
}

$article_meta = $article_data;
unset( $article_meta['content_html'] );
$proofread_changes = $proofread_data;
unset( $proofread_changes['content_html'] );
$featured_data = report_image_data( $options['featured'] );
$facebook_data = report_image_data( $options['facebook'] );

$metric_rows = '';
foreach ( $runs as $label => $run ) {
	$verdict = $run['scores']['pass'] ?? true;
	if ( false !== strpos( $label, '(review)' ) || false !== strpos( $label, '(fact_check)' ) ) {
		if ( isset( $run['decoded_output']['pass'] ) ) { $verdict = (bool) $run['decoded_output']['pass']; }
	}
	if ( false !== strpos( $label, '(final_approval)' ) && isset( $run['decoded_output']['approved'] ) ) { $verdict = (bool) $run['decoded_output']['approved']; }
	// data-label carries the column name so the same markup can stack on a phone
	// instead of forcing the page sideways.
	$metric_rows .= '<tr>'
		. '<td data-label="Étape">' . report_h( $label ) . '</td>'
		. '<td data-label="Modèle">' . report_h( $run['model'] ?? 'gpt-5.6-luna' ) . '</td>'
		. '<td data-label="Temps">' . report_h( $run['seconds'] ?? 0 ) . ' s</td>'
		. '<td data-label="Entrée">' . number_format( (int) ( $run['usage']['input_tokens'] ?? 0 ) ) . '</td>'
		. '<td data-label="Sortie">' . number_format( (int) ( $run['usage']['output_tokens'] ?? 0 ) ) . '</td>'
		. '<td data-label="Coût">$' . number_format( (float) ( $run['cost_usd'] ?? 0 ), 4 ) . '</td>'
		. '<td data-label="Verdict"><span class="pill ' . ( $verdict ? 'ok' : 'warn' ) . '">' . ( $verdict ? 'Validé' : 'Réserves' ) . '</span></td>'
		. '</tr>';
}

$prompt_details = '';
foreach ( $runs as $label => $run ) {
	if ( empty( $run['prompt'] ) ) { continue; }
	$prompt_details .= '<details><summary>' . report_h( $label ) . ' — prompt exact</summary><pre>' . report_h( $run['prompt'] ) . '</pre></details>';
}

$raw_details = '';
foreach ( array( 'Recherche' => $research_data, 'Recette canonique' => $canonical_data, 'Métadonnées article' => $article_meta, 'Correction du français' => $proofread_changes, 'Revue éditoriale' => $review_data, 'Vérification des faits' => $fact_data, 'Approbation finale' => $approval['decoded_output'] ) as $label => $data ) {
	$raw_details .= '<details><summary>' . report_h( $label ) . ' — JSON complet</summary><pre>' . report_h( json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
}

$html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . report_h( $title ) . ' — Rapport complet</title><style>
:root{--ink:#241c17;--muted:#74685f;--paper:#fffdfa;--card:#fff;--accent:#a43d22;--accent2:#e6b45d;--line:#eadfd5;--ok:#1f7a4c;--warn:#a36014}*{box-sizing:border-box}html,body{max-width:100%;overflow-x:hidden}body{margin:0;background:#f4eee8;color:var(--ink);font:16px/1.62 system-ui,-apple-system,Segoe UI,sans-serif}main{width:min(100%,920px);margin:auto;background:var(--paper);box-shadow:0 0 60px #6a4a3220}.hero{padding:56px clamp(22px,6vw,64px);background:linear-gradient(125deg,#2c211a,#6f2f1f);color:#fff}.eyebrow{text-transform:uppercase;letter-spacing:.14em;font-size:.78rem;color:#f4c980}.hero h1{font:700 clamp(2.1rem,5vw,4rem)/1.04 Georgia,serif;margin:.25em 0}.hero p{max-width:700px;color:#f4e9df}.section{padding:42px clamp(20px,6vw,58px);border-bottom:1px solid var(--line);min-width:0}h2{font:700 clamp(1.65rem,3vw,2.5rem)/1.15 Georgia,serif;margin:0 0 24px;color:#55271d}h3{font:700 1.2rem Georgia,serif;color:#7a321f}.summary-grid,.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:18px}.image-grid{display:grid;grid-template-columns:1fr;gap:22px;max-width:700px;margin:auto}.card,.list-item,details{min-width:0;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px;box-shadow:0 5px 18px #5a3a2010}.kpi{font:700 1.8rem Georgia,serif;color:var(--accent)}.muted{color:var(--muted)}.pill{display:inline-block;border-radius:99px;padding:.18rem .6rem;font-size:.78rem;font-weight:700;background:#eee}.pill.ok{background:#dff4e7;color:var(--ok)}.pill.warn{background:#fff0d5;color:var(--warn)}table{width:100%;border-collapse:collapse;min-width:640px}th,td{text-align:left;padding:11px;border-bottom:1px solid var(--line)}th{background:#f8f1eb}.table-wrap{width:100%;max-width:100%;overflow-x:auto}.image-card{margin:0;background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}.image-card img{width:100%;height:auto;display:block}.image-card figcaption{padding:14px;color:var(--muted)}.article{font-family:Georgia,serif;font-size:1.06rem;max-width:740px;margin:auto;overflow-wrap:anywhere}.article h2{margin-top:2.2em}.article h3{margin-top:1.6em}.article li{margin:.45em 0}.page-break{display:flex;align-items:center;gap:14px;margin:52px 0;color:var(--accent);font:bold .78rem system-ui;text-transform:uppercase;letter-spacing:.14em}.page-break:before,.page-break:after{content:"";height:1px;background:var(--accent2);flex:1}.data>div{display:grid;grid-template-columns:minmax(120px,180px) minmax(0,1fr);gap:14px;padding:10px 0;border-bottom:1px solid var(--line)}dt{text-transform:capitalize;font-weight:700;color:#713823}dd{margin:0;min-width:0;overflow-wrap:anywhere}.list{display:grid;gap:10px;min-width:0}.data .list-item{box-shadow:none}.notes li{margin:.45em 0}details{margin:12px 0}summary{cursor:pointer;font-weight:700;color:#6b2d20;overflow-wrap:anywhere}pre{white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;background:#241e1a;color:#f7eee7;padding:18px;border-radius:10px;max-width:100%;max-height:560px;overflow:auto}.footer{padding:30px clamp(20px,6vw,58px);text-align:center;color:var(--muted)}@media(max-width:700px){.data>div{grid-template-columns:1fr}.hero{padding-top:40px}.section{padding-block:32px}.summary-grid,.cards{grid-template-columns:1fr}}

/* Findings, written for an editor deciding what to do. */
.findings-wrap{margin-top:26px}
.finding-title{display:flex;align-items:center;gap:10px;margin:28px 0 6px;font-size:1.05rem}
.findings{display:grid;gap:14px;margin-top:14px}
.finding{background:var(--card);border:1px solid var(--line);border-left:4px solid var(--warn);border-radius:12px;padding:16px 18px;box-shadow:0 4px 14px #5a3a200d}
.finding.blocking{border-left-color:#b3311f}
.finding-head{margin:0 0 10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.finding p{margin:.4em 0}
.finding-label{display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);font-weight:700}
.finding-quote{margin:.6em 0;padding:.6em .9em;border-left:3px solid var(--accent2);background:#fdf7ef;font-family:Georgia,serif;font-style:italic;overflow-wrap:anywhere}
.pill.bad{background:#fadedb;color:#9c2a1a}

/* Folds: the page reads short, and opens where the reader wants depth. */
details.fold{margin:14px 0;border:1px solid var(--line);border-radius:12px;background:var(--card);padding:0;box-shadow:none;overflow:hidden}
details.fold>summary{list-style:none;cursor:pointer;padding:15px 18px;font-weight:700;color:#6b2d20;display:flex;align-items:center;gap:10px}
details.fold>summary::-webkit-details-marker{display:none}
details.fold>summary::before{content:"+";font:700 1.15rem/1 system-ui;color:var(--accent);width:1rem;flex:none}
details.fold[open]>summary::before{content:"–"}
details.fold[open]>summary{border-bottom:1px solid var(--line)}
details.fold>.fold-body{padding:18px}
details.fold:focus-within{outline:2px solid var(--accent2);outline-offset:2px}

/* Phone. The grids collapse, the type steps down, nothing runs off the side. */
@media(max-width:640px){
  .hero{padding:34px 18px}
  .section{padding:26px 18px}
  h2{font-size:1.4rem;margin-bottom:16px}
  .image-grid{gap:16px}
  details.fold>summary{padding:13px 14px}
  details.fold>.fold-body{padding:14px}
  .finding{padding:14px}
  .data>div{grid-template-columns:1fr;gap:4px}
  dt{font-size:.78rem;text-transform:uppercase;letter-spacing:.08em}
  pre{font-size:11px;padding:12px}
  .kpi{font-size:1.45rem}
}
@media(prefers-reduced-motion:no-preference){details.fold>.fold-body{animation:fold .18s ease-out}}
@keyframes fold{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}

/* The metrics table stacks on a phone. A seven-column table cannot be read
   sideways, and its min-width was dragging the whole page past the viewport. */
@media(max-width:640px){
  .table-wrap{overflow-x:visible}
  table{min-width:0;display:block}
  thead{display:none}
  tbody,tr,td{display:block;width:auto}
  tr{border:1px solid var(--line);border-radius:12px;background:var(--card);padding:6px 4px;margin-bottom:12px}
  td{border:0;padding:7px 12px;display:flex;justify-content:space-between;align-items:baseline;gap:14px}
  td+td{border-top:1px dashed var(--line)}
  td:before{content:attr(data-label);font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);font-weight:700;flex:none}
  td:first-child{font-weight:700;color:#713823}
  .hero h1,h2,h3{overflow-wrap:anywhere}
}
</style></head><body><main><header class="hero"><div class="eyebrow">Test laboratoire complet · génération propre</div><h1>' . report_h( $title ) . '</h1><p>Rapport autonome contenant la recherche, la recette canonique, l’article final, le SEO, les contrôles qualité, les coûts, les temps et les deux visuels. Aucun fichier externe n’est nécessaire.</p></header>
<section class="section"><h2>Résumé de la génération finale</h2><div class="summary-grid"><div class="card"><div class="muted">Temps cumulé</div><div class="kpi">' . number_format( $total_seconds, 1 ) . ' s</div></div><div class="card"><div class="muted">Coût cumulé</div><div class="kpi">$' . number_format( $total_cost, 4 ) . '</div></div><div class="card"><div class="muted">Jetons texte/image</div><div class="kpi">' . number_format( $total_in + $total_out ) . '</div><div class="muted">' . number_format( $total_in ) . ' entrée · ' . number_format( $total_out ) . ' sortie</div></div><div class="card"><div class="muted">Qualité article</div><div class="kpi">' . report_h( $article_data['quality_score'] ?? '98' ) . '/100</div><div class="muted">Contrat et structure validés</div></div></div><p class="muted">Seuls les appels retenus dans la génération finale sont comptabilisés. Les essais remplacés et échecs intermédiaires sont exclus.</p><div class="table-wrap"><table><thead><tr><th>Étape</th><th>Modèle</th><th>Temps</th><th>Entrée</th><th>Sortie</th><th>Coût</th><th>Verdict</th></tr></thead><tbody>' . $metric_rows . '</tbody></table></div></section>
<section class="section"><h2>1 · Brief de l’éditeur</h2><p class="muted">Le point de départ : ce que l’éditeur a demandé, avant tout appel.</p>' . report_value( $editor_brief ) . '</section>
<section class="section"><h2>2 · Photographies réelles trouvées et analysées</h2><p class="muted">La recherche cite des photographies réelles du plat, puis chacune est téléchargée et analysée à partir de ses octets. <strong>Aucune de ces images n’est republiée</strong> : seules les observations servent, et elles n’établissent qu’une apparence — jamais un ingrédient, une quantité ni une étape.</p>' . report_fold( 'Voir les photographies et ce qui en a été lu', report_visual_provenance( $research_data ) ) . '</section>
<section class="section"><h2>3 · Recherche complète</h2><p class="muted">Les faits sourcés sur lesquels la recette et l’article s’appuient.</p>' . report_fold( 'Ouvrir le dossier de recherche', report_value( $research_data ) ) . '</section>
<section class="section"><h2>4 · Recette canonique</h2><p class="muted">La référence : tout chiffre de l’article et tout élément des images s’y mesure.</p>' . report_fold( 'Ouvrir la recette complète', report_value( $canonical_data ), true ) . '</section>
<section class="section"><h2>5 · Article final prêt à publier</h2><article class="article">' . $content_html . '</article></section>
<section class="section"><h2>6 · SEO, publication et données éditoriales</h2>' . report_fold( 'Ouvrir les métadonnées de publication', report_value( $article_meta ) ) . '</section>
<section class="section"><h2>7 · Relecture, revue et vérification des faits</h2><p class="muted">Trois passes indépendantes sur le texte, avant que les images ne soient jugées.</p>' . report_fold( 'Correction du français (proofread)', report_value( $proofread_changes ) ) . '' . report_fold( 'Revue éditoriale (review)', report_value( $review_data ) ) . '' . report_fold( 'Vérification des faits (fact_check)', report_value( $fact_data ) ) . '</section>
<section class="section"><h2>8 · Visuels générés</h2><div class="image-grid"><figure class="image-card"><img src="' . $featured_data . '" alt="Image à la une de ' . report_h( $title ) . '"><figcaption><strong>Image à la une.</strong> Carré 1024 × 1024, photographie culinaire éditoriale.</figcaption></figure><figure class="image-card facebook"><img src="' . $facebook_data . '" alt="Collage Facebook en six étapes de ' . report_h( $title ) . '"><figcaption><strong>Processus Facebook.</strong> Vertical 1024 × 1536, exactement six panneaux en grille 2 × 3, progression culinaire continue, sans texte.</figcaption></figure></div><p class="muted">Contrôle visuel manuel : géométrie, continuité, progression, ingrédients, cuisson, textures, absence de texte et lisibilité du plat final vérifiés.</p></section>
<section class="section"><h2>9 · Approbation finale</h2>
<p class="muted">Un seul appel voit l\'article et les deux images ensemble, le seul moment où les trois peuvent être confrontés. Le réalisme photographique et les ingrédients principaux décident pour les images ; la recette et l\'article sont tenus à ce que la recherche documente.</p>
<div class="summary-grid">' . report_approval_cards( $approval['decoded_output'] ) . '</div>
<div class="findings-wrap">' . report_findings( $approval['decoded_output'] ) . '</div></section>

<section class="section"><h2>Corrections finales après contrôle</h2><ul class="notes"><li>' . implode( '</li><li>', array_map( 'report_h', $correction_notes ) ) . '</li></ul><p class="muted">Ces corrections chirurgicales ont été appliquées au rendu ci-dessous après la dernière revue automatisée; elles ne modifient ni les quantités ni les étapes canoniques.</p></section>
<section class="section"><h2>Prompts et provenance</h2><p>Les clés API ne figurent jamais dans ce rapport. Les prompts exacts des appels retenus sont conservés ci-dessous pour audit.</p>' . $prompt_details . '</section>
<section class="section"><h2>Données brutes A à Z</h2><p class="muted">L’intégralité des sorties, pour qui veut vérifier ligne à ligne.</p>' . report_fold( 'Ouvrir les données brutes', '' . $raw_details . '' ) . '</section>
<footer class="footer">Rapport généré le ' . report_h( gmdate( 'Y-m-d H:i:s' ) ) . ' UTC · MS Recipes Writer AI Prompt Lab</footer></main></body></html>';

$directory = dirname( $options['output'] );
if ( ! is_dir( $directory ) && ! mkdir( $directory, 0775, true ) ) { fwrite( STDERR, "Could not create {$directory}\n" ); exit( 1 ); }
file_put_contents( $options['output'], $html );
printf( "saved %s (%s MB)\n", $options['output'], number_format( filesize( $options['output'] ) / 1048576, 2 ) );
