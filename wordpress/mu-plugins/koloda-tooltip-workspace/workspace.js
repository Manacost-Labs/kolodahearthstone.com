(function () {
    'use strict';

    var root = document.querySelector('[data-kh-tooltip-workspace]');
    if (!root) {
        return;
    }

    var tabs = root.querySelectorAll('[data-kh-tooltip-tab]');
    var panels = root.querySelectorAll('[data-kh-tooltip-panel]');
    var tabKey = 'khTooltipWorkspaceTab';
    var pinKey = 'khTooltipWorkspacePinned';
    var pin = root.querySelector('.kh-tooltip-workspace__pin-input');
    var box = root.closest('.postbox') || document.getElementById('kh_tooltip_workspace');

    function activate(name) {
        var known = Array.prototype.some.call(tabs, function (tab) {
            return tab.getAttribute('data-kh-tooltip-tab') === name;
        });

        if (!known) {
            return;
        }

        Array.prototype.forEach.call(tabs, function (tab) {
            var active = tab.getAttribute('data-kh-tooltip-tab') === name;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        Array.prototype.forEach.call(panels, function (panel) {
            var active = panel.getAttribute('data-kh-tooltip-panel') === name;
            panel.classList.toggle('is-active', active);
            panel.hidden = !active;
        });

        try {
            window.localStorage.setItem(tabKey, name);
        } catch (error) {
            // Private browsing can disallow localStorage; the current tab still works.
        }
    }

    Array.prototype.forEach.call(tabs, function (tab) {
        tab.addEventListener('click', function () {
            activate(tab.getAttribute('data-kh-tooltip-tab'));
        });
    });

    try {
        activate(window.localStorage.getItem(tabKey) || 'cards');
    } catch (error) {
        activate('cards');
    }

    function applyPin(pinned) {
        if (box) {
            box.classList.toggle('kh-tooltip-workspace--pinned', pinned);
        }
        if (pin) {
            pin.checked = pinned;
        }
    }

    if (box && pin) {
        try {
            applyPin(window.localStorage.getItem(pinKey) !== '0');
        } catch (error) {
            applyPin(true);
        }

        pin.addEventListener('change', function () {
            applyPin(pin.checked);
            try {
                window.localStorage.setItem(pinKey, pin.checked ? '1' : '0');
            } catch (error) {
                // The visible state remains correct for the current session.
            }
        });
    }
}());
