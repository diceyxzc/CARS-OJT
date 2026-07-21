/* ============================================
   ADMIN - Request Management
   ============================================ */

// ========================================
// Dropoff Time Validation (Direct Allocation form)
// ========================================

function validateDropoffTime() {
    var pickup = document.getElementById('pickup_time');
    var dropoff = document.getElementById('dropoff_time');
    var errorMsg = document.getElementById('dropoffError');

    if (pickup && dropoff && pickup.value && dropoff.value) {
        if (dropoff.value <= pickup.value) {
            dropoff.classList.add('dropoff-error');
            if (errorMsg) errorMsg.classList.add('show');
            dropoff.setCustomValidity('Dropoff time must be after pickup time');
        } else {
            dropoff.classList.remove('dropoff-error');
            if (errorMsg) errorMsg.classList.remove('show');
            dropoff.setCustomValidity('');
        }
    }
}

// ========================================
// Available Drivers (server-checked, respects full date+time overlap)
// ========================================

function populateDriverSelect(drivers, availabilityLabel) {
    var driverSelect = document.getElementById('driver_id');
    var infoText = document.getElementById('driverAvailabilityInfo');
    if (!driverSelect) return;

    var previouslySelected = driverSelect.value;
    while (driverSelect.options.length > 1) {
        driverSelect.remove(1);
    }

    if (drivers.length > 0) {
        drivers.forEach(function(driver) {
            var option = document.createElement('option');
            option.value = driver.driver_id;
            option.dataset.carId = driver.car_id;
            option.dataset.brand = driver.brand || '';
            option.dataset.plate = driver.plate_number || '';
            option.dataset.parking = driver.parking || '';
            option.dataset.codingDay = driver.coding_day || '';

            var label = driver.name;
            if (driver.brand) {
                label += ' - ' + driver.brand + ' (' + driver.plate_number + ')';
                if (driver.coding_day) label += ' [Coding: ' + driver.coding_day + ']';
            }
            option.textContent = label;
            driverSelect.appendChild(option);
        });

        if (infoText) {
            infoText.innerHTML = '<i class="fas fa-check-circle" style="color:#2e7d32;"></i> ' + availabilityLabel;
            infoText.style.color = '#2e7d32';
        }

        var stillAvailable = drivers.some(function(d) { return String(d.driver_id) === String(previouslySelected); });
        if (previouslySelected && stillAvailable) {
            driverSelect.value = previouslySelected;
        }
    } else {
        var option = document.createElement('option');
        option.value = '';
        option.disabled = true;
        option.style.color = '#c62828';
        option.textContent = 'No drivers available';
        driverSelect.appendChild(option);

        if (infoText) {
            infoText.innerHTML = '<i class="fas fa-exclamation-circle" style="color:#c62828;"></i> No drivers available';
            infoText.style.color = '#c62828';
        }
    }

    if (driverSelect.value) {
        updateCarInfo(driverSelect.value);
    } else {
        var carDisplay = document.getElementById('carDisplay');
        var carIdInput = document.getElementById('car_id');
        if (carDisplay) carDisplay.classList.remove('show');
        if (carIdInput) carIdInput.value = '';
    }
}

function filterAvailableDrivers() {
    var dateTypeEl = document.querySelector('input[name="date_type"]:checked');
    var isRange = dateTypeEl && dateTypeEl.value === 'range';

    // Date Range mode: a single-day availability check doesn't apply across
    // multiple days (a driver busy on day 3 might be free on days 1, 2, 4...).
    // Show every active driver with a car; the backend insert loop already
    // skips/reports individual conflicting dates per driver.
    if (isRange) {
        var infoText = document.getElementById('driverAvailabilityInfo');
        var drivers = (typeof ALL_DRIVERS_WITH_CAR !== 'undefined') ? ALL_DRIVERS_WITH_CAR : [];
        if (infoText) {
            infoText.innerHTML = '<i class="fas fa-info-circle" style="color:#1a237e;"></i> Showing all drivers — conflicting dates will be skipped automatically';
            infoText.style.color = '#1a237e';
        }
        populateDriverSelect(drivers, drivers.length + ' driver(s) available');
        return;
    }

    var dateEl = document.getElementById('date');
    var pickupEl = document.getElementById('pickup_time');
    var dropoffEl = document.getElementById('dropoff_time');
    var driverSelect = document.getElementById('driver_id');
    var infoText = document.getElementById('driverAvailabilityInfo');

    if (!dateEl || !pickupEl || !dropoffEl || !driverSelect) return;

    var date = dateEl.value;
    var pickupTime = pickupEl.value;
    var dropoffTime = dropoffEl.value;

    if (!date || !pickupTime || !dropoffTime) {
        if (infoText) {
            infoText.innerHTML = '<i class="fas fa-info-circle"></i> Select date and time';
            infoText.style.color = '#6c757d';
        }
        return;
    }

    if (infoText) {
        infoText.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';
        infoText.style.color = '#f57c00';
    }

    fetch('/admin/api/available_drivers.php?date=' + encodeURIComponent(date) + '&pickup=' + encodeURIComponent(pickupTime) + '&dropoff=' + encodeURIComponent(dropoffTime))
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                populateDriverSelect(data.data, data.data.length + ' driver(s) available');
            }
        })
        .catch(function(error) {
            console.error('Error fetching available drivers:', error);
            if (infoText) {
                infoText.innerHTML = '<i class="fas fa-exclamation-circle" style="color:#c62828;"></i> Error loading drivers';
                infoText.style.color = '#c62828';
            }
        });
}

// ========================================
// Dropoff Time Validation (Edit Trip modal)
// ========================================

function validateEditDropoffTime() {
    var pickup = document.getElementById('edit_pickup_time');
    var dropoff = document.getElementById('edit_dropoff_time');
    var errorMsg = document.getElementById('editDropoffError');

    if (pickup && dropoff && pickup.value && dropoff.value) {
        if (dropoff.value <= pickup.value) {
            dropoff.classList.add('dropoff-error');
            if (errorMsg) errorMsg.classList.add('show');
            dropoff.setCustomValidity('Dropoff time must be after pickup time');
        } else {
            dropoff.classList.remove('dropoff-error');
            if (errorMsg) errorMsg.classList.remove('show');
            dropoff.setCustomValidity('');
        }
    }
}

// ========================================
// Passenger Management
// ========================================

var passengerChoicesInstance = null;

function initPassengerChoices() {
    var select = document.getElementById('passengerGrid');
    if (!select) return;
    passengerChoicesInstance = new Choices(select, {
        removeItemButton: true,
        searchEnabled: true,
        shouldSort: true,
        placeholder: true,
        placeholderValue: 'Select passenger(s)',
        noResultsText: 'No passengers found',
        noChoicesText: 'No passengers added yet'
    });
    select.addEventListener('change', updatePassengerDisplay);
}

function deleteUnusedPassengers() {
    if (!confirm('This will permanently delete every passenger with no trips assigned. Continue?')) return;

    var messageDiv = document.getElementById('addPassengerMessage');
    var formData = new FormData();
    formData.append('delete_unused_passengers_ajax', '1');

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (data.deleted_count > 0 && passengerChoicesInstance) {
                    var select = document.getElementById('passengerGrid');
                    var remaining = Array.from(select.options)
                        .filter(function(o) { return data.deleted_ids.indexOf(o.value) === -1 && data.deleted_ids.indexOf(parseInt(o.value)) === -1; })
                        .map(function(o) { return { value: o.value, label: o.textContent.trim(), selected: o.selected }; });
                    passengerChoicesInstance.clearStore();
                    passengerChoicesInstance.setChoices(remaining, 'value', 'label', true);
                    updatePassengerDisplay();
                }
                if (messageDiv) messageDiv.innerHTML = '<span style="color: #2e7d32;">' + data.message + '</span>';
                setTimeout(function() { if (messageDiv) messageDiv.innerHTML = ''; }, 2000);
            } else {
                if (messageDiv) messageDiv.innerHTML = '<span style="color: #c62828;">' + (data.message || 'Error cleaning up passengers.') + '</span>';
            }
        })
        .catch(function() {
            if (messageDiv) messageDiv.innerHTML = '<span style="color: #c62828;">Error cleaning up passengers. Please try again.</span>';
        });
}

function updatePassengerDisplay() {
    var select = document.getElementById('passengerGrid');
    var passengerError = document.getElementById('passengerError');
    if (!select) return;
    var selectedCount = select.selectedOptions ? select.selectedOptions.length : 0;
    if (passengerError) {
        passengerError.classList.toggle('show', selectedCount === 0);
    }
}

function addPassenger() {
    var nameInput = document.getElementById('new_passenger_name');
    var messageDiv = document.getElementById('addPassengerMessage');
    var name = nameInput ? nameInput.value.trim() : '';

    if (!name) {
        if (messageDiv) messageDiv.innerHTML = '<span style="color: #c62828;">Please enter a passenger name.</span>';
        return;
    }

    var select = document.getElementById('passengerGrid');
    var duplicate = Array.from(select.options).some(function(o) {
        return o.textContent.trim().toLowerCase() === name.toLowerCase();
    });
    if (duplicate) {
        if (messageDiv) messageDiv.innerHTML = '<span style="color: #f57c00;">Passenger already exists in the list.</span>';
        return;
    }

    var formData = new FormData();
    formData.append('add_passenger_ajax', '1');
    formData.append('passenger_name', name);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (passengerChoicesInstance) {
                    passengerChoicesInstance.setChoices([{
                        value: String(data.passenger_id),
                        label: data.passenger_name,
                        selected: true
                    }], 'value', 'label', false);
                }
                refreshManagePassengersList();
                if (messageDiv) messageDiv.innerHTML = '<span style="color: #2e7d32;">Passenger added successfully!</span>';
                if (nameInput) nameInput.value = '';
                updatePassengerDisplay();

                var passengerError = document.getElementById('passengerError');
                if (passengerError) passengerError.classList.remove('show');

                setTimeout(function() { if (messageDiv) messageDiv.innerHTML = ''; }, 1000);
            } else {
                if (messageDiv) messageDiv.innerHTML = '<span style="color: #c62828;">' + data.message + '</span>';
            }
        })
        .catch(function() {
            if (messageDiv) messageDiv.innerHTML = '<span style="color: #c62828;">Error adding passenger. Please try again.</span>';
        });
}

