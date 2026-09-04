document.addEventListener('DOMContentLoaded', function () {
  var buttons = document.querySelectorAll('[data-action][data-action-url]');
  if (!buttons.length) return;

  var alertBox = document.getElementById('attendanceAlert');
  // Shared across every action button on the page (Time In / Start Break / End
  // Break / Time Out never coexist in a way where more than one is even visible
  // at once, but sharing one flag also blocks a stray second click landing on
  // whichever button the state-driven reload swaps in next).
  var clicked = false;

  var loadingText = {
    time_in: 'Recording Time In...',
    start_break: 'Starting Break...',
    end_break: 'Ending Break...',
    time_out: 'Recording Time Out...'
  };
  var successText = {
    time_in: '✓ Time In Recorded',
    start_break: '✓ Break Started',
    end_break: '✓ Break Ended',
    time_out: '✓ Time Out Recorded'
  };

  buttons.forEach(function (button) {
    button.addEventListener('click', function () {
      // Disable synchronously, before the network round trip even starts — this
      // is what actually stops a double-click from firing two requests.
      if (clicked) return;
      clicked = true;

      var action = button.getAttribute('data-action');
      var csrfToken = button.getAttribute('data-csrf');
      var actionUrl = button.getAttribute('data-action-url');
      var originalText = button.textContent;

      button.disabled = true;
      button.textContent = loadingText[action] || 'Processing...';
      alertBox.style.display = 'none';

      var body = new URLSearchParams();
      body.set('csrf_token', csrfToken);
      body.set('action', action);

      fetch(actionUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString()
      })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (data.success) {
            button.textContent = successText[action] || '✓ Done';
            // The dashboard mixes several interdependent pieces (attendance card,
            // OJT progress stats, recent-attendance table) that all need to reflect
            // the same fresh server state — a short reload keeps the server as the
            // single source of truth instead of hand-patching each piece of the DOM.
            window.setTimeout(function () { window.location.reload(); }, 500);
          } else {
            clicked = false;
            button.disabled = false;
            button.textContent = originalText;
            alertBox.className = 'alert alert-error';
            alertBox.textContent = data.message || 'Something went wrong. Please try again.';
            alertBox.style.display = 'block';
          }
        })
        .catch(function () {
          clicked = false;
          button.disabled = false;
          button.textContent = originalText;
          alertBox.className = 'alert alert-error';
          alertBox.textContent = 'Network error. Please check your connection and try again.';
          alertBox.style.display = 'block';
        });
    });
  });
});
