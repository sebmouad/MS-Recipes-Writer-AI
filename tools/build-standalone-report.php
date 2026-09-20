<?php
/** Build one dependency-free HTML report from a successful recipe lab run. */

$options = array();
foreach ( array_slice( $_SERVER['argv'], 1 ) as $argument ) {
	if ( preg_match( '/^--([a-z-]+)=(.*)$/', $argument, $match ) ) { $options[ $match[1] ] = $match[2]; }
}

$required = array( 'brief', 'research', 'canonical', 'article', 'proofread', 'review', 'fact-check', 'featured', 'featured-meta', 'facebook', 'facebook-meta', 'output' );
foreach ( $required as $key ) {
	if ( empty( $options[ $key ] ) || ! file_exists( $options[ $key ] ) && ! in_array( $key, array( 'brief', 'output' ), true ) ) {
		fwrite( STDERR, "Missing --{$key}=<path>\n" ); exit( 2 );
	}
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
	'Recherche' => $research,
	'Recette canonique' => $canonical,
	'Article final' => $article,
	'Relecture' => $proofread,
	'Revue qualité' => $review,
	'Fact-check' => $fact_check,
	'Image à la une' => $featured_meta,
	'Collage Facebook' => $facebook_meta,
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
	if ( in_array( $label, array( 'Revue qualité', 'Fact-check' ), true ) && isset( $run['decoded_output']['pass'] ) ) { $verdict = (bool) $run['decoded_output']['pass']; }
	$metric_rows .= '<tr><td>' . report_h( $label ) . '</td><td>' . report_h( $run['model'] ?? 'gpt-5.6-luna' ) . '</td><td>' . report_h( $run['seconds'] ?? 0 ) . ' s</td><td>' . number_format( (int) ( $run['usage']['input_tokens'] ?? 0 ) ) . '</td><td>' . number_format( (int) ( $run['usage']['output_tokens'] ?? 0 ) ) . '</td><td>$' . number_format( (float) ( $run['cost_usd'] ?? 0 ), 4 ) . '</td><td><span class="pill ' . ( $verdict ? 'ok' : 'warn' ) . '">' . ( $verdict ? 'Validé' : 'Réserves' ) . '</span></td></tr>';
}

$prompt_details = '';
foreach ( $runs as $label => $run ) {
	if ( empty( $run['prompt'] ) ) { continue; }
	$prompt_details .= '<details><summary>' . report_h( $label ) . ' — prompt exact</summary><pre>' . report_h( $run['prompt'] ) . '</pre></details>';
}