function deletePassenger(passengerId, passengerName) {
    if (!confirm('Are you sure you want to delete "' + passengerName + '"?')) return;

    var messageDiv = document.getElementById('addPassengerMessage');
    var formData = new FormData();
    formData.append('delete_passenger_ajax', '1');
    formData.append('passenger_id', passengerId);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (passengerChoicesInstance) {
                    var remaining = Array.from(document.getElementById('passengerGrid').options)
                        .filter(function(o) { return String(o.value) !== String(passengerId); })
                        .map(function(o) { return { value: o.value, label: o.textContent.trim(), selected: o.selected }; });
                    passengerChoicesInstance.clearStore();
                    passengerChoicesInstance.setChoices(remaining, 'value', 'label', true);
                }
                refreshManagePassengersList();
                if (messageDiv) messageDiv.innerHTML = '<span style="color: #2e7d32;">' + data.message + '</span>';
                updatePassengerDisplay();
                setTimeout(function() { if (messageDiv) messageDiv.innerHTML = ''; }, 1000);
            } else {
                if (messageDiv) messageDiv.innerHTML = '<span style="color: #c62828;">' + data.message + '</span>';
            }
        })
        .catch(function() {
            if (messageDiv) messageDiv.innerHTML = '<span style="color: #c62828;">Error deleting passenger. Please try again.</span>';
        });
}

// Add this function to validate passengers before form submission
function validatePassengerSelection() {
    var select = document.getElementById('passengerGrid');
    var passengerError = document.getElementById('passengerError');
    var selectedCount = select && select.selectedOptions ? select.selectedOptions.length : 0;

    if (selectedCount === 0) {
        if (passengerError) {
            passengerError.classList.add('show');
            passengerError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        return false;
    }
    if (passengerError) passengerError.classList.remove('show');
    return true;
}

// ========================================
// Driver & Car Management
// ========================================

function updateCarInfo(driverId) {
    var select = document.getElementById('driver_id');
    if (!select) return;
    var selectedOption = select.options[select.selectedIndex];
    var carDisplay = document.getElementById('carDisplay');
    var carIdInput = document.getElementById('car_id');
    var carDisplayText = document.getElementById('carDisplayText');
    var parkingDisplayText = document.getElementById('parkingDisplayText');
    var codingDisplay = document.getElementById('codingDisplay');
    var codingDisplayText = document.getElementById('codingDisplayText');
    
    if (driverId && selectedOption && selectedOption.dataset.carId) {
        var brand = selectedOption.dataset.brand || 'Unknown';
        var plate = selectedOption.dataset.plate || 'Unknown';
        var parking = selectedOption.dataset.parking || 'Not specified';
        var codingDay = selectedOption.dataset.codingDay || '';
        var carId = selectedOption.dataset.carId;
        
        if (carDisplay) carDisplay.classList.add('show');
        if (carDisplayText) carDisplayText.textContent = brand + ' (' + plate + ')';
        if (parkingDisplayText) parkingDisplayText.textContent = parking;
        if (carIdInput) carIdInput.value = carId;
        
        if (codingDay && codingDisplay) {
            codingDisplay.style.display = 'flex';
            if (codingDisplayText) codingDisplayText.textContent = codingDay;
        } else if (codingDisplay) {
            codingDisplay.style.display = 'none';
        }
    } else {
        if (carDisplay) carDisplay.classList.remove('show');
        if (carIdInput) carIdInput.value = '';
        if (codingDisplay) codingDisplay.style.display = 'none';
    }
}

// ========================================
// Date Range Toggle
// ========================================

function toggleDateType() {
    var radios = document.getElementsByName('date_type');
    var singleGroup = document.getElementById('singleDateGroup');
    var rangeGroup = document.getElementById('dateRangeGroup');
    var singleDateInput = document.getElementById('date');
    var startDateInput = document.getElementById('start_date');
    var endDateInput = document.getElementById('end_date');
    var travelTypeSingle = document.getElementById('travel_type_single');
    var travelTypeRange = document.getElementById('travel_type_range');
    filterAvailableDrivers();

    for (var i = 0; i < radios.length; i++) {
        if (radios[i].checked && radios[i].value === 'range') {
            if (singleGroup) singleGroup.classList.add('hidden');
            if (rangeGroup) rangeGroup.classList.add('active');
            if (singleDateInput) singleDateInput.removeAttribute('required');
            if (startDateInput) startDateInput.setAttribute('required', 'required');
            if (endDateInput) endDateInput.setAttribute('required', 'required');

            if (travelTypeSingle && travelTypeRange) {
                travelTypeRange.value = travelTypeSingle.value;
                travelTypeRange.disabled = false;
                travelTypeRange.setAttribute('required', 'required');
                travelTypeSingle.disabled = true;
                travelTypeSingle.removeAttribute('required');
            }
        } else if (radios[i].checked && radios[i].value === 'single') {
            if (singleGroup) singleGroup.classList.remove('hidden');
            if (rangeGroup) rangeGroup.classList.remove('active');
            if (singleDateInput) singleDateInput.setAttribute('required', 'required');
            if (startDateInput) startDateInput.removeAttribute('required');
            if (endDateInput) endDateInput.removeAttribute('required');

            if (travelTypeSingle && travelTypeRange) {
                travelTypeSingle.value = travelTypeRange.value;
                travelTypeSingle.disabled = false;
                travelTypeSingle.setAttribute('required', 'required');
                travelTypeRange.disabled = true;
                travelTypeRange.removeAttribute('required');
            }
        }
    }
}

// ========================================
// Review Modal (Select Driver + Approve/Reject)
// ========================================

var _reviewDrivers = [];
var _reviewTripDateRaw = '';

function openReviewModal(allocationId, requestor, email, date, pickupTime, dropoffTime, pickup, dropoff, travelType, purpose, passengersJson, driversJson, tripDateRaw) {
    var passengers = [];
    try { passengers = JSON.parse(passengersJson || '[]'); } catch(e) { passengers = []; }
    try { _reviewDrivers = JSON.parse(driversJson || '[]'); } catch(e) { _reviewDrivers = []; }
    _reviewTripDateRaw = tripDateRaw;

    document.getElementById('approveRequestor').textContent = requestor;
    document.getElementById('approveEmail').textContent = email || 'Not provided';
    document.getElementById('approveDate').textContent = date;
    document.getElementById('approvePickupTime').textContent = pickupTime;
    document.getElementById('approveDropoffTime').textContent = dropoffTime || 'Not specified';
    document.getElementById('approvePickup').textContent = pickup;
    document.getElementById('approveDropoff').textContent = dropoff;
    document.getElementById('approveTravelType').textContent = travelType || 'Not specified';
    document.getElementById('approvePurpose').textContent = purpose || 'Not specified';

    var passengersHtml = '';
    if (passengers.length > 0) {
        passengers.forEach(function(name) {
            passengersHtml += '<span class="passenger-tag-modal">' + name + '</span>';
        });
    } else {
        passengersHtml = '<span style="color:#6c757d; font-size:0.85rem;">None</span>';
    }
    document.getElementById('approvePassengers').innerHTML = passengersHtml;

    // Populate driver dropdown
    var select = document.getElementById('reviewDriverSelect');
    select.innerHTML = '<option value="">Select a driver</option>';

    if (_reviewDrivers.length > 0) {
        _reviewDrivers.forEach(function(d) {
            var opt = document.createElement('option');
            opt.value = d.driver_id;
            opt.dataset.carId = d.car_id;
            opt.dataset.brand = d.brand || '';
            opt.dataset.plate = d.plate_number || '';
            opt.dataset.parking = d.parking || '';
            opt.dataset.codingDay = d.coding_day || '';
            var label = d.name;
            if (d.brand) {
                label += ' - ' + d.brand + ' (' + d.plate_number + ')';
                if (d.coding_day) label += ' [Coding: ' + d.coding_day + ']';
            }
            opt.textContent = label;
            select.appendChild(opt);
        });
    } else {
        var noneOpt = document.createElement('option');
        noneOpt.value = '';
        noneOpt.disabled = true;
        noneOpt.textContent = 'No drivers available';
        select.appendChild(noneOpt);
    }

    document.getElementById('driverInfo').style.display = 'none';
    document.getElementById('approveAllocationId').value = allocationId;
    document.getElementById('approveDriverId').value = '';

    document.getElementById('approveModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function updateReviewDriverInfo() {
    var select = document.getElementById('reviewDriverSelect');
    var selectedOption = select.options[select.selectedIndex];
    var driverInfo = document.getElementById('driverInfo');

    if (!selectedOption || !selectedOption.value) {
        driverInfo.style.display = 'none';
        document.getElementById('approveDriverId').value = '';
        return;
    }

    var driverName = selectedOption.textContent.split(' - ')[0].trim();
    var codingInfo = selectedOption.dataset.codingDay
        ? ' <span class="coding-warning">(Coding: ' + selectedOption.dataset.codingDay + ')</span>'
        : '';

    document.getElementById('approveDriverName').textContent = driverName;
    document.getElementById('approveCarInfo').innerHTML = (selectedOption.dataset.brand || 'N/A') + ' (' + (selectedOption.dataset.plate || 'N/A') + ')' + codingInfo;
    document.getElementById('approveParking').textContent = selectedOption.dataset.parking || 'Not specified';
    document.getElementById('approveDriverId').value = selectedOption.value;
    driverInfo.style.display = 'block';
}

function switchReviewToReject() {
    var id = document.getElementById('approveAllocationId').value;
    closeApproveModal();
    openRejectModal(id);
}

// Validate driver selection + coding day before letting the approve form submit
document.getElementById('approveForm').addEventListener('submit', function(e) {
    var select = document.getElementById('reviewDriverSelect');
    var selectedOption = select.options[select.selectedIndex];

    if (!selectedOption || !selectedOption.value) {
        e.preventDefault();
        alert('Please select a driver first.');
        return;
    }

    var codingDay = selectedOption.dataset.codingDay;
    if (codingDay && _reviewTripDateRaw) {
        var tripDate = new Date(_reviewTripDateRaw + 'T00:00:00');
        var dayOfWeek = tripDate.toLocaleDateString('en-US', { weekday: 'long' });
        if (codingDay.toLowerCase() === dayOfWeek.toLowerCase()) {
            e.preventDefault();
            var carData = {
                brand: selectedOption.dataset.brand,
                plate: selectedOption.dataset.plate,
                coding_day: codingDay
            };
            openCodingWarningModal(carData, _reviewTripDateRaw, function() {
                showSubmittingOverlay('Approving request and sending confirmation email...');
                document.getElementById('approveForm').submit();
            });
            return;
        }
    }

    // Passed validation, no coding conflict — form will submit natively now
    showSubmittingOverlay('Approving request and sending confirmation email...');
});

// ========================================
// Edit Approved Trip Modal
// ========================================
function openEditModal(tripId) {
    var modal = document.getElementById('editModal');
    var body = document.getElementById('editModalBody');
    body.innerHTML = '<div class="loading-spinner">Loading trip details...</div>';
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    fetch(window.location.pathname + '?get_approved_data=' + tripId + '&t=' + Date.now())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                body.innerHTML = buildEditApprovedForm(data);
                initializeEditApprovedForm();
            } else {
                body.innerHTML = '<div class="alert alert-error">' + data.message + '</div>';
            }
        })
        .catch(function() {
            body.innerHTML = '<div class="alert alert-error">Error loading trip details. Please try again.</div>';
        });
}
function closeEditModal() {
    var modal = document.getElementById('editModal');
    if (modal) { modal.classList.remove('active'); document.body.style.overflow = ''; }
}

