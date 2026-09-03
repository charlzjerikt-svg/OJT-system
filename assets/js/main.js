document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
    button.addEventListener('click', function () {
      var targetId = button.getAttribute('data-toggle-password');
      var input = document.getElementById(targetId);
      if (!input) return;
      var isHidden = input.getAttribute('type') === 'password';
      input.setAttribute('type', isHidden ? 'text' : 'password');
      button.textContent = isHidden ? 'Hide' : 'Show';
    });
  });
});
