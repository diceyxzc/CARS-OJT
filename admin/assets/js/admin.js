/* ============================================
   ADMIN - Core Functions
   ============================================ */

function openLogoutModal() {
    document.getElementById('logoutModal').classList.add('active');
}

function closeLogoutModal() {
    document.getElementById('logoutModal').classList.remove('active');
}

document.addEventListener('click', function(e) {
    var modal = document.getElementById('logoutModal');
    if (e.target === modal) closeLogoutModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLogoutModal();
});

function openDeleteModal(tripId, carBrand, tripDate) {
    var modal = document.getElementById('deleteModal');
    var confirmBtn = document.getElementById('confirmDeleteBtn');
    var tripInfo = document.getElementById('modalTripInfo');
    
    if (modal && confirmBtn && tripInfo) {
        tripInfo.textContent = carBrand + ' - ' + tripDate;
        confirmBtn.href = '?delete_approved=' + tripId + '&tab=approved';
        modal.classList.add('active');
    }
}

function closeDeleteModal() {
    var modal = document.getElementById('deleteModal');
    if (modal) modal.classList.remove('active');
}

function getStatusDisplay(status) {
    const display = {
        'pending': 'Pending',
        'approved': 'Approved',
        'declined': 'Declined',
        'completed': 'Completed',
        'in_progress': 'In Progress',
        'cancelled': 'Cancelled'
    };
    return display[status] || status.replace('_', ' ');
}