function buildEditApprovedForm(data) {
    var trip = data.trip;
    var drivers = data.available_drivers || [];
    var passengersHtml = (data.passengers && data.passengers.length > 0)
        ? data.passengers.map(function(p) { return '<span class="passenger-tag">' + escapeHtml(p.passenger_name) + '</span>'; }).join('')
        : '<span class="text-muted">None</span>';

    var driverOptions = drivers.map(function(d) {
        var label = d.is_current ? ' (Current)' : '';
        return '<option value="' + d.driver_id + '" ' + (d.is_current ? 'selected' : '') +
            ' data-car-id="' + (d.car_id || '') + '" data-brand="' + escapeHtml(d.brand || '') +
            '" data-plate="' + escapeHtml(d.plate_number || '') + '" data-parking="' + escapeHtml(d.parking || '') + '">' +
            escapeHtml(d.name) + label + (d.brand ? ' - ' + escapeHtml(d.brand) + ' (' + escapeHtml(d.plate_number) + ')' : '') +
            '</option>';
    }).join('');

    return `
        <form id="editApprovedForm">
            <input type="hidden" name="edit_approved_ajax" value="1">
            <input type="hidden" name="allocation_id" value="${trip.allocation_id}">
            <div style="margin-bottom:15px; padding:12px; background:#f8f9fa; border-radius:6px;">
                <strong>Request #:</strong> ${escapeHtml(trip.request_number)}<br>
                <strong>Passengers:</strong> ${passengersHtml}
            </div>
            <div class="form-row-2">
                <div class="floating-group">
                    <input type="date" class="form-control-modern" placeholder=" " name="date" id="edit_approved_date" required value="${trip.date}">
                    <label for="edit_approved_date">Trip Date <span class="required">*</span></label>
                </div>
                <div class="floating-group">
                    <input type="time" class="form-control-modern" placeholder=" " name="pickup_time" id="edit_approved_pickup_time" required value="${trip.pickup_time}">
                    <label for="edit_approved_pickup_time">Pickup Time <span class="required">*</span></label>
                </div>
            </div>
            <div class="form-row-2">
                <div class="floating-group">
                    <input type="time" class="form-control-modern" placeholder=" " name="dropoff_time" id="edit_approved_dropoff_time" required value="${trip.dropoff_time || ''}">
                    <label for="edit_approved_dropoff_time">Dropoff Time <span class="required">*</span></label>
                    <div class="dropoff-error-message" id="editApprovedDropoffError">Dropoff time must be after pickup time</div>
                </div>
                <div class="floating-group">
                    <select class="form-control-modern" name="driver_id" id="edit_approved_driver_id" required>${driverOptions}</select>
                    <label for="edit_approved_driver_id">Driver <span class="required">*</span></label>
                </div>
            </div>
            <div class="form-row-2">
                <div class="floating-group">
                    <input type="text" class="form-control-modern" placeholder=" " name="pickup_location" id="edit_approved_pickup_location" required value="${escapeHtml(trip.pickup_location)}">
                    <label for="edit_approved_pickup_location">Pickup Location <span class="required">*</span></label>
                </div>
                <div class="floating-group">
                    <input type="text" class="form-control-modern" placeholder=" " name="dropoff_location" id="edit_approved_dropoff_location" required value="${escapeHtml(trip.dropoff_location || '')}">
                    <label for="edit_approved_dropoff_location">Dropoff Location <span class="required">*</span></label>
                </div>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">Update Trip</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
            </div>
        </form>
    `;
}

function initializeEditApprovedForm() {
    var pickup = document.getElementById('edit_approved_pickup_time');
    var dropoff = document.getElementById('edit_approved_dropoff_time');
    function validate() {
        var err = document.getElementById('editApprovedDropoffError');
        if (pickup && dropoff && pickup.value && dropoff.value) {
            if (dropoff.value <= pickup.value) {
                dropoff.classList.add('dropoff-error');
                if (err) err.classList.add('show');
            } else {
                dropoff.classList.remove('dropoff-error');
                if (err) err.classList.remove('show');
            }
        }
    }
    if (pickup) pickup.addEventListener('change', validate);
    if (dropoff) dropoff.addEventListener('change', validate);
    validate();

    var form = document.getElementById('editApprovedForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var body = document.getElementById('editModalBody');
            body.innerHTML = '<div class="loading-spinner">Updating trip...</div>';
            fetch(window.location.pathname, { method: 'POST', body: new FormData(form) })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    body.innerHTML = data.success
                        ? '<div class="alert alert-success">' + data.message + '</div><div style="margin-top:15px;"><button type="button" class="btn btn-primary" onclick="closeEditModal(); refreshOpenApprovedDriverModal();">OK</button></div>'
                        : '<div class="alert alert-error">' + data.message + '</div>';
                })
                .catch(function() {
                    body.innerHTML = '<div class="alert alert-error">Error updating trip. Please try again.</div>';
                });
        });
    }
}

function buildEditApprovedForm(data) {
    var trip = data.trip;
    var drivers = data.available_drivers || [];
    var passengersHtml = (data.passengers && data.passengers.length > 0)
        ? data.passengers.map(function(p) { return '<span class="passenger-tag">' + escapeHtml(p.passenger_name) + '</span>'; }).join('')
        : '<span class="text-muted">None</span>';

    var driverOptions = drivers.map(function(d) {
        var label = d.is_current ? ' (Current)' : '';
        return '<option value="' + d.driver_id + '" ' + (d.is_current ? 'selected' : '') +
            ' data-car-id="' + (d.car_id || '') + '" data-brand="' + escapeHtml(d.brand || '') +
            '" data-plate="' + escapeHtml(d.plate_number || '') + '" data-parking="' + escapeHtml(d.parking || '') + '">' +
            escapeHtml(d.name) + label + (d.brand ? ' - ' + escapeHtml(d.brand) + ' (' + escapeHtml(d.plate_number) + ')' : '') +
            '</option>';
    }).join('');

    return `
        <form id="editApprovedForm">
            <input type="hidden" name="edit_approved_ajax" value="1">
            <input type="hidden" name="allocation_id" value="${trip.allocation_id}">
            <div style="margin-bottom:15px; padding:12px; background:#f8f9fa; border-radius:6px;">
                <strong>Request #:</strong> ${escapeHtml(trip.request_number)}<br>
                <strong>Passengers:</strong> ${passengersHtml}
            </div>
            <div class="form-row-2">
                <div class="floating-group">
                    <input type="date" class="form-control-modern" placeholder=" " name="date" id="edit_approved_date" required value="${trip.date}">
                    <label for="edit_approved_date">Trip Date <span class="required">*</span></label>
                </div>
                <div class="floating-group">
                    <input type="time" class="form-control-modern" placeholder=" " name="pickup_time" id="edit_approved_pickup_time" required value="${trip.pickup_time}">
                    <label for="edit_approved_pickup_time">Pickup Time <span class="required">*</span></label>
                </div>
            </div>
            <div class="form-row-2">
                <div class="floating-group">
                    <input type="time" class="form-control-modern" placeholder=" " name="dropoff_time" id="edit_approved_dropoff_time" required value="${trip.dropoff_time || ''}">
                    <label for="edit_approved_dropoff_time">Dropoff Time <span class="required">*</span></label>
                    <div class="dropoff-error-message" id="editApprovedDropoffError">Dropoff time must be after pickup time</div>
                </div>
                <div class="floating-group">
                    <select class="form-control-modern" name="driver_id" id="edit_approved_driver_id" required>${driverOptions}</select>
                    <label for="edit_approved_driver_id">Driver <span class="required">*</span></label>
                </div>
            </div>
            <div class="form-row-2">
                <div class="floating-group">
                    <input type="text" class="form-control-modern" placeholder=" " name="pickup_location" id="edit_approved_pickup_location" required value="${escapeHtml(trip.pickup_location)}">
                    <label for="edit_approved_pickup_location">Pickup Location <span class="required">*</span></label>
                </div>
                <div class="floating-group">
                    <input type="text" class="form-control-modern" placeholder=" " name="dropoff_location" id="edit_approved_dropoff_location" required value="${escapeHtml(trip.dropoff_location || '')}">
                    <label for="edit_approved_dropoff_location">Dropoff Location <span class="required">*</span></label>
                </div>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">Update Trip</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
            </div>
        </form>
    `;
}

function initializeEditApprovedForm() {
    var pickup = document.getElementById('edit_approved_pickup_time');
    var dropoff = document.getElementById('edit_approved_dropoff_time');
    function validate() {
        var err = document.getElementById('editApprovedDropoffError');
        if (pickup && dropoff && pickup.value && dropoff.value) {
            if (dropoff.value <= pickup.value) {
                dropoff.classList.add('dropoff-error');
                if (err) err.classList.add('show');
            } else {
                dropoff.classList.remove('dropoff-error');
                if (err) err.classList.remove('show');
            }
        }
    }
    if (pickup) pickup.addEventListener('change', validate);
    if (dropoff) dropoff.addEventListener('change', validate);
    validate();

    var form = document.getElementById('editApprovedForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var body = document.getElementById('editModalBody');
            body.innerHTML = '<div class="loading-spinner">Updating trip...</div>';
            fetch(window.location.pathname, { method: 'POST', body: new FormData(form) })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    body.innerHTML = data.success
                        ? '<div class="alert alert-success">' + data.message + '</div><div style="margin-top:15px;"><button type="button" class="btn btn-primary" onclick="closeEditModal(); refreshOpenApprovedDriverModal();">OK</button></div>'
                        : '<div class="alert alert-error">' + data.message + '</div>';
                })
                .catch(function() {
                    body.innerHTML = '<div class="alert alert-error">Error updating trip. Please try again.</div>';
                });
        });
    }
}

// ========================================
// Edit Pending Request Modal
// ========================================
function openEditPendingModal(tripId) {
    var modal = document.getElementById('editPendingModal');
    var body = document.getElementById('editPendingBody');
    body.innerHTML = '<div class="loading-spinner">Loading request details...</div>';
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    fetch(window.location.pathname + '?get_pending_data=' + tripId + '&t=' + Date.now())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                body.innerHTML = buildEditPendingForm(data);
                initializeEditPendingForm();
            } else {
                body.innerHTML = '<div class="alert alert-error">' + data.message + '</div>';
            }
        })
        .catch(function() {
            body.innerHTML = '<div class="alert alert-error">Error loading request details. Please try again.</div>';
        });
}
function closeEditPendingModal() {
    var modal = document.getElementById('editPendingModal');
    if (modal) { modal.classList.remove('active'); document.body.style.overflow = ''; }
}

