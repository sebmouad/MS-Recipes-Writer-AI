<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Credentials, defaults, and whether the machinery that carries the work is
 * actually running.
 *
 * Health sits on the same screen as the keys on purpose: the two ways a lot
 * sits still saying nothing are a missing key and a cron that never fires, and
 * an operator should find both in the same place.
 */
final class MSRWA_Screen_Settings {

	public static function render() {
		if ( ! MSRWA_Rights::may_manage() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }
		$configured = MSRWA_Settings::configured_providers();

		echo '<div class="wrap msrwa">';
		MSRWA_UI::head(
			__( 'Réglages', 'ms-recipes-writer-ai' ),
			__( 'Les clés, et l’état de ce qui fait avancer le travail.', 'ms-recipes-writer-ai' )
		);

		if ( isset( $_GET['saved'] ) ) { MSRWA_UI::note( esc_html__( 'Enregistré.', 'ms-recipes-writer-ai' ) ); }
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="msrwa_save_settings">
			<?php wp_nonce_field( 'msrwa_save_settings' ); ?>
			<section class="ms-card">
				<h2><?php esc_html_e( 'Clés d’API', 'ms-recipes-writer-ai' ); ?></h2>
				<p><?php esc_html_e( 'Chiffrées à l’enregistrement et jamais réaffichées. Un champ laissé vide conserve la clé enregistrée ; il ne l’efface pas.', 'ms-recipes-writer-ai' ); ?></p>
				<?php
				foreach ( array(
					'openai_key' => array( 'OpenAI', 'openai' ),
					'gemini_key' => array( 'Gemini', 'gemini' ),
					'claude_key' => array( 'Claude', 'claude' ),
				) as $field => $provider ) :
					$stored = in_array( $provider[1], $configured, true );
					?>
					<p>
						<label for="ms-<?php echo esc_attr( $field ); ?>"><strong><?php echo esc_html( $provider[0] ); ?></strong></label>
						<?php if ( $stored ) : ?><span class="ms-state ms-state-good"><?php esc_html_e( 'clé enregistrée', 'ms-recipes-writer-ai' ); ?></span><?php endif; ?>
						<br>
						<input type="password" id="ms-<?php echo esc_attr( $field ); ?>" name="msrwa_settings[<?php echo esc_attr( $field ); ?>]" value="" class="regular-text" autocomplete="off"
							placeholder="<?php echo esc_attr( $stored ? __( 'inchangée', 'ms-recipes-writer-ai' ) : __( 'aucune clé', 'ms-recipes-writer-ai' ) ); ?>">
					</p>
				<?php endforeach; ?>
				<?php if ( $configured ) : ?>
					<p class="ms-keys-check">
						<button type="button" class="button" id="ms-check-keys"><?php esc_html_e( 'Vérifier les clés', 'ms-recipes-writer-ai' ); ?></button>
						<span class="ms-muted"><?php esc_html_e( 'Demande à chaque fournisseur la liste de ses modèles : gratuit, et la seule preuve qu’un vrai appel passera.', 'ms-recipes-writer-ai' ); ?></span>
					</p>
					<ul id="ms-keys-result" class="ms-keys-result" aria-live="polite"></ul>
				<?php endif; ?>
			</section>
			<section class="ms-card">
				<h2><?php esc_html_e( 'Plafonds de dépense', 'ms-recipes-writer-ai' ); ?></h2>
				<p><?php esc_html_e( 'Trois plafonds, pour trois craintes différentes. Celui par recette arrête un article emballé ; celui du jour arrête un mauvais après-midi ; celui du mois arrête un mauvais mois que personne n’a vu venir. Ils sont vérifiés avant qu’un lot parte et avant chaque vague de chaque recette, parce qu’un lot qui tenait au départ peut cesser de tenir en cours de route. À zéro, aucun plafond.', 'ms-recipes-writer-ai' ); ?></p>
				<?php $settings = MSRWA_Settings::get(); ?>
				<p>
					<label for="ms-per-recipe"><strong><?php esc_html_e( 'Par recette', 'ms-recipes-writer-ai' ); ?></strong></label><br>
					<input type="number" id="ms-per-recipe" name="msrwa_settings[per_recipe_budget_usd]" value="<?php echo esc_attr( (float) $settings['per_recipe_budget_usd'] ); ?>" step="0.01" min="0" class="small-text ms-num"> $
					<br><small class="ms-muted"><?php esc_html_e( 'S’applique à chaque recette de chaque lot : le formulaire de lot n’en propose plus.', 'ms-recipes-writer-ai' ); ?></small>
					<?php $recipe = MSRWA_Estimate::recipe( MSRWA_Profile::FULL ); ?>
					<br><small class="ms-muted<?php echo MSRWA_Estimate::fits( (float) $recipe['cost_usd'], (float) $settings['per_recipe_budget_usd'] ) ? '' : ' ms-warn'; ?>"><?php
						echo esc_html( sprintf(
							/* translators: 1: the expected cost of one complete recipe, 2: the most it can cost when every final approval refuses. */
							__( 'Avec le routage actuel, une recette complète est estimée à %1$s, et jusqu’à %2$s si l’approbation finale refuse à chaque tentative. Un plafond sous la première valeur refuse le lot au lancement ; sous la seconde, il arrête les nouvelles tentatives avant de le franchir, et la recette se termine avec le dernier verdict.', 'ms-recipes-writer-ai' ),
							MSRWA_I18N::money( (float) $recipe['cost_usd'], 4 ),
							MSRWA_I18N::money( (float) $recipe['max_usd'], 4 )
						) );
					?></small>
				</p>
				<p>
					<label for="ms-daily"><strong><?php esc_html_e( 'Par jour', 'ms-recipes-writer-ai' ); ?></strong></label><br>
					<input type="number" id="ms-daily" name="msrwa_settings[daily_budget_usd]" value="<?php echo esc_attr( (float) ( $settings['daily_budget_usd'] ?? 0 ) ); ?>" step="0.5" min="0" class="small-text ms-num"> $
				</p>
				<p>
					<label for="ms-monthly"><strong><?php esc_html_e( 'Sur trente jours', 'ms-recipes-writer-ai' ); ?></strong></label><br>
					<input type="number" id="ms-monthly" name="msrwa_settings[monthly_budget_usd]" value="<?php echo esc_attr( (float) ( $settings['monthly_budget_usd'] ?? 0 ) ); ?>" step="1" min="0" class="small-text ms-num"> $
				</p>
			</section>

			<section class="ms-card">
				<h2><?php esc_html_e( 'Types de lot ouverts aux rédacteurs', 'ms-recipes-writer-ai' ); ?></h2>
				<p><?php esc_html_e( 'Ce qu’un rédacteur peut lancer. Un type décoché disparaît de son formulaire et est refusé s’il est demandé autrement. Les administrateurs gardent les trois. Il en reste toujours au moins un : tout décocher les rouvre tous.', 'ms-recipes-writer-ai' ); ?></p>
				<input type="hidden" name="msrwa_settings[editor_profiles_sent]" value="1">
				<div class="ms-choices">
					<?php foreach ( MSRWA_Profile::all() as $key => $profile ) : ?>
						<label class="ms-choice">
							<input type="checkbox" name="msrwa_settings[editor_profiles][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $settings['editor_profiles'], true ) ); ?>>
							<span class="ms-choice-body">
								<strong><?php echo esc_html( $profile['label'] ); ?></strong>
								<small><?php echo esc_html( $profile['description'] ); ?></small>
							</span>
							<span class="ms-choice-cost">~ <?php echo esc_html( MSRWA_I18N::money( MSRWA_Estimate::recipe( $key )['cost_usd'], 4 ) ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="ms-card">
				<h2><?php esc_html_e( 'Les articles', 'ms-recipes-writer-ai' ); ?></h2>
				<p><?php esc_html_e( 'Ce que chaque article doit être. Ces réglages atteignent directement les prompts et les contrôles du moteur.', 'ms-recipes-writer-ai' ); ?></p>
				<p>
					<label for="ms-site-language"><strong><?php esc_html_e( 'Langue des articles', 'ms-recipes-writer-ai' ); ?></strong></label><br>
					<select id="ms-site-language" name="msrwa_settings[site_language]">
						<?php foreach ( MSRWA_Profile::languages() as $code => $name ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( (string) $settings['site_language'], $code ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
					<br><small class="ms-muted"><?php esc_html_e( 'La langue de chaque article, pour tous les lots : le formulaire de lot n’en propose plus.', 'ms-recipes-writer-ai' ); ?></small>
				</p>
				<p>
					<label for="ms-min-words"><strong><?php esc_html_e( 'Longueur', 'ms-recipes-writer-ai' ); ?></strong></label><br>
					<input type="number" id="ms-min-words" name="msrwa_settings[quality_min_words]" value="<?php echo esc_attr( (int) $settings['quality_min_words'] ); ?>" step="100" min="300" max="8000" class="small-text ms-num">
					<?php esc_html_e( 'à', 'ms-recipes-writer-ai' ); ?>
					<input type="number" id="ms-max-words" name="msrwa_settings[quality_max_words]" value="<?php echo esc_attr( (int) $settings['quality_max_words'] ); ?>" step="100" min="500" max="10000" class="small-text ms-num" aria-label="<?php esc_attr_e( 'Nombre de mots maximal', 'ms-recipes-writer-ai' ); ?>">
					<?php esc_html_e( 'mots', 'ms-recipes-writer-ai' ); ?>
					<br><small class="ms-muted"><?php esc_html_e( 'Le minimum est une exigence : un article plus court échoue à son contrôle. Plus de mots coûtent plus cher à écrire et à relire.', 'ms-recipes-writer-ai' ); ?></small>
					<?php $msrwa_range = MSRWA_Prompt::word_range( array_merge( $settings, array( 'site_language' => 'ar' ) ) ); ?>
					<br><small class="ms-muted"><?php echo esc_html( sprintf(
						/* translators: 1: minimum words, 2: maximum words. */
						__( 'Comptée pour le français. Un article en arabe, qui dit la même chose en moins de mots, vise %1$s à %2$s mots.', 'ms-recipes-writer-ai' ),
						number_format_i18n( $msrwa_range['min'] ), number_format_i18n( $msrwa_range['max'] )
					) ); ?></small>
				</p>
				<p>
					<input type="hidden" name="msrwa_settings[article_pagination_enabled]" value="0">
					<label><input type="checkbox" name="msrwa_settings[article_pagination_enabled]" value="1" <?php checked( ! empty( $settings['article_pagination_enabled'] ) ); ?>>
						<strong><?php esc_html_e( 'Couper l’article en deux pages', 'ms-recipes-writer-ai' ); ?></strong></label>
				</p>
				<p>
					<label for="ms-page2"><strong><?php esc_html_e( 'Titre qui ouvre la seconde page', 'ms-recipes-writer-ai' ); ?></strong></label><br>
					<input type="text" id="ms-page2" name="msrwa_settings[article_page2_heading]" value="<?php echo esc_attr( (string) $settings['article_page2_heading'] ); ?>" class="regular-text" maxlength="120">
				</p>
				<p>
					<input type="hidden" name="msrwa_settings[recipe_schema]" value="0">
					<label><input type="checkbox" name="msrwa_settings[recipe_schema]" value="1" <?php checked( ! empty( $settings['recipe_schema'] ) ); ?>>
						<strong><?php esc_html_e( 'Publier la recette en données structurées (Recipe JSON-LD)', 'ms-recipes-writer-ai' ); ?></strong></label>
					<br><small class="ms-muted"><?php echo esc_html( MSRWA_Schema::another_plugin_prints_it()
						? __( 'Une extension de recettes active imprime déjà les siennes : rien n’est ajouté, pour ne pas décrire deux fois le même plat.', 'ms-recipes-writer-ai' )
						: __( 'Sur les articles publiés seulement. Ce que Google lit pour afficher une recette enrichie : temps, portions, ingrédients, étapes, calories.', 'ms-recipes-writer-ai' ) ); ?></small>
				</p>
				<p>
					<input type="hidden" name="msrwa_settings[seo_meta]" value="0">
					<label><input type="checkbox" name="msrwa_settings[seo_meta]" value="1" <?php checked( ! empty( $settings['seo_meta'] ) ); ?>>
						<strong><?php esc_html_e( 'Publier la description SEO et l’aperçu de partage (Open Graph)', 'ms-recipes-writer-ai' ); ?></strong></label>
					<br><small class="ms-muted"><?php echo esc_html( MSRWA_Head::another_plugin_prints_it()
						? __( 'Une extension SEO active s’en charge déjà : rien n’est ajouté, pour ne pas décrire deux fois la même page.', 'ms-recipes-writer-ai' )
						: __( 'Sur les articles publiés par ce plugin seulement. Le titre et la description écrits par l’article, et l’image de partage, pour Google, Facebook et X.', 'ms-recipes-writer-ai' ) ); ?></small>
				</p>
			</section>

			<div class="ms-card ms-save"><?php submit_button( __( 'Enregistrer', 'ms-recipes-writer-ai' ), 'primary', 'submit', false ); ?></div>
		</form>

		<?php self::style_references(); ?>
		<?php self::budget(); ?>
		<?php
		self::health();
		echo '</div>';
	}

	/** Where each ceiling stands right now. */
	private static function budget() {
		$state = MSRWA_Budget::state();
		if ( ! $state['daily']['ceiling'] && ! $state['monthly']['ceiling'] ) { return; }

		echo '<section class="ms-card"><h2>' . esc_html__( 'Où en sont les plafonds', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'La dépense du site entier, pas celle d’un rédacteur : un plafond appartient au site, et quelqu’un qui n’en verrait que sa part ne comprendrait jamais pourquoi son lot a été refusé.', 'ms-recipes-writer-ai' ) . '</p>';
		echo '<table class="ms-table ms-share"><tbody>';
		foreach ( array( 'daily' => __( 'aujourd’hui', 'ms-recipes-writer-ai' ), 'monthly' => __( 'trente jours', 'ms-recipes-writer-ai' ) ) as $name => $label ) {
			$budget = $state[ $name ];
			if ( ! $budget['ceiling'] ) { continue; }
			echo '<tr><td class="ms-share-label">' . esc_html( $label ) . '</td>'
				. '<td class="ms-bar"><span class="ms-progress">'
				. '<i style="inline-size:' . (int) $budget['share'] . '%' . ( $budget['exceeded'] ? ';background:var(--ms-stop)' : '' ) . '"></i></span></td>'
				. '<td class="ms-num">'
				. esc_html( sprintf(
					/* translators: 1: amount spent, 2: the ceiling. */
					__( '%1$s sur %2$s', 'ms-recipes-writer-ai' ),
					MSRWA_I18N::money( $budget['spent'], 2 ),
					MSRWA_I18N::money( $budget['ceiling'], 2 )
				) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$refusal = MSRWA_Budget::refusal();
		if ( '' !== $refusal ) { MSRWA_UI::note( esc_html( $refusal ), 'stop' ); }
		echo '</section>';
	}

	/**
	 * Whether the work can actually move.
	 *
	 * Every line here answers a question an operator asks when nothing is
	 * happening, and says what to do rather than only what is true.
	 */
	private static function health() {
		$next = wp_next_scheduled( 'msrwa_cleanup' );
		$pending = MSRWA_Ledger::now();
		$uploads = wp_upload_dir();
		$writable = wp_is_writable( $uploads['basedir'] );
		$cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		echo '<section class="ms-card"><h2>' . esc_html__( 'État de la machinerie', 'ms-recipes-writer-ai' ) . '</h2>';

		if ( $cron_off ) {
			MSRWA_UI::note( esc_html__( 'DISABLE_WP_CRON est actif. C’est la configuration recommandée, mais elle suppose qu’un cron serveur appelle wp-cron.php : sans lui, les recettes resteront en attente indéfiniment.', 'ms-recipes-writer-ai' ), 'warn' );
		}
		if ( ! $writable ) {
			MSRWA_UI::note( esc_html__( 'Le dossier des téléversements n’est pas accessible en écriture : aucune image générée ne pourra être enregistrée.', 'ms-recipes-writer-ai' ), 'stop' );
		}

		MSRWA_UI::figures( array(
			array( 'label' => __( 'en attente', 'ms-recipes-writer-ai' ), 'value' => number_format_i18n( $pending['moving'] ) ),
			array(
				'label' => __( 'cron', 'ms-recipes-writer-ai' ),
				'value' => $cron_off ? __( 'serveur', 'ms-recipes-writer-ai' ) : __( 'WordPress', 'ms-recipes-writer-ai' ),
				'note' => $next ? MSRWA_I18N::when( gmdate( 'Y-m-d H:i:s', $next ) ) : __( 'aucun entretien planifié', 'ms-recipes-writer-ai' ),
			),
			array(
				'label' => __( 'téléversements', 'ms-recipes-writer-ai' ),
				'value' => $writable ? __( 'accessibles', 'ms-recipes-writer-ai' ) : __( 'bloqués', 'ms-recipes-writer-ai' ),
			),
		) );
		echo '</section>';

		self::retention();
	}

	/**
	 * The owner's collages whose look the Facebook collage keeps. The first is
	 * the one drawn from; a writer's own photograph of the dish comes before it.
	 */
	private static function style_references() {
		$paths = MSRWA_Sources::style_paths();
		$refused = get_transient( 'msrwa_style_refused_' . get_current_user_id() );
		if ( false !== $refused ) { delete_transient( 'msrwa_style_refused_' . get_current_user_id() ); }
		echo '<section class="ms-card" id="ms-style"><h2>' . esc_html__( 'Style du collage Facebook', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Un de vos collages dont le rendu doit être repris : lumière, couleurs, cadrage, plan de travail. Seul son style est repris, jamais son plat. Quand le rédacteur a envoyé une photographie du plat, c’est elle qui sert de référence.', 'ms-recipes-writer-ai' ) . '</p>';
		if ( false !== $refused ) { MSRWA_UI::note( esc_html( (string) $refused ), 'warn' ); }
		if ( ! $paths && '' !== MSRWA_Sources::default_style() ) {
			$preview = MSRWA_Sources::style_preview( MSRWA_Sources::default_style() );
			echo '<div class="ms-style-list"><div class="ms-style-item">';
			if ( '' !== $preview ) { echo '<img src="' . esc_attr( $preview ) . '" alt="" width="180">'; }
			echo '<p><strong>' . esc_html__( 'Utilisée', 'ms-recipes-writer-ai' ) . '</strong> · ' . esc_html__( 'référence fournie avec l’extension', 'ms-recipes-writer-ai' ) . '</p></div></div>';
			echo '<p class="ms-muted">' . esc_html__( 'Ajoutez votre propre collage pour la remplacer ; retirez-le pour y revenir.', 'ms-recipes-writer-ai' ) . '</p>';
		} elseif ( ! $paths ) {
			echo '<p class="ms-muted">' . esc_html__( 'Aucune référence : le collage est dessiné à partir du texte seul.', 'ms-recipes-writer-ai' ) . '</p>';
		} else {
			echo '<div class="ms-style-list">';
			foreach ( $paths as $index => $path ) {
				$preview = MSRWA_Sources::style_preview( $path );
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ms-style-item">';
				echo '<input type="hidden" name="action" value="msrwa_style">';
				wp_nonce_field( 'msrwa_style' );
				echo '<input type="hidden" name="remove" value="' . esc_attr( basename( $path ) ) . '">';
				if ( '' !== $preview ) { echo '<img src="' . esc_attr( $preview ) . '" alt="" width="180">'; }
				echo '<p>' . ( 0 === $index ? '<strong>' . esc_html__( 'Utilisée', 'ms-recipes-writer-ai' ) . '</strong>' : esc_html__( 'En réserve', 'ms-recipes-writer-ai' ) ) . ' ';
				submit_button( __( 'Retirer', 'ms-recipes-writer-ai' ), 'link-delete', 'submit', false );
				echo '</p></form>';
			}
			echo '</div>';
		}
		if ( count( $paths ) < MSRWA_Sources::STYLE_MAX ) {
			echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="msrwa_style">';
			wp_nonce_field( 'msrwa_style' );
			echo '<p><label for="ms-style-file">' . esc_html__( 'Ajouter un collage (JPEG, PNG ou WebP, 10 Mo au plus)', 'ms-recipes-writer-ai' ) . '</label><br>';
			echo '<input type="file" id="ms-style-file" name="style" accept="image/jpeg,image/png,image/webp" required> ';
			submit_button( __( 'Ajouter', 'ms-recipes-writer-ai' ), 'secondary', 'submit', false );
			echo '</p></form>';
		}
		echo '</section>';
	}

	/**
	 * What is kept, for how long, and what that currently weighs.
	 *
	 * On the same screen as the keys because it is the other thing an operator
	 * comes here to check, and because a plugin that quietly grows without
	 * limit should at least say how big it has got.
	 */
	private static function retention() {
		$settings = MSRWA_Settings::get();
		$policy = MSRWA_Retention::policy();
		$weight = MSRWA_Retention::weight();
		$last = MSRWA_Retention::last();

		$ages = array(
			'events' => array( 'retention_events_days', __( 'Déroulé', 'ms-recipes-writer-ai' ), __( 'La narration minute par minute. La table qui grossit le plus vite, et celle que personne ne relit.', 'ms-recipes-writer-ai' ) ),
			'artifacts' => array( 'retention_artifacts_days', __( 'Productions lourdes', 'ms-recipes-writer-ai' ), __( 'Le dossier de recherche, l’article tel que la machine l’a écrit, les prompts d’image. Le verdict, la revue et la recette restent quoi qu’il arrive.', 'ms-recipes-writer-ai' ) ),
			'runs' => array( 'retention_runs_days', __( 'Runs entiers', 'ms-recipes-writer-ai' ), __( 'Y compris les chiffres. Laissé à zéro par défaut : une ligne d’étape est le seul témoignage de ce qu’un run a coûté. Un run qui a produit un brouillon n’est jamais supprimé.', 'ms-recipes-writer-ai' ) ),
		);

		echo '<section class="ms-card"><h2>' . esc_html__( 'Ce qui est conservé', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Trois durées, parce que trois choses vieillissent différemment. À zéro, rien de cette sorte n’est jamais supprimé.', 'ms-recipes-writer-ai' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="msrwa_save_settings">';
		wp_nonce_field( 'msrwa_save_settings' );

		$pinned = array();
		foreach ( $ages as $name => $age ) {
			list( $key, $label, $why ) = $age;
			$set = (int) ( $settings[ $key ] ?? 0 );
			if ( $set !== (int) $policy[ $name ] ) { $pinned[] = $label; }
			echo '<p><label for="ms-' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>'
				. '<input type="number" id="ms-' . esc_attr( $key ) . '" name="msrwa_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $set ) . '" step="1" min="0" max="3650" class="small-text ms-num"> '
				. esc_html__( 'jours', 'ms-recipes-writer-ai' )
				. '<br><small class="ms-muted">' . esc_html( $why ) . '</small></p>';
		}
		echo '<p>';
		submit_button( __( 'Enregistrer les durées', 'ms-recipes-writer-ai' ), 'secondary', 'submit', false );
		echo '</p></form>';

		// A filter beats the field, so a screen that showed only the field would
		// be telling an operator something that is not going to happen.
		if ( $pinned ) {
			MSRWA_UI::note( esc_html( sprintf(
				/* translators: %s is a list of names, e.g. "Déroulé, Runs entiers". */
				__( 'Un filtre impose une autre durée pour : %s. C’est le filtre qui s’applique, pas ce qui est saisi ici.', 'ms-recipes-writer-ai' ),
				implode( ', ', $pinned )
			) ), 'warn' );
		}

		MSRWA_UI::figures( array(
			array(
				'label' => __( 'poids actuel', 'ms-recipes-writer-ai' ),
				'value' => size_format( $weight['artifact_bytes'] ),
				'note' => sprintf(
					/* translators: 1: number of runs, 2: number of timeline entries. */
					__( '%1$s run(s), %2$s lignes de déroulé', 'ms-recipes-writer-ai' ),
					number_format_i18n( $weight['runs'] ),
					number_format_i18n( $weight['events'] )
				),
			),
		) );

		echo '<div class="ms-row">';
		if ( $last['at'] ) {
			echo '<span class="ms-muted">' . esc_html( sprintf(
				/* translators: 1: how long ago, 2: timeline rows removed, 3: heavy outputs removed, 4: whole runs removed. */
				__( 'Dernier passage il y a %1$s : %2$s lignes de déroulé, %3$s productions, %4$s runs.', 'ms-recipes-writer-ai' ),
				human_time_diff( (int) strtotime( $last['at'] . ' UTC' ), time() ),
				number_format_i18n( $last['events'] ),
				number_format_i18n( $last['artifacts'] ),
				number_format_i18n( $last['runs'] )
			) ) . '</span>';
		} else {
			echo '<span class="ms-muted">' . esc_html__( 'Le nettoyage n’a encore jamais tourné.', 'ms-recipes-writer-ai' ) . '</span>';
		}
		echo '<span id="ms-prune-status" class="ms-muted" aria-live="polite"></span>';
		echo '<button type="button" class="button" id="ms-prune">' . esc_html__( 'Nettoyer maintenant', 'ms-recipes-writer-ai' ) . '</button>';
		echo '</div>';

		echo '<p class="ms-muted">' . esc_html__( 'Un passage est borné : il retire ce qu’il peut sans faire tomber la requête, et reprend au suivant. Chaque durée est aussi un filtre — msrwa_retention_events_days, msrwa_retention_artifacts_days, msrwa_retention_runs_days.', 'ms-recipes-writer-ai' ) . '</p>';
		echo '</section>';
	}
}
