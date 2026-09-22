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

  /**
   * A site without pretty permalinks is served ?rest_route=/msrwa/v1, so a
   * path with its own "?" pasted on the end lands inside that value and the
   * route is never found. The parameters are therefore assembled here, with
   * whichever separator the base has left available.
   */
  function endpoint(path, params) {
    var url = MSRWA.api + path;
    var query = Object.keys(params || {}).map(function (key) {
      return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
    }).join('&');
    if (!query) return url;
    return url + (url.indexOf('?') === -1 ? '?' : '&') + query;
  }

  function call(path, options, params) {
    options = options || {};
    options.headers = Object.assign({ 'X-WP-Nonce': MSRWA.nonce, 'Content-Type': 'application/json' }, options.headers || {});
    return fetch(endpoint(path, params), options).then(function (response) {
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
    var budgetField = document.getElementById('ms-budget');
    var picker = null;
    var chosen = [];

    var pending = null;

    function refreshEstimate() {
      var count = countRecipes(recipes.value);
      say(recipeCount, count ? (count === 1 ? t.oneRecipe : (t.manyRecipes || '').replace('%d', count)) : '');
      if (!count) { say(estimate, ''); return; }

      // The figure comes from the server, because it is worked out from the
      // routing and rates actually configured rather than from anything this
      // script could know. Debounced: somebody typing a long recipe should not
      // ask a hundred times.
      window.clearTimeout(pending);
      pending = window.setTimeout(function () {
        var profile = compose.querySelector('input[name=profile]:checked');

        var query = {
          profile: profile ? profile.value : '',
          recipes: count,
          images: chosen.length
        };
        if (budgetField) { query.budget = ceiling(); }
        call('/estimate', {}, query).then(function (data) {
          compose.classList.toggle('ms-over-ceiling', data.fits === false);
          // A writer is told whether the lot fits, never what it costs.
          if (data.cost_usd === undefined) {
            say(estimate, data.fits === false ? (t.overCeilingWriter || '') : '');
            return;
          }
          var cap = ceiling() * count;
          // Two numbers, and they are different things: what this is likely to
          // cost, and the most it is allowed to cost. Showing only the first is
          // how a surprise bill happens.
          var line = (t.estimate || '')
            .replace('%1$s', money(data.cost_usd))
            .replace('%2$s', money(cap))
            .replace('%3$d', count);
          if (data.unpriced && data.unpriced.length) {
            line += ' ' + (t.unpriced || '').replace('%s', data.unpriced.join(', '));
          }
          if (data.fits === false) {
            line += ' ' + (t.overCeiling || '').replace('%1$s', money(data.per_recipe_usd)).replace('%2$s', money(data.ceiling_usd));
          }
          say(estimate, line);
        }).catch(function () {
          say(estimate, '');
        });
      }, 400);
    }

    // A writer is never shown money, so the field is simply not there for
    // them and the server applies the site's own ceiling.
    function ceiling() {
      return budgetField ? (parseFloat(budgetField.value) || 0) : 0;
    }

    recipes.addEventListener('input', refreshEstimate);
    if (budgetField) { budgetField.addEventListener('input', refreshEstimate); }
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
          budget: ceiling(),
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

  var schedule = document.getElementById('ms-schedule');
  if (schedule) {
    schedule.addEventListener('click', function () {
      schedule.disabled = true;
      say(batchStatus, t.saving || '');
      // The pairing is settled first, so a lot that leaves at three in the
      // morning leaves with what is on screen now.
      call('/batches/' + batch + '/pairs', { method: 'POST', body: JSON.stringify({ pairs: pairs() }) })
        .then(function () {
          return call('/batches/' + batch + '/schedule', {
            method: 'POST',
            body: JSON.stringify({ at: document.getElementById('ms-dispatch-at').value })
          });
        })
        .then(function () { window.location.reload(); })
        .catch(function (error) { say(batchStatus, error.message); schedule.disabled = false; });
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

  // --- Picking a stopped recipe back up ----------------------------------

  var retry = document.querySelector('.ms-retry');
  if (retry) {
    retry.addEventListener('click', function () {
      retry.disabled = true;
      say(document.getElementById('ms-run-status'), t.retrying || '');
      call('/runs/' + retry.dataset.run + '/retry', { method: 'POST' })
        .then(function () { window.location.reload(); })
        .catch(function (error) {
          say(document.getElementById('ms-run-status'), error.message);
          retry.disabled = false;
        });
    });
  }

  // --- Moving one recipe up the queue -------------------------------------

  var priority = document.querySelector('.ms-priority');
  if (priority) {
    priority.addEventListener('click', function () {
      priority.disabled = true;
      say(document.getElementById('ms-run-status'), t.applying || '');
      call('/runs/bulk', {
        method: 'POST',
        body: JSON.stringify({ do: 'prioritise', runs: [Number(priority.dataset.run)], priority: Number(priority.dataset.priority) })
      })
        .then(function () { window.location.reload(); })
        .catch(function (error) {
          say(document.getElementById('ms-run-status'), error.message);
          priority.disabled = false;
        });
    });
  }

  // --- Holding the queue --------------------------------------------------

  var queueToggle = document.getElementById('ms-queue-toggle');
  if (queueToggle) {
    queueToggle.addEventListener('click', function () {
      var held = '1' === queueToggle.dataset.held;
      queueToggle.disabled = true;
      say(document.getElementById('ms-queue-status'), held ? t.releasing : t.holding);
      call('/queue', { method: 'POST', body: JSON.stringify({ do: held ? 'release' : 'hold' }) })
        .then(function () { window.location.reload(); })
        .catch(function (error) {
          say(document.getElementById('ms-queue-status'), error.message);
          queueToggle.disabled = false;
        });
    });
  }

  // --- Throwing a lot away ------------------------------------------------

  var batchDelete = document.getElementById('ms-batch-delete');
  if (batchDelete) {
    batchDelete.addEventListener('click', function () {
      if (!window.confirm(t.confirmBatchDelete)) { return; }
      batchDelete.disabled = true;
      say(document.getElementById('ms-batch-head-status'), t.applying || '');
      call('/batches/' + batchDelete.dataset.batch, { method: 'DELETE' })
        .then(function () { window.location.href = t.passUrl; })
        .catch(function (error) {
          say(document.getElementById('ms-batch-head-status'), error.message);
          batchDelete.disabled = false;
        });
    });
  }

  // --- Whether the stored keys actually open their providers ----------------

  var checkKeys = document.getElementById('ms-check-keys');
  if (checkKeys) {
    var keysResult = document.getElementById('ms-keys-result');
    checkKeys.addEventListener('click', function () {
      checkKeys.disabled = true;
      keysResult.innerHTML = '';
      var waiting = document.createElement('li');
      waiting.textContent = t.checkingKeys || '';
      keysResult.appendChild(waiting);
      call('/keys/check', { method: 'POST' })
        .then(function (data) {
          keysResult.innerHTML = '';
          Object.keys(data).forEach(function (provider) {
            var row = document.createElement('li');
            var state = document.createElement('span');
            state.className = 'ms-state ms-state-' + ({ ok: 'good', blocked: 'stop', refused: 'stop', unreachable: 'warn', missing: 'idle' }[data[provider].state] || 'idle');
            state.textContent = data[provider].label;
            row.appendChild(state);
            row.appendChild(document.createTextNode(' ' + data[provider].message));
            keysResult.appendChild(row);
          });
        })
        .catch(function (error) { keysResult.innerHTML = ''; var row = document.createElement('li'); row.textContent = error.message; keysResult.appendChild(row); })
        .then(function () { checkKeys.disabled = false; });
    });
  }

  // --- A friendly editor for one JSON group: which model serves each step -

  var routingData = document.getElementById('ms-engine-routing-data');
  if (routingData) {
    var schema = JSON.parse(routingData.textContent);
    var routingField = document.getElementById('ms-engine-routing');
    var body = document.querySelector('.ms-routing-picker tbody');

    function parseRoute(route) {
      var parts = (route || '').split(':');
      return { provider: parts[0] || '', named: parts.slice(1).join(':') || 'medium' };
    }

    function writeRouting() {
      var value = {};
      Object.keys(schema.keys).forEach(function (key) {
        var row = body.querySelector('[data-route="' + key + '"]');
        if (!row) return;
        var model = row.querySelector('.ms-route-model');
        if (model) { value[key] = model.value; return; }
        var provider = row.querySelector('.ms-route-provider').value;
        var tier = row.querySelector('.ms-route-tier').value;
        value[key] = provider + ':' + tier;
      });
      routingField.value = JSON.stringify(value, null, 4);
    }

    function option(value, label, selected) {
      var el = document.createElement('option');
      el.value = value;
      el.textContent = label;
      if (selected) el.selected = true;
      return el;
    }

    Object.keys(schema.keys).forEach(function (key) {
      var row = document.createElement('tr');
      row.dataset.route = key;

      var labelCell = document.createElement('td');
      labelCell.textContent = schema.keys[key];
      row.appendChild(labelCell);

      var current = parseRoute(schema.current[key]);
      var controlCell = document.createElement('td');

      if ('image' === key) {
        var modelSelect = document.createElement('select');
        modelSelect.className = 'ms-route-model';
        schema.imageModels.forEach(function (entry) {
          modelSelect.appendChild(option(entry.value, entry.label, entry.value === schema.current[key]));
        });
        modelSelect.addEventListener('change', writeRouting);
        controlCell.appendChild(modelSelect);
      } else {
        var providerSelect = document.createElement('select');
        providerSelect.className = 'ms-route-provider';
        Object.keys(schema.providers).forEach(function (provider) {
          providerSelect.appendChild(option(provider, schema.providers[provider], provider === current.provider));
        });
        var tierSelect = document.createElement('select');
        tierSelect.className = 'ms-route-tier';
        schema.tiers.forEach(function (tier) {
          tierSelect.appendChild(option(tier, tier, tier === current.named));
        });
        // A route can already name a specific model rather than a tier — kept
        // selectable so switching provider and back does not silently drop it.
        if (schema.tiers.indexOf(current.named) === -1) {
          tierSelect.insertBefore(option(current.named, current.named, true), tierSelect.firstChild);
        }
        providerSelect.addEventListener('change', writeRouting);
        tierSelect.addEventListener('change', writeRouting);
        controlCell.appendChild(providerSelect);
        controlCell.appendChild(document.createTextNode(' '));
        controlCell.appendChild(tierSelect);
      }

      row.appendChild(controlCell);
      body.appendChild(row);
    });
  }

  // --- Resolving the form as it stands, without saving or spending --------

  var preview = document.getElementById('ms-engine-preview');
  if (preview) {
    var previewResult = document.getElementById('ms-engine-preview-result');
    preview.addEventListener('click', function () {
      var config = {};
      document.querySelectorAll('textarea[id^="ms-engine-"]').forEach(function (area) {
        config[area.id.replace('ms-engine-', '')] = area.value;
      });
      var languageField = document.querySelector('input[name="msrwa_engine[language]"]');
      if (languageField) { config.language = languageField.value; }

      preview.disabled = true;
      previewResult.innerHTML = '';
      call('/diagnostics/config', { method: 'POST', body: JSON.stringify({ config: config }) })
        .then(function (data) {
          var table = document.createElement('table');
          table.className = 'ms-table';
          var head = table.insertRow();
          [t.previewStep || 'Étape', t.previewRoute || 'Route', t.previewKey || 'Clé', t.previewPrice || 'Tarif'].forEach(function (title) {
            var cell = document.createElement('th');
            cell.textContent = title;
            head.appendChild(cell);
          });
          Object.keys(data.routes).forEach(function (step) {
            var route = data.routes[step];
            var row = table.insertRow();
            row.insertCell().textContent = step;
            row.insertCell().textContent = route.route.provider + ':' + route.route.model;
            row.insertCell().textContent = route.provider_known ? '✓' : '✗';
            row.insertCell().textContent = route.price_known ? '✓' : '✗';
          });
          previewResult.appendChild(table);
        })
        .catch(function (error) {
          var p = document.createElement('p');
          p.className = 'ms-muted';
          p.textContent = error.message;
          previewResult.appendChild(p);
        })
        .then(function () { preview.disabled = false; });
    });
  }

  // --- Handing the diagnostic report to somebody who can help -------------

  var copyReport = document.getElementById('ms-copy-report');
  if (copyReport) {
    var report = document.getElementById('ms-diagnostic-report');
    var copyStatus = document.getElementById('ms-copy-status');
    copyReport.addEventListener('click', function () {
      // The clipboard API needs a secure context, which a local site is not.
      // Selecting the text is the fallback that always works: the reader
      // presses their own copy shortcut and nothing is lost.
      var done = function () { say(copyStatus, t.copied || ''); };
      var select = function () { report.focus(); report.select(); say(copyStatus, t.copyManually || ''); };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(report.value).then(done).catch(select);
        return;
      }
      select();
    });
  }

  // --- Clearing what has outlived its usefulness --------------------------

  var prune = document.getElementById('ms-prune');
  if (prune) {
    prune.addEventListener('click', function () {
      prune.disabled = true;
      say(document.getElementById('ms-prune-status'), t.pruning || '');
      call('/retention', { method: 'POST' })
        .then(function () { window.location.reload(); })
        .catch(function (error) {
          say(document.getElementById('ms-prune-status'), error.message);
          prune.disabled = false;
        });
    });
  }

  // --- One decision, several recipes -------------------------------------

  var bulk = document.getElementById('ms-bulk');
  if (bulk) {
    var rail = document.getElementById('ms-bulk-rail');
    var all = document.getElementById('ms-bulk-all');
    var go = document.getElementById('ms-bulk-go');
    var bulkStatus = document.getElementById('ms-bulk-status');

    function picked() {
      return Array.prototype.filter.call(rail.querySelectorAll('.ms-pick-run'), function (box) { return box.checked; })
        .map(function (box) { return parseInt(box.value, 10); });
    }

    function countPicked() {
      var n = picked().length;
      go.disabled = 0 === n;
      say(bulkStatus, n ? (1 === n ? t.onePicked : (t.manyPicked || '').replace('%d', n)) : '');
    }

    all.addEventListener('change', function () {
      rail.querySelectorAll('.ms-pick-run').forEach(function (box) { box.checked = all.checked; });
      countPicked();
    });
    rail.addEventListener('change', function (event) {
      if (event.target.classList.contains('ms-pick-run')) { countPicked(); }
    });

    go.addEventListener('click', function () {
      var runs = picked();
      var action = document.getElementById('ms-bulk-do').value;
      if (!runs.length) return;
      // Deleting destroys the record of what was spent and cannot be undone,
      // so it is the one action that asks first.
      if ('delete' === action && !window.confirm((t.confirmDelete || '').replace('%d', runs.length))) return;

      go.disabled = true;
      say(bulkStatus, t.applying || '');
      call('/runs/bulk', { method: 'POST', body: JSON.stringify({ do: action, runs: runs }) })
        .then(function (data) {
          if (data.skipped && data.skipped.length) {
            say(bulkStatus, (t.someSkipped || '').replace('%1$d', data.done).replace('%2$d', data.skipped.length));
            window.setTimeout(function () { window.location.reload(); }, 2500);
            return;
          }
          window.location.reload();
        })
        .catch(function (error) { say(bulkStatus, error.message); go.disabled = false; });
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
