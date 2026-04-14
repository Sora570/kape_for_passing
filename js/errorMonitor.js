(function (global) {
  const ERROR_ENDPOINT = 'db/client_error_log.php';

  function safeString(value) {
    if (value === null || value === undefined) return '';
    try {
      return String(value);
    } catch (e) {
      return '[unstringifiable]';
    }
  }

  function sendErrorPayload(payload) {
    try {
      const body = JSON.stringify(payload);

      if (navigator.sendBeacon) {
        const blob = new Blob([body], { type: 'application/json' });
        navigator.sendBeacon(ERROR_ENDPOINT, blob);
      } else {
        // Fire and forget
        fetch(ERROR_ENDPOINT, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body,
          keepalive: true
        }).catch(function () {
          // Swallow network errors – we don't want to crash the app
        });
      }
    } catch (e) {
      // As a last resort, log to console – but avoid throwing
      try {
        console.error('Error reporting client error:', e);
      } catch (e2) {}
    }
  }

  function buildBasePayload() {
    return {
      timestamp: new Date().toISOString(),
      url: safeString(location && location.href),
      userAgent: safeString(navigator && navigator.userAgent),
      userRole: safeString(global.USER_ROLE),
      sessionId: safeString(global.SESSION_ID || ''),
    };
  }

  function reportClientError(message, error, context) {
    const payload = buildBasePayload();
    payload.level = 'error';
    payload.message = safeString(message || (error && error.message) || 'Unknown client error');
    payload.stack = safeString(error && (error.stack || error.stacktrace));
    payload.context = context || {};

    sendErrorPayload(payload);
  }

  // Global error handler
  global.addEventListener(
    'error',
    function (event) {
      try {
        const context = {
          filename: safeString(event.filename),
          lineno: event.lineno,
          colno: event.colno,
        };

        reportClientError(event.message, event.error, context);
      } catch (e) {
        // Best-effort only
      }
    },
    true
  );

  // Unhandled promise rejections
  global.addEventListener('unhandledrejection', function (event) {
    try {
      const reason = event.reason;
      const message =
        (reason && reason.message) ||
        safeString(reason) ||
        'Unhandled promise rejection';

      reportClientError(message, reason, { type: 'unhandledrejection' });
    } catch (e) {
      // Swallow
    }
  });

  // Expose manual reporter
  global.reportClientError = reportClientError;
})(window);

