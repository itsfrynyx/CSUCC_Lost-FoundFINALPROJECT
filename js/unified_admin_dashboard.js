/**
 * UNIFIED ADMIN DASHBOARD - MAIN JAVASCRIPT
 * Handles all dashboard functionality including profile uploads, notifications,
 * sidebar, real-time monitoring, charts, and keyboard shortcuts
 */

(function() {
    'use strict';

    // Global state
    let ordersRefreshInterval;
    let lastOrdersUpdate = Date.now();
    let currentAdminId = null;

    /**
     * PROFILE IMAGE UPLOAD
     */
    window.uploadProfileImage = function(input) {
        if (!input.files || !input.files[0]) return;

        const file = input.files[0];
        const avatar = document.querySelector('.user-avatar');
        const originalAvatarContent = avatar ? avatar.innerHTML : '';

        // Validate file size (max 5MB)
        const maxSize = 5 * 1024 * 1024;
        if (file.size > maxSize) {
            showNotification('File too large. Maximum size is 5MB.', 'error');
            input.value = ''; // Clear input
            return;
        }

        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            showNotification('Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.', 'error');
            input.value = ''; // Clear input
            return;
        }

        // Get admin ID dynamically
        const adminIdElement = document.querySelector('[data-admin-id]');
        const adminId = adminIdElement ? adminIdElement.dataset.adminId : currentAdminId;

        if (!adminId) {
            showNotification('Error: Admin ID not found', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('profile_image', file);
        formData.append('admin_id', adminId);

        // Show loading state
        if (avatar) {
            avatar.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        }

        // Use fetch API for better error handling
        fetch('ajax/upload_profile_image.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Update avatar with new image
                if (avatar && data.image_url) {
                    avatar.innerHTML = `<img src="${data.image_url}?v=${Date.now()}" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">`;
                }
                showNotification('Profile photo updated successfully!', 'success');
                
                // Clear input for next upload
                input.value = '';
            } else {
                throw new Error(data.message || 'Upload failed');
            }
        })
        .catch(error => {
            console.error('Upload error:', error);
            
            // Restore original avatar
            if (avatar) {
                avatar.innerHTML = originalAvatarContent;
            }
            
            showNotification(error.message || 'Error uploading photo. Please try again.', 'error');
            
            // Clear input
            input.value = '';
        });
    };

    /**
     * NOTIFICATION SYSTEM
     */
    window.showNotification = function(message, type = 'info', duration = 3000) {
        // Map type to Bootstrap alert classes
        const typeMap = {
            'error': 'danger',
            'info': 'info',
            'success': 'success',
            'warning': 'warning'
        };
        
        const alertType = typeMap[type] || 'info';
        
        const notification = document.createElement('div');
        notification.className = `alert alert-${alertType} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);';
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        document.body.appendChild(notification);

        // Auto remove after duration
        setTimeout(() => {
            if (notification.parentNode) {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 150);
            }
        }, duration);
    };

    /**
     * SIDEBAR TOGGLE
     */
    window.toggleSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggleBtn = document.querySelector('.toggle-sidebar-btn');
        const overlay = document.getElementById('sidebarOverlay');
        const isMobile = window.innerWidth <= 768;

        if (isMobile) {
            // Mobile: Toggle sidebar visibility with overlay
            sidebar.classList.toggle('show');
            if (overlay) {
                overlay.classList.toggle('show');
            }
        } else {
            // Desktop: Toggle collapsed state
            const isCollapsed = sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');

            if (sidebarToggleBtn) {
                const icon = sidebarToggleBtn.querySelector('i');
                sidebarToggleBtn.style.transform = isCollapsed ? 
                    'translateY(-50%) rotate(180deg)' : 'translateY(-50%) rotate(0deg)';

                if (icon) {
                    icon.style.opacity = '0';
                    setTimeout(() => {
                        icon.className = isCollapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
                        icon.style.opacity = '1';
                    }, 200);
                }
            }

            localStorage.setItem('sidebarCollapsed', isCollapsed);
            
            // Trigger chart resize if exists
            setTimeout(() => {
                if (window.salesChart) {
                    window.salesChart.resize();
                }
            }, 350);
        }

        // Close all dropdowns when collapsing/hiding sidebar
        if (sidebar.classList.contains('collapsed') || !sidebar.classList.contains('show')) {
            document.querySelectorAll('.dropdown-content').forEach(dropdown => {
                dropdown.classList.remove('show');
            });
            document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
                toggle.classList.remove('active');
            });
        }
    };

    /**
     * SCROLL TO TOP
     */
    window.scrollToTop = function() {
        const mainContent = document.getElementById('mainContent');
        if (mainContent) {
            mainContent.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    /**
     * THEME TOGGLE
     */
    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('adminTheme', isDark ? 'dark' : 'light');
        updateThemeIcon();
        
        // Update chart colors if exists
        updateChartTheme(isDark);
    };

    function updateThemeIcon() {
        const themeToggle = document.getElementById('themeToggle');
        if (!themeToggle) return;

        const icon = themeToggle.querySelector('i');
        const isDark = document.body.classList.contains('dark-mode');

        if (isDark) {
            icon.classList.remove('fa-moon');
            icon.classList.add('fa-sun');
            themeToggle.title = 'Toggle Light Mode';
        } else {
            icon.classList.remove('fa-sun');
            icon.classList.add('fa-moon');
            themeToggle.title = 'Toggle Dark Mode';
        }
    }

    function updateChartTheme(isDark) {
        if (!window.salesChart) return;

        const gridColor = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)';
        const textColor = isDark ? '#94A3B8' : '#64748B';
        const legendColor = isDark ? '#F1F5F9' : '#1E293B';

        window.salesChart.options.scales.y.grid.color = gridColor;
        window.salesChart.options.scales.y1.grid.color = gridColor;
        window.salesChart.options.scales.x.grid.color = gridColor;
        window.salesChart.options.scales.y.ticks.color = textColor;
        window.salesChart.options.scales.y1.ticks.color = textColor;
        window.salesChart.options.scales.x.ticks.color = textColor;
        window.salesChart.options.scales.y.title.color = textColor;
        window.salesChart.options.scales.y1.title.color = textColor;
        window.salesChart.options.plugins.legend.labels.color = legendColor;
        window.salesChart.update();
    }

    /**
     * FILTER ORDERS
     */
    window.filterOrders = function(status) {
        const orderItems = document.querySelectorAll('.order-item');
        const filterBtns = document.querySelectorAll('.filter-btn');

        // Update active button
        filterBtns.forEach(btn => {
            if (btn.getAttribute('data-status') === status) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        // Filter orders
        orderItems.forEach(item => {
            const orderStatus = item.querySelector('.order-status');
            if (!orderStatus) return;

            const statusText = orderStatus.textContent.trim().toLowerCase();
            if (status === 'all' || statusText === status) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    };

    /**
     * REFRESH ORDERS MANUALLY
     */
    window.refreshOrdersManual = function() {
        const refreshBtn = document.getElementById('refreshBtn');
        if (!refreshBtn) return;

        refreshBtn.classList.add('refreshing');

        setTimeout(() => {
            refreshBtn.classList.remove('refreshing');
            showNotification('Orders refreshed', 'success', 2000);

            if (typeof refreshOrders === 'function') {
                refreshOrders();
            }
        }, 800);
    };

    /**
     * REAL-TIME ORDERS MONITORING
     */
    window.refreshOrders = function() {
        const refreshBtn = document.getElementById('refreshBtn');
        const indicator = document.getElementById('realtimeIndicator');

        if (refreshBtn) {
            refreshBtn.disabled = true;
            refreshBtn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Refreshing...';
        }

        fetch('ajax/get_realtime_orders.php')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.orders) {
                    updateOrdersTable(data.orders);
                    lastOrdersUpdate = Date.now();
                    updateLastUpdateTime();

                    if (indicator) {
                        indicator.className = 'badge bg-success';
                        indicator.innerHTML = '<i class="fas fa-circle pulse-animation"></i> Live';
                    }
                } else {
                    throw new Error(data.message || 'Failed to fetch orders');
                }
            })
            .catch(error => {
                console.error('Error fetching orders:', error);
                if (indicator) {
                    indicator.className = 'badge bg-warning';
                    indicator.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Offline';
                }
            })
            .finally(() => {
                if (refreshBtn) {
                    refreshBtn.disabled = false;
                    refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh';
                }
            });
    };

    function updateOrdersTable(orders) {
        const tbody = document.getElementById('ordersTableBody');
        if (!tbody) return;

        if (orders.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">No orders yet</td></tr>';
            return;
        }

        tbody.innerHTML = orders.map(order => {
            const statusClass = getStatusClass(order.status);
            const isNewCancellation = order.is_new_cancellation;
            const isNewCompletion = order.is_new_completion;
            let rowClass = '';
            let newBadge = '';

            if (isNewCancellation) {
                rowClass = 'table-danger';
                newBadge = '<span class="badge bg-danger ms-2">NEW CANCELLATION</span>';
            } else if (isNewCompletion) {
                rowClass = 'table-success';
                newBadge = '<span class="badge bg-success ms-2">COMPLETED</span>';
            }

            return `
                <tr class="${rowClass}">
                    <td>#${escapeHtml(order.order_number)}${newBadge}</td>
                    <td>${escapeHtml(order.customer_name)}</td>
                    <td>${escapeHtml(order.customer_email)}</td>
                    <td>${order.item_count} item${order.item_count > 1 ? 's' : ''}</td>
                    <td>₱${parseFloat(order.total_amount).toFixed(2)}</td>
                    <td><span class="badge bg-${statusClass}">${order.status.charAt(0).toUpperCase() + order.status.slice(1)}</span></td>
                    <td>${order.time_ago}</td>
                </tr>
            `;
        }).join('');

        // Play notification sound for new events
        const hasNewCancellation = orders.some(order => order.is_new_cancellation);
        const hasNewCompletion = orders.some(order => order.is_new_completion);

        if (hasNewCancellation) {
            playNotificationSound('error');
        } else if (hasNewCompletion) {
            playNotificationSound('success');
        }
    }

    function getStatusClass(status) {
        const statusMap = {
            'delivered': 'success',
            'pending': 'warning',
            'processing': 'info',
            'shipped': 'primary',
            'cancelled': 'danger'
        };
        return statusMap[status] || 'secondary';
    }

    function updateLastUpdateTime() {
        const timeElement = document.getElementById('lastUpdateTime');
        if (!timeElement) return;

        const diff = Math.floor((Date.now() - lastOrdersUpdate) / 1000);
        let timeText;

        if (diff < 10) {
            timeText = 'Updated just now';
        } else if (diff < 60) {
            timeText = `Updated ${diff}s ago`;
        } else {
            const mins = Math.floor(diff / 60);
            timeText = `Updated ${mins}m ago`;
        }

        timeElement.textContent = timeText;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function playNotificationSound(type = 'success') {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();

            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);

            if (type === 'error') {
                oscillator.frequency.value = 400;
                oscillator.type = 'square';
            } else {
                oscillator.frequency.value = 800;
                oscillator.type = 'sine';
            }

            gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.5);

            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.5);

            if (type === 'success') {
                setTimeout(() => {
                    const oscillator2 = audioContext.createOscillator();
                    const gainNode2 = audioContext.createGain();
                    oscillator2.connect(gainNode2);
                    gainNode2.connect(audioContext.destination);
                    oscillator2.frequency.value = 1000;
                    oscillator2.type = 'sine';
                    gainNode2.gain.setValueAtTime(0.3, audioContext.currentTime);
                    gainNode2.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);
                    oscillator2.start(audioContext.currentTime);
                    oscillator2.stop(audioContext.currentTime + 0.3);
                }, 200);
            }
        } catch (e) {
            console.log('Audio notification not supported');
        }
    }

    /**
     * EXPORT REPORTS
     */
    window.exportReport = function(type) {
        const urlMap = {
            'sales': 'ajax/export_sales_report.php',
            'customers': 'ajax/export_customers.php',
            'inventory': 'ajax/export_inventory.php'
        };

        const url = urlMap[type];
        if (!url) return;

        showNotification('Generating report...', 'info');

        const link = document.createElement('a');
        link.href = url;
        link.download = `${type}_report_${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        setTimeout(() => {
            showNotification('Report downloaded successfully!', 'success');
        }, 1000);
    };

    /**
     * KEYBOARD SHORTCUTS
     */
    window.toggleShortcutsPanel = function() {
        const panel = document.getElementById('shortcutsPanel');
        if (!panel) return;

        panel.classList.toggle('active');

        let overlay = document.querySelector('.shortcuts-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'shortcuts-overlay';
            overlay.onclick = toggleShortcutsPanel;
            document.body.appendChild(overlay);
        }
        overlay.classList.toggle('active');
    };

    /**
     * GLOBAL SEARCH
     */
    function setupGlobalSearch() {
        const searchInput = document.getElementById('globalSearch');
        const searchResults = document.getElementById('searchResults');
        if (!searchInput || !searchResults) return;

        let searchTimeout;

        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();

            if (query.length < 2) {
                searchResults.classList.remove('show');
                return;
            }

            searchTimeout = setTimeout(() => {
                performSearch(query);
            }, 300);
        });

        searchInput.addEventListener('focus', function() {
            if (this.value.trim().length >= 2) {
                searchResults.classList.add('show');
            }
        });

        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.remove('show');
            }
        });
    }

    function performSearch(query) {
        fetch(`ajax/global_search.php?q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => displaySearchResults(data))
            .catch(error => console.error('Search error:', error));
    }

    function displaySearchResults(data) {
        const searchResults = document.getElementById('searchResults');
        if (!searchResults) return;

        let html = '';
        let hasResults = false;

        ['orders', 'customers', 'books'].forEach(category => {
            if (data[category] && data[category].length > 0) {
                hasResults = true;
                html += `<div class="search-category">${category.charAt(0).toUpperCase() + category.slice(1)}</div>`;
                data[category].forEach(item => {
                    html += `<a href="${item.url}" class="search-result-item">${item.title}<small>${item.meta}</small></a>`;
                });
            }
        });

        if (!hasResults) {
            html = '<div class="search-no-results"><i class="fas fa-search"></i><p>No results found</p></div>';
        }

        searchResults.innerHTML = html;
        searchResults.classList.add('show');
    }

    /**
     * INITIALIZE ON DOM READY
     */
    document.addEventListener('DOMContentLoaded', function() {
        // Get admin ID from page
        const adminIdElement = document.querySelector('[data-admin-id]');
        if (adminIdElement) {
            currentAdminId = adminIdElement.dataset.adminId;
        }

        // Initialize Bootstrap dropdowns
        const dropdownElements = document.querySelectorAll('.dropdown-toggle');
        dropdownElements.forEach(el => new bootstrap.Dropdown(el));

        // Load theme preference
        const savedTheme = localStorage.getItem('adminTheme');
        if (savedTheme === 'dark') {
            document.body.classList.add('dark-mode');
            updateThemeIcon();
        }

        // Remove welcome toast
        const welcomeToast = document.getElementById('welcomeToast');
        if (welcomeToast) {
            setTimeout(() => welcomeToast.remove(), 4000);
        }

        // Restore sidebar state (desktop only)
        if (window.innerWidth > 768 && localStorage.getItem('sidebarCollapsed') === 'true') {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const btn = document.querySelector('.toggle-sidebar-btn');

            if (sidebar) sidebar.classList.add('collapsed');
            if (mainContent) mainContent.classList.add('expanded');
            if (btn) {
                btn.style.transform = 'translateY(-50%) rotate(180deg)';
                const icon = btn.querySelector('i');
                if (icon) icon.className = 'fas fa-chevron-right';
            }
        }

        // Setup mobile sidebar overlay
        const overlay = document.getElementById('sidebarOverlay');
        const sidebar = document.getElementById('sidebar');
        if (overlay && sidebar) {
            overlay.addEventListener('click', () => {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
            });
        }

        // Close sidebar on menu click (mobile)
        document.querySelectorAll('.menu-item, .dropdown-item').forEach(item => {
            item.addEventListener('click', function() {
                if (window.innerWidth <= 768 && sidebar && overlay) {
                    sidebar.classList.remove('show');
                    overlay.classList.remove('show');
                }
            });
        });

        // Setup scroll to top button
        const scrollTopBtn = document.getElementById('scrollTopBtn');
        const mainContent = document.getElementById('mainContent');
        if (mainContent && scrollTopBtn) {
            mainContent.addEventListener('scroll', function() {
                if (mainContent.scrollTop > 300) {
                    scrollTopBtn.classList.add('show');
                } else {
                    scrollTopBtn.classList.remove('show');
                }
            });
        }

        // Setup global search
        setupGlobalSearch();

        // Setup keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.target.matches('input, textarea')) return;

            switch(e.key) {
                case '?':
                    e.preventDefault();
                    toggleShortcutsPanel();
                    break;
                case 'r':
                case 'R':
                    e.preventDefault();
                    refreshOrdersManual();
                    break;
                case 'n':
                case 'N':
                    e.preventDefault();
                    window.location.href = 'admin_manage_orders.php';
                    break;
                case 'b':
                case 'B':
                    e.preventDefault();
                    window.location.href = 'admin_manage_books.php';
                    break;
                case 'c':
                case 'C':
                    e.preventDefault();
                    window.location.href = 'admin_manage_customers.php';
                    break;
                case 'Escape':
                    const bell = document.getElementById('notificationBell');
                    if (bell && bell.classList.contains('active')) {
                        bell.classList.remove('active');
                    }
                    const panel = document.getElementById('shortcutsPanel');
                    if (panel && panel.classList.contains('active')) {
                        toggleShortcutsPanel();
                    }
                    break;
            }
        });

        // Start real-time monitoring
        if (document.getElementById('ordersTableBody')) {
            ordersRefreshInterval = setInterval(refreshOrders, 30000);
            setInterval(updateLastUpdateTime, 10000);
            setTimeout(refreshOrders, 5000);
        }

        // Handle window resize
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                const mainContent = document.getElementById('mainContent');

                if (window.innerWidth > 768) {
                    if (sidebar) sidebar.classList.remove('show');
                    if (overlay) overlay.classList.remove('show');
                } else {
                    if (sidebar) sidebar.classList.remove('collapsed');
                    if (mainContent) mainContent.classList.remove('expanded');
                }

                if (window.salesChart) {
                    window.salesChart.resize();
                }
            }, 250);
        });

        // Show keyboard shortcuts hint (first time only)
        if (!localStorage.getItem('keyboardHintsShown')) {
            setTimeout(() => {
                showNotification('💡 Tip: Press R to refresh, N for orders, B for books, C for customers', 'info', 5000);
                localStorage.setItem('keyboardHintsShown', 'true');
            }, 3000);
        }
    });

    // Clean up on page unload
    window.addEventListener('beforeunload', () => {
        if (ordersRefreshInterval) {
            clearInterval(ordersRefreshInterval);
        }
    });

    // Add required styles
    const style = document.createElement('style');
    style.textContent = `
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .pulse-animation {
            animation: pulse 1.5s ease-in-out infinite;
        }
        .table-danger {
            background-color: rgba(220, 53, 69, 0.1) !important;
            border-left: 3px solid #dc3545 !important;
        }
        .table-success {
            background-color: rgba(16, 185, 129, 0.1) !important;
            border-left: 3px solid #10b981 !important;
        }
    `;
    document.head.appendChild(style);

})();
