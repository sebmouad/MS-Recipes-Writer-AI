(function () {
  'use strict';
  var button = document.getElementById('msrwa-create');
  var testButtons = document.querySelectorAll('.msrwa-test-provider');
  if (typeof MSRWA === 'undefined') return;
  if (button) button.addEventListener('click', function () {
    var lines = (document.getElementById('msrwa-titles').value || '').split(/\r?\n/).map(function (v) { return v.trim(); }).filter(Boolean);
    var recipes = (document.getElementById('msrwa-recipes') ? document.getElementById('msrwa-recipes').value : '').split(/\r?\n/);
    var images = (document.getElementById('msrwa-images') ? document.getElementById('msrwa-images').value : '').split(/\r?\n/);
    var message = document.getElementById('msrwa-message');
    if (!lines.length) { message.textContent = 'Ajoutez au moins un titre.'; return; }
    button.disabled = true;
    fetch(MSRWA.api + '/batches', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': MSRWA.nonce }, body: JSON.stringify({ items: lines.map(function (title, index) { return { title: title, text: (recipes[index] || '').trim(), images: (images[index] || '').trim() ? (images[index] || '').trim().split(/[\s,]+/).filter(Boolean) : [] }; }) }) })
      .then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || 'Erreur'); return data; }); })
      .then(function (data) { message.textContent = 'Lot #' + data.id + ' créé. Il attend la validation du budget de test.'; document.getElementById('msrwa-titles').value = ''; if (document.getElementById('msrwa-recipes')) document.getElementById('msrwa-recipes').value = ''; if (document.getElementById('msrwa-images')) document.getElementById('msrwa-images').value = ''; })
      .catch(function (error) { message.textContent = error.message; })
      .finally(function () { button.disabled = false; });
  });
  Array.prototype.forEach.call(testButtons, function (testButton) { testButton.addEventListener('click', function () {
    var provider = testButton.getAttribute('data-provider');
    var status = document.getElementById('msrwa-' + provider + '-status');
    testButton.disabled = true;
    status.textContent = 'Test en cours…';
    fetch(MSRWA.api + (provider === 'openai' ? '/test/openai' : '/test/' + provider), { method: 'POST', headers: { 'X-WP-Nonce': MSRWA.nonce } })
      .then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || 'Échec du test'); return data; }); })
      .then(function (data) { status.textContent = provider + ' OK — ' + data.model + ' — ' + (data.usage && (data.usage.total_tokens || data.usage.input_tokens) ? (data.usage.total_tokens || data.usage.input_tokens) + ' tokens' : 'usage indisponible'); })
      .catch(function (error) { status.textContent = error.message; })
      .finally(function () { testButton.disabled = false; });
  }); });
}());