function buildEditPendingForm(data) {
    var trip = data.trip;
    return `
        <form id="editPendingForm">
            <input type="hidden" name="edit_pending_ajax" value="1">
            <input type="hidden" name="allocation_id" value="${trip.allocation_id}">
            <div style="margin-bottom:15px; padding:12px; background:#f8f9fa; border-radius:6px; font-size:0.85rem;">
                <strong>Request #:</strong> ${escapeHtml(trip.request_number)}
            </div>
            <div class="form-row-2">
                <div class="floating-group">
                    <input type="date" class="form-control-modern" placeholder=" " name="date" id="edit_pending_date" required value="${trip.date}">
                    <label for="edit_pending_date">Trip Date <span class="required">*</span></label>
                </div>
                <div class="floating-group">
                    <input type="time" class="form-control-modern" placeholder=" " name="pickup_time" id="edit_pending_pickup_time" required value="${trip.pickup_time}">
                    <label for="edit_pending_pickup_time">Pickup Time <span class="required">*</span></label>
                </div>
            </div>
            <div class="floating-group">
                <input type="time" class="form-control-modern" placeholder=" " name="dropoff_time" id="edit_pending_dropoff_time" required value="${trip.dropoff_time || ''}">
                <label for="edit_pending_dropoff_time">Dropoff Time <span class="required">*</span></label>
                <div class="dropoff-error-message" id="editPendingDropoffError">Dropoff time must be after pickup time</div>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">Update Request</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditPendingModal()">Cancel</button>
            </div>
        </form>
    `;
}

function initializeEditPendingForm() {
    var pickup = document.getElementById('edit_pending_pickup_time');
    var dropoff = document.getElementById('edit_pending_dropoff_time');
    function validate() {
        var err = document.getElementById('editPendingDropoffError');
        if (pickup && dropoff && pickup.value && dropoff.value) {
            if (dropoff.value <= pickup.value) {
                dropoff.classList.add('dropoff-error');
                if (err) err.classList.add('show');
            } else {
                dropoff.classList.remove('dropoff-error');
                if (err) err.classList.remove('show');
            }
        }
    }
    if (pickup) pickup.addEventListener('change', validate);
    if (dropoff) dropoff.addEventListener('change', validate);
    validate();

    var form = document.getElementById('editPendingForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var body = document.getElementById('editPendingBody');
            body.innerHTML = '<div class="loading-spinner">Updating request...</div>';
            fetch(window.location.pathname, { method: 'POST', body: new FormData(form) })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    body.innerHTML = data.success
                        ? '<div class="alert alert-success">' + data.message + '</div><div style="margin-top:15px;"><button type="button" class="btn btn-primary" onclick="closeEditPendingModal(); location.reload();">OK</button></div>'
                        : '<div class="alert alert-error">' + data.message + '</div>';
                })
                .catch(function() {
                    body.innerHTML = '<div class="alert alert-error">Error updating request. Please try again.</div>';
                });
        });
    }
}

// ========================================
// Reject Modal
// ========================================

function openRejectModal(allocationId) {
    var idEl = document.getElementById('rejectAllocationId');
    if (idEl) idEl.value = allocationId;
    var modal = document.getElementById('rejectModal');
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    var form = document.getElementById('rejectForm');
    if (form) form.reset();
}

function closeRejectModal() {
    var modal = document.getElementById('rejectModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

document.getElementById('rejectForm').addEventListener('submit', function(e) {
    var reasonSelect = document.getElementById('rejectReason');
    if (!reasonSelect || !reasonSelect.value) {
        return; // let native `required` validation handle it, no overlay yet
    }
    showSubmittingOverlay('Declining request and sending notification email...');
});

// ========================================
// Modal Close Handlers
// ========================================

document.addEventListener('click', function(e) {
    var approveModal = document.getElementById('approveModal');
    var rejectModal = document.getElementById('rejectModal');
    var deleteModal = document.getElementById('deleteModal');
    var completeInprogressModal = document.getElementById('completeInprogressModal');
    var cancelModal = document.getElementById('cancelModal');
    var codingWarningModal = document.getElementById('codingWarningModal');
    var editModal = document.getElementById('editModal');
    var editPendingModal = document.getElementById('editPendingModal');
    var startTripModal = document.getElementById('startTripModal');
    var approvedDriverModal = document.getElementById('approvedDriverModal');
    var outgoingDriverModal = document.getElementById('outgoingDriverModal');

    if (startTripModal && e.target === startTripModal) closeStartTripModal();    
    if (approveModal && e.target === approveModal) closeApproveModal();
    if (rejectModal && e.target === rejectModal) closeRejectModal();
    if (deleteModal && e.target === deleteModal) closeDeleteModal();
    if (completeInprogressModal && e.target === completeInprogressModal) closeCompleteInprogressModal();
    if (cancelModal && e.target === cancelModal) closeCancelModal();
    if (codingWarningModal && e.target === codingWarningModal) closeCodingWarningModal();
    if (editModal && e.target === editModal) closeEditModal();
    if (editPendingModal && e.target === editPendingModal) closeEditPendingModal();
    if (approvedDriverModal && e.target === approvedDriverModal) closeApprovedDriverModal();
    if (outgoingDriverModal && e.target === outgoingDriverModal) closeOutgoingDriverModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeApproveModal();
        closeRejectModal();
        closeDeleteModal();
        closeCompleteInprogressModal();
        closeCancelModal();
        closeCodingWarningModal();
        closeEditModal();
        closeEditPendingModal();
        closeApprovedDriverModal();
        closeOutgoingDriverModal();
        closeStartTripModal();
    }
});

// ========================================
// Initialize on DOM Ready
// ========================================

document.addEventListener('DOMContentLoaded', function() {
    // Set default date to today
    var dateInput = document.getElementById('date');
    if (dateInput && !dateInput.value) {
        var today = new Date();
        var year = today.getFullYear();
        var month = String(today.getMonth() + 1).padStart(2, '0');
        var day = String(today.getDate()).padStart(2, '0');
        dateInput.value = year + '-' + month + '-' + day;
        dateInput.min = dateInput.value;

        var startDate = document.getElementById('start_date');
        if (startDate) startDate.min = dateInput.value;
        var endDate = document.getElementById('end_date');
        if (endDate) endDate.min = dateInput.value;
    }

    // Default pickup time to now, dropoff to +1hr, if empty
    var pickupInput = document.getElementById('pickup_time');
    if (pickupInput && !pickupInput.value) {
        var now = new Date();
        var hours = String(now.getHours()).padStart(2, '0');
        var minutes = String(now.getMinutes()).padStart(2, '0');
        pickupInput.value = hours + ':' + minutes;
    }

    var dropoffInput = document.getElementById('dropoff_time');
    if (dropoffInput && !dropoffInput.value) {
        var nowPlus1 = new Date();
        nowPlus1.setHours(nowPlus1.getHours() + 1);
        var h2 = String(nowPlus1.getHours()).padStart(2, '0');
        var m2 = String(nowPlus1.getMinutes()).padStart(2, '0');
        dropoffInput.value = h2 + ':' + m2;
    }

    // Wire up date change -> refresh driver list
    if (dateInput) {
        dateInput.addEventListener('change', filterAvailableDrivers);
    }

    // Wire up pickup/dropoff time change -> validate AND refresh driver list
    // (this is the key fix: previously only validation ran, so the driver
    // dropdown never re-checked availability when only the time changed)
    if (pickupInput) {
        pickupInput.addEventListener('change', function() {
            validateDropoffTime();
            filterAvailableDrivers();
        });
    }
    if (dropoffInput) {
        dropoffInput.addEventListener('change', function() {
            validateDropoffTime();
            filterAvailableDrivers();
        });
    }

    // Initial validation + driver check on load
    validateDropoffTime();
    setTimeout(filterAvailableDrivers, 300);

    initPassengerChoices();
    updatePassengerDisplay();
    
    // Enter key for adding passengers
    var nameInput = document.getElementById('new_passenger_name');
    var contactInput = document.getElementById('new_passenger_contact');
    
    if (nameInput) {
        nameInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addPassenger();
            }
        });
    }
    
    if (contactInput) {
        contactInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addPassenger();
            }
        });
    }
    
    // Start/end date min validation for range, plus driver refresh
    var startDateInput = document.getElementById('start_date');
    if (startDateInput) {
        startDateInput.addEventListener('change', function() {
            var endDateInput = document.getElementById('end_date');
            if (endDateInput && this.value) {
                endDateInput.min = this.value;
                if (endDateInput.value && endDateInput.value < this.value) {
                    endDateInput.value = this.value;
                }
            }
            filterAvailableDrivers();
        });
    }

    var endDateInputEl = document.getElementById('end_date');
    if (endDateInputEl) {
        endDateInputEl.addEventListener('change', filterAvailableDrivers);
    }
});

// Close modal on outside click
document.addEventListener('click', function(e) {
    var editModal = document.getElementById('editModal');
    if (editModal && e.target === editModal) {
        closeEditModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditModal();
    }
});

// ========================================
// DIRECT ALLOCATION CONFIRMATION MODAL
// ========================================

function formatTimeDisplay(timeStr) {
    if (!timeStr) return '-';
    try {
        const date = new Date('2000-01-01 ' + timeStr);
        return date.toLocaleTimeString('en-US', { 
            hour: 'numeric', 
            minute: '2-digit',
            hour12: true 
        });
    } catch (e) {
        return timeStr;
    }
}

