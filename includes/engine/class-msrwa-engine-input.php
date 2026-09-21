<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What each step is given before it runs.
 *
 * A step's input is the whole of what it can act on, so this is where the
 * pipeline's knowledge actually flows: the editor's brief, the research package
 * trimmed to what the step can use, and the visual brief derived from the recipe
 * and the photographs that were read.
 */
final class MSRWA_Engine_Input {

	/**
	 * Where the engine reads prompts and where the lab keeps its briefs and runs.
	 *
	 * Prompts are the engine's own data and sit beside it. Briefs and saved runs
	 * belong to whoever is driving it — the lab sets this; the plugin never does,
	 * because it passes its data in directly.
	 */
	private static $lab_root = '';

	public static function use_lab_root( $path ) { self::$lab_root = rtrim( (string) $path, '/' ); }

	public static function prompt_path( $file ) { return __DIR__ . '/prompts/' . $file; }

	private static function lab_path( $file ) {
		$root = self::$lab_root ? self::$lab_root : dirname( __DIR__, 2 ) . '/tools';
		return $root . '/' . $file;
	}

	/**
	 * The settings the engine runs against.
	 *
	 * The plugin hands its own in; standalone, the engine reads the shipped
	 * defaults directly, which is what makes a lab run and a production run the
	 * same run. Set once per process by MSRWA_Engine::configure().
	 */
	private static $settings = array();

	public static function use_settings( array $settings ) { self::$settings = $settings; }

	public static function settings() {
		if ( self::$settings ) { return self::$settings; }
		// Nobody handed settings in, so fall back to the shipped defaults. A caller
		// that cares — the plugin, or the lab reading what ships — calls
		// use_settings() first and this never runs.
		if ( class_exists( 'MSRWA_Settings' ) && method_exists( 'MSRWA_Settings', 'defaults' ) ) { self::$settings = MSRWA_Settings::defaults(); }
		return self::$settings;
	}

	/** Normalizes title-, article- and image-led editor briefs into one contract. */
	public static function editor_brief( $brief ) {
		// A malformed editor_input used to be forwarded as-is. The research model then
		// answered, truthfully, that it had been given no dish to research, and the run
		// still cost money and scored seven of ten.
		if ( isset( $brief['editor_input'] ) && is_array( $brief['editor_input'] ) ) {
			$editor = $brief['editor_input'];
			if ( '' === trim( (string) ( $editor['title'] ?? '' ) ) && '' === trim( (string) ( $editor['text'] ?? '' ) ) ) {
				fwrite( STDERR, "The brief's editor_input carries neither a title nor a text; there is nothing to research.\n" );
				exit( 2 );
			}
			return $editor;
		}
		return array(
			'type' => 'article',
			'title' => (string) ( $brief['title'] ?? '' ),
			'text' => (string) ( $brief['text'] ?? '' ),
			'images' => array_values( (array) ( $brief['images'] ?? array() ) ),
		);
	}

	/** Loads a saved research run or the fixture package. Downstream stages share it unchanged. */
	public static function research_package( $brief, $options = array() ) {
		$file = (string) ( $options['research'] ?? '' );
		if ( '' === $file ) { return is_array( $brief['research'] ?? null ) ? $brief['research'] : array(); }
		if ( ! file_exists( $file ) ) { fwrite( STDERR, "No such research package: {$file}\n" ); exit( 2 ); }
		$data = json_decode( file_get_contents( $file ), true );
		if ( isset( $data['output'] ) && is_string( $data['output'] ) ) { $data = MSRWA_Json::decode( $data['output'] ); }
		if ( ! is_array( $data ) ) { fwrite( STDERR, "Research package is not valid JSON: {$file}\n" ); exit( 2 ); }
		return $data;
	}

