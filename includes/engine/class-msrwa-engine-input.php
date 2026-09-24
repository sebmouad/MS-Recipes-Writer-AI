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

	/** Prompts are the engine's own data and sit beside it. */
	public static function prompt_path( $file ) { return __DIR__ . '/prompts/' . $file; }

	/**
	 * One ingredient, written the way an image prompt should read it.
	 *
	 * Models name the unit after the thing itself — "1 pâte, pâte sablée",
	 * "2 œufs, œufs". Written out as given it reads as two ingredients, which is
	 * what an exact ingredient list exists to prevent.
	 */
	public static function ingredient_line( $ingredient ) {
		$unit = trim( (string) ( $ingredient['unit'] ?? '' ) );
		$name = trim( (string) ( $ingredient['name'] ?? '' ) );
		if ( '' !== $unit && 0 === mb_stripos( $name, $unit ) ) { $unit = ''; }
		return trim( trim( (string) ( $ingredient['quantity'] ?? '' ) . ' ' . $unit ) . ' ' . $name );
	}

	/** How many observed phrases reach a prompt. The caller sets it; zero means the default. */
	private static $observation_phrases = 0;

	/** Which observation fields describe the food rather than the frame. */
	private static $observation_fields = array();

	public static function use_observation_phrases( $count ) { self::$observation_phrases = max( 0, (int) $count ); }

	public static function use_observation_fields( $fields ) { self::$observation_fields = array_values( array_filter( (array) $fields ) ); }

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
		// An editor_input carrying neither title nor text is not a brief; the run
		// refuses it before anything is billed. Reaching here with one means the
		// caller bypassed MSRWA_Engine::run(), so the outer shape is used instead.
		$editor = isset( $brief['editor_input'] ) && is_array( $brief['editor_input'] ) ? $brief['editor_input'] : array();
		if ( '' !== trim( (string) ( $editor['title'] ?? '' ) ) || '' !== trim( (string) ( $editor['text'] ?? '' ) ) ) {
			return $editor;
		}
		return array(
			'type' => (string) ( $brief['type'] ?? 'article' ),
			'title' => (string) ( $brief['title'] ?? '' ),
			'text' => (string) ( $brief['text'] ?? '' ),
			'images' => array_values( (array) ( $brief['images'] ?? array() ) ),
			// What those images show, when they have been read. An editor who
			// attaches photographs says something the title does not.
			'image_observations' => array_values( (array) ( $brief['image_observations'] ?? array() ) ),
		);
	}

	/*
	 * The three artifacts every later step reads. They arrive in memory, from the
	 * run that produced them; reading them off disk is the lab's business, not the
	 * engine's, and keeping it that way is what lets the plugin call the same code.
	 */
	public static function research_package( $brief ) { return is_array( $brief['research'] ?? null ) ? $brief['research'] : array(); }

	public static function canonical_recipe( $brief ) { return is_array( $brief['canonical'] ?? null ) ? $brief['canonical'] : array(); }

	public static function article( $brief ) { return is_array( $brief['article'] ?? null ) ? $brief['article'] : array(); }

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
	 * Drops what an observation says about the photograph rather than the food.
	 *
	 * The vision pass is told not to, and mostly does not. When it does, the
	 * sentence is a fact about someone else's image file — a watermark, a
	 * signature, a coloured border — and it travels into the image prompt as a
	 * description of how the dish looks. A generation then drew "La Cuisine de
	 * Biscottine" across the corner and a yellow frame around the picture, which
	 * the approval step refused, at the price of two regenerations and a second
	 * verdict. The prompt already forbade text three times over; a description
	 * beats a prohibition, so the description has to go.
	 */
	public static function about_the_dish( $text, $pattern = '' ) {
		$text = trim( (string) $text );
		if ( '' === $text ) { return ''; }
		$pattern = '' !== trim( (string) $pattern ) ? $pattern : self::staging_pattern();
		$kept = array();
		// Sentence by sentence: one bad clause must not cost the whole observation.
		foreach ( preg_split( '/(?<=[.!?])\s+/u', $text ) as $sentence ) {
			if ( '' === trim( $sentence ) || preg_match( $pattern, $sentence ) ) { continue; }
			$kept[] = trim( $sentence );
		}
		return implode( ' ', $kept );
	}

	/**
	 * What counts as a description of the photograph rather than of the dish.
	 *
	 * Two families, both measured: the picture as an object — text, watermark,
	 * signature, border — and the picture's staging — the hands holding the
	 * plate, the cook's clothing, the second plate at the edge of the frame. A
	 * generation drew the watermark, and another served the dish held in two
	 * hands because that is how the source photograph was staged.
	 */
	public static function staging_pattern() {
		return '/\b(texte|textes|mention|inscription|lettrage|l[ée]gende|filigrane|signature|logo|marque|watermark|autocollant|sticker|bordure|cadre|liser[ée]|bandeau|vignette|mosa[ïi]que|collage|capture|montage'
			. '|main|mains|bras|doigt|doigts|personne|homme|femme|torse|v[êe]tement|tablier|manche|poignet'
			. '|seconde assiette|deuxi[èe]me assiette|arri[èe]re-plan|背景)\b/iu';
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
				$measured[] = self::ingredient_line( $ingredient );
			}
		}

		$lines = array( 'VISUAL BRIEF — derived from this recipe and binding. Each line below exists because a real image failed on it.' );
		$lines[] = '• NO GARNISH THAT IS NOT AN INGREDIENT. The most common defect in these images, across every dish tried, is a sprig of herb laid on the finished plate — rosemary, thyme, parsley, coriander, a bay leaf — because that is how this kind of dish is usually photographed. If the ingredient list below does not contain it, it does not go in the picture, in any panel, however conventional it looks. The same applies to a citrus wedge, a grind of visible spice, a drizzle, a dusting or a scattering of seeds. Serve the dish bare rather than garnish it with something the cook was never told to buy.';
		if ( $counts ) {
			$lines[] = '• Countable ingredients, exact numbers: ' . implode( '; ', $counts ) . '. Where a panel lays the ingredients out — the mise en place — show exactly these numbers, not one more pack, roll, fruit or egg "for composition". This binds the ingredient display only. A later panel showing the dish being made or served need not have them all in shot, and a few of the same fruit resting in the background of a finished shot is styling, not a miscount.';
		}
		// The list itself is already in the prompt; restating it here and again in
		// the closing rules was three copies of the same forty words per image.
		if ( $measured ) {
			$lines[] = '• Every other ingredient is measured, not counted: show a believable amount.';
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

		/*
		 * Colour and texture describe the food. observable_details and composition
		 * describe the frame — they are an inventory of what is in the photograph,
		 * and every leak measured so far came through them: another site's
		 * watermark and its yellow border, two hands holding the plate, red and
		 * green strips that were drawn as peppers, orange pieces that were drawn as
		 * carrots. Five defects, five refusals, one channel. The camera angle is
		 * not lost with them: the serving presentation below fixes it.
		 */
		$observed = array();
		$fields = self::$observation_fields ? self::$observation_fields : array( 'colours', 'textures' );
		foreach ( (array) ( $research['visual_observations'] ?? array() ) as $observation ) {
			if ( ! is_array( $observation ) ) { continue; }
			foreach ( $fields as $key ) {
				$value = self::about_the_dish( self::observation_text( $observation[ $key ] ?? '' ) );
				if ( '' !== $value ) { $observed[] = $value; }
			}
		}
		if ( $observed ) {
			$phrases = (int) ( self::$observation_phrases > 0 ? self::$observation_phrases : 8 );
			// These describe other cooks' photographs of the same dish, so they are
			// evidence about appearance and nothing else. Saying which one wins, here
			// where the observations actually are, is what stopped the image model
			// adding the peppers and the lemon slice it had just been shown.
			$lines[] = '• How the real dish looks, observed in photographs of it: ' . implode( ' ', array_slice( array_unique( $observed ), 0, $phrases ) )
				. ' These are other cooks\' photographs of this dish: read them for colour, texture, doneness and plating only. Where one shows a food the ingredient list above does not contain, the ingredient list wins and that food does not appear. The finished dish may not look more cooked, more darkly coloured or more elaborately garnished than these observations describe.';
		}

		// Every image is generated in its own call, so nothing makes them agree unless
		// the same decision is written into both. Three refusals in four came from the
		// featured photograph and the collage's last panel serving the dish differently.
		$lines[] = '• ONE SERVING PRESENTATION, shared by every image of this recipe: ' . self::serving_presentation( $canonical, $research, true )
			. ( $observed ? ' At the colour the observations above record.' : '' )
			. ' The featured photograph and the last panel of the collage show it that same way — same vessel, same colour: two photographs of one dish, minutes apart.';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The single way the finished dish is presented, as a decision rather than a
	 * description. Describing the dish was not enough: two calls that each read
	 * "whole, seen at three quarters" still chose a plate and a tin, and the
	 * approval step blocked the pair every time. The vessel has to be named.
	 */
	public static function serving_presentation( $canonical, $research, $short = false ) {
		// Two different reads of the same observations. Choosing the vessel may scan
		// everything, because it only lifts a single word out — "assiette", "plat",
		// "cocotte". Quoting the colour back to the image model may not: that text is
		// drawn, and the inventory fields are what put another cook's garnish in it.
		$vessel_text = '';
		$appearance_text = '';
		$fields = self::$observation_fields ? self::$observation_fields : array( 'colours', 'textures' );
		foreach ( (array) ( $research['visual_observations'] ?? array() ) as $observation ) {
			if ( ! is_array( $observation ) ) { continue; }
			foreach ( array( 'observable_details', 'composition', 'colours' ) as $key ) { $vessel_text .= ' ' . self::observation_text( $observation[ $key ] ?? '' ); }
			foreach ( $fields as $key ) { $appearance_text .= ' ' . self::observation_text( $observation[ $key ] ?? '' ); }
		}
		$vessel_text .= ' ' . (string) ( $research['visual_reference']['plating'] ?? '' );
		$text = $vessel_text;

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

		$appearance = trim( preg_replace( '/\s+/', ' ', self::about_the_dish( $appearance_text ) ) );
		$framing = ', whole and centred, photographed from a three-quarter angle at table height, never from directly above';
		if ( $short ) { return $decision . $framing . '.'; }
		return $decision . $framing
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
	/**
	 * What the real photographs showed, written for the editor who judges the
	 * generated ones.
	 *
	 * The judge's own prompt said it received "observations taken from real
	 * photographs of this dish", and it did not: research_for_text() strips them,
	 * so the only appearance it ever saw was the handful of colour and texture
	 * phrases inside the visual brief. It was deciding whether a photograph looks
	 * like this dish without having seen what this dish looks like.
	 *
	 * This is the opposite job from the one the image prompt does, so it carries
	 * the opposite risk. The generator must not be shown another cook's garnish,
	 * because it draws what it is shown. The judge must be shown it, because it
	 * is comparing — but it must know whose plate it is looking at, which is why
	 * the tier, the source and the uncertainties travel with each observation.
	 */
	public static function visual_evidence( $research ) {
		$research = is_array( $research ) ? $research : array();
		$observations = array_values( array_filter( (array) ( $research['visual_observations'] ?? array() ), 'is_array' ) );
		if ( ! $observations ) { return "VISUAL EVIDENCE: none. No real photograph of this dish was read, so judge the images on realism and on the canonical recipe alone, and say in uncertainties that you had no photographic reference.\n"; }

		$tiers = array();
		foreach ( (array) ( $research['visual_references'] ?? array() ) as $reference ) {
			if ( is_array( $reference ) && ! empty( $reference['image_url'] ) ) { $tiers[ (string) $reference['image_url'] ] = (int) ( $reference['tier'] ?? 0 ); }
		}

		$lines = array( 'VISUAL EVIDENCE — what real photographs of this dish actually showed, read from the image files themselves. This is evidence about appearance, and nothing else.' );
		$index = 0;
		foreach ( $observations as $observation ) {
			$index++;
			$tier = (int) ( $observation['tier'] ?? $tiers[ (string) ( $observation['image_url'] ?? '' ) ] ?? 0 );
			$label = 2 === $tier ? 'a close variant of this dish, weaker evidence' : ( 1 === $tier ? 'this dish' : 'unrated source' );
			$parts = array();
			foreach ( array( 'observable_details' => 'Visible', 'colours' => 'Colours', 'textures' => 'Textures' ) as $key => $name ) {
				$value = self::about_the_dish( self::observation_text( $observation[ $key ] ?? '' ) );
				if ( '' !== $value ) { $parts[] = $name . ': ' . $value; }
			}
			$doubt = self::observation_text( $observation['uncertainties'] ?? '' );
			if ( '' !== $doubt ) { $parts[] = 'The pass could not identify: ' . $doubt; }
			if ( ! $parts ) { continue; }
			$lines[] = 'Photograph ' . $index . ' (' . $label . '): ' . implode( ' ', $parts );
		}

		$lines[] = 'HOW TO USE IT. These are other cooks\' plates, not a specification. Use them to decide whether the generated images are believable and whether they show this dish: a colour, a texture, a doneness or a way of serving that matches them is right, and one that is wildly outside them is worth a finding. Never require an element because a photograph had it — a source plate served with rice does not make rice compulsory — and never treat a second-tier variant as proof of anything. What an observation says the pass could not identify proves nothing at all.' . "\n";
		return implode( "\n", $lines ) . "\n";
	}

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
	/**
	 * The grid a collage of $panels is laid out in, said the way the closing
	 * rule says it. It used to be "2 columns × 3 rows" whatever the panel
	 * count, which a site set to four panels was told as well.
	 */
	public static function grid( $panels, $columns = 2 ) {
		$panels = max( 1, (int) $panels );
		$columns = max( 1, min( $panels, (int) $columns ) );
		$rows = (int) ceil( $panels / $columns );
		if ( 1 === $panels ) { return 'one single image, no grid.'; }
		if ( 0 === $panels % $columns ) { return $columns . ' columns × ' . $rows . ' rows, every cell filled.'; }
		return $columns . ' columns × ' . $rows . ' rows, every cell filled, the last row holding ' . ( $panels % $columns ) . ' panel(s) spanning its full width.';
	}

	public static function image_prompt( $kind, $brief, $options = array(), $findings = array() ) {
		$settings = self::settings();
		$research = self::research_package( $brief );
		$canonical = self::canonical_recipe( $brief );
		$ingredients = array();
		foreach ( (array) ( $canonical['ingredients'] ?? array() ) as $ingredient ) {
			if ( is_array( $ingredient ) ) { $ingredients[] = self::ingredient_line( $ingredient ); }
		}
		// The Facebook image is drawn from the run's template; the caller that
		// names none gets the shipped collage.
		$file = self::prompt_path( 'featured' === $kind ? 'featured_image.tpl.txt' : (string) ( $options['facebook_prompt'] ?? 'facebook_image.tpl.txt' ) );
		// The visual brief already distils the observations into constraints. The raw
		// package used to follow it as well, which repeated the same sentences a
		// second time — 38% of the featured prompt, and roughly half the cost of the
		// image, since an image call is billed mostly on what it is sent. Repeating
		// them also doubled the weight of the bad ones.
		$prompt = MSRWA_Prompt::compile( trim( file_get_contents( $file ) ), $settings ) . "\n\n"
			. 'Recipe title: ' . (string) ( $canonical['title'] ?? $brief['title'] ) . "\n"
			. 'Exact ingredients: ' . implode( ', ', $ingredients ) . "\n\n"
			. self::visual_brief( $canonical, $research ) . "\n";

		if ( 'facebook' === $kind ) {
			$all_steps = array_values( (array) ( $canonical['steps'] ?? array() ) );
			$steps = array();
			$panels = (int) ( $options['collage_panels'] ?? $settings['facebook_collage_steps'] ?? 6 );
			$selected = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $options['steps'] ?? '' ) ) ) ) );
			foreach ( $selected as $number ) {
				if ( isset( $all_steps[ $number - 1 ] ) ) { $steps[] = count( $steps ) + 1 . '. ' . ( $all_steps[ $number - 1 ]['text'] ?? '' ); }
			}
			if ( count( $steps ) === $panels && $panels > 0 ) {
				$prompt .= 'Use these ' . $panels . ' editor-selected canonical moments, in this order: ' . implode( ' ', $steps ) . "\n";
			} else {
				// A partial or impossible selection is not a silent half-selection: the
				// model gets the whole pool back and chooses, as it would with none.
				$steps = array();
				foreach ( $all_steps as $index => $step ) { $steps[] = ( $index + 1 ) . '. ' . ( $step['text'] ?? '' ); }
				if ( count( $all_steps ) === $panels ) {
					// Nothing to choose: every step is a panel. Leaving the model to select
					// anyway is what produced the ordering failures — it hoisted the batter
					// to panel two, ahead of lining the case, in three runs out of five.
					$prompt .= 'The recipe has exactly ' . $panels . ' steps, so there is nothing to select. Use these, one per panel, in this order: ' . implode( ' ', $steps ) . "\n";
				} else {
					// More steps than panels is where the grid broke: a seven-step
					// quiche came back as four rows of two, one panel per step and
					// the dish added. Fewer steps than panels is the other way to
					// miscount. The arithmetic is said outright, either way.
					$prompt .= 'Canonical step pool, numbered in the order the recipe performs them: ' . implode( ' ', $steps ) . "\n";
					if ( count( $all_steps ) > $panels ) {
						$prompt .= 'The recipe has ' . count( $all_steps ) . ' steps and the canvas has room for ' . $panels . ' panels, no more: the finished dish takes the last one, so choose ' . ( $panels - 1 ) . " moments for the others, merging or leaving out the rest. Choose them with the storyboard contract, then lay them out in ascending step number; do not sample mechanically or show passive filler.\n";
					} else {
						$prompt .= 'The recipe has only ' . count( $all_steps ) . ' steps for ' . $panels . " panels: the mise en place opens and the finished dish closes, and where panels remain, show a step's two visible states — the food as it goes in, then as it comes out — for the steps that change most. Never invent a step, never repeat a panel, never leave a cell empty.\n";
					}
				}
			}
		}

		// Last, because last is what a model weighs most. Every line here is a rule
		// stated earlier that a real generation broke anyway: hands holding the dish,
		// a bowl where the brief named a plate, another site's watermark, a garnish
		// nobody bought. Restating them in six lines at the end costs about 400
		// characters and is cheaper than one refused image.
		$prompt .= "\nBEFORE YOU DRAW — the rules a previous attempt at this brief broke:\n"
			. "1. No text anywhere in the image: no caption, signature, watermark, logo, sticker, border or coloured frame. Nothing written, in any corner.\n"
			. "2. No hands, no arms, no people. Nobody holds, carries or presents the dish.\n"
			. "3. Nothing on the plate that is not in the exact ingredient list above. No herb sprig, no citrus wedge, no dusting, no drizzle, no scattered seeds, however usual that looks."
			// The observations name only what the vision pass could identify, so an
			// unrecognised garnish arrives as "red and green strips" or "a yellow
			// fruit half". Drawn literally those became peppers and a lemon slice,
			// twice, on the same recipe. A colour and a shape is not permission.
			. " If an observation describes something only by its colour or its shape — coloured strips, an unidentified fruit, green tufts, pale pieces — that is another cook's garnish and it does not belong to this recipe: leave it out.\n"
			. '4. Serve it exactly as the brief above says: ' . self::serving_presentation( $canonical, $research, true ) . "\n"
			. "5. Anything the recipe says to lift out or discard before serving is not visible in the finished dish.\n"
			. ( 'facebook' === $kind
				// A blind bake was drawn as a case heaped with white beads on paper,
				// in collage after collage: the step names them, and a step's own
				// words outweigh a rule stated pages earlier. Said here, narrowly —
				// "show every cooking stage" made room for all four of a quiche's
				// and came back as eight cells three times in four — and before the
				// panel count, which stays the very last word.
				? "6. A case baked blind appears as the golden, dry, empty case after its bake — no baking paper, no baking beans or ceramic weights, no foil, not even in a bowl nearby — even when the step names them.\n"
					. "7. Exactly " . $panels . " panels in the recipe's own order, the last one presented as rule 4 says: " . self::grid( $panels, (int) ( $options['collage_columns'] ?? 2 ) ) . " When the recipe has more moments than panels, merge or leave some out — never add a row.\n"
				: "6. One plate, one dish, photographed once. No collage, no before and after.\n" );

		$findings = array_values( array_filter( (array) $findings, 'is_array' ) );
		if ( $findings ) {
			$prompt .= "\nTHIS IMAGE WAS REFUSED. An independent editor inspected the previous attempt and listed what is wrong with it. Produce the same image with each of these corrected, and change nothing else:\n";
			foreach ( $findings as $index => $finding ) {
				$prompt .= ( $index + 1 ) . '. ' . trim( (string) ( $finding['reason'] ?? '' ) ) . ' — ' . trim( (string) ( $finding['fix'] ?? '' ) ) . "\n";
			}
		}
		return $prompt;
	}

	/** Assembles the input one step is sent, from the artifacts already produced. */
	public static function build( $step, $prompt, $brief, $options = array() ) {
		$settings = self::settings();
		$encode = static function ( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); };
		$editor = self::editor_brief( $brief );
		$research = self::research_package( $brief );
		$text_research = self::research_for_text( $research );
		$canonical = self::canonical_recipe( $brief );
		if ( 'research' === $step ) {
			return $prompt . "\nEDITOR BRIEF: " . $encode( $editor );
		}
		if ( 'canonical_recipe' === $step ) {
			return $prompt . "\nEDITOR BRIEF: " . $encode( $editor ) . "\nRESEARCH PACKAGE: " . $encode( $text_research )
				. "\n" . self::observed_appearance( $research );
		}
		if ( 'article' === $step ) {
			// A rewrite carries the findings the reviews raised; a first draft carries none.
			$feedback = is_array( $brief['feedback'] ?? null ) ? $brief['feedback'] : array();
			return $prompt . MSRWA_Quality::prompt_contract( $settings )
				. "\nRecette canonique : " . $encode( $canonical )
				. "\nRESEARCH PACKAGE: " . $encode( $text_research )
				. "\n" . self::visual_brief( $canonical, $research )
				. ( $feedback ? "\nREVIEW FINDINGS TO CORRECT IN THE COMPLETE RETURNED ARTICLE: " . $encode( $feedback ) : '' );
		}
		if ( 'review' === $step ) {
			return $prompt . "\nCANONICAL RECIPE: " . $encode( $canonical )
				. "\nRESEARCH PACKAGE: " . $encode( $text_research )
				. "\nARTICLE: " . $encode( self::article( $brief ) );
		}
		if ( 'fact_check' === $step ) {
			return $prompt . "\nRESEARCH PACKAGE: " . $encode( $text_research )
				. "\nCANONICAL RECIPE: " . $encode( $canonical )
				. "\nARTICLE: " . $encode( self::article( $brief )['content_html'] ?? '' );
		}
		if ( 'proofread' === $step ) {
			return $prompt . "\nRESEARCH PACKAGE: " . $encode( $research )
				. "\nCANONICAL RECIPE: " . $encode( $canonical )
				. "\nARTICLE TO CORRECT: " . $encode( self::article( $brief )['content_html'] ?? '' );
		}
		if ( 'final_approval' === $step ) {
			// The images travel beside this text, as bytes. Everything the judge
			// measures them against has to be in here, or it judges pictures alone:
			// the recipe it must accept as given, the research behind it, and what
			// the real photographs of this dish actually showed.
			return $prompt . "\n\nCANONICAL RECIPE: " . $encode( $canonical )
				. "\nRESEARCH PACKAGE: " . $encode( $text_research )
				. "\n\n" . self::visual_evidence( $research )
				. "\n" . self::visual_brief( $canonical, $research )
				. "\nARTICLE: " . $encode( self::article( $brief ) );
		}
		return $prompt;
	}

	/** Heading texts of an article body. */
	public static function headings( $html ) {
		preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>/is', (string) $html, $matches );
		$out = array();
		foreach ( (array) $matches[1] as $heading ) { $out[] = trim( strip_tags( $heading ) ); }
		return $out;
	}
}
