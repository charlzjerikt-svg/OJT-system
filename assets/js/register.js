document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('registerForm');
  if (!form) return;

  var submitBtn = document.getElementById('registerSubmit');
  var formAlert = document.getElementById('formAlert');
  var isSubmitting = false;

  var validators = {
    student_id: function (v) {
      v = v.trim();
      if (!v) return 'Student ID is required.';
      if (!/^[A-Za-z0-9\-\/_.]{2,50}$/.test(v)) return 'Use only letters, numbers, and - _ . / characters.';
      return '';
    },
    first_name: function (v) { return v.trim() ? '' : 'First name is required.'; },
    last_name: function (v) { return v.trim() ? '' : 'Last name is required.'; },
    email: function (v) {
      v = v.trim();
      if (!v) return 'Email address is required.';
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Please enter a valid email address.';
      return '';
    },
    mobile_number: function (v) {
      v = v.trim();
      if (!v) return '';
      return /^[0-9+\-\s()]{7,20}$/.test(v) ? '' : 'Please enter a valid mobile number.';
    },
    course: function (v) { return v.trim() ? '' : 'Course / Program is required.'; },
    year_level: function (v) { return v ? '' : 'Year level is required.'; },
    company: function (v) { return v.trim() ? '' : 'Company / Establishment is required.'; },
    ojt_hours: function (v) {
      v = v.trim();
      if (!v) return 'OJT required hours is required.';
      var n = Number(v);
      if (isNaN(n) || n <= 0) return 'OJT required hours must be a positive number.';
      if (n > 5000) return 'That number of hours looks too high. Please check the value.';
      return '';
    },
    ojt_start_date: function (v) { return v ? '' : 'OJT start date is required.'; },
    ojt_end_date: function (v) {
      if (!v) return '';
      var start = form.elements.ojt_start_date.value;
      if (start && v < start) return 'End date cannot be earlier than the start date.';
      return '';
    },
    password: function (v) {
      if (!v) return 'Password is required.';
      if (v.length < 8) return 'Password must be at least 8 characters long.';
      if (!/[A-Z]/.test(v)) return 'Include at least one uppercase letter.';
      if (!/[a-z]/.test(v)) return 'Include at least one lowercase letter.';
      if (!/[0-9]/.test(v)) return 'Include at least one number.';
      return '';
    },
    confirm_password: function (v) {
      if (!v) return 'Please confirm your password.';
      return v === form.elements.password.value ? '' : 'Passwords do not match.';
    }
  };

  function showFieldError(name, message) {
    var input = form.elements[name];
    var errorEl = document.getElementById('error-' + name);
    if (!input || !errorEl) return;
    if (message) {
      input.classList.add('input-error');
      input.classList.remove('input-success');
      errorEl.textContent = message;
    } else {
      input.classList.remove('input-error');
      if (('' + input.value).trim()) input.classList.add('input-success');
      errorEl.textContent = '';
    }
  }

  function validateField(name) {
    var validator = validators[name];
    if (!validator) return true;
    var input = form.elements[name];
    var message = validator(input.value);
    showFieldError(name, message);
    return !message;
  }

  Object.keys(validators).forEach(function (name) {
    var input = form.elements[name];
    if (!input) return;
    input.addEventListener('blur', function () { validateField(name); });
    input.addEventListener('input', function () {
      if (input.classList.contains('input-error')) validateField(name);
    });
    input.addEventListener('change', function () {
      if (input.classList.contains('input-error')) validateField(name);
    });
  });

  form.elements.ojt_start_date.addEventListener('change', function () {
    if (form.elements.ojt_end_date.value) validateField('ojt_end_date');
  });
  form.elements.password.addEventListener('input', function () {
    if (form.elements.confirm_password.value) validateField('confirm_password');
  });

  var fileInput = document.getElementById('profile_picture');
  var previewImg = document.getElementById('profilePreview');
  fileInput.addEventListener('change', function () {
    var file = fileInput.files[0];
    var errorEl = document.getElementById('error-profile_picture');
    errorEl.textContent = '';

    if (!file) {
      previewImg.style.display = 'none';
      return;
    }

    var allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (allowedTypes.indexOf(file.type) === -1) {
      errorEl.textContent = 'Profile picture must be a JPG, PNG, or WEBP image.';
      fileInput.value = '';
      previewImg.style.display = 'none';
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      errorEl.textContent = 'Profile picture must be smaller than 2MB.';
      fileInput.value = '';
      previewImg.style.display = 'none';
      return;
    }

    var reader = new FileReader();
    reader.onload = function (e) {
      previewImg.src = e.target.result;
      previewImg.style.display = 'block';
    };
    reader.readAsDataURL(file);
  });

  function setAlert(type, message) {
    formAlert.className = 'alert alert-' + type;
    formAlert.textContent = message;
    formAlert.style.display = 'block';
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (isSubmitting) return;

    var allValid = true;
    Object.keys(validators).forEach(function (name) {
      if (!validateField(name)) allValid = false;
    });

    if (!allValid) {
      setAlert('error', 'Please correct the highlighted fields.');
      var firstError = form.querySelector('.input-error');
      if (firstError) firstError.focus();
      return;
    }

    isSubmitting = true;
    submitBtn.disabled = true;
    var originalText = submitBtn.textContent;
    submitBtn.textContent = 'Creating your account…';
    formAlert.style.display = 'none';

    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (response) {
        return response.json().catch(function () {
          throw new Error('bad_response');
        });
      })
      .then(function (data) {
        if (data.success) {
          setAlert('success', data.message);
          form.reset();
          previewImg.style.display = 'none';
          Array.prototype.forEach.call(form.querySelectorAll('.input-success, .input-error'), function (el) {
            el.classList.remove('input-success', 'input-error');
          });
          submitBtn.textContent = 'Registered';
          window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
          setAlert('error', data.message || 'Something went wrong. Please try again.');
          if (data.errors) {
            Object.keys(data.errors).forEach(function (name) {
              showFieldError(name, data.errors[name]);
            });
          }
          isSubmitting = false;
          submitBtn.disabled = false;
          submitBtn.textContent = originalText;
        }
      })
      .catch(function () {
        setAlert('error', 'Network error. Please check your connection and try again.');
        isSubmitting = false;
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
      });
  });
});
