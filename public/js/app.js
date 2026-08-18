(function () {
    'use strict';

    var toggle = document.querySelector('.nav-toggle-input');
    var label = document.querySelector('.nav-toggle');

    if (!toggle || !label) {
        return;
    }

    var sync = function () {
        label.setAttribute('aria-expanded', toggle.checked ? 'true' : 'false');
        label.setAttribute('aria-controls', 'site-nav');
    };

    toggle.addEventListener('change', sync);
    sync();
})();
