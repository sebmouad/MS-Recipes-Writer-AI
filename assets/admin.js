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

  document.querySelectorAll('#msrwa-new-status, #msrwa-batch-status').forEach(function (node) {
    node.setAttribute('role', 'status'); node.setAttribute('aria-live', 'polite');
  });
  document.querySelectorAll('.msrwa-wrap form').forEach(function (settingsForm) {
    if (!settingsForm.querySelector('[name="action"][value^="msrwa_save_"]')) return;
    var dirty = false;
    var submission = null;
    settingsForm.addEventListener('input', function () { dirty = true; submission = null; });
    settingsForm.addEventListener('submit', function (event) {
      submission = event;
    });
    window.addEventListener('beforeunload', function (event) {
      if (dirty && (!submission || submission.defaultPrevented)) { event.preventDefault(); event.returnValue = ''; }
    });
  });
  document.querySelectorAll('.msrwa-wrap input[type="password"]').forEach(function (field) {
    var toggle = document.createElement('button');
    toggle.type = 'button'; toggle.className = 'button'; toggle.textContent = 'Afficher la clé saisie';
    toggle.setAttribute('aria-controls', field.id); toggle.setAttribute('aria-pressed', 'false');
    toggle.addEventListener('click', function () {
      var show = field.type === 'password'; field.type = show ? 'text' : 'password';
      toggle.textContent = show ? 'Masquer la clé saisie' : 'Afficher la clé saisie';
      toggle.setAttribute('aria-pressed', String(show));
    });
    field.after(toggle);
  });

  document.querySelectorAll('.msrwa-wrap table').forEach(function (table) {
    var scroll = table.parentElement.classList.contains('msrwa-table-scroll') ? table.parentElement : document.createElement('div');
    scroll.className = 'msrwa-table-scroll';
    scroll.tabIndex = 0;
    scroll.setAttribute('role', 'region');
    scroll.setAttribute('aria-label', 'Tableau défilant');
    if (table.parentElement !== scroll) { table.before(scroll); scroll.appendChild(table); }
    var hint = document.createElement('p');
    hint.className = 'msrwa-scroll-hint'; hint.textContent = 'Faites défiler horizontalement pour voir toutes les colonnes.';
    scroll.after(hint);
    function measure() { hint.hidden = scroll.scrollWidth <= scroll.clientWidth + 1; }
    measure();
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(measure).observe(scroll);
    else window.addEventListener('resize', measure);
  });
  document.querySelectorAll('textarea[name^="msrwa_engine["]').forEach(function (field, index) {
    var button = document.createElement('button');
    var result = document.createElement('span');
    button.type = 'button'; button.className = 'button'; button.textContent = 'Tester le JSON';
    result.id = 'msrwa-json-result-' + index;
    result.setAttribute('role', 'status');
    field.setAttribute('aria-label', field.name.replace('msrwa_engine[', '').replace(']', ''));
    field.setAttribute('aria-describedby', result.id);
    function validate() {
      try {
        var value = field.value.trim() ? JSON.parse(field.value) : {};
        if (!value || typeof value !== 'object') throw new Error('Un objet ou une liste JSON est requis.');
        field.setCustomValidity(''); field.setAttribute('aria-invalid', 'false');
        result.textContent = ' Syntaxe valide. Validation locale uniquement.';
        return true;
      } catch (error) {
        field.setCustomValidity(error.message); field.setAttribute('aria-invalid', 'true');
        result.textContent = ' ' + error.message; return false;
      }
    }
    button.addEventListener('click', validate);
    field.addEventListener('input', function () { field.setCustomValidity(''); field.removeAttribute('aria-invalid'); result.textContent = ''; });
    field.form.addEventListener('submit', function (event) { if (!validate()) { event.preventDefault(); field.reportValidity(); } });
    field.parentElement.append(button, result);
  });
  if (document.querySelector('[name="action"][value="msrwa_save_engine"], [name="action"][value="msrwa_save_settings"]')) {
    var diagnostics = document.createElement('a');
    diagnostics.href = 'admin.php?page=msrwa-operations'; diagnostics.className = 'button';
    diagnostics.textContent = 'Diagnostiquer les réglages enregistrés';
    document.querySelector('.msrwa-wrap h1').after(diagnostics);
  }

  // --- Nouveau lot -------------------------------------------------------

  var engineAction = document.querySelector('[name="action"][value="msrwa_save_engine"]');
  if (engineAction) {
    var previewButton = document.createElement('button');
    var previewOutput = document.createElement('pre');
    previewButton.type = 'button'; previewButton.className = 'button';
    previewButton.textContent = 'Simuler toute la configuration sans enregistrer';
    previewOutput.setAttribute('role', 'status'); previewOutput.hidden = true;
    engineAction.form.append(previewButton, previewOutput);
    previewButton.addEventListener('click', function () {
      var config = {};
      new FormData(engineAction.form).forEach(function (value, key) {
        var match = key.match(/^msrwa_engine\[(.+)\]$/);
        if (match) config[match[1]] = value;
      });
      previewButton.disabled = true; previewOutput.hidden = false;
      previewOutput.textContent = 'Diagnostic en cours…';
      call('/diagnostics/config', { method: 'POST', body: JSON.stringify({ config: config }) })
        .then(function (data) { previewOutput.textContent = JSON.stringify(data, null, 2); })
        .catch(function (error) { previewOutput.textContent = error.message; })
        .finally(function () { previewButton.disabled = false; });
    });
  }

  var form = document.getElementById('msrwa-new-batch');
  if (form) {
	var recipesField = document.getElementById('msrwa-recipes');
	recipesField.required = true;
	var recipeSummary = document.createElement('p');
	recipeSummary.setAttribute('role', 'status');
	recipesField.after(recipeSummary);
	var recipeList = document.createElement('ol'); recipeList.className = 'msrwa-recipe-preview';
	recipeSummary.after(recipeList);
	function summarizeRecipes() {
	  var recipes = recipesField.value.split(/^\s*-{3,}\s*$/m).filter(function (item) { return item.trim(); });
	  recipeSummary.textContent = recipes.length + ' recette(s) à préparer. Les associations seront confirmées avant lancement.';
	  recipeList.replaceChildren();
	  recipes.forEach(function (recipe) {
	    var item = document.createElement('li');
	    item.textContent = recipe.trim().split(/\r?\n/)[0].replace(/^#+\s*/, '');
	    recipeList.appendChild(item);
	  });
	}
	recipesField.addEventListener('input', summarizeRecipes);
	summarizeRecipes();
    var picker = null;
    var chosen = [];
    var field = document.getElementById('msrwa-images');
    var count = document.getElementById('msrwa-image-count');
    var status = document.getElementById('msrwa-new-status');
    var thumbnails = document.createElement('div');
    thumbnails.className = 'msrwa-thumbnail-list'; count.parentElement.after(thumbnails);

    document.getElementById('msrwa-pick-images').addEventListener('click', function () {
      if (!picker) {
        picker = wp.media({ title: 'Photographies des recettes', multiple: true, library: { type: 'image' } });
        picker.on('select', function () {
          chosen = picker.state().get('selection').map(function (item) { return item.id; });
          field.value = chosen.join(',');
          say(count, chosen.length ? chosen.length + ' photographie(s)' : 'aucune');
          thumbnails.replaceChildren();
          picker.state().get('selection').forEach(function (item) {
            var data = item.toJSON();
            var figure = document.createElement('figure');
            var img = document.createElement('img');
            var caption = document.createElement('figcaption');
            img.src = data.sizes && data.sizes.thumbnail ? data.sizes.thumbnail.url : data.url;
            img.alt = data.alt || data.title || 'Photographie sélectionnée';
            caption.textContent = data.filename || data.title || '';
            figure.append(img, caption); thumbnails.appendChild(figure);
          });
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
      form.setAttribute('aria-busy', 'true');
      say(status, chosen.length ? 'Description et appariement des photographies… Gardez cette page ouverte.' : 'Préparation du lot…');
      call('/batches', {
        method: 'POST',
        body: JSON.stringify({
          recipes: document.getElementById('msrwa-recipes').value,
          images: field.value,
          budget: document.getElementById('msrwa-budget') ? parseFloat(document.getElementById('msrwa-budget').value) : undefined
        })
      }).then(function (data) {
        window.location = 'admin.php?page=msrwa-batch&batch_id=' + data.id;
      }).catch(function (error) {
        say(status, error.message);
        button.disabled = false;
        form.removeAttribute('aria-busy');
      });
    });
  }

  // --- Un lot : appariement puis runs ------------------------------------

  var wrap = document.querySelector('.msrwa-wrap[data-batch]');
  if (!wrap) return;
  var batch = wrap.dataset.batch;
  var batchStatus = document.getElementById('msrwa-batch-status');
  if (!batchStatus) {
    batchStatus = document.createElement('p'); batchStatus.id = 'msrwa-batch-status';
    batchStatus.setAttribute('role', 'status'); wrap.querySelector('h1').after(batchStatus);
  }
  var timer = null;
  var refreshing = false;

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
    if (refreshing || document.hidden) return;
    refreshing = true;
    return call('/batches/' + batch + '/runs').then(function (data) {
      var moving = false;
      var counts = { queued: 0, running: 0, done: 0, failed: 0, cancelled: 0 };
      var labels = { queued: 'En attente', running: 'En cours', done: 'Terminé', failed: 'Échoué', cancelled: 'Annulé' };
      say(batchStatus, 'Suivi actualisé à ' + new Date().toLocaleTimeString('fr-FR') + '.');
      data.runs.forEach(function (run) {
        var row = table.querySelector('tr[data-run="' + run.id + '"]');
        if (!row) { window.location.reload(); return; }
        if (Object.prototype.hasOwnProperty.call(counts, run.status)) counts[run.status]++;
        var badge = row.querySelector('.msrwa-state');
        badge.dataset.state = run.status;
        badge.textContent = (labels[run.status] || run.status) + (run.step && 'running' === run.status ? ' — ' + run.step : '');
        var progress = row.querySelector('.msrwa-run-progress');
        progress.max = Math.max(1, run.steps_total);
        progress.value = Math.min(run.steps_done, progress.max);
        row.querySelector('.msrwa-run-steps').textContent = run.steps_done + ' / ' + run.steps_total;
        row.querySelector('.msrwa-run-cost').textContent = run.cost_usd.toFixed(4) + ' $';
        row.querySelector('.msrwa-run-seconds').textContent = run.seconds.toFixed(1) + ' s';
        if ('queued' === run.status || 'running' === run.status) moving = true;
      });
      Object.keys(counts).forEach(function (state) {
        var count = document.querySelector('[data-run-count="' + state + '"]');
        if (count) count.textContent = counts[state];
      });
      // A finished batch is a page whose drafts have appeared; reload once so
      // their links are there rather than asking the reader to press refresh.
      if (!moving && timer) { window.clearInterval(timer); timer = null; window.location.reload(); }
      if (moving && !timer) { timer = window.setInterval(refresh, 5000); }
    }).catch(function (error) {
      say(batchStatus, 'Suivi interrompu : ' + error.message + ' Nouvelle tentative automatique.');
      if (!timer) timer = window.setInterval(refresh, 10000);
    }).finally(function () { refreshing = false; });
  }

  refresh();
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
}());
