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
    // WordPress answers the REST API in the site's language unless asked for
    // the reader's: a row redrawn live read "published" on a French screen.
    params = Object.assign({ _locale: 'user' }, params || {});
    var query = Object.keys(params).map(function (key) {
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

  // Where the money goes: each segment says what it is and how much, on hover
  // or keyboard focus. Nothing is lost without it: the table below has it all.
  (function () {
    var bars = document.querySelectorAll('.ms-split-bar [data-tip], .ms-columns [data-tip]');
    if (!bars.length) return;
    var tip = document.createElement('div');
    tip.className = 'ms-split-tip';
    tip.hidden = true;
    (bars[0].closest('.msrwa') || document.body).appendChild(tip);
    function show(event) {
      var cell = event.currentTarget;
      tip.textContent = '';
      var title = document.createElement('b');
      title.textContent = cell.getAttribute('data-tip-title') || '';
      tip.appendChild(title);
      tip.appendChild(document.createTextNode(cell.getAttribute('data-tip') || ''));
      tip.hidden = false;
      var box = cell.getBoundingClientRect();
      var x = event.clientX || box.left + box.width / 2;
      tip.style.left = Math.max(8, Math.min(window.innerWidth - tip.offsetWidth - 8, x - tip.offsetWidth / 2)) + 'px';
      tip.style.top = Math.max(8, box.top - tip.offsetHeight - 8) + 'px';
    }
    function hide() { tip.hidden = true; }
    Array.prototype.forEach.call(bars, function (cell) {
      cell.addEventListener('mousemove', show);
      cell.addEventListener('focus', show);
      cell.addEventListener('mouseleave', hide);
      cell.addEventListener('blur', hide);
    });
  })();
  // As MSRWA_I18N::money() writes it, so a painted amount matches a printed one.
  function money(value, decimals) {
    var format = MSRWA.money || {};
    var parts = Number(value || 0).toFixed(undefined === decimals ? 4 : decimals).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, format.thousands || ',');
    return (format.pattern || '%s $').replace('%s', parts.join(format.decimal || '.'));
  }

  /**
   * How many recipes the text probably holds, for the estimate only:
   * MSRWA_Intake's first guess, mirrored. The lot's reading decides them
   * from the brief once it is sent.
   */
  function countRecipes(text) {
    text = text.replace(/\r\n/g, '\n').trim();
    if (!text) { return 0; }
    var ruled = /^\s*-{3,}\s*$/m.test(text);
    var blocks = ruled ? text.split(/^\s*-{3,}\s*$/m) : text.split(/\n\s*\n/);
    var count = 0;
    blocks.forEach(function (block) {
      var lines = block.split('\n').map(function (line) { return line.trim(); }).filter(Boolean);
      if (!lines.length) { return; }
      var names = !ruled && lines.length > 2 && lines.every(function (line) {
        line = line.replace(/^(?:#{1,6}|\d+[.)]|[-*•])\s*/, '');
        return line.length <= 60 && !/[,;:]|\d/.test(line);
      });
      count += names ? lines.length : 1;
    });
    return count;
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
    var drop = document.getElementById('ms-drop');
    var clear = null;

    var pending = null;

    function refreshEstimate() {
      var typed = countRecipes(recipes.value);
      // The brief decides the recipes once it is read; until then the estimate
      // holds a guess, and at least one recipe per photograph. Without text,
      // each dish the photographs show becomes a recipe.
      var count = Math.max(typed, chosen.length);
      say(recipeCount, typed ? (t.fromBrief || '') : (chosen.length ? (t.fromPhotos || '').replace('%d', chosen.length) : ''));
      if (!count) { say(estimate, ''); return; }

      // The figure comes from the server, because it is worked out from the
      // routing and rates actually configured rather than from anything this
      // script could know. Debounced: somebody typing a long recipe should not
      // ask a hundred times.
      window.clearTimeout(pending);
      pending = window.setTimeout(function () {
        var query = {
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
          // What this is likely to cost. What the lot produces and its ceiling
          // are the site's settings, not this form's.
          var line = (t.estimate || '')
            .replace('%1$s', money(data.cost_usd))
            .replace('%2$d', count);
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

    recipes.addEventListener('input', refreshEstimate);

    function megabytes(bytes) { return String(Math.floor(bytes / 1000000)); }

    // Checked here so a writer hears about a wrong file before waiting on an
    // upload; the server checks the same things again from the bytes.
    function photoProblem(files) {
      var types = ['image/jpeg', 'image/png', 'image/webp'];
      var total = 0;
      if (files.length > (t.photoCount || 30)) { return (t.photoMany || '').replace('%d', t.photoCount); }
      for (var i = 0; i < files.length; i++) {
        if (files[i].msrwaUrl) { if (fileProblem(files[i])) { return fileProblem(files[i]); } continue; }
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
      if (file.msrwaUrl) { return /^https:\/\//i.test(file.msrwaUrl) ? '' : (t.linkNotHttps || ''); }
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
        var number = document.createElement('span');
        number.className = 'ms-photo-number';
        number.textContent = String(index + 1);
        tile.appendChild(number);
        var meta = document.createElement('span');
        if (!problem) {
          var img = document.createElement('img');
          img.alt = '';
          if (file.msrwaUrl) {
            // Shown straight from its site; the site itself fetches it on sending.
            img.referrerPolicy = 'no-referrer';
            img.addEventListener('error', function () { tile.classList.add('ms-photo-unseen'); meta.textContent = t.linkUnseen || ''; });
            img.src = file.msrwaUrl;
          } else {
            img.src = URL.createObjectURL(file);
            previews.push(img.src);
          }
          tile.appendChild(img);
        }
        var name = document.createElement('span');
        name.className = 'ms-photo-name';
        name.textContent = file.msrwaPasted ? (t.pastedName || '%d').replace('%d', file.msrwaPasted) : file.name;
        name.title = file.name;
        tile.appendChild(name);
        meta.className = 'ms-photo-meta';
        meta.textContent = problem || (file.msrwaUrl ? (t.linkTag || '') + ' · ' + hostOf(file.msrwaUrl) : '') || (file.msrwaPasted ? (t.pastedTag || '') + ' · ' : '') + sizeLabel(file.size);
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
      if (drop) { drop.classList.toggle('has-photos', chosen.length > 0); }
      if (clear) { clear.hidden = chosen.length < 2; }
      var problem = photoProblem(chosen);
      say(imageCount, problem || (chosen.length === 0 ? (t.noImage || '') : chosen.length === 1 ? t.oneImage : (t.manyImages || '').replace('%d', chosen.length)));
      refreshEstimate();
    }

    function addPhotos(list) {
      Array.prototype.forEach.call(list || [], function (file) {
        var known = chosen.some(function (other) {
          if (file.msrwaUrl || other.msrwaUrl) { return file.msrwaUrl === other.msrwaUrl; }
          return other.name === file.name && other.size === file.size && other.lastModified === file.lastModified;
        });
        if (!known) { chosen.push(file); }
      });
      renderPhotos();
    }

    photos.addEventListener('change', function () {
      addPhotos(photos.files);
      // Emptied so picking the same file again after removing it still fires.
      photos.value = '';
    });

    clear = document.getElementById('ms-photo-clear');
    if (clear) { clear.addEventListener('click', function () { chosen = []; renderPhotos(); }); }

    // A pasted image has no name and, from a screen capture, is often a PNG
    // larger than the server takes. It is named, and re-encoded as a JPEG when
    // it is too heavy, so a paste never fails where a file pick would pass.
    var pasted = 0;
    function fromClipboard(blob) {
      pasted++;
      var stamp = new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '');
      var number = pasted;
      var name = 'pasted-' + stamp + '-' + number;
      var extension = { 'image/jpeg': '.jpg', 'image/png': '.png', 'image/webp': '.webp' }[blob.type];
      if (extension && (!t.photoBytes || blob.size <= t.photoBytes)) {
        return Promise.resolve(tagPasted(new File([blob], name + extension, { type: blob.type }), number));
      }
      return new Promise(function (resolve) {
        var image = new Image();
        var url = URL.createObjectURL(blob);
        image.onload = function () {
          URL.revokeObjectURL(url);
          // Smaller and softer in steps until it fits: a photograph fits at the
          // first, a busy screen capture may need the last.
          var steps = [[2560, 0.9], [2048, 0.82], [1600, 0.75], [1280, 0.7]];
          (function attempt(index) {
            var side = steps[index][0];
            var scale = Math.min(1, side / Math.max(image.naturalWidth, image.naturalHeight));
            var canvas = document.createElement('canvas');
            canvas.width = Math.round(image.naturalWidth * scale);
            canvas.height = Math.round(image.naturalHeight * scale);
            var context = canvas.getContext('2d');
            context.fillStyle = '#fff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.drawImage(image, 0, 0, canvas.width, canvas.height);
            canvas.toBlob(function (jpeg) {
              if (jpeg && t.photoBytes && jpeg.size > t.photoBytes && index + 1 < steps.length) { attempt(index + 1); return; }
              resolve(tagPasted(new File([jpeg || blob], name + '.jpg', { type: jpeg ? 'image/jpeg' : blob.type }), number));
            }, 'image/jpeg', steps[index][1]);
          })(0);
        };
        image.onerror = function () { URL.revokeObjectURL(url); resolve(tagPasted(new File([blob], name, { type: blob.type }), number)); };
        image.src = url;
      });
    }
    // An image given by its address: kept as the address, fetched by the site
    // when the lot is sent. Only what reads as one or more addresses counts.
    function hostOf(url) { try { return new URL(url).hostname; } catch (e) { return url; } }
    function links(text) {
      var words = String(text || '').trim().split(/\s+/).filter(Boolean);
      if (!words.length || !words.every(function (word) { return /^https?:\/\/\S+$/i.test(word); })) { return []; }
      return words;
    }
    function addLinks(urls) {
      addPhotos(urls.map(function (url) {
        var path = url.split(/[?#]/)[0];
        return { msrwaUrl: url, name: decodeURIComponent(path.substring(path.lastIndexOf('/') + 1)) || hostOf(url), size: 0, type: '' };
      }));
    }
    function tagPasted(file, number) { file.msrwaPasted = number; return file; }
    function addPasted(blobs) {
      return Promise.all(blobs.map(fromClipboard)).then(addPhotos);
    }

    document.addEventListener('paste', function (event) {
      var data = event.clipboardData;
      if (!data) { return; }
      var images = Array.prototype.filter.call(data.files || [], function (file) { return /^image\//.test(file.type); });
      var field = event.target && /^(TEXTAREA|INPUT)$/.test(event.target.tagName);
      if (!images.length) {
        // An address pasted outside a field is an image to add; in the
        // recipes, it is text like any other.
        var found = field ? [] : links(data.getData('text/plain'));
        if (found.length) { event.preventDefault(); addLinks(found); }
        return;
      }
      // Word and Excel put a picture of the copied text beside the text itself:
      // pasted into a field, the text is what was meant.
      if (field && (data.getData('text/plain') || '').trim()) { return; }
      event.preventDefault();
      addPasted(images);
    });

    if (drop) {
      ['dragenter', 'dragover'].forEach(function (type) {
        drop.addEventListener(type, function (event) { event.preventDefault(); drop.classList.add('is-over'); });
      });
      ['dragleave', 'dragend', 'drop'].forEach(function (type) {
        drop.addEventListener(type, function () { drop.classList.remove('is-over'); });
      });
      drop.addEventListener('drop', function (event) {
        event.preventDefault();
        var data = event.dataTransfer;
        if (!data) { return; }
        if (data.files && data.files.length) { addPhotos(data.files); return; }
        // An image dragged from another tab arrives as its address.
        var html = data.getData('text/html') || '';
        var source = /<img[^>]+src="(https?:[^"]+)"/i.exec(html);
        var found = source ? [source[1].replace(/&amp;/g, '&')] : links(data.getData('text/uri-list') || data.getData('text/plain'));
        if (found.length) { addLinks(found); }
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
      // Files first, numbered in the order the server reads them; addresses after.
      var sent = 0;
      chosen.forEach(function (file) {
        if (file.msrwaUrl) { return; }
        form.append('photos[]', file, file.name);
        if (file.msrwaPasted) { form.append('pasted[]', String(sent)); }
        sent++;
      });
      chosen.forEach(function (file) { if (file.msrwaUrl) { form.append('urls[]', file.msrwaUrl); } });
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
  // A poll that fails must not end the page: see refresh() below.
  var retry = null, misses = 0;

  // A recipe index, "aside", or nothing yet: a photograph whose new recipe
  // has no name is still undecided.
  function pairs(creating) {
    return Array.prototype.map.call(document.querySelectorAll('.ms-pair-choice'), function (select) {
      var image = parseInt(select.dataset.image, 10);
      var pair;
      if (creating && creating.image === image) { pair = { image: image, recipe: 'new', title: creating.title }; }
      else if ('aside' === select.value) { pair = { image: image, recipe: 'aside' }; }
      else if ('' === select.value || 'new' === select.value) { pair = { image: image, recipe: null }; }
      else { pair = { image: image, recipe: parseInt(select.value, 10) }; }
      // A collage the writer made: kept as the recipe's Facebook image, or not.
      var collage = document.querySelector('.ms-pair-collage-use[data-image="' + image + '"]');
      if (collage) { pair.collage = collage.checked; }
      return pair;
    });
  }

  // Every change is saved at once: a pairing somebody corrected and then
  // walked away from used to be lost, with a button nobody had pressed.
  var pairTimer = null;
  function savePairs(creating) {
    return call('/batches/' + batch + '/pairs', { method: 'POST', body: JSON.stringify({ pairs: pairs(creating) }) });
  }

  // The recipe cards follow the choices: which photographs each will carry,
  // and whether it is written from them or from references on the web.
  function refreshDishes() {
    var dishes = document.querySelectorAll('.ms-dish');
    var loose = 0;
    var byRecipe = {};
    document.querySelectorAll('.ms-pair-choice').forEach(function (select) {
      if ('' === select.value || 'new' === select.value) { loose++; return; }
      if ('aside' === select.value) { return; }
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
      var dropped = !photos.length && dish.dataset.fromPhotographs === '1';
      dish.classList.toggle('has-photos', photos.length > 0);
      dish.classList.toggle('is-dropped', dropped);
      dish.querySelector('.ms-dish-with').textContent = photos.length
        ? (photos.length === 1 ? t.dishOnePhoto : (t.dishPhotos || '').replace('%d', photos.length))
        : (dropped ? (t.dishDropped || '') : (t.dishNoPhoto || ''));
    });
    var launch = document.getElementById('ms-dispatch');
    if (launch && launch.dataset.many) {
      var written = document.querySelectorAll('.ms-dish:not(.is-dropped)').length;
      launch.textContent = written === 1 ? launch.dataset.one : launch.dataset.many.replace('%d', written);
    }
    var note = document.getElementById('ms-loose');
    if (note) {
      note.hidden = !loose;
      note.textContent = loose === 1 ? note.dataset.one : (note.dataset.many || '').replace('%d', loose);
    }
    // Nothing leaves while a photograph waits for a decision, and the reason
    // is said beside the buttons it disables, not only at the top of the list.
    ['ms-dispatch', 'ms-schedule'].forEach(function (id) {
      var button = document.getElementById(id);
      if (button) { button.disabled = loose > 0; }
    });
    var status = document.getElementById('ms-batch-status');
    if (status && note) {
      if (loose) { status.textContent = note.textContent; status.dataset.pending = '1'; }
      else if (status.dataset.pending) { status.textContent = ''; delete status.dataset.pending; }
    }
  }

  document.querySelectorAll('.ms-pair-collage-use').forEach(function (box) {
    box.addEventListener('change', function () {
      say(batchStatus, t.saving || '');
      savePairs().then(function () { say(batchStatus, t.saved || ''); }).catch(function (error) { say(batchStatus, error.message); });
    });
  });

  document.querySelectorAll('.ms-pair-choice').forEach(function (select) {
    var row = select.closest('.ms-pair');
    var naming = row && row.querySelector('.ms-pair-new');
    if (naming) {
      var create = function () {
        var title = naming.querySelector('.ms-pair-title').value.trim();
        if (!title) { naming.querySelector('.ms-pair-title').focus(); return; }
        window.clearTimeout(pairTimer);
        say(batchStatus, t.saving || '');
        // A new recipe changes the list every select offers: the page is
        // drawn again from what was saved.
        savePairs({ image: parseInt(select.dataset.image, 10), title: title })
          .then(function () { window.location.reload(); })
          .catch(function (error) { say(batchStatus, error.message); });
      };
      naming.querySelector('.ms-pair-create').addEventListener('click', create);
      naming.querySelector('.ms-pair-title').addEventListener('keydown', function (event) { if ('Enter' === event.key) { event.preventDefault(); create(); } });
    }
    select.addEventListener('change', function () {
      if (naming) {
        naming.hidden = 'new' !== select.value;
        if ('new' === select.value) { naming.querySelector('.ms-pair-title').focus(); refreshDishes(); return; }
      }
      // The writer's choice replaces the model's confidence on screen.
      var badge = row && row.querySelector('.ms-pair-badge');
      if (row && badge) {
        var aside = 'aside' === select.value;
        var tone = '' === select.value ? 'stop' : (aside ? 'idle' : 'good');
        row.className = row.className.replace(/\bms-pair-(good|warn|stop|idle)\b/, 'ms-pair-' + tone);
        badge.className = badge.className.replace(/\bms-state-(good|warn|stop|idle)\b/, 'ms-state-' + tone);
        badge.textContent = aside ? (t.pairAside || '') : (t.pairChosen || '');
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

  // Correcting the recipes themselves: a title or a text, one removed, one
  // added. Each change is saved at once and the page drawn again, since every
  // "goes with" list names the recipes.
  function editRecipe(body) {
    say(batchStatus, t.saving || '');
    window.clearTimeout(pairTimer);
    return savePairs()
      .then(function () { return call('/batches/' + batch + '/recipes', { method: 'POST', body: JSON.stringify(body) }); })
      .then(function () { window.location.reload(); })
      .catch(function (error) { say(batchStatus, error.message); throw error; });
  }
  function toggleEditor(button, open) {
    var editor = document.getElementById(button.getAttribute('aria-controls'));
    if (!editor) { return; }
    editor.hidden = !open;
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { editor.querySelector('.ms-edit-title').focus(); }
  }
  document.querySelectorAll('.ms-recipe-edit, #ms-recipe-add').forEach(function (button) {
    button.addEventListener('click', function () { toggleEditor(button, button.getAttribute('aria-expanded') !== 'true'); });
  });
  document.querySelectorAll('.ms-recipe-editor').forEach(function (editor) {
    var key = editor.dataset.recipe;
    var opener = document.querySelector('[aria-controls="' + (editor.closest('[id^="ms-recipe-editor-"]') || {}).id + '"]');
    var title = editor.querySelector('.ms-edit-title');
    var save = editor.querySelector('.ms-edit-save');
    function submit() {
      if (!title.value.trim()) { title.focus(); say(batchStatus, t.recipeNeedsName || ''); return; }
      save.disabled = true;
      editRecipe({
        action: 'new' === key ? 'add' : 'edit',
        index: 'new' === key ? 0 : parseInt(key, 10),
        title: title.value.trim(),
        text: editor.querySelector('.ms-edit-text').value
      }).catch(function () { save.disabled = false; });
    }
    save.addEventListener('click', submit);
    title.addEventListener('keydown', function (event) { if ('Enter' === event.key) { event.preventDefault(); submit(); } });
    editor.querySelector('.ms-edit-cancel').addEventListener('click', function () { if (opener) { toggleEditor(opener, false); opener.focus(); } });
  });
  document.querySelectorAll('.ms-recipe-remove').forEach(function (button) {
    button.addEventListener('click', function () {
      var row = button.closest('.ms-dish');
      var name = row ? row.querySelector('.ms-dish-title').textContent : '';
      if (!window.confirm((t.recipeRemove || '').replace('%s', name))) { return; }
      button.disabled = true;
      editRecipe({ action: 'remove', index: parseInt(button.dataset.recipe, 10) }).catch(function () { button.disabled = false; });
    });
  });

  // The page opens in the state it was saved in, undecided photographs included.
  if (document.querySelector('.ms-pair-choice')) { refreshDishes(); }

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

  // --- Redrawing a refused image ------------------------------------------

  // A redraw takes half a minute and is paid for: every button is disabled
  // until it answers, so a second click cannot start a second one.
  var redraw = document.querySelector('.ms-redraw');
  if (redraw) {
    var buttons = redraw.querySelectorAll('button');
    Array.prototype.forEach.call(buttons, function (button) {
      button.addEventListener('click', function () {
        Array.prototype.forEach.call(buttons, function (other) { other.disabled = true; });
        say(redraw.querySelector('span'), redraw.dataset.busy || '');
        call('/runs/' + redraw.dataset.run + '/redraw', { method: 'POST', body: JSON.stringify({ kind: button.value }) })
          .then(function () {
            var url = new URL(window.location.href);
            url.searchParams.set('redrawn', '1');
            window.location.href = url.toString();
          })
          .catch(function (error) {
            say(redraw.querySelector('span'), error.message);
            Array.prototype.forEach.call(buttons, function (other) { other.disabled = false; });
          });
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

  // Starting over: each form asks once more, and the one that erases the
  // data opens only once its word is typed.
  document.querySelectorAll('.ms-reset-option[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm(form.getAttribute('data-confirm'))) { event.preventDefault(); }
    });
  });
  var resetWord = document.getElementById('ms-reset-word');
  var resetAll = document.getElementById('ms-reset-all');
  if (resetWord && resetAll) {
    resetWord.addEventListener('input', function () {
      resetAll.disabled = resetWord.value.trim().toUpperCase() !== String(resetWord.getAttribute('data-word')).toUpperCase();
    });
  }

  // Shows what is being typed, for checking a pasted key; the stored key is
  // never sent back, so there is nothing else to show.
  document.querySelectorAll('.ms-keycard-toggle').forEach(function (toggle) {
    var input = document.getElementById(toggle.getAttribute('aria-controls'));
    if (!input) { return; }
    toggle.addEventListener('click', function () {
      var shown = input.type === 'password';
      input.type = shown ? 'text' : 'password';
      toggle.setAttribute('aria-pressed', shown ? 'true' : 'false');
      toggle.querySelector('.dashicons').className = 'dashicons dashicons-' + (shown ? 'hidden' : 'visibility');
    });
  });

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
            var tone = { ok: 'good', blocked: 'stop', refused: 'stop', unreachable: 'warn', missing: 'idle' }[data[provider].state] || 'idle';
            // Each verdict on its own provider's card; a list only for one without.
            var card = document.querySelector('.ms-keycard[data-provider="' + provider + '"]');
            if (card) {
              card.setAttribute('data-check', tone);
              var slot = card.querySelector('.ms-keycard-result');
              slot.innerHTML = '';
              var badge = document.createElement('span');
              badge.className = 'ms-state ms-state-' + tone;
              badge.textContent = data[provider].message;
              slot.appendChild(badge);
              return;
            }
            var row = document.createElement('li');
            var state = document.createElement('span');
            state.className = 'ms-state ms-state-' + tone;
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
  // Every `var` here shares the whole script's scope: this list was `rail`
  // until the live-rail block below took the same name and set it to null on
  // this page, and no checkbox could be ticked.

  var bulk = document.getElementById('ms-bulk');
  if (bulk) {
    var bulkRail = document.getElementById('ms-bulk-rail');
    var all = document.getElementById('ms-bulk-all');
    var go = document.getElementById('ms-bulk-go');
    var bulkStatus = document.getElementById('ms-bulk-status');

    function picked() {
      return Array.prototype.filter.call(bulkRail.querySelectorAll('.ms-pick-run'), function (box) { return box.checked; })
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
      bulkRail.querySelectorAll('.ms-pick-run').forEach(function (box) { box.checked = all.checked; });
      countPicked();
    });
    bulkRail.addEventListener('change', function (event) {
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
      // Publishing puts articles in front of readers: said, and asked, first.
      if ('publish' === action && !window.confirm((t.confirmPublish || '').replace('%d', runs.length))) return;

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

  // --- The Articles screen, live ------------------------------------------
  //
  // Running above, done below. Every few seconds /runs/live answers with each
  // run still moving and each one this page showed as moving that has since
  // settled, drawn by the same PHP that drew the page: a row is swapped only
  // when it changed, a finished one moves down, one started elsewhere
  // appears. It rests while the tab is hidden and catches up when it returns.

  var articles = document.querySelector('[data-live-articles]');
  if (articles) {
    var runningRail = document.getElementById('ms-running-rail');
    var doneRail = document.getElementById('ms-done-rail');
    var liveFilters = {};
    try { liveFilters = JSON.parse(articles.getAttribute('data-live-articles') || '{}') || {}; } catch (e) { liveFilters = {}; }
    var firstPage = '1' === articles.getAttribute('data-page');
    var liveTimer = null;
    var liveBusy = false;

    var rowOf = function (id) { return articles.querySelector('tr[data-run="' + id + '"]'); };
    var build = function (item) {
      var holder = document.createElement('tbody');
      holder.innerHTML = item.html;
      var row = holder.querySelector('tr');
      if (row) row.setAttribute('data-sig', item.sig);
      return row;
    };
    // A box somebody ticked stays ticked when its row is redrawn.
    var keepPick = function (from, to) {
      var was = from && from.querySelector('.ms-pick-run');
      var now = to.querySelector('.ms-pick-run');
      if (was && now) now.checked = was.checked;
    };
    var arrive = function (row) {
      row.classList.add('ms-run-arrived');
      window.setTimeout(function () { row.classList.remove('ms-run-arrived'); }, 2400);
    };
    var showIf = function (tbody) {
      var section = tbody.closest('section');
      var has = !!tbody.querySelector('tr');
      section.querySelector('.ms-articles-table').hidden = !has;
      section.querySelector('.ms-articles-empty').hidden = has;
    };
    var setCount = function (key, value) {
      var node = articles.querySelector('[data-count="' + key + '"]');
      if (node && undefined !== value) node.textContent = Number(value).toLocaleString(document.documentElement.lang || undefined);
    };

    var liveApply = function (data) {
      var keep = {};
      (data.moving || []).forEach(function (item, index) {
        keep[item.id] = true;
        var old = rowOf(item.id);
        if (old && old.parentNode === runningRail && old.getAttribute('data-sig') === item.sig) return;
        var row = build(item);
        if (!row) return;
        keepPick(old, row);
        if (old && old.parentNode === runningRail) { runningRail.replaceChild(row, old); return; }
        if (old) old.parentNode.removeChild(old);
        // A newcomer takes its place in the server's order, newest first.
        runningRail.insertBefore(row, runningRail.children[index] || null);
        arrive(row);
      });
      (data.settled || []).forEach(function (item) {
        keep[item.id] = true;
        var old = rowOf(item.id);
        var row = build(item);
        if (!row) return;
        keepPick(old, row);
        if (old && old.parentNode === runningRail) runningRail.removeChild(old);
        // Past the first page, the list below starts elsewhere: the finished
        // recipe is counted there rather than dropped into the middle of it.
        if (firstPage && !rowOf(item.id)) { doneRail.insertBefore(row, doneRail.firstChild); arrive(row); }
      });
      // Gone from both answers: deleted, or no longer matching the filter.
      Array.prototype.slice.call(runningRail.querySelectorAll('tr[data-run]')).forEach(function (row) {
        if (!keep[row.getAttribute('data-run')]) runningRail.removeChild(row);
      });
      showIf(runningRail);
      showIf(doneRail);
      if (data.totals) { setCount('moving', data.totals.moving); setCount('settled', data.totals.settled); }
      if (data.post_counts) {
        var sum = 0;
        Object.keys(data.post_counts).forEach(function (bucket) {
          sum += Number(data.post_counts[bucket]) || 0;
          var tab = articles.ownerDocument.querySelector('.ms-post-tab-' + bucket + ' .ms-count');
          if (tab) tab.textContent = Number(data.post_counts[bucket]).toLocaleString(document.documentElement.lang || undefined);
        });
        var all = document.querySelector('.ms-post-tab-all .ms-count');
        if (all) all.textContent = sum.toLocaleString(document.documentElement.lang || undefined);
      }
    };

    var liveSchedule = function () {
      window.clearTimeout(liveTimer);
      if (document.hidden) return;
      // Quick while something runs, slow while it only waits for a new lot.
      liveTimer = window.setTimeout(liveRefresh, runningRail.querySelector('tr[data-run]') ? 4000 : 15000);
    };
    var liveRefresh = function () {
      if (liveBusy) return;
      liveBusy = true;
      var shown = Array.prototype.map.call(runningRail.querySelectorAll('tr[data-run]'), function (row) { return row.getAttribute('data-run'); });
      var params = Object.assign({ shown: shown.join(',') }, liveFilters);
      call('/runs/live', { method: 'GET' }, params)
        .then(liveApply)
        .catch(function () {})
        .then(function () { liveBusy = false; liveSchedule(); });
    };

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { window.clearTimeout(liveTimer); } else { liveRefresh(); }
    });
    liveRefresh();
  }

  // --- Keeping a rail honest ---------------------------------------------

  var rail = document.querySelector('[data-batch] .ms-rail') || document.querySelector('#ms-live-rail .ms-rail');
  if (!rail || !batch) return;

  function paint(run) {
    var ticket = rail.querySelector('[data-run="' + run.id + '"]');
    if (!ticket) { window.location.reload(); return true; }

    // A field the answer does not carry is left as the page drew it.
    var set = function (field, value) {
      var node = ticket.querySelector('[data-field="' + field + '"]');
      if (node && value !== undefined && String(value).indexOf('undefined') === -1) node.textContent = value;
    };
    set('steps', run.steps_done + '/' + run.steps_total);
    set('cost', money(run.cost_usd));
    set('step', 'running' === run.status ? run.step_label : '');

    var bar = ticket.querySelector('.ms-progress i');
    if (bar) bar.style.inlineSize = Math.min(100, Math.round(100 * run.steps_done / Math.max(1, run.steps_total))) + '%';

    return 'queued' === run.status || 'running' === run.status;
  }

  // WordPress cron fires only when it can call the site back. Where it cannot —
  // cron disabled with no server cron, a page cache, blocked loopbacks — the
  // lot waited forever; while this page is open, it carries the lot on itself,
  // one wave at a time.
  // Up to three at once, as the site's own worker runs them: each call takes
  // the next overdue recipe, and a recipe already taken is not taken twice.
  var nudging = 0;
  function nudge(overdue) {
    while (nudging < Math.min(3, overdue)) {
      nudging++;
      call('/batches/' + batch + '/nudge', { method: 'POST' })
        .catch(function () {})
        .then(function () { nudging--; });
    }
  }

  // The lot's summary follows its recipes: how many finished, how many run.
  var summary = document.querySelector('.ms-lot-summary');
  function paintLot(runs) {
    if (!summary) return;
    var tally = { done: 0, running: 0, queued: 0, failed: 0, cancelled: 0 };
    runs.forEach(function (run) { if (run.status in tally) tally[run.status]++; });
    var total = Number(summary.getAttribute('data-lot-total')) || runs.length;
    var settled = tally.done + tally.failed + tally.cancelled;
    var lang = document.documentElement.lang || undefined;
    Object.keys(tally).forEach(function (key) {
      var node = summary.querySelector('[data-lot="' + key + '"]');
      if (!node) return;
      node.textContent = tally[key].toLocaleString(lang);
      node.parentNode.hidden = !tally[key];
    });
    var count = summary.querySelector('[data-lot="settled"]');
    if (count) count.textContent = settled.toLocaleString(lang);
    var bar = summary.querySelector('[data-lot="bar"]');
    if (bar) {
      bar.firstElementChild.style.inlineSize = Math.min(100, Math.round(100 * settled / Math.max(1, total))) + '%';
      bar.classList.toggle('ms-progress-done', settled >= total);
    }
  }

  function refresh() {
    window.clearTimeout(retry);
    retry = null;
    return call('/batches/' + batch + '/runs').then(function (data) {
      if (misses) { misses = 0; say(batchStatus, ''); }
      var runs = data.runs || [];
      if (data.stalled) nudge(Number(data.stalled));
      paintLot(runs);
      var moving = runs.map(paint).some(Boolean);
      if (moving && !timer) { timer = window.setInterval(refresh, 5000); }
      // A settled batch has drafts that were not there when the page loaded,
      // so it is reloaded once rather than leaving stale links behind.
      if (!moving && timer) { window.clearInterval(timer); timer = null; window.location.reload(); }
    }).catch(function () {
      // One blip used to stop the page for good: the interval was cleared and
      // nothing wound it again, so the lot sat there looking frozen — and on a
      // site whose cron cannot call back, this page is what carries it. Back
      // off and come back, and only give up after about a minute of failures.
      if (timer) { window.clearInterval(timer); timer = null; }
      misses++;
      if (misses <= 6) { retry = window.setTimeout(refresh, Math.min(20000, 2000 * misses)); return; }
      // Out of tries: say so rather than leaving a page that looks alive.
      say(batchStatus, t.lostTouch || t.failed || '');
    });
  }

  refresh();
}());
