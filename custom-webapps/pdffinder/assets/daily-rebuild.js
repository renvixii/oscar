/**
 * PDF Finder — daily rebuild guard (localStorage) and async background rebuild.
 */
(function () {
    'use strict';

    var STORAGE_MODE = 'pdffinder_daily_rebuild_mode';
    var STORAGE_EVALUATED = 'pdffinder_daily_rebuild_evaluated';
    var MODES = {
        disabled: 'disabled',
        silent: 'silent',
        reminder: 'reminder',
    };
    var DEFAULT_MODE = MODES.disabled;
    var API_URL = 'api-rebuild-all.php';

    var activeRebuild = null;

    function todayKey() {
        var d = new Date();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + day;
    }

    function getMode() {
        var stored = localStorage.getItem(STORAGE_MODE);
        if (stored && Object.prototype.hasOwnProperty.call(MODES, stored)) {
            return stored;
        }
        return DEFAULT_MODE;
    }

    function setMode(mode) {
        if (!Object.prototype.hasOwnProperty.call(MODES, mode)) {
            mode = DEFAULT_MODE;
        }
        localStorage.setItem(STORAGE_MODE, mode);
    }

    function wasEvaluatedToday() {
        return localStorage.getItem(STORAGE_EVALUATED) === todayKey();
    }

    function markEvaluatedToday() {
        localStorage.setItem(STORAGE_EVALUATED, todayKey());
    }

    function ensureBannerRoot() {
        var root = document.getElementById('daily-rebuild-root');
        if (root) {
            return root;
        }
        root = document.createElement('div');
        root.id = 'daily-rebuild-root';
        root.setAttribute('aria-live', 'polite');
        document.body.appendChild(root);
        return root;
    }

    function removeEl(id) {
        var el = document.getElementById(id);
        if (el) {
            el.remove();
        }
    }

    function showProgressIndicator() {
        removeEl('daily-rebuild-progress');
        var root = ensureBannerRoot();
        var el = document.createElement('div');
        el.id = 'daily-rebuild-progress';
        el.className = 'daily-rebuild-progress';
        el.innerHTML =
            '<span class="daily-rebuild-progress-spinner" aria-hidden="true"></span>' +
            '<span>Rebuilding search index in the background…</span>';
        root.appendChild(el);
    }

    function hideProgressIndicator() {
        removeEl('daily-rebuild-progress');
    }

    function showToast(message, type) {
        removeEl('daily-rebuild-toast');
        var root = ensureBannerRoot();
        var el = document.createElement('div');
        el.id = 'daily-rebuild-toast';
        el.className = 'daily-rebuild-toast daily-rebuild-toast--' + (type || 'info');
        el.textContent = message;
        root.appendChild(el);
        window.setTimeout(function () {
            removeEl('daily-rebuild-toast');
        }, 6000);
    }

    function startAsyncRebuild(options) {
        options = options || {};
        if (activeRebuild) {
            return activeRebuild;
        }

        showProgressIndicator();

        activeRebuild = fetch(API_URL, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) {
                        throw new Error((data && data.message) || 'Rebuild request failed.');
                    }
                    return data;
                });
            })
            .then(function (data) {
                hideProgressIndicator();
                if (options.silent) {
                    if (data.partial) {
                        showToast('Daily rebuild finished with some issues.', 'warning');
                    } else if (!data.ok) {
                        showToast('Daily rebuild could not complete.', 'error');
                    }
                } else if (data.ok || data.partial) {
                    showToast(data.message || 'Index rebuilt.', data.partial ? 'warning' : 'success');
                } else {
                    showToast(data.message || 'Rebuild failed.', 'error');
                }
                return data;
            })
            .catch(function (err) {
                hideProgressIndicator();
                showToast(err.message || 'Rebuild failed.', 'error');
                throw err;
            })
            .finally(function () {
                activeRebuild = null;
            });

        return activeRebuild;
    }

    function showReminderBanner() {
        removeEl('daily-rebuild-reminder');
        var root = ensureBannerRoot();
        var el = document.createElement('div');
        el.id = 'daily-rebuild-reminder';
        el.className = 'daily-rebuild-reminder';
        el.setAttribute('role', 'region');
        el.setAttribute('aria-label', 'Daily index rebuild reminder');

        el.innerHTML =
            '<div class="daily-rebuild-reminder-inner">' +
            '<p class="daily-rebuild-reminder-text">' +
            'It\'s a new day — refresh your search index for the latest PDFs?' +
            '</p>' +
            '<div class="daily-rebuild-reminder-actions">' +
            '<button type="button" class="btn btn-primary btn-sm" data-action="rebuild">Rebuild now</button>' +
            '<button type="button" class="btn btn-secondary btn-sm" data-action="dismiss">Dismiss</button>' +
            '</div>' +
            '</div>';

        el.querySelector('[data-action="dismiss"]').addEventListener('click', function () {
            el.remove();
        });

        el.querySelector('[data-action="rebuild"]').addEventListener('click', function () {
            el.remove();
            startAsyncRebuild({ silent: false });
        });

        root.appendChild(el);
    }

    function runDailyGuard() {
        if (wasEvaluatedToday()) {
            return;
        }

        var mode = getMode();

        if (mode === MODES.disabled) {
            markEvaluatedToday();
            return;
        }

        if (mode === MODES.silent) {
            markEvaluatedToday();
            startAsyncRebuild({ silent: true });
            return;
        }

        if (mode === MODES.reminder) {
            markEvaluatedToday();
            showReminderBanner();
        }
    }

    function initSettingsPanel() {
        var panel = document.getElementById('daily-rebuild-settings');
        if (!panel) {
            return;
        }

        var statusEl = document.getElementById('daily-rebuild-settings-status');
        var inputs = panel.querySelectorAll('input[name="daily_rebuild_mode"]');
        var current = getMode();

        inputs.forEach(function (input) {
            input.checked = input.value === current;
            input.addEventListener('change', function () {
                if (!input.checked) {
                    return;
                }
                setMode(input.value);
                updateSettingsStatus(statusEl);
            });
        });

        updateSettingsStatus(statusEl);
    }

    function updateSettingsStatus(statusEl) {
        if (!statusEl) {
            return;
        }

        var mode = getMode();
        var evaluated = localStorage.getItem(STORAGE_EVALUATED);
        var labels = {
            disabled: 'Disabled — manual rebuild only.',
            silent: 'Silent auto-rebuild — runs once on your first visit each day.',
            reminder: 'Smart reminder — prompts once per day on first visit.',
        };

        var text = labels[mode] || labels.disabled;
        if (evaluated) {
            text += ' Last evaluated: ' + evaluated + '.';
        } else {
            text += ' Not yet evaluated today.';
        }
        statusEl.textContent = text;
    }

    function init() {
        var script = document.currentScript;
        var context = script && script.getAttribute('data-context');

        if (context === 'settings') {
            initSettingsPanel();
            return;
        }

        if (context === 'app') {
            runDailyGuard();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
