(function(){
    async function checkSession() {
        try {
            const res = await fetch('/db/session_alive.php', { credentials: 'same-origin', cache: 'no-store' });
            return res.ok;
        } catch (err) {
            return false;
        }
    }

    // Detect bfcache / back-forward navigation
    window.addEventListener('pageshow', function (event) {
        const navEntries = (performance.getEntriesByType && performance.getEntriesByType('navigation')) || [];
        const navType = (navEntries[0] && navEntries[0].type) || (performance.navigation && performance.navigation.type) || '';
        if (event.persisted || navType === 'back_forward') {
            (async function () {
                const alive = await checkSession();
                if (!alive) {
                    // Replace URL in history so forward doesn't point to this protected page
                    history.replaceState(null, '', '/session_expired.html');
                    // Redirect to session expired page
                    window.location.replace('/session_expired.html');
                }
            })();
        }
    }, false);

    // Only check on pageshow when the page is restored from bfcache / back-forward navigation.
    // Avoid doing an immediate check on normal page loads to prevent race conditions right after login.
    // The pageshow handler above will still call checkSession when necessary.
    // (No 'load' check here to avoid prematurely redirecting right after a login redirect.)
})();
