// Auto refresh functionality
let autoRefreshInterval;

function startAutoRefresh() {
    // Refresh every 30 seconds
    autoRefreshInterval = setInterval(() => {
        updateResourceStatus();
        updateLiveCounters();
    }, 30000);
}

function updateResourceStatus() {
    fetch('api/status.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update resource boxes without full page reload
                Object.entries(data.resources).forEach(([resourceId, resource]) => {
                    updateResourceBox(resourceId, resource);
                });
            }
        })
        .catch(error => console.error('Auto refresh error:', error));
}

function updateResourceBox(resourceId, resource) {
    const box = document.querySelector(`[data-resource-id="${resourceId}"]`);
    if (box) {
        // Update status badge
        const statusBadge = box.querySelector('.status-badge');
        if (statusBadge) {
            statusBadge.textContent = resource.status;
            statusBadge.className = `status-badge status-${resource.status.toLowerCase()}`;
        }
        
        // Update box class based on status
        let boxClass = 'resource-box ';
        if (resource.status === 'PAID') {
            boxClass += 'paid';
        } else if (resource.is_occupied) {
            boxClass += 'occupied';
        } else {
            boxClass += 'vacant';
        }
        box.className = boxClass;
    }
}

// Live counter functionality
function updateLiveCounters() {
    document.querySelectorAll('.live-counter').forEach(counter => {
        const checkinTime = counter.getAttribute('data-checkin');
        if (checkinTime) {
            const checkin = new Date(checkinTime);
            const now = new Date();
            const diff = now - checkin;
            
            const hours = Math.floor(diff / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);
            
            counter.textContent = `${hours}h ${minutes}m ${seconds}s elapsed`;
            
            // Highlight if over 24 hours
            if (hours >= 24) {
                counter.style.background = 'rgba(239, 68, 68, 0.1)';
                counter.style.color = 'var(--danger-color)';
            }
        }
    });
}

// Toggle resource box expansion
function toggleResourceBox(element) {
    // Close all other expanded boxes
    document.querySelectorAll('.resource-box.expanded').forEach(box => {
        if (box !== element) {
            box.classList.remove('expanded');
        }
    });
    
    // Toggle current box
    element.classList.toggle('expanded');
}

// Payment modal functions
function openPaymentModal(resourceId, resourceName) {
    document.getElementById('paymentResourceId').value = resourceId;
    document.getElementById('paymentResourceName').value = resourceName;
    document.getElementById('paymentModal').style.display = 'flex';
}

function closePaymentModal() {
    document.getElementById('paymentModal').style.display = 'none';
    document.getElementById('paymentForm').reset();
}

// Form validation
function validateBookingForm() {
    const checkin = new Date(document.getElementById('check_in').value);
    const checkout = new Date(document.getElementById('check_out').value);
    
    if (checkout <= checkin) {
        alert('Check-out time must be after check-in time');
        return false;
    }
    
    return true;
}

// Date/time picker setup
function setupDateTimePickers() {
    const now = new Date();
    const nowString = now.toISOString().slice(0, 16);
    
    const checkinInput = document.getElementById('check_in');
    const checkoutInput = document.getElementById('check_out');
    
    if (checkinInput) {
        checkinInput.min = nowString;
        checkinInput.addEventListener('change', function() {
            const checkinTime = new Date(this.value);
            const defaultCheckout = new Date(checkinTime.getTime() + 24 * 60 * 60 * 1000);
            checkoutInput.value = defaultCheckout.toISOString().slice(0, 16);
            checkoutInput.min = this.value;
        });
    }
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Start auto refresh
    startAutoRefresh();
    
    // Update live counters immediately and then every second
    updateLiveCounters();
    setInterval(updateLiveCounters, 1000);
    
    // Setup form elements
    setupDateTimePickers();
    
    // Close modal when clicking outside
    window.addEventListener('click', function(e) {
        const modal = document.getElementById('paymentModal');
        if (e.target === modal) {
            closePaymentModal();
        }
    });
});

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
});