function openConfirmModal(formData) {
    document.getElementById('confirmDate').textContent = formData.date || '-';
    document.getElementById('confirmPickupTime').textContent = formData.pickup_time ? formatTimeDisplay(formData.pickup_time) : '-';
    document.getElementById('confirmDropoffTime').textContent = formData.dropoff_time ? formatTimeDisplay(formData.dropoff_time) : '-';
    document.getElementById('confirmDriver').textContent = formData.driver_name || '-';
    document.getElementById('confirmCar').textContent = formData.car_brand ? formData.car_brand + ' (' + formData.car_plate + ')' : '-';
    document.getElementById('confirmParking').textContent = formData.car_parking || 'Not specified';
    document.getElementById('confirmCoding').textContent = formData.coding_day || 'None';
    document.getElementById('confirmPickupLocation').textContent = formData.pickup_location || '-';
    document.getElementById('confirmDropoffLocation').textContent = formData.dropoff_location || '-';
    
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

// Update the allocation form submit to show confirmation first
document.getElementById('allocationForm').addEventListener('submit', function(e) {
    // Prevent default submission first
    e.preventDefault();
    
    var driverSelect = document.getElementById('driver_id');
    var selectedOption = driverSelect.options[driverSelect.selectedIndex];
    var dateInput = document.getElementById('date');
    var startDateInput = document.getElementById('start_date');
    var endDateInput = document.getElementById('end_date');
    var dateType = document.querySelector('input[name="date_type"]:checked');
    var pickupTime = document.getElementById('pickup_time');
    var dropoffTime = document.getElementById('dropoff_time');
    var pickupLocation = document.getElementById('pickup_location');
    var dropoffLocation = document.getElementById('dropoff_location');
    
    // Validate required fields
    if (!selectedOption || !selectedOption.value) {
        alert('Please select a driver.');
        return;
    }
    
    if (!dateInput || !dateInput.value) {
        alert('Please select a date.');
        return;
    }
    
    if (!pickupTime || !pickupTime.value) {
        alert('Please select a pickup time.');
        return;
    }
    
    if (!dropoffTime || !dropoffTime.value) {
        alert('Please select a dropoff time.');
        return;
    }
    
    if (dropoffTime.value <= pickupTime.value) {
        document.getElementById('dropoffError').classList.add('show');
        dropoffTime.classList.add('dropoff-error');
        return;
    }
    
    if (!pickupLocation || !pickupLocation.value) {
        alert('Please enter a pickup location.');
        return;
    }
    
    if (!dropoffLocation || !dropoffLocation.value) {
        alert('Please enter a dropoff location.');
        return;
    }
    
    // Validate passengers
    var passengerSelect = document.getElementById('passengerGrid');
    var selectedPassengerOptions = passengerSelect ? Array.from(passengerSelect.selectedOptions) : [];
    if (selectedPassengerOptions.length === 0) {
        var passengerError = document.getElementById('passengerError');
        if (passengerError) passengerError.classList.add('show');
        alert('Please select at least one passenger.');
        return;
    }
    var passengerError = document.getElementById('passengerError');
    if (passengerError) passengerError.classList.remove('show');
    
    if (dateType && dateType.value === 'range') {
            // Range mode: check coding-day + car/driver conflicts across every date
            checkRangeConflicts(
                selectedOption.value,
                startDateInput.value,
                endDateInput.value,
                pickupTime.value,
                dropoffTime.value,
                function(conflicts) {
                    if (conflicts.length > 0) {
                        renderRangeConflictList(conflicts);
                        document.getElementById('rangeConflictModal').classList.add('active');
                        document.body.style.overflow = 'hidden';
                        document.getElementById('rangeConflictProceedBtn').onclick = function() {
                            closeRangeConflictModal();
                            showDirectAllocationConfirm();
                        };
                    } else {
                        showDirectAllocationConfirm();
                    }
                }
            );
            return;
        }

    // Single date mode: existing coding-day check
    var codingDay = selectedOption.dataset.codingDay;
    if (codingDay) {
        var tripDate = new Date(dateInput.value);
        var dayOfWeek = tripDate.toLocaleDateString('en-US', { weekday: 'long' });
        if (codingDay.toLowerCase() === dayOfWeek.toLowerCase()) {
            var carData = {
                brand: selectedOption.dataset.brand,
                plate: selectedOption.dataset.plate,
                coding_day: codingDay
            };
            openCodingWarningModal(carData, dateInput.value, function() {
                showDirectAllocationConfirm();
            });
            return;
        }
    }

    // If no coding conflict, show confirmation directly
    showDirectAllocationConfirm();
    
    function showDirectAllocationConfirm() {
        // Get selected passengers
        var passengerSelect = document.getElementById('passengerGrid');
        var passengerNames = passengerSelect
            ? Array.from(passengerSelect.selectedOptions).map(function(o) { return o.textContent.trim(); })
            : [];
        
        var formData = {
            date: dateInput.value,
            pickup_time: pickupTime.value,
            dropoff_time: dropoffTime.value,
            driver_name: selectedOption.textContent.split(' - ')[0].trim(),
            car_brand: selectedOption.dataset.brand || 'N/A',
            car_plate: selectedOption.dataset.plate || 'N/A',
            car_parking: selectedOption.dataset.parking || 'Not specified',
            coding_day: selectedOption.dataset.codingDay || 'None',
            pickup_location: pickupLocation.value,
            dropoff_location: dropoffLocation.value,
            passengers: passengerNames
        };
        
        // Store reference to submit the form after confirmation
        window._confirmForm = this;
        openConfirmModal(formData);
    }
});

// Override the confirm submit button
document.getElementById('confirmSubmitBtn').addEventListener('click', function() {
    closeConfirmModal();
    showSubmittingOverlay('Creating trip(s)...');
    document.getElementById('allocationForm').submit();
});

// Close confirm modal on outside click
document.addEventListener('click', function(e) {
    var confirmModal = document.getElementById('confirmModal');
    if (confirmModal && e.target === confirmModal) {
        closeConfirmModal();
    }
});

// Close confirm modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeConfirmModal();
    }
});

// ========================================
// escapeHtml utility
// ========================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ========================================
// Close Approve (Review) Modal  <-- THE MAIN FIX
// ========================================
function closeApproveModal() {
    var modal = document.getElementById('approveModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// ========================================
// Coding Warning Modal
// ========================================
var codingPendingData = null;

function openCodingWarningModal(carData, date, callback) {
    document.getElementById('codingCarDisplay').textContent = carData.brand;
    document.getElementById('codingPlateDisplay').textContent = carData.plate;
    document.getElementById('codingDayDisplay2').textContent = carData.coding_day;
    document.getElementById('codingDateDisplay').textContent = date;
    document.getElementById('codingDayDisplay').textContent = carData.coding_day;
    codingPendingData = callback;
    document.getElementById('codingWarningModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeCodingWarningModal() {
    document.getElementById('codingWarningModal').classList.remove('active');
    document.body.style.overflow = '';
    codingPendingData = null;
}

function closeRangeConflictModal() {
    document.getElementById('rangeConflictModal').classList.remove('active');
    document.body.style.overflow = '';
    rangeConflictPendingCallback = null;
}

var rangeConflictPendingCallback = null;

function renderRangeConflictList(conflicts) {
    var labels = { coding: 'Coding day', car_busy: 'Car busy', driver_busy: 'Driver busy' };
    var html = conflicts.map(function(c) {
        var issueText = c.issues.map(function(i) { return labels[i] || i; }).join(', ');
        var d = new Date(c.date + 'T00:00:00');
        var display = d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
        return '<div style="padding:8px 0; border-bottom:1px solid #ffe0b2;">' +
            '<strong>' + display + '</strong>' +
            '<span style="color:#c62828; margin-left:8px; font-size:0.85rem;">' + issueText + '</span>' +
            '</div>';
    }).join('');
    document.getElementById('rangeConflictList').innerHTML = html || '<p>No conflicts found.</p>';
}

function checkRangeConflicts(driverId, startDate, endDate, pickupTime, dropoffTime, onComplete) {
    var url = '/admin/api/check_range_conflicts.php?driver_id=' + encodeURIComponent(driverId) +
        '&start_date=' + encodeURIComponent(startDate) +
        '&end_date=' + encodeURIComponent(endDate) +
        '&pickup_time=' + encodeURIComponent(pickupTime) +
        '&dropoff_time=' + encodeURIComponent(dropoffTime || '');

    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                onComplete(data.conflicts || []);
            } else {
                console.error(data.message);
                onComplete([]); // fail open — don't block submission on a check error
            }
        })
        .catch(function(err) {
            console.error('Range conflict check failed:', err);
            onComplete([]);
        });
}

document.getElementById('codingProceedBtn').addEventListener('click', function() {
    if (typeof codingPendingData === 'function') codingPendingData();
    closeCodingWarningModal();
});

// ========================================
// Edit Pending Request Modal
// ========================================
function openEditPendingModal(tripId) {
    var modal = document.getElementById('editPendingModal');
    var body = document.getElementById('editPendingBody');
    body.innerHTML = '<div class="loading-spinner">Loading request details...</div>';
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    fetch(window.location.pathname + '?get_pending_data=' + tripId + '&t=' + Date.now())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                body.innerHTML = buildEditPendingForm(data);
                initializeEditPendingForm();
            } else {
                body.innerHTML = '<div class="alert alert-error">' + data.message + '</div>';
            }
        })
        .catch(function() {
            body.innerHTML = '<div class="alert alert-error">Error loading request details. Please try again.</div>';
        });
}
function closeEditPendingModal() {
    var modal = document.getElementById('editPendingModal');
    if (modal) { modal.classList.remove('active'); document.body.style.overflow = ''; }
}

function buildEditPendingForm(data) {
    var trip = data.trip;
    return `
        <form id="editPendingForm">
            <input type="hidden" name="edit_pending_ajax" value="1">
            <input type="hidden" name="allocation_id" value="${trip.allocation_id}">
            <div style="margin-bottom:15px; padding:12px; background:#f8f9fa; border-radius:6px; font-size:0.85rem;">
                <strong>Request #:</strong> ${escapeHtml(trip.request_number)}
            </div>
            <div class="form-row-2">
                <div class="floating-group">
                    <input type="date" class="form-control-modern" placeholder=" " name="date" id="edit_pending_date" required value="${trip.date}">
                    <label for="edit_pending_date">Trip Date <span class="required">*</span></label>
                </div>
                <div class="floating-group">
                    <input type="time" class="form-control-modern" placeholder=" " name="pickup_time" id="edit_pending_pickup_time" required value="${trip.pickup_time}">
                    <label for="edit_pending_pickup_time">Pickup Time <span class="required">*</span></label>
                </div>
            </div>
            <div class="floating-group">
                <input type="time" class="form-control-modern" placeholder=" " name="dropoff_time" id="edit_pending_dropoff_time" required value="${trip.dropoff_time || ''}">
                <label for="edit_pending_dropoff_time">Dropoff Time <span class="required">*</span></label>
                <div class="dropoff-error-message" id="editPendingDropoffError">Dropoff time must be after pickup time</div>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">Update Request</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditPendingModal()">Cancel</button>
            </div>
        </form>
    `;
}

function initializeEditPendingForm() {
    var pickup = document.getElementById('edit_pending_pickup_time');
    var dropoff = document.getElementById('edit_pending_dropoff_time');
    function validate() {
        var err = document.getElementById('editPendingDropoffError');
        if (pickup && dropoff && pickup.value && dropoff.value) {
            if (dropoff.value <= pickup.value) {
                dropoff.classList.add('dropoff-error');
                if (err) err.classList.add('show');
            } else {
                dropoff.classList.remove('dropoff-error');
                if (err) err.classList.remove('show');
            }
        }
    }
    if (pickup) pickup.addEventListener('change', validate);
    if (dropoff) dropoff.addEventListener('change', validate);
    validate();

    var form = document.getElementById('editPendingForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var body = document.getElementById('editPendingBody');
            body.innerHTML = '<div class="loading-spinner">Updating request...</div>';
            fetch(window.location.pathname, { method: 'POST', body: new FormData(form) })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    body.innerHTML = data.success
                        ? '<div class="alert alert-success">' + data.message + '</div><div style="margin-top:15px;"><button type="button" class="btn btn-primary" onclick="closeEditPendingModal(); location.reload();">OK</button></div>'
                        : '<div class="alert alert-error">' + data.message + '</div>';
                })
                .catch(function() {
                    body.innerHTML = '<div class="alert alert-error">Error updating request. Please try again.</div>';
                });
        });
    }
}

