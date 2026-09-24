<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Whether an answer satisfied its step's contract.
 *
 * Scoring is deliberately not the model's own opinion: these are checks over
 * the returned data, so a step that passes here passes for a reason that can be
 * pointed at. Each check exists because a real answer once failed it.
 */
final class MSRWA_Engine_Score {

	public static function fold( $text ) {
		$text = mb_strtolower( (string) $text, 'UTF-8' );
		$map = array( 'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','œ'=>'oe','æ'=>'ae' );
		// Arabic is written with or without its short vowels and the tatweel:
		// "يُقدَّم" and "يقدم" are one word, and a heading must match either way.
		return (string) preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', strtr( $text, $map ) );
	}

	/**
	 * What counts as passed when the caller names no thresholds of its own.
	 * Every number was put there by an answer that failed; every one is the
	 * caller's to move.
	 */
	public static function thresholds( $given = array() ) {
		return array_merge( MSRWA_Engine_Config::defaults()['thresholds'], (array) $given );
	}

	/** Scores an answer against the contract of its step. */
	public static function step( $step, $text, $brief, $thresholds = array() ) {
		// The research branch below reuses $step as its loop variable.
		$name = (string) $step;
		$settings = MSRWA_Engine_Input::settings();
		$limits = self::thresholds( $thresholds );
		$checks = array();
		$json = MSRWA_Json::decode( $text );
		$checks['valid JSON'] = array( 'pass' => is_array( $json ), 'detail' => is_array( $json ) ? count( $json ) . ' keys' : 'not parseable' );
		$json = is_array( $json ) ? $json : array();

		if ( 'research' === $step ) {
			// An empty array is not evidence. Every one of these keys passing on zero
			// entries is how a package with no facts and no sources scored 7/10.
			$required = (array) $limits['research_minimums'];
			// Written from the editor's photographs, the package cites no web page:
			// the photographs are its evidence, and they are checked instead.
			$from_photographs = 'photographs' === (string) ( $brief['research_mode'] ?? '' );
			if ( $from_photographs ) { $required['references'] = 0; }
			foreach ( $required as $key => $minimum ) {
				$count = isset( $json[ $key ] ) && is_array( $json[ $key ] ) ? count( $json[ $key ] ) : -1;
				$checks[ $key ] = array( 'pass' => $count >= $minimum, 'detail' => $count < 0 ? 'missing' : $count . ' entries, ' . $minimum . ' minimum' );
			}
			$sourced = 0;
			foreach ( (array) ( $json['references'] ?? array() ) as $reference ) { if ( ! empty( $reference['url'] ) ) { $sourced++; } }
			if ( ! $from_photographs ) { $checks['references carry a URL'] = array( 'pass' => $sourced > 0, 'detail' => $sourced . ' with a URL' ); }
			$real_images = 0;
			foreach ( (array) ( $json['visual_references'] ?? array() ) as $reference ) {
				if ( $from_photographs ? 'editor' === ( $reference['source_url'] ?? '' ) && '' !== (string) ( $reference['image_url'] ?? '' ) : preg_match( '#^https://#i', (string) ( $reference['image_url'] ?? '' ) ) && preg_match( '#^https://#i', (string) ( $reference['source_url'] ?? '' ) ) ) { $real_images++; }
			}
			$checks['real image provenance'] = array( 'pass' => $real_images > 0, 'detail' => $real_images . ( $from_photographs ? ' editor photographs read' : ' image references with HTTPS image and source URLs' ) );
			$observed = count( (array) ( $json['visual_observations'] ?? array() ) );
			$checks['images inspected'] = array( 'pass' => $observed > 0, 'detail' => $observed . ' visual observations extracted from image bytes' );

			// The package now carries the recipe the sources describe, so the canonical
			// step builds rather than invents. An outline with no times is not an outline.
			$outline = is_array( $json['recipe_outline'] ?? null ) ? $json['recipe_outline'] : array();
			$timed = 0;
			foreach ( array( 'servings', 'prep_minutes', 'cook_minutes', 'total_minutes' ) as $key ) { if ( null !== ( $outline[ $key ] ?? null ) && '' !== $outline[ $key ] ) { $timed++; } }
			$checks['recipe outline'] = array( 'pass' => $timed >= (int) $limits['research_outline_figures'], 'detail' => $timed . ' of 4 figures given, ' . (int) $limits['research_outline_figures'] . ' required' );

			$cued = 0;
			foreach ( (array) ( $json['preparation'] ?? array() ) as $step ) { if ( is_array( $step ) && '' !== trim( (string) ( $step['cue'] ?? '' ) ) ) { $cued++; } }
			$steps_total = count( (array) ( $json['preparation'] ?? array() ) );
			$checks['every step has its sign'] = array( 'pass' => $steps_total > 0 && $cued === $steps_total, 'detail' => $cued . ' of ' . $steps_total . ' steps carry a visible cue' );

			$sourced = 0;
			foreach ( (array) ( $json['ingredients'] ?? array() ) as $ingredient ) { if ( is_array( $ingredient ) && '' !== trim( (string) ( $ingredient['source_url'] ?? '' ) ) ) { $sourced++; } }
			$ingredients_total = count( (array) ( $json['ingredients'] ?? array() ) );
			$checks['every ingredient is sourced'] = array( 'pass' => $ingredients_total > 0 && $sourced === $ingredients_total, 'detail' => $sourced . ' of ' . $ingredients_total . ' carry a source' );

			$tier1 = 0;
			foreach ( (array) ( $json['visual_references'] ?? array() ) as $reference ) { if ( is_array( $reference ) && 1 === (int) ( $reference['tier'] ?? 0 ) ) { $tier1++; } }
			$checks['photographs of this dish'] = array( 'pass' => $tier1 > 0, 'detail' => $tier1 . ' tier-1 references, ' . ( count( (array) ( $json['visual_references'] ?? array() ) ) - $tier1 ) . ' tier-2' );
		}

		if ( 'canonical_recipe' === $step ) {
			$errors = MSRWA_Recipe::validate( $json );
			$checks['recipe schema'] = array( 'pass' => empty( $errors ), 'detail' => $errors ? implode( ', ', array_keys( $errors ) ) : 'valid' );
			$checks['ingredients'] = array( 'pass' => count( (array) ( $json['ingredients'] ?? array() ) ) >= (int) $settings['quality_min_ingredients'], 'detail' => count( (array) ( $json['ingredients'] ?? array() ) ) . ' items' );
			$checks['steps'] = array( 'pass' => count( (array) ( $json['steps'] ?? array() ) ) >= (int) $settings['quality_min_steps'], 'detail' => count( (array) ( $json['steps'] ?? array() ) ) . ' steps' );
			// The same ingredient listed twice ("Beurre demi-sel" and "Beurre
			// demi-sel") reads as a mistake and splits one quantity in two.
			$seen = array();
			$twice = array();
			foreach ( (array) ( $json['ingredients'] ?? array() ) as $ingredient ) {
				$key = self::fold( (string) ( is_array( $ingredient ) ? ( $ingredient['name'] ?? '' ) : $ingredient ) );
				if ( '' === $key ) { continue; }
				if ( isset( $seen[ $key ] ) ) { $twice[] = $key; }
				$seen[ $key ] = true;
			}
			$checks['ingredients listed once'] = array( 'pass' => empty( $twice ), 'detail' => $twice ? 'twice: ' . implode( ', ', array_unique( $twice ) ) : count( $seen ) . ' distinct' );
		}

		if ( 'article' === $step ) {
			$canonical = MSRWA_Engine_Input::canonical_recipe( $brief );
			$quality = MSRWA_Quality::evaluate( $json, $canonical, $settings );
			$checks['quality gate'] = array( 'pass' => ! empty( $quality['pass'] ), 'detail' => 'score ' . (int) $quality['score'] . '/100' . ( empty( $quality['blockers'] ) ? '' : ', blocked on ' . implode( ', ', $quality['blockers'] ) ) );
			$words = (int) ( $quality['metrics']['words'] ?? 0 );
			// Both ends. Comparing against the minimum alone let a live English
			// article come back at 5 988 words against a 2 800–3 600 target,
			// and an over-long article is paid for twice more: review and
			// proofread each read it whole.
			// In the article's own language: Arabic is held to fewer words.
			$range = MSRWA_Prompt::word_range( $settings );
			$maximum = $range['max'];
			$ceiling = $maximum > 0 ? (int) round( $maximum * 1.15 ) : 0;
			$too_long = $ceiling > 0 && $words > $ceiling;
			$checks['words'] = array(
				'pass' => $words >= $range['min'] && ! $too_long,
				'detail' => $too_long
					? $words . ' / ' . $maximum . ' maximum'
					: $words . ' / ' . $range['min'],
			);
			$checks['headings'] = array( 'pass' => (int) ( $quality['metrics']['headings'] ?? 0 ) >= (int) $settings['quality_min_headings'], 'detail' => (int) ( $quality['metrics']['headings'] ?? 0 ) . ' / ' . (int) $settings['quality_min_headings'] );
			$content = (string) ( $json['content_html'] ?? '' );
			$headings = MSRWA_Engine_Input::headings( $content );
			$missing = array();
			foreach ( self::required_sections( $settings ) as $section => $synonyms ) {
				$found = false;
				foreach ( $synonyms as $synonym ) {
					foreach ( $headings as $heading ) { if ( false !== strpos( self::fold( $heading ), $synonym ) ) { $found = true; break 2; } }
				}
				if ( ! $found ) { $missing[] = $section; }
			}
			$required = self::required_sections( $settings );
			$checks['required sections'] = array( 'pass' => empty( $missing ), 'detail' => $missing ? 'missing: ' . implode( ', ', $missing ) : count( $required ) . '/' . count( $required ) . ' present' );
			$closing = self::closing_section( $content );
			$checks['closing section'] = array( 'pass' => $closing['words'] >= (int) $limits['article_closing_words'] && ! $closing['is_question'], 'detail' => $closing['words'] . ' words under "' . mb_substr( $closing['heading'], 0, 40 ) . '"' );
			$accents = self::accent_density( $content );
			$checks['French typography'] = array( 'pass' => $accents >= (float) $limits['article_accents_per_1000'], 'detail' => sprintf( '%.1f accented characters per 1000 (French prose sits near 30)', $accents ) );
			$checks['two parts'] = array( 'pass' => false !== strpos( $content, '<!--nextpage-->' ) || ! empty( $json['content_html_part2'] ), 'detail' => false !== strpos( $content, '<!--nextpage-->' ) ? 'page break present' : 'single block' );
			$checks['no metadata in body'] = array( 'pass' => ! preg_match( '/meta.?description|slug\s*:|mots.?cl(é|e)s\s*:/iu', $content ), 'detail' => 'body carries prose only' );
			$fields = array( 'title', 'excerpt', 'seo_title', 'seo_description', 'slug', 'tags', 'categories', 'recipe_meta', 'internal_links', 'facebook_caption', 'faq', 'visual_final_notes' );
			$absent = array();
			foreach ( $fields as $field ) { if ( ! array_key_exists( $field, $json ) ) { $absent[] = $field; } }
			$checks['fields the plugin needs'] = array( 'pass' => empty( $absent ), 'detail' => $absent ? 'missing: ' . implode( ', ', $absent ) : count( $fields ) . ' fields present' );
		}

		if ( 'fact_check' === $step ) {
			$article = (string) ( MSRWA_Engine_Input::article( $brief )['content_html'] ?? '' );
			$plain = html_entity_decode( strip_tags( $article ), ENT_QUOTES, 'UTF-8' );
			$checks['verdict is boolean'] = array( 'pass' => array_key_exists( 'pass', $json ) && is_bool( $json['pass'] ), 'detail' => isset( $json['pass'] ) ? var_export( $json['pass'], true ) : 'missing' );
			$corrections = (array) ( $json['corrections'] ?? array() );
			$quoted = 0; $invented = 0;
			foreach ( $corrections as $correction ) {
				$before = trim( (string) ( $correction['before'] ?? '' ) );
				if ( '' === $before ) { continue; }
				if ( false !== mb_strpos( $plain, $before ) ) { $quoted++; } else { $invented++; }
			}
			$checks['quotes the real text'] = array( 'pass' => 0 === $invented, 'detail' => $corrections ? $quoted . ' verbatim, ' . $invented . ' not found in the article' : 'no correction proposed' );
			$sourced = 0;
			foreach ( $corrections as $correction ) { if ( ! empty( $correction['source'] ) ) { $sourced++; } }
			$checks['each fix cites a source'] = array( 'pass' => count( $corrections ) === $sourced, 'detail' => $sourced . '/' . count( $corrections ) );
			$checks['surgical'] = array( 'pass' => count( $corrections ) <= (int) $limits['fact_check_max_fixes'], 'detail' => count( $corrections ) . ' corrections proposed, ' . (int) $limits['fact_check_max_fixes'] . ' the most that reads as a correction rather than a rewrite' );
		}

		if ( 'proofread' === $step ) {
			$original = (string) ( MSRWA_Engine_Input::article( $brief )['content_html'] ?? '' );
			$corrected = (string) ( $json['content_html'] ?? '' );
			$checks['returns the article'] = array( 'pass' => mb_strlen( $corrected ) > (float) $limits['proofread_min_ratio'] * mb_strlen( $original ), 'detail' => mb_strlen( $corrected ) . ' vs ' . mb_strlen( $original ) . ' characters' );
			$before_headings = count( MSRWA_Engine_Input::headings( $original ) );
			$after_headings = count( MSRWA_Engine_Input::headings( $corrected ) );
			$checks['structure preserved'] = array( 'pass' => $before_headings === $after_headings, 'detail' => $after_headings . ' headings vs ' . $before_headings );
			$checks['page break kept'] = array( 'pass' => false !== strpos( $corrected, '<!--nextpage-->' ), 'detail' => false !== strpos( $corrected, '<!--nextpage-->' ) ? 'present' : 'lost' );
			preg_match_all( '/\d+(?:[.,]\d+)?/', strip_tags( $original ), $before_numbers );
			preg_match_all( '/\d+(?:[.,]\d+)?/', strip_tags( $corrected ), $after_numbers );
			sort( $before_numbers[0] ); sort( $after_numbers[0] );
			$checks['no figure altered'] = array( 'pass' => $before_numbers[0] === $after_numbers[0], 'detail' => count( $after_numbers[0] ) . ' figures, ' . ( $before_numbers[0] === $after_numbers[0] ? 'identical' : 'CHANGED' ) );
			$checks['French typography'] = array( 'pass' => self::accent_density( $corrected ) >= self::accent_density( $original ) - (float) $limits['proofread_accent_slack'], 'detail' => sprintf( '%.1f vs %.1f per 1000', self::accent_density( $corrected ), self::accent_density( $original ) ) );
		}

		// A model writing French once put a Chinese character in the middle of
		// a word — "润ir les pommes" — and the answer scored 13/13. Letters
		// from an alphabet the article is not written in are never meant.
		if ( in_array( $name, array( 'research', 'canonical_recipe', 'article', 'fact_check', 'proofread' ), true ) && $json ) {
			$stray = self::stray_script( $json, (string) ( $settings['site_language'] ?? 'fr' ) );
			$checks['one alphabet'] = array( 'pass' => ! $stray, 'detail' => $stray ? 'foreign characters: ' . implode( ' · ', array_slice( $stray, 0, 3 ) ) : 'none' );
		}

		if ( 'review' === $step ) {
			$checks['verdict is boolean'] = array( 'pass' => array_key_exists( 'pass', $json ) && is_bool( $json['pass'] ), 'detail' => isset( $json['pass'] ) ? var_export( $json['pass'], true ) : 'missing' );
			$checks['findings are structured'] = array( 'pass' => isset( $json['findings'] ) && is_array( $json['findings'] ), 'detail' => count( (array) ( $json['findings'] ?? array() ) ) . ' findings' );
		}

		$passed = 0;
		foreach ( $checks as $check ) { if ( $check['pass'] ) { $passed++; } }
		return array( 'checks' => $checks, 'passed' => $passed, 'total' => count( $checks ), 'pass' => $passed === count( $checks ) );
	}

