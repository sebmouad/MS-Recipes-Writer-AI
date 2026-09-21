<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The laboratory's screens: start a run, watch it, read its report.
 *
 * A run is started and then left alone — cron carries it. This page only reads
 * the row the worker keeps writing to, which is why closing the tab costs
 * nothing and reopening it shows exactly where the run got to.
 */
final class MSRWA_Lab_Screen {

	public static function menu() {
		add_submenu_page( 'ms-recipes-writer-ai', 'Laboratoire', 'Laboratoire', 'manage_options', 'ms-recipes-writer-ai-lab', array( __CLASS__, 'page' ) );
		add_submenu_page( 'ms-recipes-writer-ai', 'Mesures', 'Mesures', 'manage_options', 'ms-recipes-writer-ai-lab-stats', array( __CLASS__, 'measures' ) );
		add_submenu_page( null, 'Rapport du laboratoire', 'Rapport du laboratoire', 'manage_options', 'ms-recipes-writer-ai-lab-report', array( __CLASS__, 'report' ) );
		add_submenu_page( null, 'Détail du run', 'Détail du run', 'manage_options', 'ms-recipes-writer-ai-lab-run', array( __CLASS__, 'detail' ) );
	}

	/** The briefs shipped with the repository, offered as a starting point. */
	public static function fixtures() {
		$out = array();
		foreach ( (array) glob( MSRWA_DIR . 'tools/fixtures/*.json' ) as $file ) {
			$brief = json_decode( (string) file_get_contents( $file ), true );
			if ( is_array( $brief ) ) { $out[ basename( $file, '.json' ) ] = (string) ( $brief['title'] ?? basename( $file, '.json' ) ); }
		}
		return $out;
	}

	public static function brief( $name ) {
		$file = MSRWA_DIR . 'tools/fixtures/' . basename( (string) $name ) . '.json';
		$brief = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		return is_array( $brief ) ? $brief : array();
	}

