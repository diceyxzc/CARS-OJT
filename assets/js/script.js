/* ============================================
   CARS - Global Script
   ============================================ */

document.addEventListener('DOMContentLoaded', function() {

    // ========================================
    // 1. Auto-hide Alerts
    // ========================================
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        const closeBtn = alert.querySelector('.close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                alert.style.display = 'none';
            });
        }
        setTimeout(function() {
            alert.style.transition = 'opacity 0.5s, transform 0.3s';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(function() {
                alert.style.display = 'none';
            }, 500);
        }, 1000);
    });

    // ========================================
    // 2. Mobile Navigation Toggle
    // ========================================
    const navToggle = document.querySelector('.nav-toggle');
    const navLinks = document.querySelector('.nav-links');
    if (navToggle && navLinks) {
        navToggle.addEventListener('click', function() {
            navLinks.classList.toggle('open');
        });
    }

    // ========================================
    // 3. Date Input - Min Date = Today
    // ========================================
    const dateInputs = document.querySelectorAll('input[type="date"]');
    const today = new Date().toISOString().split('T')[0];
    dateInputs.forEach(function(input) {
        if (!input.value) {
            input.setAttribute('min', today);
        }
    });

    // ========================================
    // 4. Confirm Actions
    // ========================================
    document.querySelectorAll('[data-confirm]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            if (!confirm(this.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    });

    // ========================================
    // 5. Form Validation Helper
    // ========================================
    document.querySelectorAll('form[data-validate]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            let valid = true;
            const required = this.querySelectorAll('[required]');
            required.forEach(function(field) {
                if (!field.value.trim()) {
                    field.classList.add('error');
                    valid = false;
                } else {
                    field.classList.remove('error');
                }
            });
            if (!valid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            }
        });
    });

    // ========================================
    // 6. Time Validation (pickup < dropoff)
    // ========================================
    const pickupInput = document.querySelector('input[name="pickup_time"]');
    const dropoffInput = document.querySelector('input[name="dropoff_time"]');
    
    if (pickupInput && dropoffInput) {
        function validateTimes() {
            if (pickupInput.value && dropoffInput.value) {
                if (pickupInput.value >= dropoffInput.value) {
                    dropoffInput.classList.add('error');
                    dropoffInput.setCustomValidity('Dropoff time must be after pickup time');
                } else {
                    dropoffInput.classList.remove('error');
                    dropoffInput.setCustomValidity('');
                }
            }
        }
        pickupInput.addEventListener('change', validateTimes);
        dropoffInput.addEventListener('change', validateTimes);
    }

    console.log('CARS System initialized successfully.');
});