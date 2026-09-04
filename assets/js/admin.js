document.addEventListener('DOMContentLoaded', function () {
  function openModal(modal) {
    if (modal) modal.style.display = 'flex';
  }
  function closeModal(modal) {
    if (modal) modal.style.display = 'none';
  }

  var rejectModal = document.getElementById('rejectModal');
  var deactivateModal = document.getElementById('deactivateModal');

  document.querySelectorAll('[data-reject-student]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('rejectStudentId').value = btn.getAttribute('data-reject-student');
      document.getElementById('rejectModalText').textContent =
        btn.getAttribute('data-reject-name') + '\'s registration will be rejected. This cannot be undone from this screen.';
      var returnTo = document.getElementById('rejectReturnTo');
      if (returnTo) returnTo.value = btn.getAttribute('data-return-profile') ? 'profile' : '';
      openModal(rejectModal);
    });
  });

  document.querySelectorAll('[data-deactivate-student]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('deactivateStudentId').value = btn.getAttribute('data-deactivate-student');
      document.getElementById('deactivateModalText').textContent =
        btn.getAttribute('data-deactivate-name') + ' will no longer be able to access the student system. Their attendance and history are preserved.';
      var returnTo = document.getElementById('deactivateReturnTo');
      if (returnTo) returnTo.value = btn.getAttribute('data-return-profile') ? 'profile' : '';
      openModal(deactivateModal);
    });
  });

  var editModal = document.getElementById('editModal');
  document.querySelectorAll('[data-edit-attendance]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('editAttendanceId').value = btn.getAttribute('data-edit-attendance');
      document.getElementById('editModalText').textContent = 'Correcting attendance for ' + btn.getAttribute('data-edit-student') + '.';
      document.getElementById('edit_time_in').value = btn.getAttribute('data-edit-time-in') || '';
      document.getElementById('edit_time_out').value = btn.getAttribute('data-edit-time-out') || '';
      openModal(editModal);
    });
  });

  var manualModal = document.getElementById('manualModal');
  var openManualBtn = document.getElementById('openManualEntry');
  if (openManualBtn) {
    openManualBtn.addEventListener('click', function () { openModal(manualModal); });
  }

  document.querySelectorAll('[data-modal-cancel]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      closeModal(rejectModal);
      closeModal(deactivateModal);
      closeModal(editModal);
      closeModal(manualModal);
    });
  });

  document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
    backdrop.addEventListener('click', function (event) {
      if (event.target === backdrop) closeModal(backdrop);
    });
  });

  // Every form submit button on the page: prevent double submission the same
  // way the student-side pages do — disable synchronously on click.
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function () {
      var btn = form.querySelector('button[type="submit"]');
      if (btn && !btn.disabled) {
        btn.disabled = true;
        btn.dataset.originalText = btn.textContent;
        btn.textContent = 'Processing...';
      }
    });
  });
});
