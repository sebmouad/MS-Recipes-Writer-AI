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
    var photos = document.getElementById('ms-photos');
    var thumbs = document.getElementById('ms-thumbs');
    var imageCount = document.getElementById('ms-image-count');
    var estimate = document.getElementById('ms-estimate');
    var status = document.getElementById('ms-compose-status');
    var budgetField = document.getElementById('ms-budget');
    var chosen = [];
    var previews = [];

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
          } else if (data.per_recipe_max_usd > data.per_recipe_usd) {
            // The final approval can refuse and have the images redrawn; a real
            // recipe refused twice cost a third more than one pass.
            line += ' ' + (t.retryMax || '').replace('%s', money(data.per_recipe_max_usd));
            if (data.ceiling_usd > 0 && data.per_recipe_max_usd > data.ceiling_usd) { line += ' ' + (t.retryOverCeiling || ''); }
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

    function megabytes(bytes) { return String(Math.floor(bytes / 1000000)); }

    // Checked here so a writer hears about a wrong file before waiting on an
    // upload; the server checks the same things again from the bytes.
    function photoProblem(files) {
      var types = ['image/jpeg', 'image/png', 'image/webp'];
      var total = 0;
      if (files.length > (t.photoCount || 30)) { return (t.photoMany || '').replace('%d', t.photoCount); }
      for (var i = 0; i < files.length; i++) {
        total += files[i].size;
        if (types.indexOf(files[i].type) === -1) { return (t.photoType || '').replace('%s', files[i].name); }
        if (t.photoBytes && files[i].size > t.photoBytes) { return (t.photoTooBig || '').replace('%1$s', files[i].name).replace('%2$s', megabytes(t.photoBytes)); }
      }
      if (t.postBytes && total > t.postBytes * 0.95) { return (t.photoTotal || '').replace('%s', megabytes(t.postBytes)); }
      return '';
    }

    photos.addEventListener('change', function () {
      previews.forEach(function (url) { URL.revokeObjectURL(url); });
      previews = [];
      chosen = Array.prototype.slice.call(photos.files || []);
      thumbs.innerHTML = '';
      chosen.forEach(function (file) {
        var img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        img.alt = file.name;
        previews.push(img.src);
        thumbs.appendChild(img);
      });
      var problem = photoProblem(chosen);
      say(imageCount, problem || (chosen.length === 0 ? (t.noImage || '') : chosen.length === 1 ? t.oneImage : (t.manyImages || '').replace('%d', chosen.length)));
      refreshEstimate();
    });

    compose.addEventListener('submit', function (event) {
      event.preventDefault();
      var button = document.getElementById('ms-submit');
      if (!countRecipes(recipes.value)) { say(status, t.noRecipes || ''); return; }

      button.disabled = true;
      // The photographs are described here, one call each. It is the slow part
      // of submitting and the only part that spends before a run exists, so it
      // says so rather than sitting on a spinner.
      var problem = photoProblem(chosen);
      if (problem) { say(status, problem); button.disabled = false; return; }
      var form = new FormData();
      form.append('recipes', recipes.value);
      form.append('budget', String(ceiling()));
      form.append('profile', (compose.querySelector('input[name=profile]:checked') || {}).value || '');
      form.append('language', document.getElementById('ms-language').value);
      chosen.forEach(function (file) { form.append('photos[]', file, file.name); });
      say(status, chosen.length ? (t.uploading || '') + ' ' + (t.describing || '') : '');
      // No Content-Type of our own: the browser writes the multipart boundary.
      fetch(endpoint('/batches'), { method: 'POST', headers: { 'X-WP-Nonce': MSRWA.nonce }, body: form }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok) throw new Error(data.message || t.failed || 'Erreur.');
          return data;
        });
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

    // The thinking level lives in its own group; the picker edits one key of
    // it and leaves every other key somebody typed there alone.
    var thinkingField = document.getElementById('ms-engine-thinking');
    function writeThinking(key, level) {
      if (!thinkingField) return;
      var value;
      try { value = JSON.parse(thinkingField.value || '{}') || {}; } catch (error) { return; }
      if (level) value[key] = level; else delete value[key];
      thinkingField.value = JSON.stringify(value, null, 4);
    }

    var imagesField = document.getElementById('ms-engine-images');
    function writeImageQuality(key, quality) {
      if (!imagesField) return;
      var value;
      try { value = JSON.parse(imagesField.value || '{}') || {}; } catch (error) { return; }
      value[('featured_image' === key ? 'featured' : 'facebook') + '_quality'] = quality;
      imagesField.value = JSON.stringify(value, null, 4);
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

      var isImage = 'featured_image' === key || 'facebook_image' === key;
      if (isImage) {
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

      // Which model this actually is. A provider and a quality name are not an
      // answer: the tier map turns them into an identifier, and that is the
      // thing that runs, costs money, and can fail to exist.
      var resolvedCell = document.createElement('td');
      resolvedCell.className = 'ms-route-resolved';
      row.appendChild(resolvedCell);

      function describe() {
        var route = (function () {
          var model = row.querySelector('.ms-route-model');
          if (model) return model.value;
          return row.querySelector('.ms-route-provider').value + ':' + row.querySelector('.ms-route-tier').value;
        })();
        var info = schema.resolved[route];
        resolvedCell.textContent = '';
        if (!info) {
          resolvedCell.appendChild(muted(schema.labels.unknown));
          return;
        }
        var name = document.createElement('code');
        name.className = 'ms-key';
        name.textContent = info.id;
        resolvedCell.appendChild(name);

        if (info.priced) {
          resolvedCell.appendChild(muted(schema.labels.perMillion
            .replace('%1$s', info.input.toFixed(2))
            .replace('%2$s', info.output.toFixed(2))));
        } else {
          resolvedCell.appendChild(flag('warn', schema.labels.unpriced));
        }
        if (info.served === 'no') resolvedCell.appendChild(flag('stop', schema.labels.unserved));
      }

      function muted(text) {
        var el = document.createElement('small');
        el.className = 'ms-muted';
        el.style.display = 'block';
        el.textContent = text;
        return el;
      }

      function flag(tone, text) {
        var el = document.createElement('small');
        el.className = 'ms-muted ms-' + tone;
        el.style.display = 'block';
        el.textContent = text;
        return el;
      }

      Array.prototype.forEach.call(controlCell.querySelectorAll('select'), function (select) {
        select.addEventListener('change', describe);
      });
      describe();

      // An image model does not think: its effort is its quality, one per image,
      // written into the `images` group. Every other route may be told how hard to think.
      var thinkingCell = document.createElement('td');
      if (isImage) {
        var qualitySelect = document.createElement('select');
        qualitySelect.className = 'ms-route-quality';
        var current = (schema.imageQuality || {})[key] || 'medium';
        (schema.qualities || []).forEach(function (name) { qualitySelect.appendChild(option(name, name, name === current)); });
        qualitySelect.addEventListener('change', function () { writeImageQuality(key, qualitySelect.value); });
        thinkingCell.appendChild(qualitySelect);
      } else {
        var thinkingSelect = document.createElement('select');
        thinkingSelect.className = 'ms-route-thinking';
        var level = (schema.thinking || {})[key] || '';
        thinkingSelect.appendChild(option('', schema.labels.thinkingDefault + ((schema.thinking || {})['default'] ? ' (' + schema.thinking['default'] + ')' : ''), '' === level));
        schema.thinkingLevels.forEach(function (name) { thinkingSelect.appendChild(option(name, name, name === level)); });
        thinkingSelect.addEventListener('change', function () { writeThinking(key, thinkingSelect.value); });
        thinkingCell.appendChild(thinkingSelect);
      }
      row.appendChild(thinkingCell);

      body.appendChild(row);
    });
  }

  // --- The catalogue: two buttons, two very different kinds of answer -----

  var catalogResult = document.getElementById('ms-catalog-result');

  function catalogSay(html, tone) {
    if (!catalogResult) return;
    catalogResult.innerHTML = '';
    var p = document.createElement('p');
    if (tone) p.className = 'ms-' + tone;
    p.innerHTML = html;
    catalogResult.appendChild(p);
  }

  // What a provider or a model wrote is text, never markup.
  function esc(value) {
    var span = document.createElement('span');
    span.textContent = String(value);
    return span.innerHTML;
  }

  function priceLines(data) {
    if (data.error) return [esc(data.error)];
    if (!data.asked) return [esc(MSRWA.text.nothingToPrice)];
    var lines = [esc(MSRWA.text.pricesFound.replace('%1$d', data.found).replace('%2$d', data.asked))];
    Object.keys(data.results || {}).forEach(function (key) {
      var row = data.results[key];
      if (row.state === 'found') lines.push(esc(key + ' : $' + row.input + ' / $' + row.output));
      else lines.push(esc(key + ' : ' + row.why));
    });
    lines.push('<em>' + esc(MSRWA.text.pricesAreIndicative) + '</em>');
    return lines;
  }

  function catalogRun(button, route, working, done) {
    if (!button) return;
    button.addEventListener('click', function () {
      button.disabled = true;
      catalogSay(esc(working));
      call(route, { method: 'POST' })
        .then(function (data) { return done(data); })
        .catch(function (error) { catalogSay(esc(error && error.message ? error.message : error), 'stop'); })
        .then(function () { button.disabled = false; });
    });
  }

  // A fetch adds models nobody has priced, and a model with no rate stops
  // every estimate that routes to it. So the lookup follows the fetch at once,
  // for those models only; when every served model already has a rate it
  // asks nothing and costs nothing.
  catalogRun(
    document.getElementById('ms-fetch-models'),
    '/catalog/models',
    MSRWA.text.askingProviders,
    function (data) {
      var listed = Object.keys(data).map(function (provider) {
        var row = data[provider];
        return esc(row.label + ' : ' + (row.models ? MSRWA.text.modelsListed.replace('%d', row.models) : row.message));
      });
      catalogSay(listed.join('<br>') + '<br>' + esc(MSRWA.text.readingPrices));
      return call('/catalog/prices', { method: 'POST' }).then(function (prices) {
        catalogSay(listed.concat(priceLines(prices)).join('<br>') + '<br><em>' + esc(MSRWA.text.reloadToSee) + '</em>');
      }, function (error) {
        catalogSay(listed.join('<br>') + '<br>' + esc(error && error.message ? error.message : error), 'stop');
      });
    }
  );

  catalogRun(
    document.getElementById('ms-fetch-prices'),
    '/catalog/prices',
    MSRWA.text.readingPrices,
    function (data) { catalogSay(priceLines(data).join('<br>')); }
  );

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
          [t.previewStep || 'Étape', t.previewRoute || 'Route', t.previewThinking || 'Réflexion', t.previewKey || 'Clé', t.previewPrice || 'Tarif', t.previewCost || 'Coût'].forEach(function (title) {
            var cell = document.createElement('th');
            cell.textContent = title;
            head.appendChild(cell);
          });
          var money = function (value) { return '$' + Number(value).toFixed(4); };
          Object.keys(data.routes).forEach(function (step) {
            var route = data.routes[step];
            var row = table.insertRow();
            row.insertCell().textContent = step;
            row.insertCell().textContent = route.route.provider + ':' + route.route.model;
            row.insertCell().textContent = route.thinking || '—';
            row.insertCell().textContent = route.has_key ? '✓' : '✗';
            row.insertCell().textContent = route.price_known ? '✓' : '✗';
            var cost = row.insertCell();
            cost.className = 'ms-num';
            cost.textContent = null === route.cost_usd || undefined === route.cost_usd ? '—' : money(route.cost_usd);
          });
          previewResult.appendChild(table);
          var total = document.createElement('p');
          total.textContent = (t.previewTotal || '%s').replace('%s', money(data.cost_usd || 0));
          if (data.max_usd > data.cost_usd) { total.textContent += ' ' + (t.retryMax || '').replace('%s', money(data.max_usd)); }
          if (data.unpriced && data.unpriced.length) {
            total.className = 'ms-warn';
            total.textContent += ' ' + (t.previewUnpriced || '') + ' ' + data.unpriced.join(', ');
          }
          previewResult.appendChild(total);
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

    var bulkDo = document.getElementById('ms-bulk-do');

    // Nothing is preselected: an action that stops or deletes recipes is
    // chosen, never inherited from the first line of a list.
    function countPicked() {
      var n = picked().length;
      go.disabled = 0 === n || '' === bulkDo.value;
      say(bulkStatus, n ? (1 === n ? t.onePicked : (t.manyPicked || '').replace('%d', n)) : '');
    }

    all.addEventListener('change', function () {
      rail.querySelectorAll('.ms-pick-run').forEach(function (box) { box.checked = all.checked; });
      countPicked();
    });
    rail.addEventListener('change', function (event) {
      if (event.target.classList.contains('ms-pick-run')) { countPicked(); }
    });
    bulkDo.addEventListener('change', countPicked);

    go.addEventListener('click', function () {
      var runs = picked();
      var action = bulkDo.value;
      if (!runs.length || !action) return;
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