// ========================================
// Delete Modal (Approved trips)
// ========================================
function openDeleteModal(tripId, type, carBrand, tripDate) {
    var modal = document.getElementById('deleteModal');
    document.getElementById('deleteModalTitle').textContent = 'Delete Approved Trip';
    document.getElementById('deleteModalMessage').textContent = 'Are you sure you want to delete this approved trip?';
    document.getElementById('confirmDeleteBtn').href = '?delete_approved=' + tripId + '&tab=approved';
    document.getElementById('modalTripInfo').textContent = carBrand + ' - ' + tripDate;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeDeleteModal() {
    var modal = document.getElementById('deleteModal');
    if (modal) { modal.classList.remove('active'); document.body.style.overflow = ''; }
}

// ========================================
// Complete In Progress Modal
// ========================================
function openCompleteInprogressModal(tripId, carBrand, driverName, tripDate, pickupTime) {
    var modal = document.getElementById('completeInprogressModal');
    var confirmBtn = document.getElementById('confirmCompleteInprogressBtn');
    document.getElementById('completeInprogressCar').textContent = carBrand;
    document.getElementById('completeInprogressDriver').textContent = driverName;
    document.getElementById('completeInprogressDate').textContent = tripDate;
    document.getElementById('completeInprogressPickup').textContent = pickupTime;
    confirmBtn.onclick = function(e) {
        e.preventDefault();
        completeInprogressTrip(tripId);
    };
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}
function completeInprogressTrip(tripId) {
    var confirmBtn = document.getElementById('confirmCompleteInprogressBtn');
    var originalText = confirmBtn.innerHTML;
    confirmBtn.innerHTML = 'Completing...';
    confirmBtn.style.pointerEvents = 'none';

    var formData = new FormData();
    formData.append('complete_inprogress_ajax', '1');
    formData.append('allocation_id', tripId);

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            confirmBtn.innerHTML = originalText;
            confirmBtn.style.pointerEvents = '';
            closeCompleteInprogressModal();
            if (data.success) {
                refreshOpenOutgoingDriverModal();
            } else {
                alert(data.message || 'Failed to complete trip.');
            }
        })
        .catch(function() {
            confirmBtn.innerHTML = originalText;
            confirmBtn.style.pointerEvents = '';
            closeCompleteInprogressModal();
            alert('Error completing trip. Please try again.');
        });
}
function closeCompleteInprogressModal() {
    var modal = document.getElementById('completeInprogressModal');
    if (modal) { modal.classList.remove('active'); document.body.style.overflow = ''; }
}

function startTripAjax(tripId) {
    var confirmBtn = document.getElementById('confirmStartTripBtn');
    var originalText = confirmBtn.innerHTML;
    confirmBtn.innerHTML = 'Starting...';
    confirmBtn.style.pointerEvents = 'none';

    var formData = new FormData();
    formData.append('start_trip_ajax', '1');
    formData.append('allocation_id', tripId);

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            confirmBtn.innerHTML = originalText;
            confirmBtn.style.pointerEvents = '';
            closeStartTripModal();
            if (data.success) {
                refreshOpenApprovedDriverModal();
            } else {
                alert(data.message || 'Failed to start trip.');
            }
        })
        .catch(function() {
            confirmBtn.innerHTML = originalText;
            confirmBtn.style.pointerEvents = '';
            closeStartTripModal();
            alert('Error starting trip. Please try again.');
        });
}

