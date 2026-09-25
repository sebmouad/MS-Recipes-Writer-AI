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
	public static function visual_brief( $canonical, $research, $single = false, $garnish_rule = true, $serving = '' ) {
		// Led by a collage, how the dish is served is what its last panel shows,
		// and a mise en place drawn before the recipe owes it no count (ENGINE.md §7, 50).
		$led = '' !== trim( (string) $serving );
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
		if ( $garnish_rule ) { $lines[] = '• NO GARNISH THAT IS NOT AN INGREDIENT. The most common defect in these images, across every dish tried, is a sprig of herb laid on the finished plate — rosemary, thyme, parsley, coriander, a bay leaf — because that is how this kind of dish is usually photographed. If the ingredient list below does not contain it, it does not go in the picture, in any panel, however conventional it looks. The same applies to a citrus wedge, a grind of visible spice, a drizzle, a dusting or a scattering of seeds. Serve the dish bare rather than garnish it with something the cook was never told to buy.'; }
		// One photograph of the finished dish has no mise en place and no panels:
		// the counts stay, and the cookware and the display rules — a sixth of its
		// prompt, billed at the image model's rate — do not travel with it.
		if ( $counts && ( $single || $led ) ) {
			$lines[] = '• Countable ingredients, exact numbers: ' . implode( '; ', $counts ) . '.';
		} elseif ( $counts ) {
			$lines[] = '• Countable ingredients, exact numbers: ' . implode( '; ', $counts ) . '. Where a panel lays the ingredients out — the mise en place — show exactly these numbers, not one more pack, roll, fruit or egg "for composition". This binds the ingredient display only. A later panel showing the dish being made or served need not have them all in shot, and a few of the same fruit resting in the background of a finished shot is styling, not a miscount.';
		}
		// The list itself is already in the prompt; restating it here and again in
		// the closing rules was three copies of the same forty words per image.
		if ( $measured && ! $single ) {
			$lines[] = '• Every other ingredient is measured, not counted: show a believable amount.';
		}
		$equipment = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) ( $canonical['equipment'] ?? array() ) ) ) ) );
		if ( $equipment && ! $single && ! $led ) {
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
		$lines[] = '• ONE SERVING PRESENTATION, shared by every image of this recipe: ' . ( $led ? trim( (string) $serving ) : self::serving_presentation( $canonical, $research, true ) )
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
		// A dish baked to be served in its dish is served in it, whatever word an
		// observation lets fall: "assiette" in a gratin's photographs is usually
		// the portion beside it, and lifted the whole gratin onto a plate.
		$decision = self::baked_in_its_dish( $canonical );
		foreach ( '' === $decision ? $vessels : array() as $needle => $sentence ) {
			if ( false !== mb_stripos( $text, $needle ) ) { $decision = $sentence; break; }
		}
		if ( '' === $decision ) { $decision = self::served_from_recipe( $canonical ); }

		$appearance = trim( preg_replace( '/\s+/', ' ', self::about_the_dish( $appearance_text ) ) );
		$framing = ', whole and centred, photographed from a three-quarter angle at table height, never from directly above';
		if ( $short ) { return $decision . $framing . '.'; }
		return $decision . $framing
			. ( '' === $appearance ? '' : ', and at exactly the colour the observations record: ' . mb_substr( $appearance, 0, 240 ) );
	}

	/** The serving of a dish baked to be served in its own dish, or '' for any other. */
	private static function baked_in_its_dish( $canonical ) {
		$title = mb_strtolower( (string) ( $canonical['title'] ?? '' ) );
		$equipment = mb_strtolower( implode( ' | ', array_map( 'strval', (array) ( $canonical['equipment'] ?? array() ) ) ) );
		foreach ( array( 'plats à gratin individuels', 'ramequin', 'cassolette' ) as $word ) {
			if ( false !== mb_strpos( $equipment, $word ) ) { return 'served in the individual baking dishes it was cooked in, straight from the oven, one of them in front'; }
		}
		foreach ( array( 'gratin', 'parmentier', 'lasagne', 'clafoutis', 'moussaka', 'crumble', 'tian ' ) as $word ) {
			if ( false !== mb_strpos( $title . ' ', $word ) || ( 'gratin' === $word && false !== mb_strpos( $equipment, 'plat à gratin' ) ) ) {
				return 'served in the baking dish it was cooked in, straight from the oven, never lifted out onto a plate';
			}
		}
		return '';
	}

	/**
	 * How the dish is served when no photograph shows it, read from the recipe.
	 *
	 * The fallback used to be a plate for everything, so a gratin was drawn
	 * lifted out of the dish it is baked and served in, and a potée out of its
	 * pot. A dish baked to be served in its dish stays in it; a stew goes to
	 * the deep dish the recipe names, else stays in its pot; a tart or quiche
	 * stays in its tin. Anything else is plated.
	 */
	public static function served_from_recipe( $canonical ) {
		$title = mb_strtolower( (string) ( $canonical['title'] ?? '' ) );
		$equipment = mb_strtolower( implode( ' | ', array_map( 'strval', (array) ( $canonical['equipment'] ?? array() ) ) ) );
		$has = static function ( $text, array $words ) {
			foreach ( $words as $word ) { if ( false !== mb_strpos( $text, $word ) ) { return true; } }
			return false;
		};
		$baked = self::baked_in_its_dish( $canonical );
		if ( '' !== $baked ) { return $baked; }
		if ( $has( $equipment, array( 'moule à tarte', 'moule à quiche', 'cercle à tarte' ) ) ) { return 'presented in its own baking tin, the same tin as the earlier panels'; }
		if ( $has( $equipment, array( 'cocotte', 'faitout', 'marmite', 'tajine' ) ) && ! $has( $equipment, array( 'plaque de cuisson', 'moule' ) ) ) {
			return $has( $equipment, array( 'plat creux', 'plat de service' ) ) ? 'served in a deep serving dish with its cooking liquid, out of the pot' : 'served in the cooking pot it was made in';
		}
		return 'removed from whatever it was cooked in and served whole on a plain ceramic plate';
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
	 * The categories the caller's site files posts under. The article chooses
	 * among them rather than naming its own: a name the site does not have is
	 * a post left uncategorised. Absent from a brief, nothing is said.
	 */
	public static function site_categories( $brief ) {
		$names = array_values( array_filter( array_map( static function ( $name ) { return trim( strip_tags( (string) $name ) ); }, (array) ( $brief['site_categories'] ?? array() ) ) ) );
		if ( ! $names ) { return ''; }
		return 'SITE CATEGORIES — "categories" names one or two of these, copied exactly, the most specific that fit the dish; never a name outside this list, and an empty array when none fits: '
			. json_encode( array_slice( $names, 0, 60 ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
	}

	/**
	 * How the dish looks, for the article. It used to receive the image's visual
	 * brief, and the brief's directions reached the prose: fourteen articles
	 * measured told their readers to serve "sur une assiette en céramique unie",
	 * "sans garniture", "vue de trois-quarts". Colour and texture describe the
	 * food; the plate, the frame and the garnish rule belong to the photograph.
	 */
	public static function appearance_for_prose( $research ) {
		$observed = array();
		foreach ( (array) ( $research['visual_observations'] ?? array() ) as $observation ) {
			if ( ! is_array( $observation ) ) { continue; }
			foreach ( array( 'colours', 'textures' ) as $key ) {
				$value = self::about_the_dish( self::observation_text( $observation[ $key ] ?? '' ) );
				if ( '' !== $value ) { $observed[] = $value; }
			}
		}
		if ( ! $observed ) { return ''; }
		return "APPEARANCE OF THE COOKED DISH: " . implode( ' ', array_slice( array_unique( $observed ), 0, 6 ) )
			. " Use it for the signs a cook reads by eye — what the surface does, what a cut reveals, what correctly cooked looks like. It is not a serving instruction: the plate, the angle and the garnish are the reader's choice.\n";
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

	/**
	 * What the composing model is sent for a collage: the owner's own brief with
	 * the dish named, the recipe it must follow, how the dish is served, and what
	 * the attached reference is — a photograph of this dish, or a collage of
	 * another whose look alone is to be kept.
	 *
	 * $reference is 'editor', 'style' or ''.
	 */
	public static function collage_brief( $brief, $template_file, $reference = '' ) {
		$settings = self::settings();
		$canonical = self::canonical_recipe( $brief );
		$title = (string) ( $canonical['title'] ?? ( $brief['title'] ?? '' ) );
		$text = str_replace( '[DISH]', $title, MSRWA_Prompt::compile( trim( (string) file_get_contents( self::prompt_path( $template_file ) ) ), $settings ) );
		$ingredients = array();
		foreach ( (array) ( $canonical['ingredients'] ?? array() ) as $ingredient ) {
			if ( is_array( $ingredient ) ) { $ingredients[] = '- ' . self::ingredient_line( $ingredient ); }
		}
		$steps = array();
		foreach ( array_values( (array) ( $canonical['steps'] ?? array() ) ) as $index => $step ) {
			$steps[] = ( $index + 1 ) . '. ' . trim( (string) ( is_array( $step ) ? ( $step['text'] ?? '' ) : $step ) );
		}
		$text .= "\n\nTHE RECIPE — follow its ingredients, its cooking method and its order; add nothing it does not use:\n" . $title
			. "\nIngredients:\n" . implode( "\n", $ingredients )
			. "\nSteps:\n" . implode( "\n", $steps )
			. "\nServed: " . self::serving_presentation( $canonical, self::research_package( $brief ), true );
		if ( 0 === strpos( (string) $reference, 'style+' ) ) {
			$text .= "\n\nThe first attached image is my own approved collage of another dish: keep its look, never its food, its steps or its cookware. The second is " . ( 'style+editor' === $reference ? "the editor's photograph of this dish" : 'a photograph of this dish from a recipe site' ) . ": take what the dish is from it, never its light, colours, background or framing.";
		} elseif ( 'editor' === $reference || 'research' === $reference ) {
			$text .= "\n\nThe attached image is a photograph of this dish " . ( 'editor' === $reference ? 'supplied by the editor.' : 'from a recipe site.' );
		} elseif ( 'style' === $reference ) {
			$text .= "\n\nThe attached image is my own approved collage of another dish: keep its look, never its food, its steps or its cookware.";
		}
		return $text;
	}

	/**
	 * The user's brief for a collage drawn before any recipe exists (ENGINE.md
	 * §7, 50): the dish, the writer's words, and what the research found about
	 * how it is made — as knowledge of the dish, never as a list that limits
	 * what the collage may show.
	 */
	public static function collage_brief_free( $brief, $template_file, $reference = '' ) {
		$research = self::research_package( $brief );
		$title = trim( (string) ( $brief['title'] ?? '' ) );
		if ( '' === $title ) { $title = (string) ( $research['dish_identity']['name'] ?? '' ); }
		$text = str_replace( '[DISH]', $title, MSRWA_Prompt::compile( trim( (string) file_get_contents( self::prompt_path( $template_file ) ) ), self::settings() ) );
		$notes = trim( (string) ( $brief['text'] ?? '' ) );
		$text .= "\n\nTHE DISH: " . $title . ( '' !== $notes ? "\nWhat the writer said about it: " . mb_substr( $notes, 0, 1500 ) : '' );
		$known = array();
		foreach ( array_slice( (array) ( $research['ingredients'] ?? array() ), 0, 20 ) as $ingredient ) {
			if ( is_array( $ingredient ) && '' !== trim( (string) ( $ingredient['name'] ?? '' ) ) ) { $known[] = trim( (string) $ingredient['name'] ); }
		}
		$method = array();
		foreach ( array_slice( (array) ( $research['preparation'] ?? array() ), 0, 10 ) as $index => $step ) {
			$action = is_array( $step ) ? trim( (string) ( $step['action'] ?? '' ) ) : trim( (string) $step );
			if ( '' !== $action ) { $method[] = ( $index + 1 ) . '. ' . $action; }
		}
		$sides = array();
		foreach ( array_slice( (array) ( $research['accompaniments'] ?? array() ), 0, 5 ) as $side ) {
			if ( is_array( $side ) && '' !== trim( (string) ( $side['name'] ?? '' ) ) ) { $sides[] = trim( (string) $side['name'] ); }
		}
		if ( $known || $method ) {
			$text .= "\n\nWHAT RECIPE SITES SAY ABOUT IT — how the dish is usually made, to get it right; add freely to it:"
				. ( $known ? "\nUsual ingredients: " . implode( ', ', $known ) . '.' : '' )
				. ( $method ? "\nUsual method:\n" . implode( "\n", $method ) : '' )
				. ( $sides ? "\nOften served with: " . implode( ', ', $sides ) . '.' : '' );
		}
		if ( 0 === strpos( (string) $reference, 'style+' ) ) {
			$text .= "\n\nThe first attached image is my own approved collage of another dish: keep its look, never its food, its steps or its cookware. The second is " . ( 'style+editor' === $reference ? "the editor's photograph of this dish" : 'a photograph of this dish from a recipe site' ) . ": take what the dish is from it, never its light, colours, background or framing.";
		} elseif ( 'editor' === $reference || 'research' === $reference ) {
			$text .= "\n\nThe attached image is a photograph of this dish " . ( 'editor' === $reference ? 'supplied by the editor.' : 'from a recipe site.' );
		} elseif ( 'style' === $reference ) {
			$text .= "\n\nThe attached image is my own approved collage of another dish: keep its look, never its food, its steps or its cookware.";
		}
		return $text;
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
			. self::visual_brief( $canonical, $research, 'featured' === $kind, true, self::collage_serving( $brief ) ) . "\n";

		if ( 'facebook' === $kind ) {
			$all_steps = array_values( (array) ( $canonical['steps'] ?? array() ) );
			$steps = array();
			$panels = (int) ( $options['collage_panels'] ?? 6 );
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

	/**
	 * The opening every text step after the research shares, byte for byte:
	 * the research package, then the canonical recipe when the step reads it.
	 * Providers cache a repeated prefix and bill it at a fraction of the input
	 * rate; with the research after each step's own instructions, no two calls
	 * of a recipe began alike and nothing was ever reused.
	 */
	public static function shared_context( $brief, $with_recipe = false ) {
		$encode = static function ( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); };
		return 'RESEARCH PACKAGE: ' . $encode( self::research_for_text( self::research_package( $brief ) ) ) . "\n"
			. ( $with_recipe ? 'CANONICAL RECIPE: ' . $encode( self::canonical_recipe( $brief ) ) . "\n" : '' )
			. "\n";
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
		// The recipe, the article and the review open on the same bytes — the
		// research package, then the recipe once there is one — so the provider
		// bills the part it has already read at its cached rate. Their own
		// instructions come after the shared part, never before it.
		if ( 'canonical_recipe' === $step ) {
			return self::shared_context( $brief ) . $prompt . "\nEDITOR BRIEF: " . $encode( $editor )
				. "\n" . self::observed_appearance( $research )
				. self::collage_lead( $brief, $step );
		}
		if ( 'article' === $step ) {
			// A rewrite carries the findings the reviews raised; a first draft carries none.
			$feedback = is_array( $brief['feedback'] ?? null ) ? $brief['feedback'] : array();
			return self::shared_context( $brief, true ) . $prompt . MSRWA_Quality::prompt_contract( $settings )
				. "\n" . self::appearance_for_prose( $research )
				. self::site_categories( $brief )
				. self::collage_lead( $brief, $step )
				. ( $feedback ? "\nREVIEW FINDINGS TO CORRECT IN THE COMPLETE RETURNED ARTICLE: " . $encode( $feedback ) : '' );
		}
		if ( 'review' === $step ) {
			return self::shared_context( $brief, true ) . $prompt
				. "\nARTICLE: " . $encode( self::article( $brief ) );
		}
		if ( 'final_approval' === $step ) {
			// The images travel beside this text, as bytes. Everything the judge
			// measures them against has to be in here, or it judges pictures alone:
			// the recipe it must accept as given and what the real photographs of
			// this dish actually showed. The article and the research are not: the
			// review has already held the text to them, and sending both again made
			// this the largest input of the recipe for a judgement of two images.
			// The list of what was attached travels with the data, not inside the
			// prompt: the prompt is compiled before the images exist, and its
			// placeholder was emptied every time — the judge never saw the list.
			$received = trim( (string) ( $brief['images_received'] ?? '' ) );
			return $prompt . "\n\nIMAGES RECEIVED:\n" . ( '' !== $received ? $received : '- none.' )
				. "\n\nCANONICAL RECIPE: " . $encode( $canonical )
				. "\n\n" . self::visual_evidence( $research )
				. "\n" . self::visual_brief( $canonical, $research, false, '' === (string) ( $brief['collage_lead'] ?? '' ), self::collage_serving( $brief ) )
				. self::collage_lead( $brief, $step );
		}
		return $prompt;
	}

	/**
	 * How the collage that leads the recipe serves the finished dish, as its
	 * reading describes it; empty without a lead or a reading.
	 */
	public static function collage_serving( $brief ) {
		if ( '' === (string) ( $brief['collage_lead'] ?? '' ) ) { return ''; }
		$reading = (array) ( $brief['collage'] ?? array() );
		$said = trim( trim( (string) ( $reading['serving'] ?? '' ) ) . ' ' . trim( (string) ( $reading['finished_dish'] ?? '' ) ) );
		return '' === $said ? '' : 'as the last panel of the collage serves it — ' . $said . ' The featured photograph shows the same food served the same way; its plate or board, angle and light may differ, and a tart, gratin or cake may be shown in its baking dish or out of it.';
	}

	/**
	 * What a step is told when the Facebook collage leads the recipe (ENGINE.md
	 * §7, 50). The collage says what the dish is — every ingredient, garnish and
	 * side it shows; the research says how much and how long. Empty otherwise.
	 */
	public static function collage_lead( $brief, $step ) {
		$lead = (string) ( $brief['collage_lead'] ?? '' );
		if ( '' === $lead ) { return ''; }
		$reading = (array) ( $brief['collage'] ?? array() );
		$whose = 'provided' === $lead ? "the editor's own step-by-step collage of this dish" : 'a step-by-step collage of this dish, drawn before this recipe';
		$seen = array_intersect_key( $reading, array_flip( array( 'panels', 'ingredients_seen', 'garnish_and_sides', 'finished_dish', 'serving' ) ) );
		$encoded = json_encode( $seen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( 'canonical_recipe' === $step ) {
			return "\nTHE COLLAGE — this recipe is written from " . $whose . '. What it shows is the dish: ' . $encoded
				. "\nEvery ingredient it shows, and every garnish and side it is served with, is in the recipe's ingredient list — a garnish as an ingredient \"pour servir\". A drink standing beside the plate is not an ingredient: it goes in the notes as what the dish is served with, unless a panel shows it going into the food. Nor is a side that is a dish in its own right — a roast, a stew, another gratin, a dish that needs its own recipe: it too goes in the notes as what the dish is served with; a salad, bread, fresh herbs or a simple vegetable stay ingredients. Its steps follow the order of the panels and its equipment names the vessels they show. This overrides any instruction to list only what the research names. What the collage never decides is how much: the quantities, times, temperatures and food-safety rules come from the research, and for an ingredient the research does not measure, the usual quantity a home cook would use. A collage shows a kitchen, not a weighing: never count what a panel shows.\n";
		}
		if ( 'article' === $step ) {
			return "\nTHE DISH AS ITS COLLAGE SHOWS IT — the recipe was written from " . $whose . ', and the article describes the same dish: '
				. json_encode( array_intersect_key( $reading, array_flip( array( 'finished_dish', 'serving', 'garnish_and_sides' ) ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				. "\nWhere the article says what the finished dish looks like or how it is served, this is it. Its garnish and sides are part of the recipe.\n";
		}
		if ( 'final_approval' === $step ) {
			if ( 'provided' === $lead ) {
				return "\nTHE COLLAGE IS THE EDITOR'S OWN and is the reference this recipe was written from. Do not judge it: give it \"good\" with no finding. Judge the featured image, and whether it shows the same dish as the collage's last panel — same food, filling and colour; the plate, angle, light and garnish may differ.\n";
			}
			return "\nTHE COLLAGE WAS DRAWN FIRST, and the recipe was written from it. Judge the collage as a photograph and as a sequence (checks 1 and 4) — never against the ingredient list: every ingredient, herb, garnish, side, drink or prop a home cook would use is expected in it, and an ingredient the recipe lacks is not a finding. Nothing in it is counted against the recipe either: how many eggs, figs or potatoes a panel shows is never a finding, since the recipe was measured after the collage was drawn. How the collage serves the finished dish — in its baking dish or out of it, on a plate or a board — is the recipe's presentation, never a defect. The featured image must show the same dish as the collage's last panel; the plate, angle, light and garnish may differ.\n";
		}
		return '';
	}

	/** Heading texts of an article body. */
	public static function headings( $html ) {
		preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>/is', (string) $html, $matches );
		$out = array();
		foreach ( (array) $matches[1] as $heading ) { $out[] = trim( strip_tags( $heading ) ); }
		return $out;
	}
}
