<?php
// The prompt a model receives is compiled from the settings, so a settings
// change is a prompt change — in the plugin and in the lab alike.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'images', 'prompt' );

$template = "Write {{words_total}} words in {{language}}.\n{{#if two_pages}}Split at {{page_break_marker}}, page two opens on {{page2_opening}}.{{/if}}{{#unless two_pages}}One single page.{{/unless}}\nSections:\n{{required_sections}}\nFeatured {{featured_size}}, Facebook {{facebook_size}}, format {{image_format}}.";

msrwa_test_settings( array( 'quality_min_words' => 2800, 'article_pagination_enabled' => 1, 'featured_ratio' => '1:1', 'facebook_ratio' => '4:5', 'image_format' => 'webp' ) );
$two = MSRWA_Prompt::compile( $template, MSRWA_Settings::get() );
msrwa_test_contains( $two, '2800 words in French', 'The word count and language must come from the settings.' );
msrwa_test_contains( $two, '<!--nextpage-->', 'A two-page article must carry the page break marker.' );
msrwa_test_contains( $two, 'Préparation de la recette étape par étape', 'Page two opens on the configured heading.' );
msrwa_test_contains( $two, '1024x1024', 'The featured size follows the configured ratio.' );
msrwa_test_contains( $two, '1024x1536', 'The Facebook size follows the configured ratio.' );
msrwa_test_contains( $two, 'WEBP', 'The image format reaches the prompt.' );

// Turning pagination off rewrites the prompt rather than post-processing the article.
msrwa_test_settings( array( 'quality_min_words' => 1800, 'article_pagination_enabled' => 0, 'featured_ratio' => '1:1', 'facebook_ratio' => '4:5' ) );
$single = MSRWA_Prompt::compile( $template, MSRWA_Settings::get() );
msrwa_test_contains( $single, '1800 words', 'A shorter target must reach the prompt.' );
msrwa_test_contains( $single, 'One single page', 'A single-page article must say so.' );
msrwa_test_missing( $single, '<!--nextpage-->', 'A single-page article must not ask for a page break.' );

// The outline is editable and its order is kept.
msrwa_test_settings( array( 'required_sections' => array( 'Première section', 'Deuxième section' ) ) );
$custom = MSRWA_Prompt::compile( $template, MSRWA_Settings::get() );
msrwa_test_contains( $custom, '1. Première section', 'A configured outline must be numbered in order.' );
msrwa_test_contains( $custom, '2. Deuxième section', 'Every configured section must appear.' );
msrwa_test_assert( 14 === count( MSRWA_Prompt::default_sections() ), 'The shipped outline carries the agreed sections.' );

// An unknown placeholder is removed, never shown to the model.
msrwa_test_settings();
$unknown = MSRWA_Prompt::compile( 'Keep {{words_total}} and drop {{not_a_variable}}.', MSRWA_Settings::get() );
msrwa_test_missing( $unknown, '{{', 'No placeholder may survive compilation.' );
msrwa_test_missing( $unknown, 'not_a_variable', 'An unknown variable must not leak into the prompt.' );

// The standalone lab keeps one research-centred contract per active stage.
require_once dirname( __DIR__ ) . '/tools/lib/steps.php';
$lab_steps = lab_steps();
$active = array_values( array_diff( array_keys( $lab_steps ), array( 'research_recipe' ) ) );
msrwa_test_assert( array( 'research', 'canonical_recipe', 'article', 'review', 'fact_check', 'proofread' ) === $active, 'The lab must expose the active text pipeline, plus rejected experiments that stay re-runnable.' );
$lab_prompts = glob( dirname( __DIR__ ) . '/tools/prompts/*.txt' );
msrwa_test_assert( 9 === count( $lab_prompts ), 'The lab must keep one maintained prompt for six text stages, two image stages and the final approval.' );
$brief = lab_brief( 'tarte-pommes' );
$article_input = lab_build_input( 'article', lab_prompt( 'article' ), $brief, array() );
msrwa_test_contains( $article_input, 'RESEARCH PACKAGE:', 'The article lab input must carry the shared research package.' );
msrwa_test_contains( $article_input, 'observed in photographs of it', 'Real-image observations must reach the article, distilled rather than raw.' );
$facebook_template = file_get_contents( dirname( __DIR__ ) . '/tools/prompts/facebook_image.tpl.txt' );
$facebook_prompt = MSRWA_Prompt::compile( $facebook_template, lab_settings() );
msrwa_test_contains( $facebook_prompt, 'One vertical 2:3 canvas at 1024x1536', 'The Facebook benchmark must compile to the dominant reference geometry.' );
msrwa_test_contains( $facebook_prompt, 'EXACTLY 6 equal panels', 'The Facebook collage must require a strict six-panel grid.' );
msrwa_test_contains( $facebook_prompt, 'CHOOSE ONE STORYBOARD ARCHETYPE', 'The Facebook storyboard must adapt to the recipe type.' );
msrwa_test_contains( $facebook_prompt, 'PENULTIMATE STATE', 'Panel five must adapt to the most useful pre-serving state.' );
msrwa_test_contains( $facebook_prompt, 'APPETITE HERO', 'The Facebook storyboard must finish with the strongest truthful serving.' );
msrwa_test_contains( $facebook_prompt, 'do not sample mechanically', 'The Facebook storyboard must select visual transformations instead of evenly spaced steps.' );

msrwa_test_done( 'MSRWA prompt compiler contracts' );
