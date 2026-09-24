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
    var chosen = [];
    var previews = [];

    var pending = null;

    function refreshEstimate() {
      var typed = countRecipes(recipes.value);
      // Without text, each dish the photographs show becomes a recipe: at most
      // one per photograph, which is the figure the estimate has to hold.
      var count = typed || chosen.length;
      say(recipeCount, typed
        ? (typed === 1 ? t.oneRecipe : (t.manyRecipes || '').replace('%d', typed))
        : (chosen.length ? (t.fromPhotos || '').replace('%d', chosen.length) : ''));
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
      return t.ceilingUsd || 0;
    }

    recipes.addEventListener('input', refreshEstimate);
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

    // A tile per photograph, added to across several picks and drops: the
    // list is the writer's to build and prune, not replaced by each pick.
    function fileProblem(file) {
      if (['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type) === -1) { return (t.photoType || '').replace('%s', file.name); }
      if (t.photoBytes && file.size > t.photoBytes) { return (t.photoTooBig || '').replace('%1$s', file.name).replace('%2$s', megabytes(t.photoBytes)); }
      return '';
    }

    function sizeLabel(bytes) {
      return bytes >= 1000000 ? (bytes / 1000000).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1000)) + ' kB';
    }

    function renderPhotos() {
      previews.forEach(function (url) { URL.revokeObjectURL(url); });
      previews = [];
      thumbs.innerHTML = '';
      chosen.forEach(function (file, index) {
        var problem = fileProblem(file);
        var tile = document.createElement('li');
        tile.className = 'ms-photo' + (problem ? ' ms-photo-bad' : '');
        if (!problem) {
          var img = document.createElement('img');
          img.src = URL.createObjectURL(file);
          img.alt = '';
          previews.push(img.src);
          tile.appendChild(img);
        }
        var name = document.createElement('span');
        name.className = 'ms-photo-name';
        name.textContent = file.name;
        name.title = file.name;
        tile.appendChild(name);
        var meta = document.createElement('span');
        meta.className = 'ms-photo-meta';
        meta.textContent = problem || sizeLabel(file.size);
        tile.appendChild(meta);
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'ms-photo-remove';
        remove.setAttribute('aria-label', (t.removePhoto || '%s').replace('%s', file.name));
        remove.textContent = '×';
        remove.addEventListener('click', function () { chosen.splice(index, 1); renderPhotos(); });
        tile.appendChild(remove);
        thumbs.appendChild(tile);
      });
      var problem = photoProblem(chosen);
      say(imageCount, problem || (chosen.length === 0 ? (t.noImage || '') : chosen.length === 1 ? t.oneImage : (t.manyImages || '').replace('%d', chosen.length)));
      refreshEstimate();
    }

    function addPhotos(list) {
      Array.prototype.forEach.call(list || [], function (file) {
        var known = chosen.some(function (other) { return other.name === file.name && other.size === file.size && other.lastModified === file.lastModified; });
        if (!known) { chosen.push(file); }
      });
      renderPhotos();
    }

    photos.addEventListener('change', function () {
      addPhotos(photos.files);
      // Emptied so picking the same file again after removing it still fires.
      photos.value = '';
    });

    var drop = document.getElementById('ms-drop');
    if (drop) {
      ['dragenter', 'dragover'].forEach(function (type) {
        drop.addEventListener(type, function (event) { event.preventDefault(); drop.classList.add('is-over'); });
      });
      ['dragleave', 'dragend', 'drop'].forEach(function (type) {
        drop.addEventListener(type, function () { drop.classList.remove('is-over'); });
      });
      drop.addEventListener('drop', function (event) {
        event.preventDefault();
        if (event.dataTransfer) { addPhotos(event.dataTransfer.files); }
      });
    }

    compose.addEventListener('submit', function (event) {
      event.preventDefault();
      var button = document.getElementById('ms-submit');
      if (!countRecipes(recipes.value) && !chosen.length) { say(status, t.noRecipes || ''); return; }

      button.disabled = true;
      // The photographs are described here, one call each. It is the slow part
      // of submitting and the only part that spends before a run exists, so it
      // says so rather than sitting on a spinner.
      var problem = photoProblem(chosen);
      if (problem) { say(status, problem); button.disabled = false; return; }
      var form = new FormData();
      form.append('recipes', recipes.value);
      form.append('profile', (compose.querySelector('input[name=profile]:checked') || {}).value || '');
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

  // Every change is saved at once: a pairing somebody corrected and then
  // walked away from used to be lost, with a button nobody had pressed.
  var pairTimer = null;
  function savePairs() {
    return call('/batches/' + batch + '/pairs', { method: 'POST', body: JSON.stringify({ pairs: pairs() }) });
  }

  // The recipe cards follow the choices: which photographs each will carry,
  // and whether it is written from them or from references on the web.
  function refreshDishes() {
    var dishes = document.querySelectorAll('.ms-dish');
    var loose = 0;
    var byRecipe = {};
    document.querySelectorAll('.ms-pair-choice').forEach(function (select) {
      if ('' === select.value) { loose++; return; }
      (byRecipe[select.value] = byRecipe[select.value] || []).push(select.dataset.url || '');
    });
    dishes.forEach(function (dish) {
      var photos = byRecipe[dish.dataset.recipe] || [];
      var thumbs = dish.querySelector('.ms-dish-thumbs');
      thumbs.innerHTML = '';
      photos.forEach(function (url) {
        if (!url) return;
        var img = document.createElement('img');
        img.src = url; img.alt = ''; img.loading = 'lazy';
        thumbs.appendChild(img);
      });
      dish.classList.toggle('has-photos', photos.length > 0);
      dish.querySelector('.ms-dish-with').textContent = photos.length
        ? (photos.length === 1 ? t.dishOnePhoto : (t.dishPhotos || '').replace('%d', photos.length))
        : (t.dishNoPhoto || '');
    });
    var note = document.getElementById('ms-loose');
    if (note) {
      note.hidden = !loose;
      note.textContent = loose === 1 ? note.dataset.one : (note.dataset.many || '').replace('%d', loose);
    }
  }

  document.querySelectorAll('.ms-pair-choice').forEach(function (select) {
    select.addEventListener('change', function () {
      // The writer's choice replaces the model's confidence on screen.
      var row = select.closest('.ms-pair');
      var badge = row && row.querySelector('.ms-pair-badge');
      if (row && badge) {
        var tone = '' === select.value ? 'idle' : 'good';
        row.className = row.className.replace(/\bms-pair-(good|warn|stop|idle)\b/, 'ms-pair-' + tone);
        badge.className = badge.className.replace(/\bms-state-(good|warn|stop|idle)\b/, 'ms-state-' + tone);
        badge.textContent = '' === select.value ? (t.pairAside || '') : (t.pairChosen || '');
        var reason = row.querySelector('.ms-pair-reason');
        if (reason) { reason.remove(); }
      }
      refreshDishes();
      window.clearTimeout(pairTimer);
      say(batchStatus, t.saving || '');
      pairTimer = window.setTimeout(function () {
        savePairs()
          .then(function () { say(batchStatus, t.pairsSaved || t.saved || ''); })
          .catch(function (error) { say(batchStatus, error.message); });
      }, 300);
    });
  });

  var schedule = document.getElementById('ms-schedule');
  if (schedule) {
    schedule.addEventListener('click', function () {
      schedule.disabled = true;
      say(batchStatus, t.saving || '');
      // The pairing is settled first, so a lot that leaves at three in the
      // morning leaves with what is on screen now.
      savePairs()
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
      window.clearTimeout(pairTimer);
      savePairs()
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

    function writeRouting() {
      var value = {};
      Object.keys(schema.keys).forEach(function (key) {
        var row = body.querySelector('[data-route="' + key + '"]');
        if (!row) return;
        value[key] = row.querySelector('.ms-route-tier').value;
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

    // Options that resolve to a model unable to serve the step stay visible,
    // greyed and named as such, so the reason for the absence is on screen.
    // labelOf names an option afresh: a level's model changes with the provider.
    function markBlocked(select, key, routeOf, labelOf) {
      var blocked = (schema.blocked || {})[key] || {};
      Array.prototype.forEach.call(select.options, function (opt) {
        if (labelOf) { opt.dataset.label = labelOf(opt.value); }
        if (!opt.dataset.label) { opt.dataset.label = opt.textContent; }
        var why = blocked[routeOf(opt.value)];
        opt.disabled = !!why && !opt.selected;
        opt.textContent = why ? (schema.labels.blockedOption || '%s').replace('%s', opt.dataset.label) : opt.dataset.label;
        opt.title = why || '';
      });
    }

    function option(value, label, selected) {
      var el = document.createElement('option');
      el.value = value;
      el.textContent = label;
      if (selected) el.selected = true;
      return el;
    }

    function small(tone, text) {
      var el = document.createElement('small');
      el.className = 'ms-muted' + (tone ? ' ms-' + tone : '');
      el.textContent = text;
      return el;
    }

    // A visible name over each control: on a phone the column headers are gone,
    // and two selects reading "medium" one above the other were the confusion.
    function field(label, control) {
      var wrap = document.createElement('label');
      wrap.className = 'ms-route-field';
      var name = document.createElement('span');
      name.className = 'ms-route-field-name';
      name.textContent = label;
      wrap.appendChild(name);
      wrap.appendChild(control);
      return wrap;
    }

    Object.keys(schema.keys).forEach(function (key) {
      var row = document.createElement('tr');
      row.dataset.route = key;

      var labelCell = document.createElement('th');
      labelCell.scope = 'row';
      labelCell.textContent = schema.keys[key];
      row.appendChild(labelCell);

      var modelCell = document.createElement('td');
      modelCell.dataset.label = schema.labels.model;
      var controls = document.createElement('div');
      controls.className = 'ms-route-controls';
      modelCell.appendChild(controls);

      var isImage = 'featured_image' === key || 'facebook_image' === key;
      // One path for every step, text or image: a provider, then the models
      // the Modèles screen keeps for this step on that provider. A level's
      // pick is offered as the level, named by its model.
      var offered = (schema.choices || {})[key] || {};
      var currentRoute = schema.current[key] || '';
      var currentProvider = currentRoute.split(':')[0];
      var providerSelect = document.createElement('select');
      providerSelect.className = 'ms-route-provider';
      Object.keys(schema.providers).forEach(function (provider) {
        if (!offered[provider] && provider !== currentProvider) return;
        providerSelect.appendChild(option(provider, schema.providers[provider], provider === currentProvider));
      });
      var tierSelect = document.createElement('select');
      tierSelect.className = 'ms-route-tier';
      var modelLabel = function (value) {
        var info = schema.resolved[value];
        var name = info ? (info.label || info.id) : value.split(':').slice(1).join(':');
        var choice = (offered[value.split(':')[0]] || []).filter(function (c) { return c.value === value; })[0];
        var tier = choice && choice.tier ? (schema.tierNames || {})[choice.tier] : '';
        return tier ? name + ' · ' + tier : name;
      };
      function fillModels(keepValue) {
        tierSelect.innerHTML = '';
        var list = offered[providerSelect.value] || [];
        list.forEach(function (choice) { tierSelect.appendChild(option(choice.value, '', choice.value === keepValue)); });
        // A route naming something no longer offered stays visible, flagged.
        if (keepValue && keepValue.split(':')[0] === providerSelect.value && !list.some(function (c) { return c.value === keepValue; })) {
          tierSelect.insertBefore(option(keepValue, '', true), tierSelect.firstChild);
        }
        markBlocked(tierSelect, key, function (value) { return value; }, modelLabel);
        if (tierSelect.selectedOptions[0] && tierSelect.selectedOptions[0].disabled || !tierSelect.value) {
          var usable = Array.prototype.find.call(tierSelect.options, function (opt) { return !opt.disabled; });
          if (usable) { tierSelect.value = usable.value; markBlocked(tierSelect, key, function (value) { return value; }, modelLabel); }
        }
      }
      fillModels(currentRoute);
      providerSelect.addEventListener('change', function () { fillModels(''); });
      tierSelect.addEventListener('change', function () { markBlocked(tierSelect, key, function (value) { return value; }, modelLabel); });
      providerSelect.addEventListener('change', writeRouting);
      tierSelect.addEventListener('change', writeRouting);
      controls.appendChild(field(schema.labels.provider, providerSelect));
      controls.appendChild(field(schema.labels.model, tierSelect));

      // Which model this actually is, what it costs, and whether it can run:
      // that is the thing that is billed and that can fail to exist.
      var info = document.createElement('div');
      info.className = 'ms-route-info';
      modelCell.appendChild(info);
      row.appendChild(modelCell);

      function describe() {
        var route = row.querySelector('.ms-route-tier').value;
        var resolved = schema.resolved[route];
        info.textContent = '';
        if (!resolved) { info.appendChild(small('', schema.labels.unknown)); return; }
        var name = document.createElement('code');
        name.className = 'ms-key';
        name.textContent = resolved.id;
        info.appendChild(name);
        if (resolved.priced) {
          info.appendChild(small('', schema.labels.perMillion
            .replace('%1$s', resolved.input.toFixed(2))
            .replace('%2$s', resolved.output.toFixed(2))));
        } else {
          info.appendChild(small('warn', schema.labels.unpriced));
        }
        if (resolved.served === 'no') info.appendChild(small('stop', schema.labels.unserved));
        var why = ((schema.blocked || {})[key] || {})[route];
        if (why) info.appendChild(small('stop', why));
      }

      Array.prototype.forEach.call(controls.querySelectorAll('select'), function (select) {
        select.addEventListener('change', describe);
      });
      describe();

      // An image model does not think: its effort is its quality, one per image,
      // written into the `images` group. Every other route may be told how hard to think.
      var effortCell = document.createElement('td');
      if (isImage) {
        var qualitySelect = document.createElement('select');
        qualitySelect.className = 'ms-route-quality';
        var quality = (schema.imageQuality || {})[key] || 'medium';
        (schema.qualities || []).forEach(function (name) {
          qualitySelect.appendChild(option(name, (schema.qualityNames || {})[name] || name, name === quality));
        });
        qualitySelect.addEventListener('change', function () { writeImageQuality(key, qualitySelect.value); });
        effortCell.dataset.label = schema.labels.quality;
        effortCell.appendChild(field(schema.labels.quality, qualitySelect));
      } else {
        var thinkingSelect = document.createElement('select');
        thinkingSelect.className = 'ms-route-thinking';
        var level = (schema.thinking || {})[key] || '';
        var siteLevel = (schema.thinking || {})['default'];
        var names = schema.thinkingNames || {};
        thinkingSelect.appendChild(option('', siteLevel
          ? schema.labels.thinkingSite.replace('%s', names[siteLevel] || siteLevel)
          : schema.labels.thinkingDefault, '' === level));
        schema.thinkingLevels.forEach(function (name) { thinkingSelect.appendChild(option(name, names[name] || name, name === level)); });
        thinkingSelect.addEventListener('change', function () { writeThinking(key, thinkingSelect.value); });
        effortCell.dataset.label = schema.labels.thinking;
        effortCell.appendChild(field(schema.labels.thinking, thinkingSelect));
      }
      row.appendChild(effortCell);

      body.appendChild(row);
    });

    // --- Quality presets: one choice for every step, "custom" once edited ---
    var presetRow = document.querySelector('#ms-presets .ms-preset-row');
    var presets = schema.presets || {};
    function rowState(key) {
      var row = body.querySelector('[data-route="' + key + '"]');
      if (!row) return null;
      var thinking = row.querySelector('.ms-route-thinking');
      var quality = row.querySelector('.ms-route-quality');
      return {
        route: row.querySelector('.ms-route-tier').value,
        thinking: thinking ? thinking.value : null,
        quality: quality ? quality.value : null
      };
    }
    function matches(preset) {
      return Object.keys(schema.keys).every(function (key) {
        var now = rowState(key);
        if (!now || now.route !== (preset.routing || {})[key]) return false;
        if (now.quality !== null) return now.quality === (preset.quality || {})[key];
        return now.thinking === ((preset.thinking || {})[key] || '');
      });
    }
    function setSelect(select, value) {
      if (!select || select.value === value) return;
      select.value = value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function applyPreset(name) {
      var preset = presets[name];
      Object.keys(schema.keys).forEach(function (key) {
        var row = body.querySelector('[data-route="' + key + '"]');
        var route = (preset.routing || {})[key];
        if (!row || !route) return;
        setSelect(row.querySelector('.ms-route-provider'), route.split(':')[0]);
        setSelect(row.querySelector('.ms-route-tier'), route);
        if (row.querySelector('.ms-route-quality')) { setSelect(row.querySelector('.ms-route-quality'), (preset.quality || {})[key] || 'medium'); }
        else { setSelect(row.querySelector('.ms-route-thinking'), (preset.thinking || {})[key] || ''); }
      });
      refreshPresets();
    }
    var presetButtons = {};
    Object.keys(presets).forEach(function (name) {
      var preset = presets[name];
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'ms-preset';
      button.dataset.preset = name;
      var title = document.createElement('strong');
      title.textContent = preset.label;
      button.appendChild(title);
      if (preset.cost) {
        var cost = document.createElement('span');
        cost.className = 'ms-preset-cost';
        cost.textContent = (schema.labels.perRecipe || '%s').replace('%s', preset.cost);
        button.appendChild(cost);
      }
      var note = document.createElement('small');
      note.textContent = preset.note;
      button.appendChild(note);
      if (preset.over) {
        var over = document.createElement('small');
        over.className = 'ms-warn';
        over.textContent = schema.labels.overCeiling || '';
        button.appendChild(over);
      }
      button.addEventListener('click', function () { applyPreset(name); });
      presetRow.appendChild(button);
      presetButtons[name] = button;
    });
    var custom = document.createElement('span');
    custom.className = 'ms-preset ms-preset-custom';
    custom.setAttribute('aria-live', 'polite');
    var customTitle = document.createElement('strong');
    customTitle.textContent = schema.labels.custom || '';
    custom.appendChild(customTitle);
    if (presetRow) presetRow.appendChild(custom);
    function refreshPresets() {
      var active = '';
      Object.keys(presets).forEach(function (name) { if (!active && matches(presets[name])) active = name; });
      Object.keys(presetButtons).forEach(function (name) {
        presetButtons[name].setAttribute('aria-pressed', name === active ? 'true' : 'false');
      });
      custom.classList.toggle('is-active', '' === active);
    }
    body.addEventListener('change', refreshPresets);
    refreshPresets();
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
