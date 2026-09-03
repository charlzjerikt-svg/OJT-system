document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('loginForm');
  if (!form) return;

  var submitBtn = document.getElementById('loginSubmit');
  var identifier = document.getElementById('identifier');
  var password = document.getElementById('password');
  var submitted = false;

  form.addEventListener('submit', function (event) {
    if (!identifier.value.trim() || !password.value) {
      return; // let the browser's native required-field validation handle it
    }

    if (submitted) {
      event.preventDefault();
      return;
    }

    // The form still submits normally (this is a server-rendered page, not
    // AJAX) — disabling here only blocks a second click while the request
    // and subsequent page load are in flight.
    submitted = true;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Logging in…';
  });
});
