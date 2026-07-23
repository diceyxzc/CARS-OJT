// ============================================================
// PRINT SCHEDULE FUNCTION
// ============================================================
function printSchedule() {
// Store current week info
const weekLabel = document.getElementById('weekLabel').textContent;
const tripCount = document.getElementById('tripCount').textContent;

// Get the schedule grid content
const scheduleContent = document.getElementById('scheduleGrid').innerHTML;

// Open print window
const printWindow = window.open('', '_blank', 'width=1100,height=900');

printWindow.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
        <title>Driver Weekly Schedule</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                padding: 15px;
                background: #ffffff;
                color: #1a1a2e;
                font-size: 12px;
            }
            
            .print-header {
                background: #ffffff;
                padding: 10px 0 12px 0;
                margin-bottom: 12px;
                border-bottom: 2px solid #e8ecf1;
            }
            .print-header h1 {
                font-size: 22px;
                font-weight: 700;
                margin: 0;
                color: #1a1a2e;
                display: flex;
                align-items: center;
                gap: 12px;
                flex-wrap: wrap;
            }
            .print-header h1 span {
                background: #FFD700;
                color: #1a1a2e;
                font-size: 11px;
                padding: 2px 12px;
                border-radius: 20px;
                font-weight: 600;
            }
            .print-header .week-label {
                font-size: 13px;
                color: #6c757d;
                margin-top: 3px;
            }
            .print-header .meta {
                font-size: 11px;
                color: #adb5bd;
                margin-top: 2px;
            }
            
            .print-stats {
                display: flex;
                flex-wrap: wrap;
                gap: 8px 20px;
                padding: 10px 0;
                background: transparent;
                margin-bottom: 12px;
                border-bottom: 1px solid #f0f0f0;
                align-items: center;
            }
            .print-stats .stat-item {
                display: flex;
                align-items: center;
                gap: 5px;
                font-size: 12px;
                color: #555;
            }
            .print-stats .stat-item strong {
                color: #1a1a2e;
                font-size: 13px;
                font-weight: 600;
            }
            .print-stats .stat-item .dot {
                width: 9px;
                height: 9px;
                border-radius: 50%;
                display: inline-block;
                flex-shrink: 0;
            }
            .dot-approved { background: #28a745; }
            .dot-in_progress { background: #ffc107; }
            .print-stats .stat-spacer { flex: 1; }
            .print-stats .stat-total {
                font-weight: 600;
                color: #1a1a2e;
                font-size: 12px;
            }
            
            .schedule-wrapper {
                background: white;
                border-radius: 0;
                padding: 0;
                border: 1px solid #e8ecf1;
                overflow: hidden;
            }
            
            .schedule-grid {
                display: flex;
                flex-direction: column;
                width: 100%;
                font-size: 11px;
            }
            
            .schedule-header {
                display: grid;
                grid-template-columns: 140px repeat(7, 1fr);
                background: #f8f9fa;
                border-bottom: 2px solid #1a1a2e;
                font-weight: 700;
                color: #1a1a2e;
            }
            .schedule-header .cell {
                padding: 8px 4px;
                text-align: center;
                font-size: 11px;
                border-right: 1px solid #e8ecf1;
                min-width: 0;
                word-break: break-word;
            }
            .schedule-header .cell:first-child {
                text-align: left;
                padding-left: 12px;
                border-right: 1px solid #e8ecf1;
            }
            .schedule-header .cell:last-child { border-right: none; }
            .schedule-header .cell .date-small {
                font-weight: 400;
                font-size: 9px;
                color: #6c757d;
                display: block;
                margin-top: 1px;
            }
            .schedule-header .cell.today {
                background: rgba(255, 193, 7, 0.12);
            }
            
            .schedule-row {
                display: grid;
                grid-template-columns: 140px repeat(7, 1fr);
                border-bottom: 1px solid #f0f0f0;
            }
            .schedule-row:last-child { border-bottom: none; }
            
            .schedule-row .cell {
                padding: 6px 3px;
                text-align: center;
                border-right: 1px solid #f0f0f0;
                vertical-align: top;
                min-height: 55px;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                min-width: 0;
                word-break: break-word;
            }
            .schedule-row .cell:first-child {
                text-align: left;
                padding-left: 12px;
                border-right: 1px solid #e8ecf1;
                display: block;
                min-height: auto;
            }
            .schedule-row .cell:last-child { border-right: none; }
            
            .driver-name .driver-fullname {
                font-weight: 700;
                font-size: 12px;
                color: #1a1a2e;
                display: block;
            }
            .driver-name .car-info {
                display: block;
                font-weight: 400;
                font-size: 9px;
                color: #6c757d;
                margin-top: 2px;
            }
            .driver-name .car-info .plate {
                color: #adb5bd;
            }
            
            .trip-card {
                background: #f8f9fa;
                border-radius: 4px;
                padding: 4px 6px;
                margin-bottom: 2px;
                border-left: 3px solid #6c757d;
                text-align: left;
                font-size: 9px;
                width: 100%;
                max-width: 100%;
            }
            .trip-card:last-child { margin-bottom: 0; }
            .trip-card .trip-time-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 2px;
                flex-wrap: wrap;
            }
            .trip-card .trip-time {
                font-weight: 700;
                color: #1a1a2e;
                font-size: 9px;
            }
            .trip-card .trip-time-dropoff {
                color: #6c757d;
                font-size: 8px;
            }
            .trip-card .trip-location {
                display: block;
                font-size: 7px;
                color: #6c757d;
                margin-top: 1px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .trip-card .trip-location-dropoff {
                color: #adb5bd;
            }
            .trip-card .trip-status {
                display: inline-block;
                padding: 1px 6px;
                border-radius: 8px;
                font-size: 6px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.3px;
                margin-top: 2px;
            }
            .trip-card .trip-status.approved {
                background: #d4edda;
                color: #155724;
            }
            .trip-card .trip-status.in_progress {
                background: #fff3cd;
                color: #856404;
            }
            .trip-card .trip-status.completed {
                background: #d1ecf1;
                color: #0c5460;
            }
            .trip-card .trip-status.cancelled {
                background: #f8d7da;
                color: #721c24;
            }
            
            .empty-cell {
                color: #dee2e6;
                font-size: 14px;
            }
            
            .footer {
                margin-top: 15px;
                padding: 10px 0;
                background: transparent;
                border-top: 1px solid #f0f0f0;
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 10px;
                color: #6c757d;
                flex-wrap: wrap;
                gap: 5px;
            }
            .footer .stats {
                font-weight: 600;
                color: #1a1a2e;
            }
            
            @media print {
                body { padding: 15px; }
                .schedule-header { grid-template-columns: 140px repeat(7, 1fr); }
                .schedule-row { grid-template-columns: 140px repeat(7, 1fr); }
                .schedule-header .cell { font-size: 11px; padding: 8px 4px; }
                .schedule-header .cell .date-small { font-size: 9px; }
                .schedule-row .cell { min-height: 55px; padding: 6px 3px; }
                .driver-name .driver-fullname { font-size: 12px; }
                .driver-name .car-info { font-size: 9px; }
                .trip-card { font-size: 9px; padding: 4px 6px; border-left-width: 3px; margin-bottom: 2px; }
                .trip-card .trip-time { font-size: 9px; }
                .trip-card .trip-time-dropoff { font-size: 8px; }
                .trip-card .trip-location { font-size: 7px; }
                .trip-card .trip-status { font-size: 6px; padding: 1px 6px; border-radius: 8px; }
                .empty-cell { font-size: 14px; }
                .print-stats { gap: 8px 20px; padding: 10px 0; }
                .print-stats .stat-item { font-size: 12px; }
                .print-stats .stat-item strong { font-size: 13px; }
                .print-stats .stat-item .dot { width: 9px; height: 9px; }
                .print-stats .stat-total { font-size: 12px; }
                .print-header h1 { font-size: 22px; }
                .print-header .week-label { font-size: 13px; }
                .print-header .meta { font-size: 11px; }
                .footer { font-size: 10px; padding: 10px 0; }
                .schedule-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .trip-card .trip-status { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .schedule-row { page-break-inside: avoid; }
                .print-stats .stat-item .dot { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .schedule-wrapper { border: 1px solid #dee2e6; }
            }
        </style>
    </head>
    <body>
        <div class="print-header">
            <h1>Driver Weekly Schedule</h1>
            <div class="week-label">${weekLabel}</div>
            <div class="meta">Generated: ${new Date().toLocaleString()}</div>
        </div>
        
        <div class="print-stats">
            <div class="stat-item">
                <span class="dot dot-approved"></span>
                <strong id="approvedCount">0</strong> Approved
            </div>
            <div class="stat-item">
                <span class="dot dot-in_progress"></span>
                <strong id="inProgressCount">0</strong> In Progress
            </div>
            <div class="stat-spacer"></div>
            <div class="stat-total">${tripCount}</div>
        </div>
        
        <div class="schedule-wrapper">
            <div class="schedule-grid">
                ${scheduleContent}
            </div>
        </div>
        
        <div class="footer">
            <div>CARS System | ${new Date().toLocaleDateString()}</div>
        </div>
        
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const approved = document.querySelectorAll('.trip-status.approved').length;
                const inProgress = document.querySelectorAll('.trip-status.in_progress').length;
                
                document.getElementById('approvedCount').textContent = approved;
                document.getElementById('inProgressCount').textContent = inProgress;
            });
            
            window.onload = function() {
                window.print();
            }
        <\/script>
    </body>
    </html>
`);

printWindow.document.close();
}

// ============================================================
// MODAL FUNCTIONS
// ============================================================

function openPrintModal() {
const modal = document.getElementById('printModal');
modal.classList.add('show');
// Hide driver selection, show options
document.getElementById('driverSelectionArea').style.display = 'none';
}

function closePrintModal() {
const modal = document.getElementById('printModal');
modal.classList.remove('show');
}

function printAllDrivers() {
closePrintModal();
printSchedule();
}

function showDriverSelection() {
document.getElementById('driverSelectionArea').style.display = 'block';
loadDriverList();
}

function loadDriverList() {
const list = document.getElementById('modalDriverList');
const drivers = document.querySelectorAll('.schedule-row');

list.innerHTML = '';

drivers.forEach(row => {
    const driverId = row.dataset.driverId;
    const nameEl = row.querySelector('.driver-name');
    let name = 'Unknown';
    let carText = 'No car assigned';
    
    if (nameEl) {
        const fullText = nameEl.textContent.trim();
        name = fullText.split('Designation')[0].trim();
        if (!name) name = 'Unknown Driver';
        
        const carInfo = nameEl.querySelector('.car-info');
        if (carInfo) {
            carText = carInfo.textContent.trim();
        }
    }
    
    const item = document.createElement('div');
    item.className = 'modal-driver-item';
    item.innerHTML = `
        <input type="checkbox" class="modal-driver-checkbox" data-driver-id="${driverId}" checked>
        <div class="driver-info">
            <div class="driver-name">${name}</div>
            <div class="driver-car">${carText}</div>
        </div>
    `;
    
    item.addEventListener('click', function(e) {
        if (e.target.tagName !== 'INPUT') {
            const cb = this.querySelector('.modal-driver-checkbox');
            cb.checked = !cb.checked;
            updateModalSelectAll();
            updateModalCount();
        }
    });
    
    const checkbox = item.querySelector('.modal-driver-checkbox');
    checkbox.addEventListener('change', function() {
        updateModalSelectAll();
        updateModalCount();
    });
    
    list.appendChild(item);
});

const selectAll = document.getElementById('modalSelectAll');
selectAll.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.modal-driver-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = this.checked;
    });
    updateModalCount();
});

updateModalSelectAll();
updateModalCount();
}

function updateModalSelectAll() {
const checkboxes = document.querySelectorAll('.modal-driver-checkbox');
const checked = document.querySelectorAll('.modal-driver-checkbox:checked');
const selectAll = document.getElementById('modalSelectAll');
if (selectAll) {
    selectAll.checked = (checkboxes.length > 0 && checked.length === checkboxes.length);
}
}

function updateModalCount() {
const checked = document.querySelectorAll('.modal-driver-checkbox:checked').length;
const total = document.querySelectorAll('.modal-driver-checkbox').length;
const countEl = document.getElementById('modalSelectedCount');
if (countEl) {
    countEl.textContent = `${checked} / ${total}`;
}
}

function printSelectedDrivers() {
const selectedIds = [];
document.querySelectorAll('.modal-driver-checkbox:checked').forEach(cb => {
    selectedIds.push(cb.dataset.driverId);
});

if (selectedIds.length === 0) {
    alert('Please select at least one driver to print.');
    return;
}

closePrintModal();

const selectedRows = [];
document.querySelectorAll('.schedule-row').forEach(row => {
    const driverId = row.dataset.driverId;
    if (selectedIds.includes(driverId)) {
        selectedRows.push(row.outerHTML);
    }
});

printCustomSchedule(selectedRows, `${selectedIds.length} Driver${selectedIds.length > 1 ? 's' : ''}`);
}

function printCustomSchedule(rows, title) {
const weekLabel = document.getElementById('weekLabel').textContent;
const tripCount = document.getElementById('tripCount').textContent;
const headerHTML = document.querySelector('.schedule-header').outerHTML;
let gridHTML = headerHTML + rows.join('');

const printWindow = window.open('', '_blank', 'width=1100,height=900');

printWindow.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
        <title>Driver Weekly Schedule</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                padding: 15px;
                background: #ffffff;
                color: #1a1a2e;
                font-size: 12px;
            }
            .print-header {
                background: #ffffff;
                padding: 10px 0 12px 0;
                margin-bottom: 12px;
                border-bottom: 2px solid #e8ecf1;
            }
            .print-header h1 {
                font-size: 22px;
                font-weight: 700;
                margin: 0;
                color: #1a1a2e;
            }
            .print-header .print-title {
                font-size: 14px;
                color: #28a745;
                margin-top: 5px;
                font-weight: 600;
            }
            .print-header .week-label {
                font-size: 13px;
                color: #6c757d;
                margin-top: 3px;
            }
            .print-header .meta {
                font-size: 11px;
                color: #adb5bd;
                margin-top: 2px;
            }
            .print-stats {
                display: flex;
                flex-wrap: wrap;
                gap: 8px 20px;
                padding: 10px 0;
                border-bottom: 1px solid #f0f0f0;
                margin-bottom: 12px;
                align-items: center;
            }
            .print-stats .stat-item {
                display: flex;
                align-items: center;
                gap: 5px;
                font-size: 12px;
                color: #555;
            }
            .print-stats .stat-item strong {
                color: #1a1a2e;
                font-size: 13px;
            }
            .print-stats .stat-item .dot {
                width: 9px;
                height: 9px;
                border-radius: 50%;
                display: inline-block;
            }
            .dot-approved { background: #28a745; }
            .dot-in_progress { background: #ffc107; }
            .print-stats .stat-spacer { flex: 1; }
            .print-stats .stat-total {
                font-weight: 600;
                color: #1a1a2e;
                font-size: 12px;
            }
            .schedule-wrapper {
                background: white;
                border: 1px solid #e8ecf1;
                overflow: hidden;
            }
            .schedule-grid {
                display: flex;
                flex-direction: column;
                width: 100%;
                font-size: 11px;
            }
            .schedule-header {
                display: grid;
                grid-template-columns: 140px repeat(7, 1fr);
                background: #f8f9fa;
                border-bottom: 2px solid #1a1a2e;
                font-weight: 700;
                color: #1a1a2e;
            }
            .schedule-header .cell {
                padding: 8px 4px;
                text-align: center;
                font-size: 11px;
                border-right: 1px solid #e8ecf1;
            }
            .schedule-header .cell:first-child {
                text-align: left;
                padding-left: 12px;
            }
            .schedule-header .cell:last-child { border-right: none; }
            .schedule-header .cell .date-small {
                font-weight: 400;
                font-size: 9px;
                color: #6c757d;
                display: block;
                margin-top: 1px;
            }
            .schedule-row {
                display: grid;
                grid-template-columns: 140px repeat(7, 1fr);
                border-bottom: 1px solid #f0f0f0;
            }
            .schedule-row:last-child { border-bottom: none; }
            .schedule-row .cell {
                padding: 6px 3px;
                text-align: center;
                border-right: 1px solid #f0f0f0;
                min-height: 55px;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
            }
            .schedule-row .cell:first-child {
                text-align: left;
                padding-left: 12px;
                display: block;
                min-height: auto;
            }
            .schedule-row .cell:last-child { border-right: none; }
            .driver-name .driver-fullname {
                font-weight: 700;
                font-size: 12px;
                color: #1a1a2e;
                display: block;
            }
            .driver-name .car-info {
                display: block;
                font-weight: 400;
                font-size: 9px;
                color: #6c757d;
                margin-top: 2px;
            }
            .trip-card {
                background: #f8f9fa;
                border-radius: 4px;
                padding: 4px 6px;
                margin-bottom: 2px;
                border-left: 3px solid #6c757d;
                text-align: left;
                font-size: 9px;
                width: 100%;
            }
            .trip-card:last-child { margin-bottom: 0; }
            .trip-card .trip-time {
                font-weight: 700;
                color: #1a1a2e;
                font-size: 9px;
            }
            .trip-card .trip-time-dropoff {
                color: #6c757d;
                font-size: 8px;
            }
            .trip-card .trip-location {
                display: block;
                font-size: 7px;
                color: #6c757d;
                margin-top: 1px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .trip-card .trip-status {
                display: inline-block;
                padding: 1px 6px;
                border-radius: 8px;
                font-size: 6px;
                font-weight: 700;
                text-transform: uppercase;
                margin-top: 2px;
            }
            .trip-card .trip-status.approved {
                background: #d4edda;
                color: #155724;
            }
            .trip-card .trip-status.in_progress {
                background: #fff3cd;
                color: #856404;
            }
            .empty-cell {
                color: #dee2e6;
                font-size: 14px;
            }
            .footer {
                margin-top: 15px;
                padding: 10px 0;
                border-top: 1px solid #f0f0f0;
                display: flex;
                justify-content: space-between;
                font-size: 10px;
                color: #6c757d;
            }
            .footer .stats {
                font-weight: 600;
                color: #1a1a2e;
            }
            @media print {
                .schedule-header {
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .trip-card .trip-status {
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .schedule-row {
                    page-break-inside: avoid;
                }
                .print-stats .stat-item .dot {
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
            }
        </style>
    </head>
    <body>
        <div class="print-header">
            <h1>Driver Weekly Schedule</h1>
            <div class="print-title"> ${title}</div>
            <div class="week-label">${weekLabel}</div>
            <div class="meta">Generated: ${new Date().toLocaleString()}</div>
        </div>
        
        <div class="print-stats">
            <div class="stat-item">
                <span class="dot dot-approved"></span>
                <strong id="approvedCount">0</strong> Approved
            </div>
            <div class="stat-item">
                <span class="dot dot-in_progress"></span>
                <strong id="inProgressCount">0</strong> In Progress
            </div>
            <div class="stat-spacer"></div>
            <div class="stat-total">${tripCount}</div>
        </div>
        
        <div class="schedule-wrapper">
            <div class="schedule-grid">
                ${gridHTML}
            </div>
        </div>
        
        <div class="footer">
            <div class="stats">${tripCount}</div>
            <div>CARS System | ${new Date().toLocaleDateString()}</div>
        </div>
        
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const approved = document.querySelectorAll('.trip-status.approved').length;
                const inProgress = document.querySelectorAll('.trip-status.in_progress').length;
                document.getElementById('approvedCount').textContent = approved;
                document.getElementById('inProgressCount').textContent = inProgress;
            });
            window.onload = function() {
                window.print();
            }
        <\/script>
    </body>
    </html>
`);

printWindow.document.close();
}