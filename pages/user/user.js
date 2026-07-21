/* ============================================
   USER - Request Functions
   ============================================ */

function resetForm() {
    var form = document.getElementById('requestForm');
    if (form) form.reset();
}

// ========================================
// ESCAPE HTML HELPER
// ========================================

function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ========================================
// CONFIRMATION MODAL FUNCTIONS
// ========================================

function openConfirmModal(formData) {
    document.getElementById('confirmName').textContent = formData.name || '-';
    document.getElementById('confirmDepartment').textContent = formData.department || '-';
    document.getElementById('confirmLocalNumber').textContent = formData.contact || '-';
    document.getElementById('confirmEmail').textContent = formData.email || '-';
    
    // Format date: July 17, 2026 | Thursday
    var formattedDate = '-';
    if (formData.date) {
        var d = new Date(formData.date + 'T00:00:00');
        var options = { year: 'numeric', month: 'long', day: 'numeric' };
        var dateStr = d.toLocaleDateString('en-US', options);
        var dayStr = d.toLocaleDateString('en-US', { weekday: 'long' });
        formattedDate = dateStr + ' | ' + dayStr;
    }
    document.getElementById('confirmDate').textContent = formattedDate;
    
    // Format time: Pickup → Dropoff
    var formattedTime = '-';
    if (formData.pickup_time && formData.dropoff_time) {
        var pt = new Date('2000-01-01T' + formData.pickup_time);
        var dt = new Date('2000-01-01T' + formData.dropoff_time);
        var pickupStr = pt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        var dropoffStr = dt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        formattedTime = pickupStr + ' → ' + dropoffStr;
    } else if (formData.pickup_time) {
        var pt = new Date('2000-01-01T' + formData.pickup_time);
        formattedTime = pt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    }
    document.getElementById('confirmTime').textContent = formattedTime;
    
    // Format route: Pickup Location → Dropoff Location
    var formattedRoute = '-';
    if (formData.pickup_location && formData.dropoff_location) {
        formattedRoute = formData.pickup_location + ' → ' + formData.dropoff_location;
    } else if (formData.pickup_location) {
        formattedRoute = formData.pickup_location;
    }
    document.getElementById('confirmRoute').textContent = formattedRoute;
    
    document.getElementById('confirmTravelType').textContent = formData.travel_type || '-';
    document.getElementById('confirmPurpose').textContent = formData.purpose || '-';
    
    // Format passengers
    var passengersHtml = '';
    if (formData.passengers && formData.passengers.length > 0) {
        formData.passengers.forEach(function(p) {
            passengersHtml += '<span class="passenger-tag-mini">' + escapeHtml(p) + '</span>';
        });
    } else {
        passengersHtml = '<span style="color:#6c757d; font-size:0.8rem;">None</span>';
    }
    document.getElementById('confirmPassengers').innerHTML = passengersHtml;
    
    document.getElementById('confirmModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeConfirmModal() {
    document.getElementById('confirmModal').classList.remove('active');
    document.body.style.overflow = '';
}

// ========================================
// FORM SUBMISSION WITH CONFIRMATION
// ========================================

document.addEventListener('DOMContentLoaded', function() {
    // Set default date to today
    var dateInput = document.getElementById('req_date');
    if (dateInput && !dateInput.value) {
        var today = new Date();
        var year = today.getFullYear();
        var month = String(today.getMonth() + 1).padStart(2, '0');
        var day = String(today.getDate()).padStart(2, '0');
        dateInput.value = year + '-' + month + '-' + day;
    }
    
    // Validate dropoff time is after pickup time
    var pickupTime = document.getElementById('req_pickup_time');
    var dropoffTime = document.getElementById('req_dropoff_time');
    
    if (pickupTime && dropoffTime) {
        function validateTimes() {
            if (pickupTime.value && dropoffTime.value) {
                if (dropoffTime.value <= pickupTime.value) {
                    dropoffTime.setCustomValidity('Dropoff time must be after pickup time');
                    dropoffTime.classList.add('error');
                } else {
                    dropoffTime.setCustomValidity('');
                    dropoffTime.classList.remove('error');
                }
            }
        }
        pickupTime.addEventListener('change', validateTimes);
        dropoffTime.addEventListener('change', validateTimes);
    }
    
    // ========================================
    // FORM SUBMISSION HANDLER
    // ========================================
    var form = document.getElementById('requestForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Get form elements
            var name = document.getElementById('req_name');
            var department = document.getElementById('req_department');
            var contact = document.getElementById('req_contact');
            var email = document.getElementById('req_email');
            var date = document.getElementById('req_date');
            var pickupTime = document.getElementById('req_pickup_time');
            var dropoffTime = document.getElementById('req_dropoff_time');
            var travelType = document.getElementById('req_travel_type');
            var pickupLocation = document.getElementById('req_pickup_location');
            var dropoffLocation = document.getElementById('req_dropoff_location');
            var passengers = document.getElementById('req_passengers');
            var purpose = document.getElementById('req_purpose');
            
            // Validate all required fields
            if (!name.value.trim()) { alert('Please enter your name.'); return; }
            if (!department.value) { alert('Please select your department.'); return; }
            if (!contact.value.trim()) { alert('Please enter your local number.'); return; }
            if (!email.value.trim()) { alert('Please enter your email address.'); return; }
            if (!date.value) { alert('Please select a date.'); return; }
            if (!pickupTime.value) { alert('Please select a pickup time.'); return; }
            if (!dropoffTime.value) { alert('Please select a dropoff time.'); return; }
            if (dropoffTime.value <= pickupTime.value) { 
                alert('Dropoff time must be after pickup time.'); 
                dropoffTime.classList.add('error');
                return; 
            }
            if (!travelType.value) { alert('Please select a travel type.'); return; }
            if (!pickupLocation.value.trim()) { alert('Please enter a pickup location.'); return; }
            if (!dropoffLocation.value.trim()) { alert('Please enter a dropoff location.'); return; }
            
            // Validate passengers
            var passengerNames = passengers.value.split(',').map(function(p) { return p.trim(); }).filter(function(p) { return p !== ''; });
            if (passengerNames.length === 0) { alert('Please enter at least one passenger name.'); return; }
            
            // Build form data for modal
            var formData = {
                name: name.value.trim(),
                department: department.options[department.selectedIndex].text,
                contact: contact.value.trim(),
                email: email.value.trim(),  
                date: date.value,
                pickup_time: pickupTime.value,
                dropoff_time: dropoffTime.value,
                travel_type: travelType.value,
                purpose: purpose ? purpose.value : '',
                pickup_location: pickupLocation.value,
                dropoff_location: dropoffLocation.value,
                passengers: passengerNames
            };
            
            // Store reference to submit the form after confirmation
            window._confirmForm = form;
            openConfirmModal(formData);
        });
    }
    
    // ========================================
    // CONFIRM SUBMIT BUTTON
    // ========================================
    var confirmBtn = document.getElementById('confirmSubmitBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            closeConfirmModal();
            // Submit the form using the stored reference
            if (window._confirmForm) {
                window._confirmForm.submit();
            }
        });
    }
    
    // ========================================
    // CLOSE MODAL ON OUTSIDE CLICK
    // ========================================
    document.addEventListener('click', function(e) {
        var confirmModal = document.getElementById('confirmModal');
        if (confirmModal && e.target === confirmModal) {
            closeConfirmModal();
        }
    });
    
    // ========================================
    // CLOSE MODAL ON ESCAPE KEY
    // ========================================
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeConfirmModal();
        }
    });
});