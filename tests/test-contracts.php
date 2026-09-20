<?php
// Lightweight contract checks runnable without a WordPress installation.
define( 'ABSPATH', __DIR__ );
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_user( $value ) { return preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $value ); }
function esc_url_raw( $value ) { return filter_var( $value, FILTER_SANITIZE_URL ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function strip_shortcodes( $value ) { return (string) $value; }
function is_wp_error( $value ) { return false; }
class WP_Error { public function __construct() {} }
require dirname( __DIR__ ) . '/includes/class-msrwa-catalog.php';
require dirname( __DIR__ ) . '/includes/class-msrwa-recipe.php';
require dirname( __DIR__ ) . '/includes/class-msrwa-router.php';
require dirname( __DIR__ ) . '/includes/class-msrwa-quality.php';

class MSRWA_Settings {
	public static function get() {
		return array( 'mode' => 'automatic', 'openai_key' => '', 'gemini_key' => '', 'claude_key' => '', 'manual_models' => array( 'text' => 'openai:gpt-5.6-luna', 'review' => 'claude:claude-sonnet-5', 'image' => 'gemini:gemini-3.1-flash-image', 'search' => 'openai:gpt-5.6-luna' ), 'quality_min_score' => 90, 'quality_min_words' => 2800, 'quality_max_words' => 4200, 'quality_min_headings' => 16, 'quality_min_paragraphs' => 35, 'quality_min_ingredients' => 6, 'quality_min_steps' => 6, 'internal_links_max' => 3 );
	}
}
define( 'MSRWA_OPENAI_KEY', 'contract-test-only' );

$eligible = MSRWA_Catalog::eligible( 'text' );
if ( ! isset( $eligible['openai:gpt-5.6-luna'] ) ) { throw new RuntimeException( 'Luna missing from catalog.' ); }
$input = MSRWA_Recipe::normalize_input( array( 'title' => '  Tarte <b>aux pommes</b> ', 'text' => "Farine\nPommes", 'images' => array( 'https://example.test/a.jpg', 'javascript:alert(1)' ) ) );
if ( 'Tarte aux pommes' !== $input['title'] || 1 !== count( $input['reference_images'] ) ) { throw new RuntimeException( 'Input normalization failed.' ); }
if ( ! MSRWA_Recipe::can_transition( 'intake', 'association' ) || MSRWA_Recipe::can_transition( 'intake', 'review' ) ) { throw new RuntimeException( 'Stage transition contract failed.' ); }
$route = MSRWA_Router::plan( 'text' );
if ( ! is_array( $route ) || 'openai' !== $route['provider'] || 'gpt-5.6-luna' !== $route['model'] ) { throw new RuntimeException( 'Automatic router contract failed.' ); }
$paragraph = implode( ' ', array_fill( 0, 80, 'mot' ) );
$content = implode( '', array_map( function ( $index ) use ( $paragraph ) { return ( $index <= 16 ? '<h2>Section ' . $index . '</h2>' : '' ) . '<p>' . $paragraph . '</p>'; }, range( 1, 35 ) ) );
$canonical = array( 'servings' => 4, 'prep_minutes' => 20, 'cook_minutes' => 30, 'cuisine' => 'Française', 'calories_estimate' => 450, 'ingredients' => array_fill( 0, 6, array( 'name' => 'Ingrédient' ) ), 'steps' => array_fill( 0, 6, array( 'text' => 'Étape' ) ), 'difficulty' => 'Facile', 'equipment' => array( 'Four' ), 'notes' => array( 'Note' ), 'faq' => array( array( 'question' => 'Question ?', 'answer' => 'Réponse.' ) ), 'keywords' => array( 'recette' ) );
$article = array( 'content_html' => $content, 'excerpt' => str_repeat( 'e', 140 ), 'seo_title' => str_repeat( 't', 45 ), 'seo_description' => str_repeat( 'd', 145 ), 'slug' => 'recette-test', 'tags' => array( 'test' ), 'categories' => array( 'Recettes' ), 'facebook_caption' => 'Découvrez cette recette.', 'internal_links' => array( array( 'url' => '/a/' ), array( 'url' => '/b/' ) ) );
$quality = MSRWA_Quality::evaluate( $article, $canonical );
if ( empty( $quality['pass'] ) || 100 !== $quality['score'] ) { throw new RuntimeException( 'Quality gate passing contract failed.' ); }
$article['content_html'] = '<h2>Recette</h2><p>Trop court.</p>';
if ( ! empty( MSRWA_Quality::evaluate( $article, $canonical )['pass'] ) ) { throw new RuntimeException( 'Quality gate rejection contract failed.' ); }
echo "MSRWA contracts OK\n";
