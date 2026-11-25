/**
 * Mobile Handler for Admin Dashboard
 * Handles sidebar toggle, overlay, and responsive behavior
 */

(function() {
    'use strict';
    
    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    function init() {
        setupSidebarToggle();
        setupOverlayHandler();
        setupMenuItemHandlers();
        setupResizeHandler();
        restoreSidebarState();
    }
    
    /**
     * Setup sidebar toggle functionality
     */
    function setupSidebarToggle() {
        // Override any existing toggleSidebar function
        window.toggleSidebar = function() {
            const sidebar = document.getElementById('sidebar') || document.querySelector('.sidebar');
            const mainContent = document.getElementById('mainContent') || document.querySelector('.main-content');
            const overlay = document.getElementById('sidebarOverlay') || document.querySelector('.sidebar-overlay');
            
            if (!sidebar) {
                console.warn('Sidebar element not found');
                return;
            }
            
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile) {
                // Mobile: Toggle sidebar visibility with overlay
                sidebar.classList.toggle('show');
                if (overlay) {
                    overlay.classList.toggle('show');
                }
                
                // Prevent body scroll when sidebar is open
                if (sidebar.classList.contains('show')) {
                    document.body.style.overflow = 'hidden';
                } else {
                    document.body.style.overflow = '';
                }
            } else {
                // Desktop: Toggle collapsed state
                const isCollapsed = sidebar.classList.toggle('collapsed');
                if (mainContent) {
                    mainContent.classList.toggle('expanded');
                }
                
                // Update toggle button icon
                const toggleBtn = document.querySelector('.toggle-sidebar-btn');
                if (toggleBtn) {
                    const icon = toggleBtn.querySelector('i');
                    if (icon) {
                        icon.className = isCollapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
                    }
                }
                
                // Save state
                try {
                    localStorage.setItem('sidebarCollapsed', isCollapsed);
                } catch (e) {
                    console.warn('Could not save sidebar state:', e);
                }
            }
            
            // Close all dropdowns
            closeAllDropdowns();
        };
    }
    
    /**
     * Setup overlay click handler
     */
    function setupOverlayHandler() {
        const overlay = document.getElementById('sidebarOverlay') || document.querySelector('.sidebar-overlay');
        
        if (overlay) {
            overlay.addEventListener('click', function() {
                const sidebar = document.getElementById('sidebar') || document.querySelector('.sidebar');
                if (sidebar) {
                    sidebar.classList.remove('show');
                    overlay.classList.remove('show');
                    document.body.style.overflow = '';
                }
            });
        }
    }
    
    /**
     * Setup menu item click handlers for mobile
     */
    function setupMenuItemHandlers() {
        const menuItems = document.querySelectorAll('.menu-item, .dropdown-item');
        
        menuItems.forEach(function(item) {
            item.addEventListener('click', function(e) {
                if (window.innerWidth <= 768) {
                    // Only close sidebar if it's a link (not a dropdown toggle)
                    if (!item.classList.contains('dropdown-toggle')) {
                        const sidebar = document.getElementById('sidebar') || document.querySelector('.sidebar');
                        const overlay = document.getElementById('sidebarOverlay') || document.querySelector('.sidebar-overlay');
                        
                        if (sidebar) {
                            sidebar.classList.remove('show');
                            document.body.style.overflow = '';
                        }
                        if (overlay) {
                            overlay.classList.remove('show');
                        }
                    }
                }
            });
        });
    }
    
    /**
     * Setup window resize handler
     */
    function setupResizeHandler() {
        let resizeTimer;
        
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                const sidebar = document.getElementById('sidebar') || document.querySelector('.sidebar');
                const overlay = document.getElementById('sidebarOverlay') || document.querySelector('.sidebar-overlay');
                const mainContent = document.getElementById('mainContent') || document.querySelector('.main-content');
                
                if (window.innerWidth > 768) {
                    // Desktop: Remove mobile classes
                    if (sidebar) {
                        sidebar.classList.remove('show');
                    }
                    if (overlay) {
                        overlay.classList.remove('show');
                    }
                    document.body.style.overflow = '';
                    
                    // Restore collapsed state from localStorage
                    restoreSidebarState();
                } else {
                    // Mobile: Remove desktop collapsed state
                    if (sidebar) {
                        sidebar.classList.remove('collapsed');
                    }
                    if (mainContent) {
                        mainContent.classList.remove('expanded');
                    }
                }
            }, 250);
        });
    }
    
    /**
     * Restore sidebar state from localStorage (desktop only)
     */
    function restoreSidebarState() {
        if (window.innerWidth > 768) {
            try {
                const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                if (isCollapsed) {
                    const sidebar = document.getElementById('sidebar') || document.querySelector('.sidebar');
                    const mainContent = document.getElementById('mainContent') || document.querySelector('.main-content');
                    const toggleBtn = document.querySelector('.toggle-sidebar-btn');
                    
                    if (sidebar) {
                        sidebar.classList.add('collapsed');
                    }
                    if (mainContent) {
                        mainContent.classList.add('expanded');
                    }
                    if (toggleBtn) {
                        const icon = toggleBtn.querySelector('i');
                        if (icon) {
                            icon.className = 'fas fa-chevron-right';
                        }
                    }
                }
            } catch (e) {
                console.warn('Could not restore sidebar state:', e);
            }
        }
    }
    
    /**
     * Close all dropdown menus
     */
    function closeAllDropdowns() {
        document.querySelectorAll('.dropdown-content').forEach(function(dropdown) {
            dropdown.classList.remove('show');
        });
        document.querySelectorAll('.dropdown-toggle').forEach(function(toggle) {
            toggle.classList.remove('active');
        });
    }
    
    /**
     * Handle dropdown toggle
     */
    window.toggleDropdown = function(element) {
        const sidebar = document.getElementById('sidebar') || document.querySelector('.sidebar');
        const dropdownContent = element.nextElementSibling;
        const isCollapsed = sidebar && sidebar.classList.contains('collapsed');
        
        // Close other dropdowns
        document.querySelectorAll('.dropdown-content').forEach(function(dropdown) {
            if (dropdown !== dropdownContent) {
                dropdown.classList.remove('show');
            }
        });
        document.querySelectorAll('.dropdown-toggle').forEach(function(toggle) {
            if (toggle !== element) {
                toggle.classList.remove('active');
            }
        });
        
        // Toggle current dropdown
        dropdownContent.classList.toggle('show');
        element.classList.toggle('active');
        
        // For collapsed sidebar, close dropdown when clicking outside
        if (isCollapsed && dropdownContent.classList.contains('show')) {
            setTimeout(function() {
                function closeDropdown(e) {
                    if (!element.contains(e.target) && !dropdownContent.contains(e.target)) {
                        dropdownContent.classList.remove('show');
                        element.classList.remove('active');
                        document.removeEventListener('click', closeDropdown);
                    }
                }
                document.addEventListener('click', closeDropdown);
            }, 100);
        }
    };
    
    // Expose utility functions
    window.mobileHandler = {
        closeSidebar: function() {
            const sidebar = document.getElementById('sidebar') || document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlay') || document.querySelector('.sidebar-overlay');
            
            if (sidebar) {
                sidebar.classList.remove('show');
            }
            if (overlay) {
                overlay.classList.remove('show');
            }
            document.body.style.overflow = '';
        },
        
        openSidebar: function() {
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar') || document.querySelector('.sidebar');
                const overlay = document.getElementById('sidebarOverlay') || document.querySelector('.sidebar-overlay');
                
                if (sidebar) {
                    sidebar.classList.add('show');
                }
                if (overlay) {
                    overlay.classList.add('show');
                }
                document.body.style.overflow = 'hidden';
            }
        },
        
        isMobile: function() {
            return window.innerWidth <= 768;
        }
    };
    
    console.log('Mobile handler initialized');
})();
