<!-- Mobile Menu Button -->
<button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Toggle Menu">
    <i class="fas fa-bars"></i>
</button>

<style>
/* Mobile Menu Button */
.mobile-menu-btn {
    display: none;
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, #1e3a5f 0%, #2d5a8c 100%);
    border: none;
    border-radius: 50%;
    color: white;
    font-size: 1.5rem;
    cursor: pointer;
    box-shadow: 0 4px 20px rgba(30, 58, 95, 0.4);
    z-index: 1002;
    transition: all 0.3s ease;
}

.mobile-menu-btn:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 24px rgba(30, 58, 95, 0.5);
}

.mobile-menu-btn:active {
    transform: scale(0.95);
}

.mobile-menu-btn i {
    transition: transform 0.3s ease;
}

.mobile-menu-btn.active i {
    transform: rotate(90deg);
}

/* Sidebar Overlay */
.sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 999;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.sidebar-overlay.active {
    display: block;
    opacity: 1;
}

@media (max-width: 768px) {
    .mobile-menu-btn {
        display: flex !important;
        align-items: center;
        justify-content: center;
    }
    
    /* Ensure sidebar is above overlay */
    #sidebar {
        z-index: 1001 !important;
    }
}
</style>

<script>
(function() {
    'use strict';
    
    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMobileMenu);
    } else {
        initMobileMenu();
    }
    
    function initMobileMenu() {
        const menuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        
        console.log('Mobile menu initialized');
        console.log('Menu button:', menuBtn);
        console.log('Sidebar:', sidebar);
        
        if (!menuBtn || !sidebar) {
            console.error('Mobile menu or sidebar not found!');
            return;
        }
        
        // Add click event to menu button
        menuBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Menu button clicked!');
            toggleSidebar();
        });
        
        // Close sidebar on window resize to desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                closeSidebar();
            }
        });
    }
    
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const menuBtn = document.getElementById('mobileMenuBtn');
        let overlay = document.getElementById('sidebarOverlay');
        
        console.log('Toggling sidebar...');
        
        if (!sidebar) {
            console.error('Sidebar not found!');
            return;
        }
        
        const isOpen = sidebar.classList.contains('show');
        console.log('Sidebar currently open:', isOpen);
        
        if (isOpen) {
            // Close sidebar
            sidebar.classList.remove('show');
            menuBtn.classList.remove('active');
            
            if (overlay) {
                overlay.classList.remove('active');
                setTimeout(function() {
                    if (overlay && overlay.parentNode) {
                        overlay.parentNode.removeChild(overlay);
                    }
                }, 300);
            }
            
            document.body.style.overflow = '';
        } else {
            // Open sidebar
            sidebar.classList.add('show');
            menuBtn.classList.add('active');
            
            // Create overlay
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'sidebarOverlay';
                overlay.className = 'sidebar-overlay';
                document.body.appendChild(overlay);
                
                // Add click handler to overlay
                overlay.addEventListener('click', function() {
                    console.log('Overlay clicked');
                    toggleSidebar();
                });
                
                // Trigger animation
                setTimeout(function() {
                    overlay.classList.add('active');
                }, 10);
            }
            
            document.body.style.overflow = 'hidden';
        }
        
        console.log('Sidebar toggled. New state:', sidebar.classList.contains('show'));
    }
    
    function closeSidebar() {
        const sidebar = document.getElementById('sidebar');
        const menuBtn = document.getElementById('mobileMenuBtn');
        const overlay = document.getElementById('sidebarOverlay');
        
        if (sidebar && sidebar.classList.contains('show')) {
            sidebar.classList.remove('show');
            if (menuBtn) menuBtn.classList.remove('active');
            if (overlay && overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
            document.body.style.overflow = '';
        }
    }
    
    // Make toggleSidebar available globally for debugging
    window.toggleMobileSidebar = toggleSidebar;
})();
</script>
