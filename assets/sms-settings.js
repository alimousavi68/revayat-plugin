(function () {
	'use strict';
	var root = document.querySelector('.revayat-sms-settings');
	if (!root || typeof revayatSMSTools === 'undefined') return;
	function activate(key) {
		if (!root.querySelector('[data-sms-panel="' + key + '"]')) key = 'common';
		root.querySelectorAll('[data-sms-panel]').forEach(function (panel) { panel.hidden = panel.dataset.smsPanel !== key; });
		root.querySelectorAll('[data-sms-tab]').forEach(function (tab) {
			var active = tab.dataset.smsTab === key;
			tab.classList.toggle('nav-tab-active', active);
			if (active) tab.setAttribute('aria-current', 'page'); else tab.removeAttribute('aria-current');
		});
	}
	root.querySelectorAll('[data-sms-tab]').forEach(function (tab) {
		tab.addEventListener('click', function (event) { event.preventDefault(); activate(tab.dataset.smsTab); });
	});
	activate('common');
	var result = root.querySelector('#rv-sms-tool-result');
	root.querySelectorAll('[data-sms-tool]').forEach(function (button) {
		button.addEventListener('click', async function () {
			var provider = root.querySelector('#rv-sms-test-provider').value;
			var mode = button.dataset.smsTool;
			var consent = root.querySelector('#rv-sms-test-confirm').checked;
			if ((mode === 'send_test' || mode === 'retry') && !consent) {
				result.textContent = 'ابتدا تأیید ارسال واقعی را انتخاب کنید.'; return;
			}
			var data = new URLSearchParams({
				action: 'revayat_sms_tool', nonce: revayatSMSTools.nonce, mode: mode,
				provider: provider, context: root.querySelector('#rv-sms-test-context').value,
				mobile: root.querySelector('#rv-sms-test-mobile').value, confirm: consent ? '1' : '0',
				entry: button.dataset.entry || ''
			});
			var candidate = root.querySelector('#rv-sms-' + provider + '_api_key');
			if (mode === 'connection' && candidate) data.set('api_key', candidate.value);
			root.querySelectorAll('[data-sms-tool]').forEach(function (item) { item.disabled = true; });
			result.textContent = 'در حال بررسی…'; result.className = '';
			try {
				var response = await fetch(revayatSMSTools.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: data.toString() });
				var json = await response.json();
				var output = json.data || {};
				var message = output.message || 'پاسخ معتبر دریافت نشد.';
				if (output.credit != null) message += '\nاعتبار: ' + output.credit + ' (واحد API)';
				if (output.variables) message += '\nمتغیرها: ' + output.variables.join(', ');
				if (output.text) message += '\nمتن پترن: ' + output.text;
				result.textContent = message;
				result.className = json.success && output.active !== false ? 'rv-sms-success' : 'rv-sms-error';
			} catch (error) { result.textContent = 'ارتباط برقرار نشد؛ دوباره تلاش کنید.'; result.className = 'rv-sms-error'; }
			finally { root.querySelectorAll('[data-sms-tool]').forEach(function (item) { item.disabled = false; }); }
		});
	});
})();
