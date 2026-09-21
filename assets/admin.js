/**
 * The two screens that talk back: submitting a lot, and watching one run.
 *
 * Everything else is rendered by PHP and stays rendered. What is here does one
 * of three things — help someone fill a form in, keep a rail honest while work
 * moves, or send a decision. Nothing here decides anything itself.
 */
(function () {
  'use strict';

  if (typeof MSRWA === 'undefined') return;

  var t = MSRWA.text || {};

  function call(path, options) {
    options = options || {};
    options.headers = Object.assign({ 'X-WP-Nonce': MSRWA.nonce, 'Content-Type': 'application/json' }, options.headers || {});
    return fetch(MSRWA.api + path, options).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) throw new Error(data.message || t.failed || 'Erreur.');
        return data;
      });
    });
  }

  function say(node, message) { if (node) node.textContent = message || ''; }
  function money(value) { return value.toFixed(4) + ' $'; }

  /** The separator the writer types, mirrored from MSRWA_Intake so the count agrees. */
  function countRecipes(text) {
    return text.split(/^\s*-{3,}\s*$/m).filter(function (block) { return block.trim() !== ''; }).length;
  }

  // --- Composing a lot ---------------------------------------------------

  var compose = document.getElementById('ms-compose');
  if (compose) {
    var recipes = document.getElementById('ms-recipes');
    var recipeCount = document.getElementById('ms-recipe-count');
    var field = document.getElementById('ms-images');
    var thumbs = document.getElementById('ms-thumbs');
    var imageCount = document.getElementById('ms-image-count');
    var estimate = document.getElementById('ms-estimate');
    var status = document.getElementById('ms-compose-status');
    var picker = null;
    var chosen = [];

    function refreshEstimate() {
      var count = countRecipes(recipes.value);
      var profile = compose.querySelector('input[name=profile]:checked');
      var per = profile ? parseFloat(profile.closest('.ms-choice').querySelector('.ms-choice-cost').textContent.replace(/[^\d.]/g, '')) : 0;
      var cap = parseFloat(document.getElementById('ms-budget').value) || 0;

      say(recipeCount, count ? (count === 1 ? t.oneRecipe : (t.manyRecipes || '').replace('%d', count)) : '');
      if (!count) { say(estimate, ''); return; }

      // Two numbers, and they are different things: what this is likely to
      // cost, and the most it is allowed to cost. Showing only the first is
      // how a surprise bill happens.
      say(estimate, (t.estimate || '')
        .replace('%1$s', money(per * count))
        .replace('%2$s', money(cap * count))
        .replace('%3$d', count));
    }

    recipes.addEventListener('input', refreshEstimate);
    document.getElementById('ms-budget').addEventListener('input', refreshEstimate);
    compose.querySelectorAll('input[name=profile]').forEach(function (input) { input.addEventListener('change', refreshEstimate); });

    document.getElementById('ms-pick').addEventListener('click', function () {
      if (!picker) {
        picker = wp.media({ title: t.pickImages || '', multiple: true, library: { type: 'image' } });
        picker.on('select', function () {
          var selection = picker.state().get('selection');
          chosen = [];
          thumbs.innerHTML = '';
          selection.each(function (item) {
            chosen.push(item.id);
            var sizes = item.get('sizes') || {};
            var src = (sizes.thumbnail || sizes.medium || sizes.full || {}).url || item.get('url');
            var img = document.createElement('img');
            img.src = src;
            img.alt = '';
            thumbs.appendChild(img);
          });
          field.value = chosen.join(',');
          say(imageCount, chosen.length === 1 ? t.oneImage : (t.manyImages || '').replace('%d', chosen.length));
        });
      }
      picker.open();
    });

    compose.addEventListener('submit', function (event) {
      event.preventDefault();
      var button = document.getElementById('ms-submit');
      if (!countRecipes(recipes.value)) { say(status, t.noRecipes || ''); return; }

      button.disabled = true;
      // The photographs are described here, one call each. It is the slow part
      // of submitting and the only part that spends before a run exists, so it
      // says so rather than sitting on a spinner.
      say(status, t.describing || '');
      call('/batches', {
        method: 'POST',
        body: JSON.stringify({
          recipes: recipes.value,
          images: field.value,
          budget: parseFloat(document.getElementById('ms-budget').value),
          profile: (compose.querySelector('input[name=profile]:checked') || {}).value,
          language: document.getElementById('ms-language').value
        })
      }).then(function (data) {
        window.location = 'admin.php?page=msrwa-batch&batch_id=' + data.id;
      }).catch(function (error) {
        say(status, error.message);
        button.disabled = false;
      });
    });

    refreshEstimate();
  }

  // --- A lot: settling the pairing, then watching it run -----------------

  var wrap = document.querySelector('[data-batch]');
  var batch = wrap ? wrap.dataset.batch : null;
  var batchStatus = document.getElementById('ms-batch-status');
  var timer = null;

  function pairs() {
    return Array.prototype.map.call(document.querySelectorAll('.ms-pair-choice'), function (select) {
      return { image: parseInt(select.dataset.image, 10), recipe: '' === select.value ? null : parseInt(select.value, 10) };
    });
  }

  var save = document.getElementById('ms-save-pairs');
  if (save) {
    save.addEventListener('click', function () {
      save.disabled = true;
      say(batchStatus, t.saving || '');
      call('/batches/' + batch + '/pairs', { method: 'POST', body: JSON.stringify({ pairs: pairs() }) })
        .then(function () { say(batchStatus, t.saved || ''); })
        .catch(function (error) { say(batchStatus, error.message); })
        .finally(function () { save.disabled = false; });
    });
  }

  var dispatch = document.getElementById('ms-dispatch');
  if (dispatch) {
    dispatch.addEventListener('click', function () {
      dispatch.disabled = true;
      say(batchStatus, t.sending || '');
      // The pairing is saved first, so what is dispatched is what is on screen
      // rather than what was last confirmed.
      call('/batches/' + batch + '/pairs', { method: 'POST', body: JSON.stringify({ pairs: pairs() }) })
        .then(function () { return call('/batches/' + batch + '/dispatch', { method: 'POST' }); })
        .then(function () { window.location.reload(); })
        .catch(function (error) { say(batchStatus, error.message); dispatch.disabled = false; });
    });
  }

  // --- Keeping a rail honest ---------------------------------------------

  var rail = document.querySelector('[data-batch] .ms-rail') || document.querySelector('#ms-live-rail .ms-rail');
  if (!rail || !batch) return;

  function paint(run) {
    var ticket = rail.querySelector('[data-run="' + run.id + '"]');
    if (!ticket) { window.location.reload(); return true; }

    var set = function (field, value) {
      var node = ticket.querySelector('[data-field="' + field + '"]');
      if (node) node.textContent = value;
    };
    set('steps', run.steps_done + '/' + run.steps_total);
    set('cost', money(run.cost_usd));
    set('step', 'running' === run.status ? run.step : '');

    var bar = ticket.querySelector('.ms-progress i');
    if (bar) bar.style.inlineSize = Math.min(100, Math.round(100 * run.steps_done / Math.max(1, run.steps_total))) + '%';

    return 'queued' === run.status || 'running' === run.status;
  }

  function refresh() {
    return call('/batches/' + batch + '/runs').then(function (data) {
      var moving = data.runs.map(paint).some(Boolean);
      if (moving && !timer) { timer = window.setInterval(refresh, 5000); }
      // A settled batch has drafts that were not there when the page loaded,
      // so it is reloaded once rather than leaving stale links behind.
      if (!moving && timer) { window.clearInterval(timer); timer = null; window.location.reload(); }
    }).catch(function () {
      if (timer) { window.clearInterval(timer); timer = null; }
    });
  }

  refresh();
}());