	/** Loads the saved canonical step when supplied, otherwise the fixture recipe. */
	public static function canonical_recipe( $brief, $options = array() ) {
		$file = (string) ( $options['canonical'] ?? '' );
		if ( '' === $file ) { return is_array( $brief['canonical'] ?? null ) ? $brief['canonical'] : array(); }
		if ( ! file_exists( $file ) ) { fwrite( STDERR, "No such canonical recipe: {$file}\n" ); exit( 2 ); }
		$data = json_decode( file_get_contents( $file ), true );
		if ( isset( $data['output'] ) && is_string( $data['output'] ) ) { $data = MSRWA_Json::decode( $data['output'] ); }
		if ( ! is_array( $data ) ) { fwrite( STDERR, "Canonical recipe is not valid JSON: {$file}\n" ); exit( 2 ); }
		return $data;
	}

	public static function brief( $name ) {
		$file = self::lab_path( 'fixtures/' . basename( $name ) . '.json' );
		if ( ! file_exists( $file ) ) { fwrite( STDERR, "No such brief: {$file}\n" ); exit( 2 ); }
		$brief = json_decode( file_get_contents( $file ), true );
		if ( ! is_array( $brief ) ) { fwrite( STDERR, "Brief is not valid JSON: {$file}\n" ); exit( 2 ); }
		return $brief;
	}

