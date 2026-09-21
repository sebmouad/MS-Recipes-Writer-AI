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
		add_submenu_page( null, 'Rapport du laboratoire', 'Rapport du laboratoire', 'manage_options', 'ms-recipes-writer-ai-lab-report', array( __CLASS__, 'report' ) );
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
								<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ms-recipes-writer-ai-lab-report&run_id=' . (int) $run['id'] ) ); ?>" target="_blank">Rapport</a>
								<?php if ( in_array( $run['status'], array( 'queued', 'running' ), true ) ) : ?>
									<button class="button msrwa-lab-cancel" data-run="<?php echo esc_attr( $run['id'] ); ?>">Arrêter</button>
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
		$state = json_decode( (string) $run['result_json'], true );
		if ( ! is_array( $state ) || empty( $state['steps'] ) ) { wp_die( esc_html__( 'Ce run n’a encore rien produit.', 'ms-recipes-writer-ai' ) ); }
		require_once MSRWA_DIR . 'tools/report.php';
		// The report is a complete document with its own head and styles; the
		// admin's chrome would only get in its way.
		header( 'Content-Type: text/html; charset=utf-8' );
		echo report_render( $state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer escapes its own values.
		exit;
	}
}
