/**
 * The two screens that talk back: a new submission, and a batch being matched
 * then run. Everything else on the admin side is rendered by PHP.
 */
(function () {
  'use strict';

  if (typeof MSRWA === 'undefined') return;

  function call(path, options) {
    options = options || {};
    options.headers = Object.assign({ 'X-WP-Nonce': MSRWA.nonce, 'Content-Type': 'application/json' }, options.headers || {});
    return fetch(MSRWA.api + path, options).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) throw new Error(data.message || 'Une erreur est survenue.');
        return data;
      });
    });
  }

  function say(node, message) { if (node) node.textContent = message; }

  // --- Nouveau lot -------------------------------------------------------

  var form = document.getElementById('msrwa-new-batch');
  if (form) {
    var picker = null;
    var chosen = [];
    var field = document.getElementById('msrwa-images');
    var count = document.getElementById('msrwa-image-count');
    var status = document.getElementById('msrwa-new-status');

    document.getElementById('msrwa-pick-images').addEventListener('click', function () {
      if (!picker) {
        picker = wp.media({ title: 'Photographies des recettes', multiple: true, library: { type: 'image' } });
        picker.on('select', function () {
          chosen = picker.state().get('selection').map(function (item) { return item.id; });
          field.value = chosen.join(',');
          say(count, chosen.length ? chosen.length + ' photographie(s)' : 'aucune');
        });
      }
      picker.open();
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var button = form.querySelector('button[type=submit]');
      button.disabled = true;
      // The photographs are described here, one call each, so this is the slow
      // part of the submission and the only part that spends before a run.
      say(status, 'Description des photographies…');
      call('/batches', {
        method: 'POST',
        body: JSON.stringify({
          recipes: document.getElementById('msrwa-recipes').value,
          images: field.value,
          budget: parseFloat(document.getElementById('msrwa-budget').value)
        })
      }).then(function (data) {
        window.location = 'admin.php?page=msrwa-batch&batch_id=' + data.id;
      }).catch(function (error) {
        say(status, error.message);
        button.disabled = false;
      });
    });
  }

  // --- Un lot : appariement puis runs ------------------------------------

  var wrap = document.querySelector('.msrwa-wrap[data-batch]');
  if (!wrap) return;
  var batch = wrap.dataset.batch;
  var batchStatus = document.getElementById('msrwa-batch-status');
  var timer = null;

  function pairs() {
    return Array.prototype.map.call(document.querySelectorAll('.msrwa-pair'), function (select) {
      return { image: parseInt(select.dataset.image, 10), recipe: '' === select.value ? null : parseInt(select.value, 10) };
    });
  }

  var save = document.getElementById('msrwa-save-pairs');
  if (save) {
    save.addEventListener('click', function () {
      save.disabled = true;
      say(batchStatus, 'Enregistrement…');
      call('/batches/' + batch + '/pairs', { method: 'POST', body: JSON.stringify({ pairs: pairs() }) })
        .then(function () { say(batchStatus, 'Appariement enregistré.'); })
        .catch(function (error) { say(batchStatus, error.message); })
        .finally(function () { save.disabled = false; });
    });
  }

  var dispatch = document.getElementById('msrwa-dispatch');
  if (dispatch) {
    dispatch.addEventListener('click', function () {
      dispatch.disabled = true;
      say(batchStatus, 'Envoi…');
      // The pairing is saved first, so what is dispatched is always what is on
      // screen rather than what was last confirmed.
      call('/batches/' + batch + '/pairs', { method: 'POST', body: JSON.stringify({ pairs: pairs() }) })
        .then(function () { return call('/batches/' + batch + '/dispatch', { method: 'POST' }); })
        .then(function (data) {
          say(batchStatus, data.started + ' recette(s) lancée(s).');
          window.location.reload();
        })
        .catch(function (error) { say(batchStatus, error.message); dispatch.disabled = false; });
    });
  }

  var table = document.getElementById('msrwa-runs');
  if (!table) return;

  function refresh() {
    return call('/batches/' + batch + '/runs').then(function (data) {
      var moving = false;
      data.runs.forEach(function (run) {
        var row = table.querySelector('tr[data-run="' + run.id + '"]');
        if (!row) { window.location.reload(); return; }
        row.querySelector('.msrwa-run-state').textContent = run.status + (run.step && 'running' === run.status ? ' — ' + run.step : '');
        row.querySelector('.msrwa-run-steps').textContent = run.steps_done + ' / ' + run.steps_total;
        row.querySelector('.msrwa-run-cost').textContent = run.cost_usd.toFixed(4) + ' $';
        row.querySelector('.msrwa-run-seconds').textContent = run.seconds.toFixed(1) + ' s';
        if ('queued' === run.status || 'running' === run.status) moving = true;
      });
      // A finished batch is a page whose drafts have appeared; reload once so
      // their links are there rather than asking the reader to press refresh.
      if (!moving && timer) { window.clearInterval(timer); timer = null; window.location.reload(); }
      if (moving && !timer) { timer = window.setInterval(refresh, 5000); }
    }).catch(function () {
      if (timer) { window.clearInterval(timer); timer = null; }
    });
  }

  refresh();
}());
