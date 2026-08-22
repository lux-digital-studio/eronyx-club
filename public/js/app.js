(function () {
    'use strict';

    var toggle = document.querySelector('.nav-toggle-input');
    var label = document.querySelector('.nav-toggle');
    var nav = document.getElementById('site-nav');

    if (!toggle || !label) {
        return;
    }

    var sync = function () {
        var open = toggle.checked;
        label.setAttribute('aria-expanded', open ? 'true' : 'false');
        label.setAttribute('aria-controls', 'site-nav');
    };

    var close = function () {
        if (!toggle.checked) {
            return;
        }
        toggle.checked = false;
        sync();
    };

    toggle.addEventListener('change', sync);
    sync();

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            close();
        }
    });

    if (nav) {
        nav.addEventListener('click', function (event) {
            var target = event.target;
            if (target && target.closest && target.closest('a')) {
                close();
            }
        });
    }
})();
