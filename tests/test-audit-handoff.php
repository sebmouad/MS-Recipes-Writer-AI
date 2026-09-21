<?php
require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/tools/lib/steps.php';
require_once dirname(__DIR__) . '/tools/report.php';

// F11: use a fake sentinel only; create()/to_array() do not resolve credentials.
$sentinel = 'AUDIT_FAKE_SECRET_DO_NOT_USE';
$config = MSRWA_Engine_Config::create(array(
    'providers' => array(
        'openai' => array(
            'headers' => array('Authorization: Bearer ' . $sentinel),
        ),
    ),
));
msrwa_test_missing(
    json_encode($config->to_array()),
    $sentinel,
    'F11: serialized configuration must redact literal credentials.'
);

// F12: round-trip the modern engine envelope through the existing loader.
$canonical = array('title' => 'AUDIT_CANONICAL_SENTINEL', 'servings' => 2);
$path = tempnam(sys_get_temp_dir(), 'msrwa-audit-');
if (false === $path) {
    throw new RuntimeException('Could not create temporary audit fixture.');
}
try {
    $json = json_encode(array(
        'ok' => true,
        'artifacts' => array('canonical' => $canonical),
        'steps' => array(),
        'totals' => array(),
        'errors' => array(),
        'events' => array(),
    ));
    if (false === $json || strlen($json) !== file_put_contents($path, $json)) {
        throw new RuntimeException('Could not write complete audit fixture.');
    }
    $loaded = lab_canonical_recipe(array(), array('canonical' => $path));
    msrwa_test_assert(
        $canonical === $loaded,
        'F12: a saved engine run must resolve to artifacts.canonical.'
    );
} finally {
    unlink($path);
}

// F01 reporting surface: a string "false" is not a boolean approval.
$cards = report_approval_cards(array('approved' => 'false'));
msrwa_test_missing(
    $cards,
    '>Approuvé<',
    'F01 report: a wrongly typed approval must not display as approved.'
);

// F35: inspect the HTML string only; do not open this fixture in a browser.
$marker = '<script>document.documentElement.dataset.msrwaAudit = "1";</script>';
$html = report_render(array(
    'ok' => true,
    'artifacts' => array(
        'article' => array(
            'title' => 'Offline audit',
            'content_html' => '<p>Harmless fixture.</p>' . $marker,
        ),
    ),
    'steps' => array(),
    'totals' => array(),
    'errors' => array(),
    'events' => array(),
));
msrwa_test_missing(
    $html,
    $marker,
    'F35: article markup must not inject a script into the report.'
);

// F01 at the gate itself, not only at the report that displays it.
$complete = array(
    'approved' => true,
    'article' => array('verdict' => 'good', 'summary' => 'x'),
    'featured_image' => array('verdict' => 'good', 'realism' => 'good', 'summary' => 'x'),
    'facebook_image' => array('verdict' => 'good', 'realism' => 'good', 'panels_counted' => 6, 'summary' => 'x'),
    'consistency' => array('verdict' => 'good', 'summary' => 'x'),
    'findings' => array(),
    'uncertainties' => array(),
);
msrwa_test_assert(true === MSRWA_Engine_Score::accepts($complete, MSRWA_Engine_Score::approval($complete, 2, 6))['approved'], 'F01: a clean verdict must still approve.');

$blocked = $complete;
$blocked['findings'] = array(array('target' => 'featured_image', 'severity' => 'blocking', 'quote' => '', 'reason' => 'poivrons absents de la recette', 'fix' => 'les retirer'));
msrwa_test_assert(false === MSRWA_Engine_Score::accepts($blocked, MSRWA_Engine_Score::approval($blocked, 2, 6))['approved'], 'F01: approved=true with a blocking finding must never be accepted.');

foreach (array('false', 1, 'true') as $wrong) {
    $typed = $complete;
    $typed['approved'] = $wrong;
    msrwa_test_assert(false === MSRWA_Engine_Score::accepts($typed, MSRWA_Engine_Score::approval($typed, 2, 6))['approved'], 'F01: ' . var_export($wrong, true) . ' is not a boolean approval.');
}

$partial = $complete;
unset($partial['featured_image']['realism']);
msrwa_test_assert(false === MSRWA_Engine_Score::accepts($partial, MSRWA_Engine_Score::approval($partial, 2, 6))['sound'], 'F01: a verdict missing a required field is not sound.');

// F05: an unpriced model costs an unknown amount, never nothing.
$unpriced = new MSRWA_Result();
$unpriced->step('article', array('cost_usd' => null, 'usage' => array('input_tokens' => 5000, 'output_tokens' => 900)));
msrwa_test_assert(1 === $unpriced->totals()['unpriced_steps'], 'F05: a step with usage and no published rate must be counted as unpriced.');

// F03: one physical call is charged once, in its own bucket.
$once = new MSRWA_Result();
$once->step('final_approval', array('cost_usd' => 0.02, 'usage' => array()));
$once->step('facebook_image', array('cost_usd' => 0.03, 'usage' => array()));
msrwa_test_assert(0.05 === round($once->totals()['cost_usd'], 4), 'F03: two judgements and a redraw must total their own sum.');
msrwa_test_assert(0.03 === round($once->totals()['buckets']['facebook'], 4), 'F03: a redraw belongs to its own image bucket.');

// F11: a credential in an endpoint must not survive serialization either.
$leaky = MSRWA_Engine_Config::create(array('providers' => array('openai' => array(
    'text_endpoint' => 'https://user:AUDIT_URL_SECRET@gw.test/v1?api_key=AUDIT_QUERY_SECRET',
))));
$serialized = json_encode($leaky->to_array());
msrwa_test_missing($serialized, 'AUDIT_URL_SECRET', 'F11: userinfo credentials must be redacted.');
msrwa_test_missing($serialized, 'AUDIT_QUERY_SECRET', 'F11: a key in a query string must be redacted.');

// F35: legitimate recipe formatting must survive the sanitiser.
$kept = report_article_html('<h2>T</h2><p>Cuire <strong>45 min</strong></p><ul><li>x</li></ul><a href="https://example.org">s</a>');
foreach (array('<h2>', '<strong>', '<li>', 'https://example.org') as $keep) {
    msrwa_test_contains($kept, $keep, 'F35: the sanitiser must keep ' . $keep . '.');
}
msrwa_test_missing(report_article_html('<p onclick="x()">t</p><img src=x onerror=y>'), 'onerror', 'F35: event attributes must not survive.');
msrwa_test_missing(report_article_html('<a href="javascript:x()">t</a>'), 'javascript:', 'F35: an unsafe URL scheme must not survive.');

msrwa_test_done('audit-handoff-regressions');