	/**
	 * Every place in an answer where letters of a foreign alphabet appear,
	 * each with a few characters around it. The article's languages are
	 * written in Latin letters, and Arabic in Arabic with Latin names and
	 * units beside it; nothing else belongs. Addresses are not text.
	 */
	public static function stray_script( $value, $language = 'fr' ) {
		$foreign = '\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Cyrillic}\p{Thai}\p{Hebrew}\p{Devanagari}' . ( 'ar' === $language ? '' : '\p{Arabic}' );
		$found = array();
		$walk = static function ( $value, $key = '' ) use ( &$walk, &$found, $foreign ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $inner_key => $inner ) { $walk( $inner, (string) $inner_key ); }
				return;
			}
			if ( ! is_string( $value ) || preg_match( '/(^|_)url$/', $key ) ) { return; }
			if ( preg_match_all( '/.{0,8}[' . $foreign . ']+.{0,8}/u', $value, $matches ) ) {
				foreach ( $matches[0] as $match ) { $found[] = trim( $match ); }
			}
		};
		$walk( $value );
		return array_values( array_unique( $found ) );
	}

	/**
	 * Scores an approval verdict. The risk here is not a missing key but a judge that
	 * waves everything through, so the checks demand a verdict per artifact, a panel
	 * count it actually made, and findings precise enough to act on: a blocking
	 * finding that refuses to approve, and a quote on every article finding, since a
	 * paraphrase cannot be applied to the text.
	 */
	public static function approval( $verdict, $image_count, $expected_panels, $targets = array() ) {
		$checks = array();
		$readable = is_array( $verdict ) && ! empty( $verdict );
		$checks['valid JSON'] = array( 'pass' => $readable, 'detail' => $readable ? count( $verdict ) . ' keys' : 'not parseable' );
		$verdict = $readable ? $verdict : array();

		$checks['approved is a boolean'] = array( 'pass' => isset( $verdict['approved'] ) && is_bool( $verdict['approved'] ), 'detail' => isset( $verdict['approved'] ) ? var_export( $verdict['approved'], true ) : 'missing' );

		$verdicts = array( 'good', 'reservations', 'bad' );
		$targets = $targets ? array_values( (array) $targets ) : array( 'article', 'featured_image', 'facebook_image', 'consistency' );
		$missing = array();
		foreach ( $targets as $target ) {
			$value = (string) ( $verdict[ $target ]['verdict'] ?? '' );
			if ( ! in_array( $value, $verdicts, true ) ) { $missing[] = $target; }
		}
		$checks['a verdict per artifact'] = array( 'pass' => empty( $missing ), 'detail' => $missing ? 'missing or invalid: ' . implode( ', ', $missing ) : count( $targets ) . ' verdicts' );

		// Only the images that were actually shown are asked about. A run that
		// produced no collage cannot be marked down for failing to judge one.
		$image_targets = array_values( array_intersect( array( 'featured_image', 'facebook_image' ), $targets ) );
		if ( $image_targets ) {
			$realism = array();
			foreach ( $image_targets as $target ) {
				if ( ! in_array( (string) ( $verdict[ $target ]['realism'] ?? '' ), $verdicts, true ) ) { $realism[] = $target; }
			}
			$checks['realism judged separately'] = array( 'pass' => empty( $realism ), 'detail' => $realism ? 'missing: ' . implode( ', ', $realism ) : count( $image_targets ) . ' image(s)' );
		}

		// The judge must count the panels it was shown, not repeat the number asked for.
		if ( in_array( 'facebook_image', $targets, true ) ) {
			$panels = $verdict['facebook_image']['panels_counted'] ?? null;
			$checks['collage panels counted'] = array( 'pass' => is_int( $panels ) && $panels > 0, 'detail' => null === $panels ? 'missing' : $panels . ' counted, ' . $expected_panels . ' expected' );
		}

		$findings = array_values( array_filter( (array) ( $verdict['findings'] ?? array() ), 'is_array' ) );
		$blocking = 0;
		$unquoted = 0;
		$bad_target = 0;
		foreach ( $findings as $finding ) {
			if ( 'blocking' === ( $finding['severity'] ?? '' ) ) { $blocking++; }
			if ( ! in_array( (string) ( $finding['target'] ?? '' ), $targets, true ) ) { $bad_target++; }
			if ( 'article' === ( $finding['target'] ?? '' ) && '' === trim( (string) ( $finding['quote'] ?? '' ) ) ) { $unquoted++; }
			if ( '' === trim( (string) ( $finding['fix'] ?? '' ) ) ) { $unquoted++; }
		}
		$checks['findings are addressed'] = array( 'pass' => $readable && 0 === $bad_target, 'detail' => $bad_target ? $bad_target . ' with no valid target' : count( $findings ) . ' findings' );
		$checks['findings are actionable'] = array( 'pass' => $readable && 0 === $unquoted, 'detail' => $unquoted ? $unquoted . ' without a quote or a fix' : 'every finding carries a fix' );

		// A refusal nobody can act on is not a verdict.
		$justified = $readable && ( ! empty( $verdict['approved'] ) || count( $findings ) > 0 );
		$checks['a refusal is justified'] = array( 'pass' => $justified, 'detail' => $justified ? 'ok' : 'refused with no findings' );

		// A blocking finding and an approval cannot both stand.
		$coherent = $readable && ! ( $blocking > 0 && ! empty( $verdict['approved'] ) );
		$checks['approval matches findings'] = array( 'pass' => $coherent, 'detail' => $coherent ? ( $blocking . ' blocking, approved ' . var_export( ! empty( $verdict['approved'] ), true ) ) : 'approved despite ' . $blocking . ' blocking findings' );

		$checks['uncertainties reported'] = array( 'pass' => isset( $verdict['uncertainties'] ) && is_array( $verdict['uncertainties'] ), 'detail' => isset( $verdict['uncertainties'] ) ? count( (array) $verdict['uncertainties'] ) . ' entries' : 'missing' );

		return $checks;
	}

	/**
	 * Whether a verdict accepts the work. The gate, in one testable place.
	 *
	 * It used to live inside the engine's judging step, where it checked three of
	 * the contracts it had just scored and then read `approved` with !empty().
	 * That accepted the string "false", and it ignored the check that says a
	 * blocking finding and an approval cannot both stand — so a verdict could
	 * block and approve at once.
	 */
	public static function accepts( $verdict, $checks ) {
		$verdict = is_array( $verdict ) ? $verdict : array();
		$sound = true;
		foreach ( self::mandatory_contracts() as $contract ) {
			if ( empty( $checks[ $contract ]['pass'] ) ) { $sound = false; }
		}
		$blocking = 0;
		foreach ( (array) ( $verdict['findings'] ?? array() ) as $finding ) {
			if ( is_array( $finding ) && 'blocking' === ( $finding['severity'] ?? '' ) ) { $blocking++; }
		}
		return array(
			'sound' => $sound,
			'approved' => $sound && true === ( $verdict['approved'] ?? null ) && 0 === $blocking,
			'blocking' => $blocking,
		);
	}

	/**
	 * The checks a verdict must pass before it means anything at all.
	 *
	 * A judge that did not say whether the images look like photographs, or that
	 * filed a finding against a target that does not exist, has not finished the
	 * job it was given. Neither is an editorial refusal; both are a non-answer,
	 * and a non-answer must never read as approval.
	 */
	public static function mandatory_contracts() {
		return array(
			'valid JSON', 'a verdict per artifact', 'a refusal is justified',
			'approved is a boolean', 'approval matches findings',
			'realism judged separately', 'findings are addressed',
		);
	}

	/** The blocking findings the judge raised against one image. */
	public static function findings_for( $verdict, $target ) {
		$out = array();
		foreach ( (array) ( ( is_array( $verdict ) ? $verdict : array() )['findings'] ?? array() ) as $finding ) {
			if ( ! is_array( $finding ) || 'blocking' !== ( $finding['severity'] ?? '' ) ) { continue; }
			if ( $target === ( $finding['target'] ?? '' ) ) { $out[] = $finding; }
		}
		return $out;
	}

	/**
	 * The sentence-level repairs that would clear every blocking article finding.
	 *
	 * The judge quotes the sentence at fault and gives its replacement, so the
	 * repair needs no model: it is applied in code and judged again. All or
	 * nothing — one finding without a usable quote means the article needs a
	 * person, and patching the others would only hide that.
	 */
	public static function article_repairs( $verdict, $html ) {
		if ( ! is_array( $verdict ) || ! empty( $verdict['approved'] ) ) { return array(); }
		$repairs = array();
		foreach ( self::findings_for( $verdict, 'article' ) as $finding ) {
			$quote = trim( (string) ( $finding['quote'] ?? '' ) );
			if ( '' === $quote || ! array_key_exists( 'replacement', $finding ) || ! is_string( $finding['replacement'] ) ) { return array(); }
			if ( false === mb_strpos( (string) $html, $quote ) ) { return array(); }
			$replacement = trim( strip_tags( $finding['replacement'] ) );
			if ( $replacement === $quote ) { return array(); }
			$repairs[] = array( 'before' => $quote, 'after' => $replacement, 'reason' => (string) ( $finding['reason'] ?? '' ) );
		}
		return $repairs;
	}

	/**
	 * Which images a refusal asks us to regenerate. A consistency finding names no
	 * single image, so it is charged to the collage: the featured image is one
	 * photograph of the finished dish and the collage is the piece that has to agree
	 * with it, and measurement put every consistency break on the collage's side.
	 */
	/**
	 * A rule the judge reports on but has been seen to wave through: a collage
	 * ending on a whole, uncut dish. What it answers about the last panel decides,
	 * not whether it remembered to call that blocking.
	 */
	public static function enforce( $verdict, $language = 'fr' ) {
		if ( ! is_array( $verdict ) || false !== ( $verdict['facebook_image']['last_panel_opened'] ?? null ) ) { return $verdict; }
		$verdict['approved'] = false;
		$verdict['findings'] = array_values( (array) ( $verdict['findings'] ?? array() ) );
		// The judge usually says so itself; one finding is enough to redraw.
		if ( self::findings_for( $verdict, 'facebook_image' ) ) { return $verdict; }
		// Editors read the findings, so they are in the site's language.
		$french = 'fr' === substr( (string) $language, 0, 2 );
		$verdict['findings'][] = array(
			'target' => 'facebook_image', 'severity' => 'blocking', 'quote' => '', 'replacement' => '',
			'reason' => $french ? 'Le dernier panneau montre le plat entier : on n’en voit jamais l’intérieur.' : 'The last panel shows the finished dish whole: its inside is never seen.',
			'fix' => $french ? 'Montrer le plat ouvert dans le dernier panneau : une part soulevée, un morceau rompu ou une cuillerée.' : 'Show the finished dish opened in the last panel: a slice lifted out, a piece broken in two or a spoonful raised.',
		);
		return $verdict;
	}

	public static function images_to_retry( $verdict ) {
		if ( ! is_array( $verdict ) || ! empty( $verdict['approved'] ) ) { return array(); }
		$retry = array();
		foreach ( array( 'featured' => 'featured_image', 'facebook' => 'facebook_image' ) as $kind => $target ) {
			if ( self::findings_for( $verdict, $target ) ) { $retry[] = $kind; }
		}
		if ( self::findings_for( $verdict, 'consistency' ) && ! in_array( 'facebook', $retry, true ) ) { $retry[] = 'facebook'; }
		return $retry;
	}

	/**
	 * Accented characters per thousand. A French recipe written without accents
	 * is a writing mistake, not a style: real prose sits around thirty.
	 */
	public static function accent_density( $html ) {
		$text = trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) $html ) ) );
		$length = mb_strlen( $text, 'UTF-8' );
		if ( ! $length ) { return 0.0; }
		preg_match_all( '/[àâäéèêëîïôöùûüçœæ]/ui', $text, $matches );
		return round( count( $matches[0] ) * 1000 / $length, 2 );
	}

	/**
	 * The outline the specification requires, each with the wordings that satisfy
	 * it. Matching ignores accents and case so typography is measured separately.
	 */
	public static function required_sections( $settings = array() ) {
		// A caller that ships its own outline decides it entirely: this is also
		// what an editable outline needs, and the shape is section => synonyms.
		$supplied = (array) ( $settings['required_sections'] ?? array() );
		if ( $supplied ) {
			$out = array();
			foreach ( $supplied as $section => $synonyms ) {
				$out[ (string) $section ] = array_values( array_filter( array_map( 'strval', (array) $synonyms ) ) );
			}
			if ( $out ) { return $out; }
		}

		// Otherwise the shipped outline, in the language the article is being
		// written in. Matching only French wordings failed every correct
		// English article — seen live at 9/10, "missing: choix, matériel,
		// erreurs, conservation" — which then paid for a correction round that
		// could not fix anything.
		$language = (string) ( $settings['site_language'] ?? 'fr' );
		$shipped = self::outlines();
		return $shipped[ $language ] ?? $shipped['fr'];
	}

	/**
	 * The required outline per language, each section with the wordings that
	 * satisfy it. Matching ignores accents and case, so typography is measured
	 * separately and a missing accent never reads as a missing section.
	 */
	public static function outlines() {
		return array(
			'fr' => array(
				'ingrédients'  => array( 'ingredient' ),
				'choix'        => array( 'choisir', 'choix', 'selection' ),
				'substitutions'=> array( 'substitut', 'remplacer', 'alternative' ),
				'matériel'     => array( 'materiel', 'equipement', 'ustensile' ),
				'préparation'  => array( 'preparation', 'etape', 'pas a pas' ),
				'erreurs'      => array( 'erreur', 'piege', 'eviter' ),
				'conservation' => array( 'conservation', 'conserver', 'rechauff' ),
				'variantes'    => array( 'variante', 'version', 'adaptation' ),
				'service'      => array( 'service', 'servir', 'accompagn', 'decoupe' ),
				'faq'          => array( 'faq', 'questions frequentes', 'question' ),
			),
			'en' => array(
				'ingredients'  => array( 'ingredient' ),
				'choosing'     => array( 'choos', 'select', 'pick', 'buying', 'which' ),
				'substitutions'=> array( 'substitut', 'swap', 'replace', 'alternative' ),
				'equipment'    => array( 'equipment', 'tool', 'utensil', 'pan', 'kit' ),
				'method'       => array( 'method', 'step', 'how to make', 'instructions' ),
				'mistakes'     => array( 'mistake', 'wrong', 'avoid', 'pitfall', 'trouble' ),
				'storage'      => array( 'stor', 'keep', 'leftover', 'reheat', 'freez' ),
				'variations'   => array( 'variation', 'version', 'adapt', 'twist' ),
				'serving'      => array( 'serv', 'accompan', 'side', 'pair' ),
				'faq'          => array( 'faq', 'frequently asked', 'question' ),
			),
			'es' => array(
				'ingredientes' => array( 'ingrediente' ),
				'elección'     => array( 'elegir', 'eleccion', 'seleccion', 'escoger', 'comprar' ),
				'sustituciones'=> array( 'sustitu', 'reemplaz', 'alternativa', 'cambiar' ),
				'utensilios'   => array( 'utensilio', 'equipo', 'material', 'herramienta' ),
				'preparación'  => array( 'preparacion', 'paso', 'elaboracion', 'como hacer' ),
				'errores'      => array( 'error', 'evitar', 'fallo' ),
				'conservación' => array( 'conserva', 'guardar', 'recalentar', 'congela' ),
				'variantes'    => array( 'variante', 'version', 'adaptacion' ),
				'servir'       => array( 'servir', 'acompan', 'presentacion' ),
				'faq'          => array( 'faq', 'preguntas frecuentes', 'pregunta' ),
			),
			'ar' => array(
				'المكونات'     => array( 'مكون', 'مكونات' ),
				'الاختيار'     => array( 'اختيار', 'اختر', 'تختار', 'يختار', 'انتقاء', 'انتق' ),
				'البدائل'      => array( 'بديل', 'بدائل', 'استبدال' ),
				'الأدوات'      => array( 'أدوات', 'معدات', 'أواني' ),
				'الطريقة'      => array( 'طريقة', 'خطوات', 'تحضير' ),
				'الأخطاء'      => array( 'أخطاء', 'خطأ', 'تجنب' ),
				'الحفظ'        => array( 'حفظ', 'تخزين', 'تسخين' ),
				'التنويعات'    => array( 'تنويع', 'نسخة', 'تعديل' ),
				'التقديم'      => array( 'تقديم', 'يقدم', 'مرافق' ),
				'الأسئلة'      => array( 'أسئلة', 'الشائعة', 'سؤال' ),
			),
		);
	}

	/**
	 * The article's closing section: whatever sits under the last h2. An editor
	 * titles it "Une recette à refaire", not "Conclusion", so it is measured by
	 * position and substance rather than by its wording.
	 */
	public static function closing_section( $html ) {
		$parts = preg_split( '/<h2[^>]*>/i', (string) $html );
		$last = trim( (string) end( $parts ) );
		$heading = '';
		if ( preg_match( '/^(.*?)<\/h2>/is', $last, $match ) ) { $heading = trim( strip_tags( $match[1] ) ); $last = substr( $last, strlen( $match[0] ) ); }
		$words = preg_match_all( '/\p{L}+/u', strip_tags( $last ) );
		return array( 'heading' => $heading, 'words' => (int) $words, 'is_question' => false !== strpos( $heading, '?' ) );
	}
}