function openTripModal(trip) {
    var modal = document.getElementById('tripModal');
    var body = document.getElementById('tripModalBody');
    var title = document.getElementById('tripModalTitle');
    
    function getStatusDisplay(status) {
        const display = {
            'pending': 'Pending',
            'approved': 'Approved',
            'declined': 'Declined',
            'completed': 'Completed',
            'in_progress': 'In Progress',
            'cancelled': 'Cancelled'
        };
        return display[status] || status.replace('_', ' ');
    }
    
    var passengersHtml = '';
    if (trip.passengers && trip.passengers.length > 0) {
        trip.passengers.forEach(function(p) {
            passengersHtml += '<span class="passenger-tag-modal">' + p.passenger_name + '</span>';
        });
    } else {
        passengersHtml = '<span style="color:#6c757d;">None</span>';
    }
    
    title.textContent = 'Trip Details - ' + trip.brand + ' (' + trip.plate_number + ')';
    
    body.innerHTML = `
        <!-- Trip Information Section -->
        <div class="section-title">Trip Information <span class="badge-section">Basic details</span></div>
        <div class="detail-row">
            <span class="label">Request #</span>
            <span class="value"><strong>${trip.request_number || 'N/A'}</strong></span>  
        </div>
        <div class="detail-row">
            <span class="label">Date</span>
            <span class="value">${new Date(trip.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</span>
        </div>
        <div class="detail-row" style="align-items:flex-start;">
            <span class="label">Time</span>
            <span class="value" style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                <div>
                    <div style="font-size:0.65rem; color:#6c757d; text-transform:uppercase; letter-spacing:0.3px; margin-bottom:4px; font-weight:600;">Scheduled</div>
                    <div style="margin-bottom:2px;"><span style="color:#6c757d; font-size:0.75rem;">Departure:</span> ${new Date('2000-01-01 ' + trip.pickup_time).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}</div>
                    <div><span style="color:#6c757d; font-size:0.75rem;">Arrival:</span> ${trip.dropoff_time ? new Date('2000-01-01 ' + trip.dropoff_time).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) : 'Not specified'}</div>
                </div>
                <div>
                    <div style="font-size:0.65rem; color:#2e7d32; text-transform:uppercase; letter-spacing:0.3px; margin-bottom:4px; font-weight:600;">Actual</div>
                    <div style="margin-bottom:2px;"><span style="color:#6c757d; font-size:0.75rem;">Departure:</span> ${trip.actual_pickup_time ? new Date('2000-01-01 ' + trip.actual_pickup_time).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) : '<span style="color:#adb5bd;">Not yet</span>'}</div>
                    <div><span style="color:#6c757d; font-size:0.75rem;">Arrival:</span> ${trip.actual_dropoff_time ? new Date('2000-01-01 ' + trip.actual_dropoff_time).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) : '<span style="color:#adb5bd;">Not yet</span>'}</div>
                </div>
            </span>
        </div>
        <div class="detail-row">
            <span class="label">Status</span>
            <span class="value"><span class="badge ${trip.status === 'pending' ? 'badge-pending' : trip.status === 'approved' ? 'badge-approved' : trip.status === 'declined' ? 'badge-declined' : trip.status === 'completed' ? 'badge-completed' : trip.status === 'in_progress' ? 'badge-in_progress' : 'badge-cancelled'}">${getStatusDisplay(trip.status)}</span></span>
        </div>

        <!-- Requestor Information Section -->
        <div class="section-divider"></div>
        <div class="section-title">Requestor Information <span class="badge-section">Person who requested</span></div>
        <div class="detail-row">
            <span class="label">Name</span>
            <span class="value">${trip.requestor}</span>
        </div>
        <div class="detail-row">
            <span class="label">Email</span>
            <span class="value">${trip.requestor_email || 'Not provided'}</span>
        </div>

        <!-- Vehicle & Driver Section -->
        <div class="section-divider"></div>
        <div class="section-title">Vehicle & Driver <span class="badge-section">Assigned resources</span></div>
        <div class="detail-row">
            <span class="label">Car</span>
            <span class="value">${trip.brand} (${trip.plate_number})</span>
        </div>
        <div class="detail-row">
            <span class="label">Parking</span>
            <span class="value">${trip.parking || 'Not specified'}</span>
        </div>
        <div class="detail-row">
            <span class="label">Driver</span>
            <span class="value">${trip.driver_name}</span>
        </div>
        <div class="detail-row">
            <span class="label">Driver Mobile</span>
            <span class="value">${trip.driver_mobile || 'Not available'}</span>
        </div>

        <!-- Trip Details Section -->
        <div class="section-divider"></div>
        <div class="section-title">Trip Details <span class="badge-section">Route & purpose</span></div>
        <div class="detail-row">
            <span class="label">Pickup</span>
            <span class="value">${trip.pickup_location}</span>
        </div>
        <div class="detail-row">
            <span class="label">Dropoff</span>
            <span class="value">${trip.dropoff_location || 'Not specified'}</span>
        </div>
        ${trip.travel_type && trip.travel_type !== 'Not specified' ? `
        <div class="detail-row">
            <span class="label">Travel Type</span>
            <span class="value"><span>${trip.travel_type}</span></span>
        </div>` : ''}
        ${trip.purpose ? `
        <div class="detail-row">
            <span class="label">Purpose</span>
            <span class="value">${trip.purpose}</span>
        </div>` : ''}

        <!-- Passengers Section -->
        <div class="section-divider"></div>
        <div class="section-title">Passengers <span class="badge-section">${trip.passengers ? trip.passengers.length : 0} passenger(s)</span></div>
        <div class="detail-row" style="border-bottom: none; padding-bottom: 0;">
            <span class="label" style="align-self: flex-start;">Passengers</span>
            <span class="value">${passengersHtml}</span>
        </div>

        <!-- Remarks Section -->
        ${trip.remarks && trip.remarks !== 'None' && !trip.remarks.includes('Purpose:') ? `
        <div class="section-divider"></div>
        <div class="section-title">Remarks <span class="badge-section">Additional notes</span></div>
        <div class="detail-row" style="border-bottom: none; padding-bottom: 0;">
            <span class="label">Remarks</span>
            <span class="value">${trip.remarks}</span>
        </div>` : ''}
    `;
    
    modal.classList.add('active');
}

function closeTripModal() {
    document.getElementById('tripModal').classList.remove('active');
}

document.addEventListener('click', function(e) {
    var modal = document.getElementById('tripModal');
    if (e.target === modal) {
        closeTripModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeTripModal();
    }
});