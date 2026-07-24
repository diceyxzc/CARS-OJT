var guardModalAction = null; // 'start' | 'complete'
var guardModalAllocationId = null;

function nowTimeValue() {
    var now = new Date();
    var h = String(now.getHours()).padStart(2, '0');
    var m = String(now.getMinutes()).padStart(2, '0');
    return h + ':' + m;
}

function openGuardModal(allocationId, action) {
    guardModalAllocationId = allocationId;
    guardModalAction = action;

    var title = document.getElementById('guardModalTitle');
    var timeLabel = document.getElementById('guardModalTimeLabel');
    var confirmBtn = document.getElementById('guardModalConfirmBtn');

    if (action === 'start') {
        title.textContent = 'Start Trip';
        timeLabel.textContent = 'Actual Departure';
        confirmBtn.className = 'btn-guard-start';
        confirmBtn.textContent = 'Start Trip';
    } else {
        title.textContent = 'Complete Trip';
        timeLabel.textContent = 'Actual Arrival';
        confirmBtn.className = 'btn-guard-complete';
        confirmBtn.textContent = 'Complete Trip';
    }

    document.getElementById('guardModalTime').value = nowTimeValue();
    document.getElementById('guardModal').classList.add('active');
}

function closeGuardModal() {
    document.getElementById('guardModal').classList.remove('active');
    guardModalAction = null;
    guardModalAllocationId = null;
}

document.getElementById('guardModalConfirmBtn').addEventListener('click', function() {
    var actualTime = document.getElementById('guardModalTime').value;
    if (!actualTime) {
        alert('Please set the time.');
        return;
    }

    var endpoint = guardModalAction === 'start' ? 'start_trip_ajax' : 'complete_inprogress_ajax';
    var formData = new FormData();
    formData.append(endpoint, '1');
    formData.append('allocation_id', guardModalAllocationId);
    formData.append('actual_time', actualTime);

    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Processing...';

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            closeGuardModal();
            if (data.success) {
                refreshTripList();
            } else {
                alert(data.message || 'Something went wrong.');
            }
        })
        .catch(function() {
            btn.disabled = false;
            closeGuardModal();
            alert('Error processing request. Please try again.');
        });
});

document.getElementById('guardModal').addEventListener('click', function(e) {
    if (e.target === this) closeGuardModal();
});

function formatTripTime(t) {
    var d = new Date('2000-01-01 ' + t);
    return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
}

function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function refreshTripList() {
    fetch(window.location.pathname + '?ajax_refresh=1&t=' + Date.now())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) return;
            var list = document.getElementById('guardTripList');

            if (data.trips.length === 0) {
                list.innerHTML = '<p class="text-muted">No trips scheduled for today.</p>';
                return;
            }

            var html = '';
            data.trips.forEach(function(t) {
                html += '<div class="trip-card-guard status-' + t.status + '" data-allocation-id="' + t.allocation_id + '">';
                html += '<div class="trip-card-guard-top">';
                html += '<div class="trip-main-info">';
                html += '<strong>' + escapeHtml(t.driver_name) + '</strong> — ' + escapeHtml(t.brand) + ' (' + escapeHtml(t.plate_number) + ')';
                html += '<div class="trip-sub-info">';
                html += formatTripTime(t.pickup_time);
                if (t.dropoff_time) html += ' – ' + formatTripTime(t.dropoff_time);
                html += ' &middot; ' + escapeHtml(t.pickup_location);
                if (t.dropoff_location) html += ' → ' + escapeHtml(t.dropoff_location);
                html += '</div>';
                if (t.status === 'approved' && !t.startability.startable) {
                    html += '<div class="trip-blocked-reason">' + escapeHtml(t.startability.reason) + '</div>';
                }
                html += '</div>';
                html += '<div class="trip-actions">';
                if (t.status === 'approved') {
                    html += '<button type="button" class="btn-guard-start" ' + (t.startability.startable ? '' : 'disabled') + ' onclick="openGuardModal(' + t.allocation_id + ', \'start\')">Start</button>';
                } else if (t.status === 'in_progress') {
                    html += '<button type="button" class="btn-guard-complete" onclick="openGuardModal(' + t.allocation_id + ', \'complete\')">Complete</button>';
                }
                html += '</div></div></div>';
            });
            list.innerHTML = html;
        })
        .catch(function(e) { console.error('Refresh failed:', e); });
}

// Auto-refresh every 5 seconds so guards see new approvals / other guards' actions
setInterval(refreshTripList, 5000);