// ========================================
// Cancel Trip Modal
// ========================================
function openCancelModal(tripId, type, carBrand, driverName, tripDate, pickupTime) {
    var modal = document.getElementById('cancelModal');
    document.getElementById('cancelCar').textContent = carBrand;
    document.getElementById('cancelDriver').textContent = driverName;
    document.getElementById('cancelDate').textContent = tripDate;
    document.getElementById('cancelPickup').textContent = pickupTime;
    var confirmBtn = document.getElementById('confirmCancelBtn');
    confirmBtn.href = (type === 'inprogress')
        ? '?cancel_inprogress=' + tripId + '&tab=outgoing'
        : '?cancel_approved=' + tripId + '&tab=approved';
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeCancelModal() {
    var modal = document.getElementById('cancelModal');
    if (modal) { modal.classList.remove('active'); document.body.style.overflow = ''; }
}
document.getElementById('confirmCancelBtn').addEventListener('click', function(e) {
    var href = this.getAttribute('href') || '';
    if (href.indexOf('cancel_approved') !== -1 || href.indexOf('cancel_inprogress') !== -1) {
        showSubmittingOverlay('Cancelling trip and sending notification email...');
    }
});

// ========================================
// Approved Trips - Driver Modal (data table)
// ========================================

function makeCell(html) {
    var td = document.createElement('td');
    td.style.padding = '6px 8px';
    td.style.borderBottom = '1px solid #f1f3f5';
    td.innerHTML = html;
    return td;
}

function formatDateDisplay(dateStr) {
    if (!dateStr) return '-';
    var d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
}

function refreshOpenApprovedDriverModal() {
    var dateInput = document.getElementById('approved_date_input');
    var date = dateInput ? dateInput.value : '';
    var driverId = window._openApprovedDriverId;

    refreshApprovedDriverList();

    if (!driverId) return;

    fetch(window.location.pathname + '?ajax=1&tab=approved&format=json&approved_date=' + encodeURIComponent(date) + '&t=' + Date.now())
        .then(function(r) { return r.json(); })
        .then(function(drivers) {
            var match = drivers.find(function(d) { return String(d.driver_id) === String(driverId); });
            if (match) {
                openApprovedDriverModal(match);
            } else {
                closeApprovedDriverModal();
            }
        })
        .catch(function() {
            console.error('Failed to refresh driver trip modal.');
        });
}

// ========================================
// Approved Trips - Driver Modal (data table)
// ========================================
 
function openApprovedDriverModal(data) {
    window._openApprovedDriverId = data.driver_id;
 
    // Destroy any existing DataTable instance FIRST, before touching tbody.
    // destroy() reverts the table's DOM to its very first init-time snapshot -
    // if we destroy AFTER inserting fresh rows, it wipes them out and reverts
    // to stale data. Destroying first means there's nothing left to revert.
    if (approvedModalTable) {
        try {
            if ($.fn.DataTable.isDataTable('#approvedDriverModalTable')) {
                approvedModalTable.destroy();
            }
        } catch (e) {
            console.log('Error destroying approved modal table:', e);
        }
        approvedModalTable = null;
    }
 
    document.getElementById('approvedDriverModalTitle').textContent = data.driver_name + ' — Approved Trips';
 
    var subtitleParts = [];
    subtitleParts.push('<span class="driver-modal-count">' + data.trips.length + ' trip' + (data.trips.length !== 1 ? 's' : '') + '</span>');
    subtitleParts.push('<span style="color:#343434; font-weight:700;">' + escapeHtml(data.car_brand) + ' (' + escapeHtml(data.car_plate) + ')</span>');
    if (data.driver_mobile) subtitleParts.push('<span style="color:#2e7d32; font-weight:700;">' + escapeHtml(data.driver_mobile) + '</span>');
    document.getElementById('approvedDriverModalSubtitle').innerHTML = subtitleParts.join(' &middot; ');
 
    var tbody = document.getElementById('approvedDriverModalBody');
    tbody.innerHTML = '';
 
    data.trips.forEach(function (t) {
        var tr = document.createElement('tr');
 
        // Requestor
        tr.appendChild(makeCell(escapeHtml(t.requestor) + '<br><span class="text-muted" style="font-size:0.65rem;">' + escapeHtml(t.requestor_email || '') + '</span>'));
 
        // Date & Time - Add data-sort attribute for proper sorting
        var dateCell = document.createElement('td');
        dateCell.style.padding = '6px 8px';
        dateCell.style.borderBottom = '1px solid #f1f3f5';
        // Use the raw date + time for sorting (YYYY-MM-DD HH:MM format)
        dateCell.setAttribute('data-sort', t.date + ' ' + t.pickup_time);
        dateCell.innerHTML = formatDateDisplay(t.date) + '<br><span class="text-muted" style="font-size:0.7rem;">' +
            formatTimeDisplay(t.pickup_time) + (t.dropoff_time ? ' – ' + formatTimeDisplay(t.dropoff_time) : '') + '</span>';
        tr.appendChild(dateCell);
 
        // Car
        tr.appendChild(makeCell(escapeHtml(t.brand) + '<br><span class="text-muted" style="font-size:0.65rem;">' + escapeHtml(t.plate_number) + '</span>'));
 
        // Route
        tr.appendChild(makeCell(escapeHtml(t.pickup_location) + '<span style="color:#adb5bd;"> → </span>' + escapeHtml(t.dropoff_location || '-')));
 
        // Passengers
        var passengersHtml = (t.passengers && t.passengers.length > 0)
            ? t.passengers.map(function (p) { return '<span class="passenger-tag" style="font-size:0.65rem;">' + escapeHtml(p.passenger_name) + '</span>'; }).join('')
            : '<span class="text-muted" style="font-size:0.65rem;">None</span>';
        tr.appendChild(makeCell(passengersHtml));
 
        // Remarks
        var remarksHtml = t.remarks || '-';
        if (remarksHtml !== '-') {
            var purpose = '';
            var travelType = '';
            if (remarksHtml.includes('Purpose:')) {
                var purposeMatch = remarksHtml.match(/Purpose:\s*([^|]+)/i);
                if (purposeMatch) purpose = purposeMatch[1].trim();
            }
            if (remarksHtml.includes('Travel Type:')) {
                var travelMatch = remarksHtml.match(/Travel Type:\s*([^|]+)/i);
                if (travelMatch) travelType = travelMatch[1].trim();
            }
            if (purpose || travelType) {
                remarksHtml = '';
                if (travelType) remarksHtml += '<span style="font-size:0.6rem; color:#6c757d; text-transform:uppercase; letter-spacing:0.3px;">' + escapeHtml(travelType) + '</span><br>';
                if (purpose) remarksHtml += '<span style="font-size:0.7rem;">' + escapeHtml(purpose) + '</span>';
                if (!purpose && !travelType) remarksHtml = escapeHtml(t.remarks);
            } else {
                remarksHtml = escapeHtml(remarksHtml);
            }
        }
        tr.appendChild(makeCell(remarksHtml));
 
        // Actions
        var actionTd = document.createElement('td');
        actionTd.style.padding = '8px 10px';
        actionTd.style.borderBottom = '1px solid #f1f3f5';
        var actionsDiv = document.createElement('div');
        actionsDiv.className = 'action-buttons-lg';
 
        var startBtn = document.createElement('button');
        startBtn.type = 'button';
        startBtn.className = 'btn btn-primary';
        startBtn.innerHTML = '<i class="fas fa-play"></i> Start';
        var canStart = t.startability && t.startability.startable;
        if (canStart) {
            startBtn.style.background = '#1a237e';
            startBtn.addEventListener('click', function () {
                openStartTripModal(
                    t.allocation_id,
                    t.requestor,
                    t.brand,
                    t.plate_number,
                    t.driver_name,
                    formatTimeDisplay(t.pickup_time)
                );
            });
        } else {
            startBtn.disabled = true;
            startBtn.style.opacity = '0.5';
            startBtn.style.cursor = 'not-allowed';
            startBtn.title = t.startability ? t.startability.reason : 'Cannot start this trip right now.';
        }
 
        var editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'btn btn-primary';
        editBtn.innerHTML = '<i class="fas fa-edit"></i> Edit';
        editBtn.addEventListener('click', function () { openEditModal(t.allocation_id); });
 
        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn btn-warning';
        cancelBtn.style.background = '#f57c00';
        cancelBtn.style.color = 'white';
        cancelBtn.innerHTML = '<i class="fas fa-times"></i> Cancel';
        cancelBtn.addEventListener('click', function () {
            openCancelModal(t.allocation_id, 'approved', t.brand, t.driver_name, formatDateDisplay(t.date), formatTimeDisplay(t.pickup_time));
        });
 
        actionsDiv.appendChild(startBtn);
        actionsDiv.appendChild(editBtn);
        actionsDiv.appendChild(cancelBtn);
        actionTd.appendChild(actionsDiv);
        tr.appendChild(actionTd);
        tbody.appendChild(tr);
    });
 
    // Initialize DataTable after rendering
    setTimeout(function() {
        initApprovedModalTable();
    }, 200);
 
    document.getElementById('approvedDriverModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeApprovedDriverModal() {
    var modal = document.getElementById('approvedDriverModal');
    if (!modal) return;
    
    // Destroy DataTable instance first
    if (approvedModalTable) {
        try {
            // Check if DataTable still exists
            if ($.fn.DataTable.isDataTable('#approvedDriverModalTable')) {
                approvedModalTable.destroy(false);
            }
        } catch(e) {
            console.log('Error destroying approved modal table:', e);
        }
        approvedModalTable = null;
    }
    
    // Reset the table to its original state
    var table = document.getElementById('approvedDriverModalTable');
    if (table) {
        // Remove DataTable wrapper if it exists
        var wrapper = table.closest('.dataTables_wrapper');
        if (wrapper && wrapper.parentNode) {
            wrapper.parentNode.insertBefore(table, wrapper);
            wrapper.parentNode.removeChild(wrapper);
        }
        
        // Clean up DataTable-added elements
        table.classList.remove('dataTable', 'no-footer');
        table.removeAttribute('role');
        table.removeAttribute('aria-describedby');
        table.style.width = '100%';
        
        // Remove sorting classes from header cells
        var headers = table.querySelectorAll('th');
        headers.forEach(function(th) {
            th.classList.remove('sorting', 'sorting_asc', 'sorting_desc', 'sorting_disabled');
            th.removeAttribute('aria-label');
            th.removeAttribute('aria-sort');
            th.removeAttribute('tabindex');
            th.removeAttribute('rowspan');
            th.removeAttribute('colspan');
        });
    }
    
    // Clear the table body content
    var tbody = document.getElementById('approvedDriverModalBody');
    if (tbody) {
        tbody.innerHTML = '';
    }
    
    // Remove any leftover DataTable elements
    var dtElements = modal.querySelectorAll('.dataTables_length, .dataTables_filter, .dataTables_info, .dataTables_paginate');
    dtElements.forEach(function(el) {
        if (el.parentNode) {
            el.parentNode.removeChild(el);
        }
    });
    
    // Hide the modal
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

// ========================================
// Outgoing Trips - Driver Modal (data table)
// ========================================

function getStatusBadgeClassJs(status) {
    var map = { pending: 'badge-pending', approved: 'badge-approved', in_progress: 'badge-in_progress', completed: 'badge-completed' };
    return map[status] || '';
}
function getStatusDisplayJs(status) {
    var map = { pending: 'Pending', approved: 'Approved', in_progress: 'In Progress', completed: 'Completed' };
    return map[status] || status;
}

function openOutgoingDriverModal(data) {
    window._openOutgoingDriverId = data.driver_id;
     if (outgoingModalTable) {
        try {
            if ($.fn.DataTable.isDataTable('#outgoingDriverModalTable')) {
                outgoingModalTable.destroy();
            }
        } catch (e) {
            console.log('Error destroying outgoing modal table:', e);
        }
        outgoingModalTable = null;
    }
    document.getElementById('outgoingDriverModalTitle').textContent = data.driver_name + ' — Trip Details';

    var subtitleParts = [];
    subtitleParts.push('<span class="driver-modal-count">' + data.trips.length + ' trip' + (data.trips.length !== 1 ? 's' : '') + '</span>');
    subtitleParts.push('<span style="color:#343434; font-weight:700;">' + escapeHtml(data.car_brand) + ' (' + escapeHtml(data.car_plate) + ')</span>');
    if (data.driver_mobile) subtitleParts.push('<span style="color:#2e7d32; font-weight:700;">' + escapeHtml(data.driver_mobile) + '</span>');
    document.getElementById('outgoingDriverModalSubtitle').innerHTML = subtitleParts.join(' &middot; ');

    var tbody = document.getElementById('outgoingDriverModalBody');
    tbody.innerHTML = '';

    data.trips.forEach(function (t) {
        var tr = document.createElement('tr');

        // Requestor
        tr.appendChild(makeCell(escapeHtml(t.requestor) + '<br><span class="text-muted" style="font-size:0.65rem;">#' + escapeHtml(t.request_number) + '</span>'));

        // Date & Time (scheduled) - Add data-sort attribute for proper sorting
        var dateCell = document.createElement('td');
        dateCell.style.padding = '6px 8px';
        dateCell.style.borderBottom = '1px solid #f1f3f5';
        // Use the raw date + time for sorting (YYYY-MM-DD HH:MM format)
        dateCell.setAttribute('data-sort', t.date + ' ' + t.pickup_time);
        
        var dateTimeHtml = formatDateDisplay(t.date) + '<br><span class="text-muted" style="font-size:0.7rem;">' +
            formatTimeDisplay(t.pickup_time) + (t.dropoff_time ? ' – ' + formatTimeDisplay(t.dropoff_time) : '') + '</span>';

        if (t.status === 'completed' && (t.actual_pickup_time || t.actual_dropoff_time)) {
            dateTimeHtml += '<br><span style="font-size:0.65rem; color:#2e7d32;">Actual: ' +
                (t.actual_pickup_time ? formatTimeDisplay(t.actual_pickup_time) : '-') +
                ' – ' +
                (t.actual_dropoff_time ? formatTimeDisplay(t.actual_dropoff_time) : '-') +
                '</span>';
        }
        
        dateCell.innerHTML = dateTimeHtml;
        tr.appendChild(dateCell);

        // Car
        tr.appendChild(makeCell(escapeHtml(t.brand) + '<br><span class="text-muted" style="font-size:0.65rem;">' + escapeHtml(t.plate_number) + '</span>'));

        // Route
        tr.appendChild(makeCell(escapeHtml(t.pickup_location) + '<span style="color:#adb5bd;"> → </span>' + escapeHtml(t.dropoff_location || '-')));

        // Passengers
        var passengersHtml = (t.passengers && t.passengers.length > 0)
            ? t.passengers.map(function (p) { return '<span class="passenger-tag" style="font-size:0.65rem;">' + escapeHtml(p.passenger_name) + '</span>'; }).join('')
            : '<span class="text-muted" style="font-size:0.65rem;">None</span>';
        tr.appendChild(makeCell(passengersHtml));

        // Status
        tr.appendChild(makeCell('<span class="badge ' + getStatusBadgeClassJs(t.status) + '">' + getStatusDisplayJs(t.status) + '</span>'));

        // Actions
        var actionTd = document.createElement('td');
        actionTd.style.padding = '8px 10px';
        actionTd.style.borderBottom = '1px solid #f1f3f5';
        var actionsDiv = document.createElement('div');
        actionsDiv.className = 'action-buttons-lg';

        if (t.status === 'in_progress') {
            var completeBtn = document.createElement('button');
            completeBtn.type = 'button';
            completeBtn.className = 'btn btn-success';
            completeBtn.innerHTML = '<i class="fas fa-check"></i> Complete';
            completeBtn.addEventListener('click', function () {
                openCompleteInprogressModal(t.allocation_id, t.brand, t.driver_name, formatDateDisplay(t.date), formatTimeDisplay(t.pickup_time));
            });

            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'btn btn-warning';
            cancelBtn.style.background = '#f57c00';
            cancelBtn.style.color = 'white';
            cancelBtn.innerHTML = '<i class="fas fa-times"></i> Cancel';
            cancelBtn.addEventListener('click', function () {
                openCancelModal(t.allocation_id, 'inprogress', t.brand, t.driver_name, formatDateDisplay(t.date), formatTimeDisplay(t.pickup_time));
            });

            actionsDiv.appendChild(completeBtn);
            actionsDiv.appendChild(cancelBtn);
        } else if (t.status === 'pending') {
            var span = document.createElement('span');
            span.className = 'text-muted';
            span.style.fontSize = '0.8rem';
            span.textContent = 'Awaiting approval';
            actionsDiv.appendChild(span);
        } else if (t.status === 'completed') {
            var span = document.createElement('span');
            span.className = 'text-muted';
            span.style.fontSize = '0.8rem';
            span.textContent = 'Completed';
            actionsDiv.appendChild(span);
        }

        actionTd.appendChild(actionsDiv);
        tr.appendChild(actionTd);
        tbody.appendChild(tr);
    });

    // Initialize DataTable after rendering
    setTimeout(function() {
        initOutgoingModalTable();
    }, 200);

    document.getElementById('outgoingDriverModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeOutgoingDriverModal() {
    var modal = document.getElementById('outgoingDriverModal');
    if (!modal) return;
    
    // Destroy DataTable instance first
    if (outgoingModalTable) {
        try {
            // Check if DataTable still exists
            if ($.fn.DataTable.isDataTable('#outgoingDriverModalTable')) {
                outgoingModalTable.destroy(false);
            }
        } catch(e) {
            console.log('Error destroying outgoing modal table:', e);
        }
        outgoingModalTable = null;
    }
    
    // Reset the table to its original state
    var table = document.getElementById('outgoingDriverModalTable');
    if (table) {
        // Remove DataTable wrapper if it exists
        var wrapper = table.closest('.dataTables_wrapper');
        if (wrapper && wrapper.parentNode) {
            wrapper.parentNode.insertBefore(table, wrapper);
            wrapper.parentNode.removeChild(wrapper);
        }
        
        // Clean up DataTable-added elements
        table.classList.remove('dataTable', 'no-footer');
        table.removeAttribute('role');
        table.removeAttribute('aria-describedby');
        table.style.width = '100%';
        
        // Remove sorting classes from header cells
        var headers = table.querySelectorAll('th');
        headers.forEach(function(th) {
            th.classList.remove('sorting', 'sorting_asc', 'sorting_desc', 'sorting_disabled');
            th.removeAttribute('aria-label');
            th.removeAttribute('aria-sort');
            th.removeAttribute('tabindex');
            th.removeAttribute('rowspan');
            th.removeAttribute('colspan');
        });
    }
    
    // Clear the table body content
    var tbody = document.getElementById('outgoingDriverModalBody');
    if (tbody) {
        tbody.innerHTML = '';
    }
    
    // Remove any leftover DataTable elements
    var dtElements = modal.querySelectorAll('.dataTables_length, .dataTables_filter, .dataTables_info, .dataTables_paginate');
    dtElements.forEach(function(el) {
        if (el.parentNode) {
            el.parentNode.removeChild(el);
        }
    });
    
    // Hide the modal
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

// ========================================
// INIT DATATABLE FOR MODALS 
// ========================================

let approvedModalTable = null;
let outgoingModalTable = null;

// ========================================
// INIT DATATABLE - Approved Modal
// ========================================
 
function initApprovedModalTable() {
    var table = document.getElementById('approvedDriverModalTable');
    if (!table) return;
 
    // Safety net - should already be destroyed by openApprovedDriverModal,
    // but guards against any other call path that skips that step.
    if ($.fn.DataTable.isDataTable('#approvedDriverModalTable')) {
        $('#approvedDriverModalTable').DataTable().destroy();
        approvedModalTable = null;
    }
 
    var tbody = document.getElementById('approvedDriverModalBody');
    var rows = tbody ? tbody.querySelectorAll('tr') : [];
    var dataRows = Array.from(rows).filter(function(row) {
        return !row.querySelector('td[colspan]');
    });
 
    if (dataRows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:20px; color:#6c757d;">No approved trips found for this driver.</td></tr>';
        return;
    }
 
    approvedModalTable = $('#approvedDriverModalTable').DataTable({
        pageLength: 5,
        lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, "All"]],
        order: [[1, 'asc']],
        scrollY: '50vh',        // only this region scrolls
        scrollCollapse: true,   // shrinks if there are fewer rows than 50vh
        columnDefs: [
            { orderable: false, targets: [4, 5, 6] }
        ],
        language: {
            search: "Search:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ trips",
            infoEmpty: "No trips found",
            infoFiltered: "(filtered from _MAX_ total trips)",
            zeroRecords: "No matching trips found",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        dom: '<"dt-top"lf>t<"dt-bottom"ip>',
        classes: {
            sWrapper: 'dataTables_wrapper dt-custom-requests'
        }
    });
}

function initOutgoingModalTable() {
    var table = document.getElementById('outgoingDriverModalTable');
    if (!table) return;

    if ($.fn.DataTable.isDataTable('#outgoingDriverModalTable')) {
        $('#outgoingDriverModalTable').DataTable().destroy();
        outgoingModalTable = null;
    }

    var tbody = document.getElementById('outgoingDriverModalBody');
    var rows = tbody ? tbody.querySelectorAll('tr') : [];
    var dataRows = Array.from(rows).filter(function(row) {
        return !row.querySelector('td[colspan]');
    });

    if (dataRows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:20px; color:#6c757d;">No trips found for this driver.</td></tr>';
        return;
    }

    $.fn.dataTable.ext.type.order['status-sort-pre'] = function(data) {
        var tempDiv = document.createElement('div');
        tempDiv.innerHTML = data;
        var text = (tempDiv.textContent || tempDiv.innerText || '').trim().toLowerCase();
        switch (text) {
            case 'in progress': return 1;
            case 'pending': return 2;
            case 'approved': return 3;
            case 'completed': return 4;
            default: return 5;
        }
    };

    outgoingModalTable = $('#outgoingDriverModalTable').DataTable({
        pageLength: 5,
        lengthMenu: [[5, 10, 50, -1], [5, 10, 25, 50, "All"]],
        order: [[5, 'asc'], [1, 'asc']],
        scrollY: '50vh',        // only this region scrolls
        scrollCollapse: true,
        responsive: true,
        scrollx: true,
        columnDefs: [
            { targets: 1, orderDataType: 'dom-text', type: 'date' },
            { targets: 5, type: 'status-sort' },
            { orderable: false, targets: [4, 6] }
        ],
        language: {
            search: "Search:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ trips",
            infoEmpty: "No trips found",
            infoFiltered: "(filtered from _MAX_ total trips)",
            zeroRecords: "No matching trips found",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        dom: '<"dt-top"lf>t<"dt-bottom"ip>',
        classes: {
            sWrapper: 'dataTables_wrapper dt-custom-requests'
        }
    });
}

// ========================================
// Date Navigation Functions
// ========================================

function changeApprovedDate(days) {
    var dateInput = document.getElementById('approved_date_input');
    if (!dateInput) return;
    
    var currentDate = new Date(dateInput.value + 'T00:00:00');
    currentDate.setDate(currentDate.getDate() + days);
    
    var year = currentDate.getFullYear();
    var month = String(currentDate.getMonth() + 1).padStart(2, '0');
    var day = String(currentDate.getDate()).padStart(2, '0');
    var newDate = year + '-' + month + '-' + day;
    
    dateInput.value = newDate;
    document.getElementById('approvedDateForm').submit();
}

function changeOutgoingDate(days) {
    var dateInput = document.getElementById('outgoing_date_input');
    if (!dateInput) return;
    
    var currentDate = new Date(dateInput.value + 'T00:00:00');
    currentDate.setDate(currentDate.getDate() + days);
    
    var year = currentDate.getFullYear();
    var month = String(currentDate.getMonth() + 1).padStart(2, '0');
    var day = String(currentDate.getDate()).padStart(2, '0');
    var newDate = year + '-' + month + '-' + day;
    
    dateInput.value = newDate;
    document.getElementById('outgoingDateForm').submit();
}

// ========================================
// Pending Requests DataTable
// ========================================

function initPendingTable() {
    var table = document.getElementById('pendingTable');
    if (!table) return;
    
    // Check if there's any data
    var tbody = document.getElementById('pendingTable').querySelector('tbody');
    if (!tbody || tbody.children.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:20px; color:#6c757d;">No pending requests found.</td></tr>';
    }
    
    // Destroy existing DataTable if it exists
    if ($.fn.DataTable.isDataTable('#pendingTable')) {
        $('#pendingTable').DataTable().destroy();
    }
    
    $('#pendingTable').DataTable({
        pageLength: 10,
        lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, "All"]],
        order: [[3, 'asc'], [4, 'asc']], // Sort by Date column (index 3) descending
        responsive: true,
        columnDefs: [
            { orderable: false, targets: [9] } // Disable sorting on Action column (index 9)
        ],
        language: {
            search: "Search:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ requests",
            infoEmpty: "No requests found",
            infoFiltered: "(filtered from _MAX_ total requests)",
            zeroRecords: "No matching requests found",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        dom: '<"dt-top"lf>t<"dt-bottom"ip>',
        classes: {
            sWrapper: 'dataTables_wrapper dt-custom-requests'
        }
    });
}

// Call it when DOM is ready
$(document).ready(function() {
    initPendingTable();
});

// ========================================
// Start Trip Modal
// ========================================
function openStartTripModal(tripId, requestor, carBrand, carPlate, driverName, pickupTime) {
    var confirmStartBtn = document.getElementById('confirmStartTripBtn');
    document.getElementById('startTripRequestor').textContent = requestor;
    document.getElementById('startTripCar').textContent = carBrand + ' (' + carPlate + ')';
    document.getElementById('startTripDriver').textContent = driverName;
    document.getElementById('startTripPickup').textContent = pickupTime;
    confirmStartBtn.onclick = function(e) {
        e.preventDefault();
        startTripAjax(tripId);
    };
    document.getElementById('startTripModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeStartTripModal() {
    var modal = document.getElementById('startTripModal');
    if (modal) { modal.classList.remove('active'); document.body.style.overflow = ''; }
}

// ========================================
// Submitting Overlay
// ========================================
function showSubmittingOverlay(subtitle) {
    var overlay = document.getElementById('submittingOverlay');
    var subtitleEl = document.getElementById('submittingSubtitle');
    if (subtitleEl) subtitleEl.textContent = subtitle || 'Sending email, please wait.';
    if (overlay) overlay.classList.add('active');
}

// ========================================
// Refreshed Approved List 
// ========================================
function refreshApprovedDriverList() {
    var dateInput = document.getElementById('approved_date_input');
    var date = dateInput ? dateInput.value : '';
    fetch(window.location.pathname + '?ajax=1&tab=approved&approved_date=' + encodeURIComponent(date) + '&t=' + Date.now())
        .then(function(r) { return r.text(); })
        .then(function(html) {
            var list = document.getElementById('approvedDriverList');
            if (list) list.innerHTML = html;
        })
        .catch(function() {
            console.error('Failed to refresh approved driver list.');
        });
}

function refreshOutgoingDriverList() {
    var dateInput = document.getElementById('outgoing_date_input');
    var date = dateInput ? dateInput.value : '';
    fetch(window.location.pathname + '?ajax=1&tab=outgoing&outgoing_date=' + encodeURIComponent(date) + '&t=' + Date.now())
        .then(function(r) { return r.text(); })
        .then(function(html) {
            var list = document.getElementById('outgoingDriverList');
            if (list) list.innerHTML = html;
        })
        .catch(function() {
            console.error('Failed to refresh outgoing driver list.');
        });
}

function refreshOpenOutgoingDriverModal() {
    var dateInput = document.getElementById('outgoing_date_input');
    var date = dateInput ? dateInput.value : '';
    var driverId = window._openOutgoingDriverId;

    refreshOutgoingDriverList();

    if (!driverId) return;

    fetch(window.location.pathname + '?ajax=1&tab=outgoing&format=json&outgoing_date=' + encodeURIComponent(date) + '&t=' + Date.now())
        .then(function(r) { return r.json(); })
        .then(function(drivers) {
            var match = drivers.find(function(d) { return String(d.driver_id) === String(driverId); });
            if (match) {
                openOutgoingDriverModal(match);
            } else {
                closeOutgoingDriverModal();
            }
        })
        .catch(function() {
            console.error('Failed to refresh outgoing driver trip modal.');
        });
}