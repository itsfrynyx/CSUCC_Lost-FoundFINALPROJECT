<!-- Real-time Block Status Checker -->
<style>
.block-notification-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.85);
    backdrop-filter: blur(10px);
    z-index: 99999;
    animation: fadeIn 0.3s ease;
}

.block-notification-overlay.show {
    display: flex;
    align-items: center;
    justify-content: center;
}

.block-notification-modal {
    background: white;
    border-radius: 24px;
    max-width: 500px;
    width: 90%;
    box-shadow: 0 25px 70px rgba(0, 0, 0, 0.4);
    animation: slideUpBounce 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    overflow: hidden;
}

.block-notification-header {
    padding: 40px 40px 30px;
    text-align: center;
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    position: relative;
}

.block-notification-icon {
    width: 100px;
    height: 100px;
    margin: 0 auto 20px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 50px;
    color: white;
    animation: pulseIcon 2s ease-in-out infinite;
    border: 3px solid rgba(255, 255, 255, 0.3);
}

.block-notification-title {
    font-size: 28px;
    font-weight: 700;
    margin: 0;
    color: white;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

.block-notification-body {
    padding: 40px;
    text-align: center;
}

.block-notification-message {
    font-size: 17px;
    color: #475569;
    line-height: 1.7;
    margin-bottom: 30px;
}

.block-notification-details {
    background: #fef2f2;
    border-left: 4px solid #ef4444;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 30px;
    text-align: left;
}

.block-notification-details h4 {
    font-size: 15px;
    font-weight: 600;
    color: #dc2626;
    margin: 0 0 12px 0;
}

.block-notification-details ul {
    margin: 0;
    padding: 0;
    list-style: none;
}

.block-notification-details li {
    padding: 8px 0;
    color: #64748b;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.block-notification-details li i {
    color: #ef4444;
    font-size: 16px;
    width: 20px;
}

.block-notification-btn {
    width: 100%;
    padding: 16px 24px;
    border: none;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
}

.block-notification-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUpBounce {
    0% {
        opacity: 0;
        transform: translateY(100px) scale(0.8);
    }
    60% {
        opacity: 1;
        transform: translateY(-10px) scale(1.02);
    }
    80% {
        transform: translateY(5px) scale(0.98);
    }
    100% {
        transform: translateY(0) scale(1);
    }
}

@keyframes pulseIcon {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}
</style>

<!-- Block Notification Modal -->
<div class="block-notification-overlay" id="blockNotificationOverlay">
    <div class="block-notification-modal">
        <div class="block-notification-header">
            <div class="block-notification-icon">
                <i class="fas fa-ban"></i>
            </div>
            <h3 class="block-notification-title">Account Blocked</h3>
        </div>
        <div class="block-notification-body">
            <p class="block-notification-message">
                Your account has been blocked by the Super Administrator. You will not be able to access the system until your account is unblocked.
            </p>
            
            <div class="block-notification-details">
                <h4><i class="fas fa-info-circle"></i> What this means:</h4>
                <ul>
                    <li>
                        <i class="fas fa-lock"></i>
                        <span>You cannot access any system features</span>
                    </li>
                    <li>
                        <i class="fas fa-sign-out-alt"></i>
                        <span>You will be logged out automatically</span>
                    </li>
                    <li>
                        <i class="fas fa-phone"></i>
                        <span>Please contact support for assistance</span>
                    </li>
                </ul>
            </div>
            
            <button class="block-notification-btn" onclick="handleBlockedLogout()">
                <i class="fas fa-sign-out-alt me-2"></i>
                I Understand - Logout
            </button>
        </div>
    </div>
</div>

<script>
// Check block status every 5 seconds
let blockCheckInterval;
let blockCheckEnabled = true;

function startBlockStatusChecker() {
    // Check immediately on load
    checkBlockStatus();
    
    // Then check every 5 seconds
    blockCheckInterval = setInterval(checkBlockStatus, 5000);
}

function checkBlockStatus() {
    if (!blockCheckEnabled) return;
    
    fetch('check_block_status.php')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.is_blocked) {
                // User is blocked! Show notification
                showBlockNotification();
                // Stop checking
                clearInterval(blockCheckInterval);
                blockCheckEnabled = false;
            }
        })
        .catch(error => {
            console.error('Error checking block status:', error);
        });
}

function showBlockNotification() {
    const overlay = document.getElementById('blockNotificationOverlay');
    overlay.classList.add('show');
    
    // Prevent closing by clicking outside
    overlay.style.pointerEvents = 'none';
    overlay.querySelector('.block-notification-modal').style.pointerEvents = 'auto';
}

function handleBlockedLogout() {
    // Redirect to logout
    window.location.href = 'Sign_out.php?blocked=1';
}

// Start checking when page loads
document.addEventListener('DOMContentLoaded', function() {
    startBlockStatusChecker();
});

// Also check when page becomes visible (user switches back to tab)
document.addEventListener('visibilitychange', function() {
    if (!document.hidden && blockCheckEnabled) {
        checkBlockStatus();
    }
});
</script>
