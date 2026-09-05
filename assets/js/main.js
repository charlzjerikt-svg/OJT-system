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

  // Header profile/notification dropdowns — present on every page via header.php,
  // so this lives in main.js rather than a page-specific script.
  var dropdownPairs = [
    { trigger: 'profileTrigger', menu: 'profileDropdown' },
    { trigger: 'notifBell', menu: 'notifDropdown' }
  ];

  dropdownPairs.forEach(function (pair) {
    var trigger = document.getElementById(pair.trigger);
    var menu = document.getElementById(pair.menu);
    if (!trigger || !menu) return;

    trigger.addEventListener('click', function (event) {
      event.stopPropagation();
      var wasOpen = menu.classList.contains('open');
      dropdownPairs.forEach(function (p) {
        var m = document.getElementById(p.menu);
        if (m) m.classList.remove('open');
      });
      if (!wasOpen) menu.classList.add('open');
    });
  });

  document.addEventListener('click', function () {
    dropdownPairs.forEach(function (pair) {
      var menu = document.getElementById(pair.menu);
      if (menu) menu.classList.remove('open');
    });
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      dropdownPairs.forEach(function (pair) {
        var menu = document.getElementById(pair.menu);
        if (menu) menu.classList.remove('open');
      });
      closeSidebar();
    }
  });

  // Mobile sidebar drawer — the sidebar is hidden off-canvas below 900px (see
  // style.css); the hamburger button in the topbar slides it in as an overlay
  // with a dimmed backdrop, present on every authenticated app-shell page.
  var sidebar = document.getElementById('appSidebar');
  var backdrop = document.getElementById('sidebarBackdrop');
  var hamburger = document.getElementById('hamburgerBtn');

  function openSidebar() {
    if (sidebar) sidebar.classList.add('open');
    if (backdrop) backdrop.classList.add('open');
  }
  function closeSidebar() {
    if (sidebar) sidebar.classList.remove('open');
    if (backdrop) backdrop.classList.remove('open');
  }

  if (hamburger && sidebar) {
    hamburger.addEventListener('click', function (event) {
      event.stopPropagation();
      if (sidebar.classList.contains('open')) closeSidebar(); else openSidebar();
    });
  }
  if (backdrop) {
    backdrop.addEventListener('click', closeSidebar);
  }
});
