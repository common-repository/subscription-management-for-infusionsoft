(function () {
	'use strict';

	var REDIRECT_DELAY_MSEC = 3000;

	var container = null,
		form = null,
		tagChecks = [],
		unsubCheck = null,
		initial = {};

	function handleChange(e) {
		var target = e.target;

		if (target === unsubCheck) {
			if (target.checked) {
                for (var i = 0; i < tagChecks.length; ++i)
                    tagChecks[i].checked = false;
            } else {
                for (var i = 0; i < tagChecks.length; ++i) {
                	var input = tagChecks[i];
                    input.checked = initial[input.getAttribute('name')];
                }
			}
		} else if (tagChecks.indexOf(target) >= 0) {
			var anyChecked = false;
			for (var i = 0; i < tagChecks.length; ++i)
				if (tagChecks[i].checked)
					anyChecked = true;
			unsubCheck.checked = !anyChecked;
		}
	}

	function init() {
        /* Handle redirects. */
        container = document.getElementById('email_tracking_manage_subscriptions');
        if (container === null)
        	return;
        var redirect_url = container.getAttribute('data-redirect-url');
        if (redirect_url) {
        	setTimeout(function() { window.location.href = redirect_url; }, REDIRECT_DELAY_MSEC);
        	return;
        }

        /* Gather form inputs and store initial check states. */
        form = document.getElementById('sm_form');
        if (form === null)
        	return;

        var inputs = form.getElementsByTagName('input');
        for (var i= 0; i < inputs.length; ++i) {
            var input = inputs[i];
            if (input.getAttribute('type') !== 'checkbox')
                continue;
            if (input.getAttribute('name') === 'unsubscribe') {
            	unsubCheck = input;
			} else {
            	tagChecks.push(input);
            	initial[input.getAttribute('name')] = input.checked;
			}
        }

        form.addEventListener('change', handleChange);
	}

	document.addEventListener('DOMContentLoaded', init);
})();
