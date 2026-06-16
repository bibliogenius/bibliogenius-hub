// Render UTC timestamps in the visitor's local timezone.
//
// Server stores and emits timestamps in UTC. Any element carrying a `data-utc`
// attribute (with the ISO-8601 instant either in `datetime` or in `data-utc`)
// is rewritten to the browser's locale + timezone via toLocaleString(), so the
// admin sees their own system time instead of GMT. Without JS, the markup still
// shows the value labelled "UTC", so the information is never lost.
(function () {
    function localize(el) {
        var iso = el.getAttribute('datetime') || el.getAttribute('data-utc');
        if (!iso) {
            return;
        }
        var d = new Date(iso);
        if (isNaN(d.getTime())) {
            return;
        }
        el.textContent = d.toLocaleString();
        el.title = iso; // keep the raw UTC instant on hover
    }

    function run() {
        document.querySelectorAll('[data-utc]').forEach(localize);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