	public static function page() {
		if ( ! MSRWA_Lab::may_run() ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		$runs = MSRWA_Lab::recent( 30 );
		$fixtures = self::fixtures();
		$settings = MSRWA_Settings::get();
		$missing = '' === trim( (string) ( $settings['openai_key'] ?? '' ) );
		?>
		<div class="wrap msrwa-wrap">
			<h1>Laboratoire</h1>
			<p class="description">Un run complet exécute les dix étapes du moteur sur un seul sujet et coûte de l’argent réel — environ 0,11 $ et quatre minutes et demie. Il est porté par le cron : une fois lancé, vous pouvez fermer cet onglet.</p>

			<?php if ( $missing ) : ?>
				<div class="notice notice-error"><p>Aucune clé OpenAI enregistrée dans la configuration. Un run échouerait à la première étape.</p></div>
			<?php endif; ?>

			<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<div class="notice notice-warning"><p><code>DISABLE_WP_CRON</code> est actif : les runs n’avanceront que si un cron serveur appelle <code>wp-cron.php</code>. C’est la configuration recommandée, mais elle doit exister.</p></div>
			<?php endif; ?>

			<div class="msrwa-card">
				<h2>Lancer un run</h2>
				<form id="msrwa-lab-start" class="msrwa-fields">
					<p>
						<label for="msrwa-lab-brief"><strong>Sujet</strong></label><br>
						<select id="msrwa-lab-brief" name="fixture">
							<?php foreach ( $fixtures as $slug => $title ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $title ); ?></option>
							<?php endforeach; ?>
							<option value="">— autre titre —</option>
						</select>
					</p>
					<p id="msrwa-lab-title-row" style="display:none">
						<label for="msrwa-lab-title"><strong>Titre</strong></label><br>
						<input type="text" id="msrwa-lab-title" name="title" class="regular-text" placeholder="Tarte aux pommes normande">
					</p>
					<p>
						<label for="msrwa-lab-budget"><strong>Budget maximum, en dollars</strong></label><br>
						<input type="number" id="msrwa-lab-budget" name="budget" value="0.50" step="0.01" min="0.01" class="small-text">
						<span class="description">Le run s’arrête plutôt que de dépasser ce plafond.</span>
					</p>
					<p><button type="submit" class="button button-primary">Lancer</button> <span id="msrwa-lab-start-status" class="description"></span></p>
				</form>
			</div>

			<div class="msrwa-card">
				<h2>Runs</h2>
				<table class="widefat striped" id="msrwa-lab-runs">
					<thead><tr><th>#</th><th>Sujet</th><th>État</th><th>Étapes</th><th>Coût</th><th>Durée</th><th></th></tr></thead>
					<tbody>
					<?php if ( ! $runs ) : ?>
						<tr><td colspan="7">Aucun run pour l’instant.</td></tr>
					<?php endif; ?>
					<?php foreach ( $runs as $run ) : ?>
						<tr data-run="<?php echo esc_attr( $run['id'] ); ?>">
							<td><?php echo esc_html( $run['id'] ); ?></td>
							<td><?php echo esc_html( $run['label'] ); ?></td>
							<td class="msrwa-lab-state"><?php echo esc_html( self::state_label( $run ) ); ?></td>
							<td class="msrwa-lab-steps"><?php echo esc_html( $run['steps_done'] . ' / ' . $run['steps_total'] ); ?></td>
							<td class="msrwa-lab-cost"><?php echo esc_html( sprintf( '%.4f $', (float) $run['cost_usd'] ) ); ?></td>
							<td class="msrwa-lab-seconds"><?php echo esc_html( sprintf( '%.1f s', (float) $run['seconds'] ) ); ?></td>
							<td>
								<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ms-recipes-writer-ai-lab-run&run_id=' . (int) $run['id'] ) ); ?>">Détail</a>
								<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ms-recipes-writer-ai-lab-report&run_id=' . (int) $run['id'] ) ); ?>" target="_blank">Rapport</a>
								<?php if ( in_array( $run['status'], array( 'queued', 'running' ), true ) ) : ?>
									<button class="button msrwa-lab-cancel" data-run="<?php echo esc_attr( $run['id'] ); ?>">Arrêter</button>
								<?php else : ?>
									<button class="button msrwa-lab-delete" data-run="<?php echo esc_attr( $run['id'] ); ?>">Supprimer</button>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ( '' !== (string) $run['error_message'] ) : ?>
							<tr><td colspan="7"><em><?php echo esc_html( $run['error_message'] ); ?></em></td></tr>
						<?php endif; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">Un run en cours se met à jour tout seul. Le rapport s’ouvre à tout moment, y compris pendant le run : il montre ce qui existe déjà.</p>
			</div>
		</div>
		<?php
	}

	private static function state_label( array $run ) {
		$labels = array( 'queued' => 'en attente', 'running' => 'en cours', 'done' => 'terminé', 'failed' => 'échoué', 'cancelled' => 'arrêté' );
		$label = $labels[ $run['status'] ] ?? $run['status'];
		return '' !== (string) $run['step'] && 'running' === $run['status'] ? $label . ' — ' . $run['step'] : $label;
	}

	/**
	 * The run's own HTML report, rendered by the same code the command line
	 * uses. It is a whole document, so it is printed and nothing else.
	 */
	public static function report() {
		if ( ! MSRWA_Lab::may_run() ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		$run = MSRWA_Lab::get( isset( $_GET['run_id'] ) ? absint( $_GET['run_id'] ) : 0 );
		if ( ! $run || ! MSRWA_Lab::may_see( $run ) ) { wp_die( esc_html__( 'Run introuvable.', 'ms-recipes-writer-ai' ) ); }
		$state = MSRWA_Lab::state( (int) $run['id'] );
		if ( empty( $state['steps'] ) ) { wp_die( esc_html__( 'Ce run n’a encore rien produit.', 'ms-recipes-writer-ai' ) ); }
		require_once MSRWA_DIR . 'tools/report.php';
		// The report is a complete document with its own head and styles; the
		// admin's chrome would only get in its way.
		header( 'Content-Type: text/html; charset=utf-8' );
		echo report_render( $state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer escapes its own values.
		exit;
	}

	/** The run that is asked for, or nothing at all. */
	private static function requested() {
		$run = MSRWA_Lab::get( isset( $_GET['run_id'] ) ? absint( $_GET['run_id'] ) : 0 );
		if ( ! $run || ! MSRWA_Lab::may_see( $run ) ) { wp_die( esc_html__( 'Run introuvable.', 'ms-recipes-writer-ai' ) ); }
		return $run;
	}

	/**
	 * Everything the engine reported about one run, as rows rather than as a
	 * rendered page: what each step scored, what each call was billed, what was
	 * produced and what the judge said about it.
	 */
	public static function detail() {
		if ( ! MSRWA_Lab::may_run() ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		$run = self::requested();
		$id = (int) $run['id'];
		$steps = MSRWA_Lab::steps( $id );
		$calls = MSRWA_Lab::calls( $id );
		$events = MSRWA_Lab::events( $id );
		$artifacts = MSRWA_Lab::artifacts( $id );
		$state = MSRWA_Lab::state( $id );
		$totals = $state['totals'];
		$approval = (array) ( $artifacts['approval'] ?? array() );
		?>
		<div class="wrap msrwa-wrap">
			<h1>Run #<?php echo esc_html( $id ); ?> — <?php echo esc_html( $run['label'] ); ?></h1>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ms-recipes-writer-ai-lab' ) ); ?>">Retour</a>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ms-recipes-writer-ai-lab-report&run_id=' . $id ) ); ?>" target="_blank">Rapport lisible</a>
			</p>

			<div class="msrwa-card">
				<h2>Total</h2>
				<div class="msrwa-fields">
					<dl><dt>État</dt><dd><?php echo esc_html( self::state_label( $run ) ); ?></dd></dl>
					<dl><dt>Coût</dt><dd><?php echo esc_html( sprintf( '%.4f $', (float) $totals['cost_usd'] ) ); ?></dd></dl>
					<dl><dt>Durée</dt><dd><?php echo esc_html( sprintf( '%.1f s', (float) $totals['seconds'] ) ); ?></dd></dl>
					<dl><dt>Tokens</dt><dd><?php echo esc_html( number_format_i18n( $totals['input_tokens'] ) . ' / ' . number_format_i18n( $totals['output_tokens'] ) ); ?></dd></dl>
				</div>
				<?php if ( ! empty( $totals['unpriced_steps'] ) ) : ?>
					<p class="description"><strong><?php echo esc_html( $totals['unpriced_steps'] ); ?> étape(s) sur un modèle sans tarif publié : le coût de ce run n’est pas vérifiable.</strong></p>
				<?php endif; ?>
				<p class="description">Par poste :
					<?php foreach ( (array) $totals['buckets'] as $bucket => $spent ) : ?>
						<code><?php echo esc_html( $bucket . ' ' . sprintf( '%.4f $', (float) $spent ) ); ?></code>
					<?php endforeach; ?>
				</p>
				<?php if ( '' !== (string) $run['error_message'] ) : ?><p class="description"><strong><?php echo esc_html( $run['error_message'] ); ?></strong></p><?php endif; ?>
			</div>

			<div class="msrwa-card">
				<h2>Étapes</h2>
				<table class="widefat striped">
					<thead><tr><th>Étape</th><th>Modèle</th><th>Durée</th><th>Coût</th><th>Score</th><th>Contrôles non satisfaits</th></tr></thead>
					<tbody>
					<?php foreach ( $steps as $step ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $step['step'] ); ?></strong><?php if ( '' !== $step['error'] ) : ?><br><em><?php echo esc_html( $step['error'] ); ?></em><?php endif; ?></td>
							<td><?php echo esc_html( $step['model'] ); ?></td>
							<td><?php echo esc_html( sprintf( '%.1f s', $step['seconds'] ) ); ?></td>
							<td><?php echo esc_html( null === $step['cost_usd'] ? 'tarif inconnu' : sprintf( '%.4f $', $step['cost_usd'] ) ); ?></td>
							<td><?php echo esc_html( null === $step['passed'] ? '—' : $step['passed'] . ' / ' . $step['total'] ); ?></td>
							<td>
								<?php $failed = array_filter( $step['checks'], static function ( $check ) { return is_array( $check ) && empty( $check['pass'] ); } ); ?>
								<?php if ( ! $failed ) : ?>—<?php endif; ?>
								<?php foreach ( $failed as $label => $check ) : ?>
									<div><code><?php echo esc_html( $label ); ?></code> <?php echo esc_html( is_scalar( $check['detail'] ?? '' ) ? (string) $check['detail'] : '' ); ?></div>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="msrwa-card">
				<h2>Appels</h2>
				<p class="description">Ce qui a réellement été facturé : quel modèle a répondu, par quel point d’entrée, et quelle part de l’entrée était en cache.</p>
				<table class="widefat striped">
					<thead><tr><th>Étape</th><th>Fournisseur / modèle</th><th>Entrée</th><th>Cache</th><th>Sortie</th><th>Durée</th><th>Coût</th></tr></thead>
					<tbody>
					<?php if ( ! $calls ) : ?><tr><td colspan="7">Aucun appel enregistré.</td></tr><?php endif; ?>
					<?php foreach ( $calls as $call ) : ?>
						<tr>
							<td><?php echo esc_html( $call['step'] ); ?></td>
							<td><?php echo esc_html( $call['provider'] . ' / ' . $call['model'] ); ?><?php if ( '' !== (string) $call['tier'] ) : ?> <small><?php echo esc_html( $call['tier'] ); ?></small><?php endif; ?><br><small><?php echo esc_html( $call['endpoint'] ); ?></small></td>
							<td><?php echo esc_html( number_format_i18n( (int) $call['input_tokens'] ) ); ?></td>
							<td><?php echo esc_html( (int) $call['cached_tokens'] ? number_format_i18n( (int) $call['cached_tokens'] ) . ' (' . round( 100 * (int) $call['cached_tokens'] / max( 1, (int) $call['input_tokens'] ) ) . '%)' : '—' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $call['output_tokens'] ) ); ?></td>
							<td><?php echo esc_html( sprintf( '%.1f s', (float) $call['seconds'] ) ); ?></td>
							<td><?php echo esc_html( empty( $call['priced'] ) ? 'tarif inconnu' : sprintf( '%.4f $', (float) $call['cost_usd'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $approval ) : ?>
				<div class="msrwa-card">
					<h2>Verdict</h2>
					<p><strong><?php echo esc_html( empty( $approval['approved'] ) ? 'Refusé' : 'Approuvé' ); ?></strong></p>
					<div class="msrwa-fields">
						<?php foreach ( array( 'article' => 'Article', 'featured_image' => 'Image à la une', 'facebook_image' => 'Collage', 'consistency' => 'Cohérence' ) as $key => $label ) : ?>
							<?php $entry = (array) ( $approval[ $key ] ?? array() ); ?>
							<dl><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( (string) ( $entry['verdict'] ?? '—' ) ); ?><br><small><?php echo esc_html( (string) ( $entry['summary'] ?? '' ) ); ?></small></dd></dl>
						<?php endforeach; ?>
					</div>
					<?php if ( ! empty( $approval['findings'] ) ) : ?>
						<ul>
						<?php foreach ( (array) $approval['findings'] as $finding ) : ?>
							<li><strong><?php echo esc_html( (string) ( $finding['severity'] ?? '' ) ); ?></strong> — <?php echo esc_html( (string) ( $finding['target'] ?? '' ) ); ?> : <?php echo esc_html( (string) ( $finding['reason'] ?? '' ) ); ?>
							<?php if ( ! empty( $finding['fix'] ) ) : ?><br><em><?php echo esc_html( (string) $finding['fix'] ); ?></em><?php endif; ?></li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="msrwa-card">
				<h2>Productions</h2>
				<table class="widefat striped">
					<thead><tr><th>Nom</th><th>Taille</th></tr></thead>
					<tbody>
					<?php foreach ( $artifacts as $key => $value ) : ?>
						<tr><td><code><?php echo esc_html( $key ); ?></code></td><td><?php echo esc_html( size_format( strlen( (string) wp_json_encode( $value ) ) ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="msrwa-card">
				<h2>Déroulé</h2>
				<div class="msrwa-event-list">
					<?php foreach ( $events as $event ) : ?>
						<div>
							<span><?php echo esc_html( sprintf( '%6.1f s', (float) $event['at'] ) ); ?> <code><?php echo esc_html( $event['step'] ); ?></code></span>
							<span><?php echo esc_html( $event['message'] ); ?></span>
							<small><?php echo esc_html( $event['kind'] ); ?></small>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * What the runs say together.
	 *
	 * A single run says what happened once; these say what happens. Which step
	 * carries the cost, which model is actually billed, which named check keeps
	 * failing, and how often the judge approves anything at all.
	 */
	public static function measures() {
		if ( ! MSRWA_Lab::may_run() ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 0;
		$summary = MSRWA_Lab_Stats::summary( $days );
		$verdicts = MSRWA_Lab_Stats::verdicts( $days );
		?>
		<div class="wrap msrwa-wrap">
			<h1>Mesures</h1>
			<p class="description">Sur les runs terminés<?php echo $days ? esc_html( ' des ' . $days . ' derniers jours' ) : ''; ?>. Un seul run dit ce qui s’est passé une fois ; ceux-ci disent ce qui se passe.</p>
			<p>
				<?php foreach ( array( 0 => 'tout', 7 => '7 jours', 30 => '30 jours' ) as $window => $label ) : ?>
					<a class="button<?php echo $days === $window ? ' button-primary' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=ms-recipes-writer-ai-lab-stats&days=' . $window ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</p>

			<div class="msrwa-card">
				<h2>Ensemble</h2>
				<div class="msrwa-fields">
					<dl><dt>Runs</dt><dd><?php echo esc_html( number_format_i18n( $summary['runs'] ) ); ?></dd></dl>
					<dl><dt>Dépense totale</dt><dd><?php echo esc_html( sprintf( '%.4f $', $summary['spend_usd'] ) ); ?></dd></dl>
					<dl><dt>Coût moyen</dt><dd><?php echo esc_html( sprintf( '%.4f $', $summary['cost_usd'] ) ); ?></dd></dl>
					<dl><dt>Durée moyenne</dt><dd><?php echo esc_html( sprintf( '%.1f s', $summary['seconds'] ) ); ?></dd></dl>
				</div>
				<?php if ( $verdicts['judged'] ) : ?>
					<p class="description">Jugés : <?php echo esc_html( $verdicts['judged'] ); ?> — approuvés <?php echo esc_html( $verdicts['approved'] ); ?>, soit <?php echo esc_html( round( 100 * $verdicts['approved'] / $verdicts['judged'] ) ); ?> %. <?php echo esc_html( $verdicts['findings'] ); ?> remarques dont <?php echo esc_html( $verdicts['blocking'] ); ?> bloquantes.</p>
					<?php foreach ( $verdicts['artifacts'] as $target => $counts ) : ?>
						<p class="description"><code><?php echo esc_html( $target ); ?></code> :
						<?php foreach ( $counts as $verdict => $count ) : ?><?php echo esc_html( $verdict . ' ×' . $count . '  ' ); ?><?php endforeach; ?></p>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<div class="msrwa-card">
				<h2>Par étape</h2>
				<table class="widefat striped">
					<thead><tr><th>Étape</th><th>Poste</th><th>Exécutions</th><th>Durée moy.</th><th>Coût moy.</th><th>Dépense</th><th>Entrée moy.</th><th>Sortie moy.</th><th>Score moy.</th><th>Échecs</th></tr></thead>
					<tbody>
					<?php foreach ( MSRWA_Lab_Stats::by_step( $days ) as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $row['step'] ); ?></strong></td>
							<td><?php echo esc_html( $row['bucket'] ); ?></td>
							<td><?php echo esc_html( $row['runs'] ); ?></td>
							<td><?php echo esc_html( sprintf( '%.1f s', (float) $row['seconds'] ) ); ?></td>
							<td><?php echo esc_html( sprintf( '%.4f $', (float) $row['cost'] ) ); ?></td>
							<td><?php echo esc_html( sprintf( '%.4f $', (float) $row['spend'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( round( (float) $row['input_tokens'] ) ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( round( (float) $row['output_tokens'] ) ) ); ?></td>
							<td><?php echo esc_html( null === $row['score'] ? '—' : round( 100 * (float) $row['score'] ) . ' %' ); ?></td>
							<td><?php echo esc_html( $row['failures'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="msrwa-card">
				<h2>Par modèle</h2>
				<table class="widefat striped">
					<thead><tr><th>Fournisseur / modèle</th><th>Appels</th><th>Entrée</th><th>En cache</th><th>Sortie</th><th>Durée moy.</th><th>Dépense</th><th>Sans tarif</th></tr></thead>
					<tbody>
					<?php foreach ( MSRWA_Lab_Stats::by_model( $days ) as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['provider'] . ' / ' . $row['model'] ); ?></td>
							<td><?php echo esc_html( $row['calls'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $row['input_tokens'] ) ); ?></td>
							<td><?php echo esc_html( (int) $row['input_tokens'] ? round( 100 * (int) $row['cached_tokens'] / (int) $row['input_tokens'] ) . ' %' : '—' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $row['output_tokens'] ) ); ?></td>
							<td><?php echo esc_html( sprintf( '%.1f s', (float) $row['seconds'] ) ); ?></td>
							<td><?php echo esc_html( sprintf( '%.4f $', (float) $row['spend'] ) ); ?></td>
							<td><?php echo esc_html( $row['unpriced'] ? $row['unpriced'] . ' appel(s)' : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="msrwa-card">
				<h2>Contrôles qui échouent</h2>
				<p class="description">Un contrôle qui échoue une fois sur six est du bruit ; six fois sur six, c’est un contrat que le prompt ne tient pas.</p>
				<table class="widefat striped">
					<thead><tr><th>Étape</th><th>Contrôle</th><th>Échecs</th><th>Sur</th><th>Taux</th></tr></thead>
					<tbody>
					<?php $checks = MSRWA_Lab_Stats::checks( $days ); ?>
					<?php if ( ! $checks ) : ?><tr><td colspan="5">Aucun contrôle en échec.</td></tr><?php endif; ?>
					<?php foreach ( $checks as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['step'] ); ?></td>
							<td><code><?php echo esc_html( $row['check'] ); ?></code></td>
							<td><?php echo esc_html( $row['failed'] ); ?></td>
							<td><?php echo esc_html( $row['seen'] ); ?></td>
							<td><?php echo esc_html( round( 100 * $row['failed'] / max( 1, $row['seen'] ) ) . ' %' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
}