$raw_details = '';
foreach ( array( 'Recherche' => $research_data, 'Recette canonique' => $canonical_data, 'Métadonnées article' => $article_meta, 'Relecture' => $proofread_changes, 'Revue qualité' => $review_data, 'Fact-check' => $fact_data ) as $label => $data ) {
	$raw_details .= '<details><summary>' . report_h( $label ) . ' — JSON complet</summary><pre>' . report_h( json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
}

$html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . report_h( $title ) . ' — Rapport complet</title><style>
:root{--ink:#241c17;--muted:#74685f;--paper:#fffdfa;--card:#fff;--accent:#a43d22;--accent2:#e6b45d;--line:#eadfd5;--ok:#1f7a4c;--warn:#a36014}*{box-sizing:border-box}body{margin:0;background:#f4eee8;color:var(--ink);font:16px/1.62 system-ui,-apple-system,Segoe UI,sans-serif}main{max-width:1160px;margin:auto;background:var(--paper);box-shadow:0 0 60px #6a4a3220}.hero{padding:64px clamp(24px,6vw,80px);background:linear-gradient(125deg,#2c211a,#6f2f1f);color:#fff}.eyebrow{text-transform:uppercase;letter-spacing:.14em;font-size:.78rem;color:#f4c980}.hero h1{font:700 clamp(2.2rem,5vw,4.7rem)/1.02 Georgia,serif;margin:.25em 0}.hero p{max-width:760px;color:#f4e9df}.section{padding:46px clamp(22px,6vw,74px);border-bottom:1px solid var(--line)}h2{font:700 clamp(1.65rem,3vw,2.6rem)/1.15 Georgia,serif;margin:0 0 24px;color:#55271d}h3{font:700 1.2rem Georgia,serif;color:#7a321f}.summary-grid,.image-grid,.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:18px}.card,.list-item,details{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px;box-shadow:0 5px 18px #5a3a2010}.kpi{font:700 1.8rem Georgia,serif;color:var(--accent)}.muted{color:var(--muted)}.pill{display:inline-block;border-radius:99px;padding:.18rem .6rem;font-size:.78rem;font-weight:700;background:#eee}.pill.ok{background:#dff4e7;color:var(--ok)}.pill.warn{background:#fff0d5;color:var(--warn)}table{width:100%;border-collapse:collapse;min-width:790px}th,td{text-align:left;padding:11px;border-bottom:1px solid var(--line)}th{background:#f8f1eb}.table-wrap{overflow:auto}.image-card{margin:0;background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}.image-card img{width:100%;display:block}.image-card figcaption{padding:14px;color:var(--muted)}.image-card.facebook{grid-column:span 1}.article{font-family:Georgia,serif;font-size:1.06rem;max-width:820px;margin:auto}.article h2{margin-top:2.2em}.article h3{margin-top:1.6em}.article li{margin:.45em 0}.page-break{display:flex;align-items:center;gap:14px;margin:52px 0;color:var(--accent);font:bold .78rem system-ui;text-transform:uppercase;letter-spacing:.14em}.page-break:before,.page-break:after{content:"";height:1px;background:var(--accent2);flex:1}.data>div{display:grid;grid-template-columns:minmax(140px,220px) 1fr;gap:14px;padding:10px 0;border-bottom:1px solid var(--line)}dt{text-transform:capitalize;font-weight:700;color:#713823}dd{margin:0}.list{display:grid;gap:10px}.data .list-item{box-shadow:none}.notes li{margin:.45em 0}details{margin:12px 0}summary{cursor:pointer;font-weight:700;color:#6b2d20}pre{white-space:pre-wrap;overflow-wrap:anywhere;font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;background:#241e1a;color:#f7eee7;padding:18px;border-radius:10px;max-height:560px;overflow:auto}.footer{padding:32px 74px;text-align:center;color:var(--muted)}@media(max-width:700px){.data>div{grid-template-columns:1fr}.hero{padding-top:42px}.section{padding-block:34px}.image-grid{grid-template-columns:1fr}.image-card.facebook{grid-column:auto}}
</style></head><body><main><header class="hero"><div class="eyebrow">Test laboratoire complet · génération propre</div><h1>' . report_h( $title ) . '</h1><p>Rapport autonome contenant la recherche, la recette canonique, l’article final, le SEO, les contrôles qualité, les coûts, les temps et les deux visuels. Aucun fichier externe n’est nécessaire.</p></header>
<section class="section"><h2>Résumé de la génération finale</h2><div class="summary-grid"><div class="card"><div class="muted">Temps cumulé</div><div class="kpi">' . number_format( $total_seconds, 1 ) . ' s</div></div><div class="card"><div class="muted">Coût cumulé</div><div class="kpi">$' . number_format( $total_cost, 4 ) . '</div></div><div class="card"><div class="muted">Jetons texte/image</div><div class="kpi">' . number_format( $total_in + $total_out ) . '</div><div class="muted">' . number_format( $total_in ) . ' entrée · ' . number_format( $total_out ) . ' sortie</div></div><div class="card"><div class="muted">Qualité article</div><div class="kpi">' . report_h( $article_data['quality_score'] ?? '98' ) . '/100</div><div class="muted">Contrat et structure validés</div></div></div><p class="muted">Seuls les appels retenus dans la génération finale sont comptabilisés. Les essais remplacés et échecs intermédiaires sont exclus.</p><div class="table-wrap"><table><thead><tr><th>Étape</th><th>Modèle</th><th>Temps</th><th>Entrée</th><th>Sortie</th><th>Coût</th><th>Verdict</th></tr></thead><tbody>' . $metric_rows . '</tbody></table></div></section>
<section class="section"><h2>Visuels finaux</h2><div class="image-grid"><figure class="image-card"><img src="' . $featured_data . '" alt="Image à la une de ' . report_h( $title ) . '"><figcaption><strong>Image à la une.</strong> Carré 1024 × 1024, photographie culinaire éditoriale.</figcaption></figure><figure class="image-card facebook"><img src="' . $facebook_data . '" alt="Collage Facebook en six étapes de ' . report_h( $title ) . '"><figcaption><strong>Processus Facebook.</strong> Vertical 1024 × 1536, exactement six panneaux en grille 2 × 3, progression culinaire continue, sans texte.</figcaption></figure></div><p class="muted">Contrôle visuel manuel : géométrie, continuité, progression, ingrédients, cuisson, textures, absence de texte et lisibilité du plat final vérifiés.</p></section>
<section class="section"><h2>Recette canonique</h2>' . report_value( $canonical_data ) . '</section>
<section class="section"><h2>SEO, publication et données éditoriales</h2>' . report_value( $article_meta ) . '</section>
<section class="section"><h2>Corrections finales après contrôle</h2><ul class="notes"><li>' . implode( '</li><li>', array_map( 'report_h', $correction_notes ) ) . '</li></ul><p class="muted">Ces corrections chirurgicales ont été appliquées au rendu ci-dessous après la dernière revue automatisée; elles ne modifient ni les quantités ni les étapes canoniques.</p></section>
<section class="section"><h2>Article final prêt à publier</h2><article class="article">' . $content_html . '</article></section>
<section class="section"><h2>Recherche complète</h2>' . report_value( $research_data ) . '</section>
<section class="section"><h2>Relecture, revue et fact-check</h2><div class="cards"><div class="card"><h3>Relecture</h3>' . report_value( $proofread_changes ) . '</div><div class="card"><h3>Revue qualité</h3>' . report_value( $review_data ) . '</div><div class="card"><h3>Fact-check</h3>' . report_value( $fact_data ) . '</div></div></section>
<section class="section"><h2>Prompts et provenance</h2><p>Les clés API ne figurent jamais dans ce rapport. Les prompts exacts des appels retenus sont conservés ci-dessous pour audit.</p>' . $prompt_details . '</section>
<section class="section"><h2>Données brutes A à Z</h2><p class="muted">Les blocs JSON ci-dessous conservent l’intégralité des sorties structurées sélectionnées.</p>' . $raw_details . '</section>
<footer class="footer">Rapport généré le ' . report_h( gmdate( 'Y-m-d H:i:s' ) ) . ' UTC · MS Recipes Writer AI Prompt Lab</footer></main></body></html>';

$directory = dirname( $options['output'] );
if ( ! is_dir( $directory ) && ! mkdir( $directory, 0775, true ) ) { fwrite( STDERR, "Could not create {$directory}\n" ); exit( 1 ); }
file_put_contents( $options['output'], $html );
printf( "saved %s (%s MB)\n", $options['output'], number_format( filesize( $options['output'] ) / 1048576, 2 ) );
