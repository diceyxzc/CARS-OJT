/**
 * CARS Auto-Update System - Simplified
 * Focuses on: Trip start/complete notifications, car status updates, new pending requests
 */

// ============================================
// UTILITY FUNCTIONS
// ============================================

function showNotification(message, type = 'info') {
    let notification = document.getElementById('liveNotification');
    if (!notification) {
        notification = document.createElement('div');
        notification.id = 'liveNotification';
        notification.style.cssText = `
            position: fixed;
            top: 80px;
            right: 20px;
            background: #1a237e;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            z-index: 99999;
            font-weight: 600;
            font-size: 0.85rem;
            max-width: 350px;
            animation: slideInRight 0.4s ease;
            display: none;
        `;
        document.body.appendChild(notification);
    }
    
    const colors = {
        info: '#1a237e',
        success: '#2e7d32',
        warning: '#f57c00',
        error: '#c62828'
    };
    notification.style.background = colors[type] || colors.info;
    notification.textContent = '🔔 ' + message;
    notification.style.display = 'block';
    
    clearTimeout(notification._timeout);
    notification._timeout = setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transition = 'opacity 0.5s';
        setTimeout(() => {
            notification.style.display = 'none';
            notification.style.opacity = '1';
        }, 500);
    }, 5000);
}

// ============================================
// STATS UPDATE (only numbers, no table rebuilds)
// ============================================

function updateStats(stats) {
    const mappings = {
        'statPending': stats.pending || 0,
        'statOutgoing': stats.in_progress_today || 0,
        'statApproved' : stats.approved_today || 0,
        'statToday': stats.today_trips || 0,
        'statWeekly': stats.weekly_trips || 0,
        'statTotal': stats.total_trips_month || 0,
        'statTotalCars': stats.total_cars || 0,
        'statAvailableCars': stats.available_cars || 0,
        'statInUseCars': stats.in_use_cars || 0,
        'statMaintenanceCars': stats.maintenance_cars || 0
    };
    
    Object.keys(mappings).forEach(id => {
        const el = document.getElementById(id);
        if (el && el.textContent != mappings[id]) {
            el.textContent = mappings[id];
        }
    });
    
    // Update pending badge on tab if it exists
    const pendingBadge = document.getElementById('pendingBadge');
    if (pendingBadge) {
        const value = stats.pending || 0;
        if (pendingBadge.textContent != value) {
            pendingBadge.textContent = value;
        }
    }
}

// ============================================
// MAIN FETCH & UPDATE
// ============================================

let lastPendingCount = null;
let lastStatusUpdate = null;
let updateInterval = null;

function fetchAndUpdate() {
    // Only run on dashboard
    if (!document.getElementById('statPending')) {
        return;
    }
    
    fetch('/admin/api/trip_status.php')
        .then(response => response.json())
        .then(data => {
            if (!data.success) return;
            
            const stats = data.data.stats;
            const statusUpdate = data.data.status_update || {};
            
            // Update stats numbers
            updateStats(stats);
            
            // 🔔 CHECK FOR NEW PENDING REQUESTS
            const currentPending = stats.pending || 0;
            if (lastPendingCount !== null && currentPending > lastPendingCount) {
                const newCount = currentPending - lastPendingCount;
                showNotification(`📋 ${newCount} new pending request(s)! (${currentPending} total)`, 'warning');
            }
            lastPendingCount = currentPending;
            
            // 🔔 CHECK FOR TRIP START/COMPLETE EVENTS
            const started = statusUpdate.trips_updated || 0;
            const carsAvailable = statusUpdate.cars_set_available || 0;
            
            if (started > 0) {
                showNotification(`🚗 ${started} trip(s) started (cars now in use)`, 'warning');
            }
            
            if (carsAvailable > 0) {
                showNotification(`✅ ${carsAvailable} trip(s) completed (cars now available)`, 'success');
            }
            
            // Update last status
            lastStatusUpdate = statusUpdate;
            
            // Update timestamp
            const timestampEl = document.getElementById('lastUpdateTime');
            if (timestampEl && data.data.timestamp) {
                const time = new Date(data.data.timestamp);
                timestampEl.textContent = 'Updated: ' + time.toLocaleTimeString('en-PH', {
                    hour: 'numeric',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: true
                });
            }
            
            // Update status dot
            const dot = document.getElementById('statusDot');
            if (dot) dot.style.background = '#4fc3f7';
            
        })
        .catch(error => {
            console.error('❌ Auto-update error:', error);
            const dot = document.getElementById('statusDot');
            if (dot) dot.style.background = '#ff6b6b';
        });
}

// ============================================
// INITIALIZE
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    // Only run on dashboard
    if (!document.getElementById('statPending')) {
        console.log('⏭️ Not on dashboard, skipping auto-update');
        return;
    }
    
    // Get initial pending count
    const pendingEl = document.getElementById('statPending');
    if (pendingEl) {
        lastPendingCount = parseInt(pendingEl.textContent) || 0;
    }
    
    // Run immediately
    fetchAndUpdate();
    
    // Then every 3 seconds
    updateInterval = setInterval(fetchAndUpdate, 3000);
    
    console.log('✅ Auto-update started on dashboard');
});

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    if (updateInterval) {
        clearInterval(updateInterval);
        updateInterval = null;
    }
});

// Add this CSS for the notification animation
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from { transform: translateX(100px); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
`;
document.head.appendChild(style);