	/**
	 * One observation field as prose. The vision model returns some of these as a
	 * list of sentences and some as a sentence; casting a list to string yields the
	 * word "Array", which then travels into an image prompt as the description of
	 * the dish.
	 */
	public static function observation_text( $value ) {
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $item ) { if ( is_scalar( $item ) ) { $parts[] = trim( (string) $item ); } }
			return trim( implode( ' ', array_filter( $parts ) ) );
		}
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Turns the recipe and the research into constraints an image model can obey,
	 * instead of the JSON dump it used to receive.
	 *
	 * Every line here answers a failure the approval step actually raised: two
	 * pastry rolls where the recipe says one, seven apples where it says six, a
	 * cooling rack and a serving board that appear in no step, and a final panel
	 * browner than the photographs of the real dish.
	 */
	public static function visual_brief( $canonical, $research ) {
		$countable = array( 'pièce', 'pièces', 'piece', 'pieces', 'rouleau', 'rouleaux', 'gousse', 'gousses', 'tranche', 'tranches', 'feuille', 'feuilles', 'branche', 'branches', 'oeuf', 'œuf', 'unité', 'unités', '' );
		$counts = array();
		$measured = array();
		foreach ( (array) ( $canonical['ingredients'] ?? array() ) as $ingredient ) {
			if ( ! is_array( $ingredient ) ) { continue; }
			$name = trim( (string) ( $ingredient['name'] ?? '' ) );
			$quantity = trim( (string) ( $ingredient['quantity'] ?? '' ) );
			$unit = trim( (string) ( $ingredient['unit'] ?? '' ) );
			if ( '' === $name ) { continue; }
			if ( '' !== $quantity && is_numeric( str_replace( ',', '.', $quantity ) ) && in_array( mb_strtolower( $unit ), $countable, true ) ) {
				$counts[] = $name . ' — exactly ' . $quantity . ( '' === $unit ? '' : ' ' . $unit );
			} else {
				$measured[] = trim( $quantity . ' ' . $unit . ' ' . $name );
			}
		}

		$lines = array( 'VISUAL BRIEF — derived from this recipe and binding. Each line below exists because a real image failed on it.' );
		$lines[] = '• NO GARNISH THAT IS NOT AN INGREDIENT. The most common defect in these images, across every dish tried, is a sprig of herb laid on the finished plate — rosemary, thyme, parsley, coriander, a bay leaf — because that is how this kind of dish is usually photographed. If the ingredient list below does not contain it, it does not go in the picture, in any panel, however conventional it looks. The same applies to a citrus wedge, a grind of visible spice, a drizzle, a dusting or a scattering of seeds. Serve the dish bare rather than garnish it with something the cook was never told to buy.';
		if ( $counts ) {
			$lines[] = '• Countable ingredients, exact numbers: ' . implode( '; ', $counts ) . '. Where a panel lays the ingredients out — the mise en place — show exactly these numbers, not one more pack, roll, fruit or egg "for composition". This binds the ingredient display only. A later panel showing the dish being made or served need not have them all in shot, and a few of the same fruit resting in the background of a finished shot is styling, not a miscount.';
		}
		if ( $measured ) {
			$lines[] = '• Measured ingredients, no count to respect, show a believable amount: ' . implode( '; ', $measured ) . '.';
		}
		$equipment = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) ( $canonical['equipment'] ?? array() ) ) ) ) );
		if ( $equipment ) {
			$lines[] = '• The cookware this recipe names: ' . implode( ', ', $equipment ) . '. These must be the ones actually used for the steps that need them, and in one colour and material throughout — the same tin in every panel it appears in. Ordinary kitchen things a cook obviously needs to perform a step are fine and expected: a board to peel on, a bowl to mix in, a spoon, a knife, a cloth. What is a defect is a support that changes how the finished dish is presented — a cooling rack, a board or a plate standing in for the serving vessel in the last panel alone, or a second tin of a different colour.';
		}
		$servings = (int) ( $canonical['servings'] ?? 0 );
		$cook = (int) ( $canonical['cook_minutes'] ?? 0 );
		if ( $servings > 0 || $cook > 0 ) {
			$lines[] = '• Scale and doneness: ' . ( $servings > 0 ? 'serves ' . $servings . '. ' : '' ) . ( $cook > 0 ? 'Cooked ' . $cook . ' minutes, so the colour is what that produces — not darker for drama.' : '' );
		}

		$observed = array();
		foreach ( (array) ( $research['visual_observations'] ?? array() ) as $observation ) {
			if ( ! is_array( $observation ) ) { continue; }
			foreach ( array( 'colours', 'textures', 'observable_details', 'composition' ) as $key ) {
				$value = self::observation_text( $observation[ $key ] ?? '' );
				if ( '' !== $value ) { $observed[] = $value; }
			}
		}
		if ( $observed ) {
			$lines[] = '• How the real dish looks, observed in photographs of it: ' . implode( ' ', array_slice( array_unique( $observed ), 0, 8 ) ) . ' The finished dish must match this. It may not look more cooked, more darkly coloured or more elaborately garnished than these observations describe.';
		}

		// Every image is generated in its own call, so nothing makes them agree unless
		// the same decision is written into both. Three refusals in four came from the
		// featured photograph and the collage's last panel serving the dish differently.
		$lines[] = '• ONE SERVING PRESENTATION, shared by every image of this recipe: ' . self::serving_presentation( $canonical, $research ) . ' The featured photograph and the last panel of the collage must show the finished dish presented that same way, in the same vessel and at the same degree of colour. They are two photographs of one dish, taken minutes apart.';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The single way the finished dish is presented, as a decision rather than a
	 * description. Describing the dish was not enough: two calls that each read
	 * "whole, seen at three quarters" still chose a plate and a tin, and the
	 * approval step blocked the pair every time. The vessel has to be named.
	 */
	public static function serving_presentation( $canonical, $research ) {
		$text = '';
		foreach ( (array) ( $research['visual_observations'] ?? array() ) as $observation ) {
			if ( ! is_array( $observation ) ) { continue; }
			foreach ( array( 'observable_details', 'composition', 'colours' ) as $key ) { $text .= ' ' . self::observation_text( $observation[ $key ] ?? '' ); }
		}
		$text .= ' ' . (string) ( $research['visual_reference']['plating'] ?? '' );

		// A vessel the observations actually name wins; otherwise one is chosen here,
		// because leaving it open is what let the two images disagree.
		$vessels = array(
			'assiette' => 'served on a plain ceramic plate, out of any cooking vessel',
			'plat de service' => 'served on a plain serving dish, out of any cooking vessel',
			'plat rond' => 'served on a round serving dish, out of any cooking vessel',
			'moule' => 'presented in its own baking tin, the same tin as the earlier panels',
			'bol' => 'served in a bowl',
			'planche' => 'served on a wooden board',
			'cocotte' => 'served in the cooking pot it was made in',
			'poêle' => 'served in the pan it was made in',
		);
		$decision = '';
		foreach ( $vessels as $needle => $sentence ) {
			if ( false !== mb_stripos( $text, $needle ) ) { $decision = $sentence; break; }
		}
		if ( '' === $decision ) { $decision = 'removed from whatever it was cooked in and served whole on a plain ceramic plate'; }

		$appearance = trim( preg_replace( '/\s+/', ' ', $text ) );
		return $decision . ', whole and centred, photographed from a three-quarter angle at table height, never from directly above'
			. ( '' === $appearance ? '' : ', and at exactly the colour the observations record: ' . mb_substr( $appearance, 0, 240 ) );
	}

	/**
	 * What was observed in real photographs, as prose. The canonical recipe is
	 * written before a visual brief can be derived from it, so this is the part of
	 * the brief that does not depend on the recipe.
	 */
	public static function observed_appearance( $research ) {
		$observed = array();
		foreach ( (array) ( $research['visual_observations'] ?? array() ) as $observation ) {
			if ( ! is_array( $observation ) ) { continue; }
			foreach ( array( 'observable_details', 'colours', 'textures', 'composition' ) as $key ) {
				$value = self::observation_text( $observation[ $key ] ?? '' );
				if ( '' !== $value ) { $observed[] = $value; }
			}
		}
		if ( ! $observed ) { return ''; }
		return "OBSERVED APPEARANCE — taken from real photographs of this dish, not from a description of it: "
			. implode( ' ', array_slice( array_unique( $observed ), 0, 8 ) )
			. " Use it for the signs a cook reads by eye: what the surface does, what a cut reveals, what correctly cooked looks like. It establishes appearance only — never an ingredient, a quantity or a step it cannot show.\n";
	}

	/**
	 * The part of the research an image model can act on.
	 *
	 * The whole package used to be sent, and it grew past the provider's 32,000
	 * character limit: the collage call was rejected outright. Temperatures, source
	 * URLs, food-safety rules and originality notes cannot change a photograph, and
	 * the visual brief already distils what can.
	 */
	public static function research_for_image( $research ) {
		$research = is_array( $research ) ? $research : array();
		$observations = array();
		foreach ( (array) ( $research['visual_observations'] ?? array() ) as $observation ) {
			if ( ! is_array( $observation ) ) { continue; }
			$observations[] = array(
				'observable_details' => self::observation_text( $observation['observable_details'] ?? '' ),
				'composition' => self::observation_text( $observation['composition'] ?? '' ),
				'colours' => self::observation_text( $observation['colours'] ?? '' ),
				'textures' => self::observation_text( $observation['textures'] ?? '' ),
			);
		}
		return array(
			'dish_identity' => $research['dish_identity'] ?? array(),
			'visual_observations' => $observations,
		);
	}

	/**
	 * The research a text step can act on.
	 *
	 * `originality_notes` describes how the research was conducted, `visual_references`
	 * are URLs, and `visual_observations` describe a photograph — none of it changes a
	 * recipe, a correction or a fact check, and the article receives the appearance
	 * distilled in its visual brief instead. Dropping them takes about 6 100 characters
	 * off every text call that carries the package.
	 */
	public static function research_for_text( $research ) {
		$research = is_array( $research ) ? $research : array();
		foreach ( array( 'originality_notes', 'visual_references', 'visual_observations' ) as $key ) { unset( $research[ $key ] ); }
		return $research;
	}

	/**
	 * The prompt one image generation receives. Shared by the image lab and the
	 * approval retry loop so a regenerated image is built exactly like a first one,
	 * plus the defects the approval step asked to correct.
	 *
	 * $findings is the list the judge returned for this image; passing it is what
	 * makes a retry a correction rather than another roll of the dice.
	 */
	public static function image_prompt( $kind, $brief, $options = array(), $findings = array() ) {
		$settings = self::settings();
		$encode = static function ( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); };
		$research = self::research_package( $brief, $options );
		$canonical = self::canonical_recipe( $brief, $options );
		$ingredients = array();
		foreach ( (array) ( $canonical['ingredients'] ?? array() ) as $ingredient ) {
			$ingredients[] = trim( ( $ingredient['quantity'] ?? '' ) . ' ' . ( $ingredient['unit'] ?? '' ) . ' ' . ( $ingredient['name'] ?? '' ) );
		}
		$file = self::prompt_path( ( 'featured' === $kind ? 'featured_image' : 'facebook_image' ) . '.tpl.txt' );
		$prompt = MSRWA_Prompt::compile( trim( file_get_contents( $file ) ), $settings ) . "\n\n"
			. 'Recipe title: ' . (string) ( $canonical['title'] ?? $brief['title'] ) . "\n"
			. 'Exact ingredients: ' . implode( ', ', $ingredients ) . "\n\n"
			. self::visual_brief( $canonical, $research ) . "\n"
			. 'What the real photographs showed, for anything the brief above does not cover: ' . $encode( self::research_for_image( $research ) ) . "\n";

		if ( 'facebook' === $kind ) {
			$all_steps = array_values( (array) ( $canonical['steps'] ?? array() ) );
			$steps = array();
			$selected = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $options['steps'] ?? '' ) ) ) ) );
			if ( $selected ) {
				if ( 6 !== count( $selected ) ) { fwrite( STDERR, "Facebook --steps must contain exactly six comma-separated canonical step numbers.\n" ); exit( 2 ); }
				foreach ( $selected as $number ) {
					if ( isset( $all_steps[ $number - 1 ] ) ) { $steps[] = count( $steps ) + 1 . '. ' . ( $all_steps[ $number - 1 ]['text'] ?? '' ); }
				}
				if ( 6 !== count( $steps ) ) { fwrite( STDERR, "One or more Facebook --steps numbers do not exist in the canonical recipe.\n" ); exit( 2 ); }
				$prompt .= 'Use these six editor-selected canonical moments, in this order: ' . implode( ' ', $steps ) . "\n";
			} else {
				foreach ( $all_steps as $index => $step ) { $steps[] = ( $index + 1 ) . '. ' . ( $step['text'] ?? '' ); }
				$panels = (int) ( $settings['facebook_collage_steps'] ?? 6 );
				if ( count( $all_steps ) === $panels ) {
					// Nothing to choose: every step is a panel. Leaving the model to select
					// anyway is what produced the ordering failures — it hoisted the batter
					// to panel two, ahead of lining the case, in three runs out of five.
					$prompt .= 'The recipe has exactly ' . $panels . ' steps, so there is nothing to select. Use these, one per panel, in this order: ' . implode( ' ', $steps ) . "\n";
				} else {
					$prompt .= 'Canonical step pool, numbered in the order the recipe performs them: ' . implode( ' ', $steps ) . "\nSelect exactly " . $panels . " visually distinct moments using the storyboard contract, then lay them out in ascending step number; do not sample mechanically or show passive filler.\n";
				}
			}
		}

		// The provider rejects a prompt over 32000 characters, and did so once the
		// research package grew. Fail here, where the cause is visible, not there.
		if ( strlen( $prompt ) > 30000 ) {
			fwrite( STDERR, 'The ' . $kind . " image prompt is " . strlen( $prompt ) . " characters; the provider refuses anything over 32000. Trim what self::research_for_image() forwards.\n" );
			exit( 1 );
		}

		$findings = array_values( array_filter( (array) $findings, 'is_array' ) );
		if ( $findings ) {
			$prompt .= "\nTHIS IMAGE WAS REFUSED. An independent editor inspected the previous attempt and listed what is wrong with it. Produce the same image with each of these corrected, and change nothing else:\n";
			foreach ( $findings as $index => $finding ) {
				$prompt .= ( $index + 1 ) . '. ' . trim( (string) ( $finding['reason'] ?? '' ) ) . ' — ' . trim( (string) ( $finding['fix'] ?? '' ) ) . "\n";
			}
		}
		return $prompt;
	}

	/** Assembles the same input the pipeline would send for this step. */
	public static function build( $step, $prompt, $brief, $options ) {
		$settings = self::settings();
		$encode = static function ( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); };
		$editor = self::editor_brief( $brief );
		$research = self::research_package( $brief, $options );
		$text_research = self::research_for_text( $research );
		$canonical = self::canonical_recipe( $brief, $options );
		if ( 'research' === $step ) {
			return $prompt . "\nEDITOR BRIEF: " . $encode( $editor );
		}
		if ( 'canonical_recipe' === $step ) {
			return $prompt . "\nEDITOR BRIEF: " . $encode( $editor ) . "\nRESEARCH PACKAGE: " . $encode( $text_research )
				. "\n" . self::observed_appearance( $research );
		}
		if ( 'article' === $step ) {
			$feedback = array();
			$feedback_file = (string) ( $options['feedback'] ?? '' );
			if ( '' !== $feedback_file && file_exists( $feedback_file ) ) {
				$saved = json_decode( file_get_contents( $feedback_file ), true );
				$feedback = MSRWA_Json::decode( (string) ( $saved['output'] ?? '' ) );
				$feedback = is_array( $feedback ) ? $feedback : array();
			}
			return $prompt . MSRWA_Quality::prompt_contract( $settings )
				. "\nRecette canonique : " . $encode( $canonical )
				. "\nRESEARCH PACKAGE: " . $encode( $text_research )
				. "\n" . self::visual_brief( $canonical, $research )
				. ( $feedback ? "\nREVIEW FINDINGS TO CORRECT IN THE COMPLETE RETURNED ARTICLE: " . $encode( $feedback ) : '' );
		}
		if ( 'review' === $step ) {
			return $prompt . "\nCANONICAL RECIPE: " . $encode( $canonical )
				. "\nRESEARCH PACKAGE: " . $encode( $text_research )
				. "\nARTICLE: " . $encode( self::article_under_test( $options ) );
		}
		if ( 'fact_check' === $step ) {
			return $prompt . "\nRESEARCH PACKAGE: " . $encode( $text_research )
				. "\nCANONICAL RECIPE: " . $encode( $canonical )
				. "\nARTICLE: " . $encode( self::article_under_test( $options )['content_html'] ?? '' );
		}
		if ( 'proofread' === $step ) {
			return $prompt . "\nRESEARCH PACKAGE: " . $encode( $research )
				. "\nCANONICAL RECIPE: " . $encode( $canonical )
				. "\nARTICLE TO CORRECT: " . $encode( self::article_under_test( $options )['content_html'] ?? '' );
		}
		return $prompt;
	}

	/**
	 * The article the review, fact check and proofreading steps are measured on.
	 * One fixed article for every provider and tier, so the comparison is fair.
	 */
	public static function article_under_test( $options ) {
		static $article = null;
		if ( null !== $article ) { return $article; }
		$file = $options['article'] ?? '';
		if ( '' === $file ) {
			$candidates = glob( self::lab_path( 'runs/article-*.json' ) );
			sort( $candidates );
			$file = $candidates ? $candidates[0] : '';
		}
		if ( '' === $file || ! file_exists( $file ) ) { fwrite( STDERR, "No article to measure against. Run the article step first, or pass --article=<run.json>.\n" ); exit( 2 ); }
		$run = json_decode( file_get_contents( $file ), true );
		$article = json_decode( (string) ( $run['output'] ?? '' ), true );
		$article = is_array( $article ) ? $article : array();
		return $article;
	}

	/** Heading texts of an article body. */
	public static function headings( $html ) {
		preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>/is', (string) $html, $matches );
		$out = array();
		foreach ( (array) $matches[1] as $heading ) { $out[] = trim( strip_tags( $heading ) ); }
		return $out;
	}
}
