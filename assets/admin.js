(function () {
  'use strict';

  if (typeof MSRWA === 'undefined') return;

  function request(url, options) {
    options = options || {};
    options.headers = options.headers || {};
    options.headers['X-WP-Nonce'] = MSRWA.nonce;
    return fetch(MSRWA.api + url, options).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) throw new Error(data.message || 'Une erreur est survenue.');
        return data;
      });
    });
  }

  function setMessage(message, text, isError) {
    if (!message) return;
    message.textContent = text;
    message.classList.toggle('is-error', !!isError);
    message.classList.toggle('is-success', !isError && !!text);
  }

  var form = document.getElementById('msrwa-create-form');
  if (form) {
    var recipe = document.getElementById('msrwa-recipe');
    var imageUrls = document.getElementById('msrwa-image-urls');
    var files = document.getElementById('msrwa-reference-files');
    var fileHelp = document.getElementById('msrwa-files-help');
    var submit = document.getElementById('msrwa-create');
    var message = document.getElementById('msrwa-message');

    if (recipe && !document.getElementById('msrwa-batch-help')) {
      var batchHelp = document.createElement('span');
      batchHelp.id = 'msrwa-batch-help';
      batchHelp.className = 'description';
      batchHelp.textContent = 'Pour créer plusieurs recettes dans un lot, séparez-les par une ligne --- . Les références sont ensuite associées automatiquement et les ambiguïtés sont signalées.';
      recipe.parentNode.appendChild(batchHelp);
    }

    function selectedFilesMessage() {
      if (!files || !fileHelp) return;
      var count = files.files ? files.files.length : 0;
      fileHelp.textContent = count ? count + ' image' + (count > 1 ? 's sélectionnées.' : ' sélectionnée.') : fileHelp.getAttribute('data-default');
    }

    if (fileHelp) fileHelp.setAttribute('data-default', fileHelp.textContent);
    if (files) files.addEventListener('change', selectedFilesMessage);

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var text = (recipe.value || '').trim();
      if (!text) {
        setMessage(message, 'Ajoutez le texte ou la recette à traiter.', true);
        recipe.focus();
        return;
      }

      var selected = files && files.files ? Array.prototype.slice.call(files.files) : [];
      var maximum = parseInt(form.getAttribute('data-max-reference-images'), 10) || 10;
      var urls = (imageUrls.value || '').split(/\r?\n/).map(function (url) { return url.trim(); }).filter(Boolean);
      if (selected.length + urls.length > maximum) {
        setMessage(message, 'Ajoutez au plus ' + maximum + ' images de référence.', true);
        return;
      }
      for (var index = 0; index < selected.length; index += 1) {
        if (!/^image\/(jpeg|png|webp|gif)$/i.test(selected[index].type) || selected[index].size > 10485760) {
          setMessage(message, 'Chaque fichier doit être une image JPEG, PNG, WebP ou GIF de 10 Mo maximum.', true);
          return;
        }
      }

      var recipes = text.split(/\r?\n\s*---+\s*(?:\r?\n|$)/).map(function (entry) { return entry.trim(); }).filter(Boolean);
      if (!recipes.length) {
        setMessage(message, 'Ajoutez au moins une recette valide.', true);
        return;
      }
      var data = new FormData();
      data.append('items', JSON.stringify(recipes.map(function (entry) {
        var title = entry.split(/\r?\n/).map(function (line) { return line.trim(); }).filter(Boolean)[0] || 'Recette à rédiger';
        return { title: title.slice(0, 160), text: entry, images: urls };
      })));
      selected.forEach(function (file) { data.append('reference_files[]', file, file.name); });

      submit.disabled = true;
      setMessage(message, 'Création du lot et vérification des références…', false);
      request('/batches', { method: 'POST', body: data })
        .then(function (result) {
          var suffix = result.reference_upload_errors && result.reference_upload_errors.length ? ' Certaines images ont été refusées : ' + result.reference_upload_errors.map(function (error) { return error.message; }).join(' ') : '';
          setMessage(message, 'Lot #' + result.id + ' créé avec ' + (result.total || recipes.length) + ' recette(s). Les étapes restantes se poursuivent en arrière-plan.' + suffix, !!suffix);
          if (!suffix) {
            recipe.value = '';
            imageUrls.value = '';
            files.value = '';
            selectedFilesMessage();
          }
        })
        .catch(function (error) { setMessage(message, error.message, true); })
        .finally(function () { submit.disabled = false; });
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll('.msrwa-test-provider'), function (button) {
    button.addEventListener('click', function () {
      var provider = button.getAttribute('data-provider');
      var status = document.getElementById('msrwa-' + provider + '-status');
      button.disabled = true;
      setMessage(status, 'Test en cours…', false);
      request(provider === 'openai' ? '/test/openai' : '/test/' + provider, { method: 'POST' })
        .then(function (result) {
          var usage = result.usage || {};
          var tokens = usage.total_tokens || usage.input_tokens;
          setMessage(status, provider + ' opérationnel — ' + result.model + (tokens ? ' — ' + tokens + ' tokens' : ''), false);
        })
        .catch(function (error) { setMessage(status, error.message, true); })
        .finally(function () { button.disabled = false; });
    });
  });

  Array.prototype.forEach.call(document.querySelectorAll('.msrwa-batch-action'), function (button) {
    button.addEventListener('click', function () {
      var batchId = button.getAttribute('data-batch-id');
      var action = button.getAttribute('data-action');
      if (!batchId || !action) return;
      if (action === 'cancel' && !window.confirm('Annuler ce lot ? Les appels déjà acceptés par un fournisseur peuvent rester facturés.')) return;
      button.disabled = true;
      request('/batches/' + encodeURIComponent(batchId) + '/' + encodeURIComponent(action), { method: 'POST' })
        .then(function () { window.location.reload(); })
        .catch(function (error) { window.alert(error.message); })
        .finally(function () { button.disabled = false; });
    });
  });

  Array.prototype.forEach.call(document.querySelectorAll('.msrwa-job-action'), function (button) {
    button.addEventListener('click', function () {
      var jobId = button.getAttribute('data-job-id');
      var action = button.getAttribute('data-action');
      var card = button.closest('.msrwa-job-card');
      var status = card ? card.querySelector('.msrwa-job-action-status') : null;
      if (!jobId || !action) return;
      if (action === 'cancel' && !window.confirm('Annuler ce job ? Les appels déjà acceptés par un fournisseur peuvent rester facturés.')) return;
      if (action === 'association' && !window.confirm('Confirmer cette association et reprendre le job ?')) return;
      button.disabled = true;
      setMessage(status, 'Action en cours…', false);
      var requestOptions = { method: 'POST' };
      if (action === 'association') {
        requestOptions.headers = { 'Content-Type': 'application/json' };
        requestOptions.body = JSON.stringify({ confirmed: true });
      }
      request('/jobs/' + encodeURIComponent(jobId) + '/' + encodeURIComponent(action), requestOptions)
        .then(function (result) {
          var labels = { retry: 'Relance planifiée.', cancel: 'Job annulé.', association: 'Association confirmée ; reprise planifiée.' };
          setMessage(status, labels[action] || 'Action enregistrée.', false);
          window.location.reload();
        })
        .catch(function (error) { setMessage(status, error.message, true); })
        .finally(function () { button.disabled = false; });
    });
  });

  var firstProviderTest = document.querySelector('.msrwa-test-provider');
  if (firstProviderTest) {
    var syncWrap = document.createElement('p');
    syncWrap.className = 'msrwa-catalog-sync';
    syncWrap.innerHTML = '<label>Catalogue <select><option value="openai">OpenAI</option><option value="gemini">Gemini</option></select></label> <button type="button" class="button">Synchroniser le catalogue</button> <span role="status"></span>';
    firstProviderTest.closest('p').parentNode.appendChild(syncWrap);
    var select = syncWrap.querySelector('select');
    var syncButton = syncWrap.querySelector('button');
    var syncStatus = syncWrap.querySelector('span');
    syncButton.addEventListener('click', function () {
      syncButton.disabled = true;
      setMessage(syncStatus, 'Synchronisation…', false);
      request('/catalog/sync?provider=' + encodeURIComponent(select.value), { method: 'POST' })
        .then(function (result) { setMessage(syncStatus, select.value + ' : ' + result.count + ' modèles vérifiés.', false); })
        .catch(function (error) { setMessage(syncStatus, error.message, true); })
        .finally(function () { syncButton.disabled = false; });
    });
  }
}());
