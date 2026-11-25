<?php
// Top Header Bar Component
if (!isset($user_id)) {
    $user_id = $_SESSION['user_id'] ?? 0;
}

// Get counts for badges - check table existence first
$cart_count = 0;
$check_cart = $conn->query("SHOW TABLES LIKE 'shopping_cart'");
if ($check_cart && $check_cart->num_rows > 0) {
    $cart_result = $conn->query("SELECT COUNT(*) as c FROM shopping_cart WHERE user_id = $user_id");
    $cart_count = $cart_result ? ($cart_result->fetch_assoc()['c'] ?? 0) : 0;
}

// Check if wishlist table exists before querying
$wishlist_count = 0;
$check_wishlist = $conn->query("SHOW TABLES LIKE 'wishlist'");
if ($check_wishlist && $check_wishlist->num_rows > 0) {
    $wishlist_result = $conn->query("SELECT COUNT(*) as c FROM wishlist WHERE user_id = $user_id");
    $wishlist_count = $wishlist_result ? ($wishlist_result->fetch_assoc()['c'] ?? 0) : 0;
}

$unread_notif = 0;
$check_notif = $conn->query("SHOW TABLES LIKE 'notifications'");
if ($check_notif && $check_notif->num_rows > 0) {
    $notif_result = $conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_id = $user_id AND is_read = 0");
    $unread_notif = $notif_result ? ($notif_result->fetch_assoc()['c'] ?? 0) : 0;
}

// Get user info
$user_name = $user['firstname'] ?? 'User';
$user_initial = strtoupper(substr($user_name, 0, 1));
$user_avatar = $user['profile_picture'] ?? null;
?>
<style>
/* Top Header Bar - Minimal & Clean */
.top-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    width: 100%;
    height: 70px;
    background: var(--header-bg, #FFFFFF);
    border-bottom: 1px solid #E8E6E1;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    z-index: 2000;
    display: flex;
    align-items: center;
    padding: 0 32px;
    gap: 24px;
    box-sizing: border-box;
}

/* Logo Section - Compact */
.header-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 200px;
    flex-shrink: 0;
}

.header-logo img {
    width: 40px;
    height: 40px;
    border-radius: 8px;
}

.header-logo-text h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
    color: #0B5D3B;
    line-height: 1.2;
}

.header-logo-text p {
    margin: 0;
    font-size: 11px;
    color: #6B7280;
    line-height: 1;
}

/* Search Bar - Minimal & Elegant - Expanded */
.header-search {
    flex: 1;
    max-width: 700px;
    position: relative;
}

.header-search input {
    width: 100%;
    padding: 10px 40px 10px 16px;
    border: 1px solid #E8E6E1;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s ease;
    background: #F5F8F5;
    color: #1F2937;
}

.header-search input:focus {
    outline: none;
    border-color: #0B5D3B;
    background: #FFFFFF;
    box-shadow: 0 0 0 3px rgba(11, 93, 59, 0.12);
}

.header-search input::placeholder {
    color: #9CA3AF;
    font-weight: 400;
    font-size: 13px;
}

.header-search .search-btn {
    position: absolute;
    right: 4px;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    color: #6B7280;
    border: none;
    padding: 6px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
    font-size: 16px;
    transition: all 0.2s ease;
}

.header-search .search-btn:hover {
    background: transparent;
    color: #0B5D3B;
    transform: translateY(-50%) scale(1.1);
}

.header-search .search-btn:active {
    transform: translateY(-50%) scale(0.95);
}

/* Header Actions - Compact */
.header-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
    margin-left: auto;
}

.header-icon-btn {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: #FFFFFF;
    border: 1px solid #E8E6E1;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    position: relative;
    transition: all 0.2s ease;
    font-size: 16px;
    color: #1F2937;
    text-decoration: none;
}

.header-icon-btn:hover {
    background: #F5F8F5;
    border-color: #0B5D3B;
    color: #0B5D3B;
}

.header-icon-btn .badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #DC2626;
    color: #FFFFFF;
    min-width: 18px;
    height: 18px;
    border-radius: 9px;
    font-size: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    border: 2px solid #FFFFFF;
}

.header-user-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    border-radius: 8px;
    border: 1px solid #E8E6E1;
    background: #FFFFFF;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    color: #1F2937;
}

.header-user-btn:hover {
    background: #F5F8F5;
    border-color: #0B5D3B;
}

.header-user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #0B5D3B 0%, #168354 100%);
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    overflow: hidden;
}

.header-user-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.header-user-name {
    font-size: 14px;
    font-weight: 600;
}

/* Search Dropdown */
.search-dropdown {
    position: absolute;
    top: calc(100% + 12px);
    left: 0;
    width: 100%;
    background: #FFFFFF;
    border: 2px solid #E5E7EB;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.15);
    max-height: 500px;
    overflow-y: auto;
    z-index: 99999;
    display: none;
}

.search-dropdown.active {
    display: block;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.search-result-item {
    display: flex;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid #F3F4F6;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    color: var(--text-primary, #111827);
}

.search-result-item:last-child {
    border-bottom: none;
}

.search-result-item:hover {
    background: linear-gradient(90deg, #F9FAFB 0%, #EFF6FF 100%);
    padding-left: 24px;
}

.search-result-item img {
    width: 60px;
    height: 80px;
    object-fit: cover;
    border-radius: 8px;
    margin-right: 16px;
    background: #f8f9fa;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.search-result-info {
    flex: 1;
    min-width: 0;
}

.search-result-title {
    font-weight: 600;
    font-size: 15px;
    margin-bottom: 4px;
    color: var(--text-primary, #111827);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.search-result-author {
    font-size: 13px;
    color: var(--text-secondary, #6B7280);
    margin-bottom: 4px;
}

.search-result-price {
    font-size: 14px;
    font-weight: 700;
    color: var(--color-brand-primary, #6366F1);
}

.search-no-results {
    padding: 60px 20px;
    text-align: center;
    color: var(--text-secondary, #6B7280);
}

.search-no-results i {
    font-size: 3rem;
    color: #E5E7EB;
    margin-bottom: 16px;
    display: block;
}

.search-no-results p {
    font-size: 15px;
    margin-top: 8px;
}

.search-dropdown::-webkit-scrollbar {
    width: 8px;
}

.search-dropdown::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.search-dropdown::-webkit-scrollbar-thumb {
    background: var(--color-brand-primary, #6366F1);
    border-radius: 10px;
}

.search-dropdown::-webkit-scrollbar-thumb:hover {
    background: #4F46E5;
}

/* Adjust body and sidebar for top header */
html, body {
    margin: 0;
    padding: 0;
    width: 100%;
    overflow-x: hidden;
}

body {
    padding-top: 70px;
}

.sidebar {
    top: 70px;
    height: calc(100vh - 70px);
}

.main-content {
    margin-top: 0;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .top-header {
        padding: 0 15px;
        gap: 15px;
    }
    
    .header-logo {
        min-width: auto;
    }
    
    .header-logo-text {
        display: none;
    }
    
    .header-search {
        max-width: none;
    }
    
    .header-user-name {
        display: none;
    }
}

/* Categories Dropdown */
.categories-dropdown {
    position: absolute;
    top: calc(100% + 12px);
    right: 0;
    min-width: 280px;
    background: #FFFFFF;
    border: 2px solid #E5E7EB;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.15);
    max-height: 500px;
    overflow-y: auto;
    z-index: 99999;
    display: none;
}

.categories-dropdown.active {
    display: block;
    animation: slideDown 0.3s ease;
}

.category-item {
    display: flex;
    align-items: center;
    padding: 14px 20px;
    border-bottom: 1px solid #F3F4F6;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    color: var(--text-primary, #111827);
    gap: 12px;
}

.category-item:last-child {
    border-bottom: none;
}

.category-item:hover {
    background: linear-gradient(90deg, #F9FAFB 0%, #EFF6FF 100%);
    padding-left: 24px;
}

.category-item i {
    width: 24px;
    font-size: 16px;
    color: var(--color-brand-primary, #6366F1);
}

.category-item span {
    flex: 1;
    font-weight: 500;
    font-size: 14px;
}

.category-item .count {
    font-size: 12px;
    color: var(--text-secondary, #6B7280);
    background: #F3F4F6;
    padding: 2px 8px;
    border-radius: 10px;
}

.header-categories {
    position: relative;
}

/* Dark Mode Styles */
body.dark-mode {
    background: #0f172a;
    color: #e2e8f0;
}

body.dark-mode .top-header {
    background: #1e293b !important;
    border-bottom-color: #334155 !important;
}

body.dark-mode .header-search input {
    background: #0f172a;
    border-color: #334155;
    color: #e2e8f0;
}

body.dark-mode .header-icon-btn,
body.dark-mode .header-user-btn {
    background: #0f172a;
    border-color: #334155;
    color: #e2e8f0;
}

body.dark-mode .header-icon-btn:hover,
body.dark-mode .header-user-btn:hover {
    background: #334155;
}

body.dark-mode .search-dropdown,
body.dark-mode .categories-dropdown {
    background: #1e293b;
    border-color: #334155;
}

body.dark-mode .search-result-item,
body.dark-mode .category-item {
    color: #e2e8f0;
}

body.dark-mode .search-result-item:hover,
body.dark-mode .category-item:hover {
    background: #0f172a;
}

body.dark-mode .sidebar {
    background: #1e293b;
}

body.dark-mode .main-content {
    background: #0f172a;
}

body.dark-mode .section-card,
body.dark-mode .stat-card,
body.dark-mode .card {
    background: #1e293b;
    border-color: #334155;
    color: #e2e8f0;
}
</style>

<div class="top-header">
    <!-- Logo -->
    <div class="header-logo">
        <img src="../PICTURES/Site-Logo.png" alt="CSUCC FIND Logo">
        <div class="header-logo-text">
            <h3>Caraga State University</h3>
            <p>Lost & Found Management System</p>
        </div>
    </div>
    
    <!-- Search Bar - Minimal Design -->
    <div class="header-search">
        <input type="text" id="headerSearchInput" placeholder="Search lost and found items..." autocomplete="off">
        <button class="search-btn" onclick="performSearch()">
            <i class="fas fa-search"></i>
        </button>
        <div class="search-dropdown" id="headerSearchDropdown"></div>
    </div>
    
    <!-- Actions -->
    <div class="header-actions">
        <!-- Dark Mode Toggle -->
        <div class="header-icon-btn" id="darkModeBtn" title="Toggle Dark Mode">
            <i class="fas fa-moon"></i>
        </div>
        
        <!-- User Profile -->
        <a href="profile.php" class="header-user-btn">
            <div class="header-user-avatar">
                <?php if (!empty($user_avatar)): ?>
                    <img src="../<?= htmlspecialchars($user_avatar) ?>" alt="<?= htmlspecialchars($user_name) ?>">
                <?php else: ?>
                    <?= $user_initial ?>
                <?php endif; ?>
            </div>
            <span class="header-user-name"><?= htmlspecialchars($user_name) ?></span>
        </a>
    </div>
</div>

<script>
// Live search functionality
let searchTimeout;
const searchInput = document.getElementById('headerSearchInput');
const searchDropdown = document.getElementById('headerSearchDropdown');

// Perform search function
function performSearch() {
    const query = searchInput.value.trim();
    if (query.length > 0) {
        window.location.href = 'user_found_items.php';
    }
}

// Enter key to search
searchInput.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        performSearch();
    }
});

searchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const query = this.value.trim();
    
    if (query.length < 2) {
        searchDropdown.classList.remove('active');
        return;
    }
    
    searchTimeout = setTimeout(() => {
        fetch(`search_books.php?q=${encodeURIComponent(query)}`)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.books.length > 0) {
                    searchDropdown.innerHTML = data.books.map(book => `
                        <a href="book_details.php?id=${book.id}" class="search-result-item">
                            <img src="${book.cover_image || '../PICTURES/default-book.jpg'}" alt="${book.title}" onerror="this.src='../PICTURES/default-book.jpg'">
                            <div class="search-result-info">
                                <div class="search-result-title">${book.title}</div>
                                <div class="search-result-author">by ${book.author}</div>
                                <div class="search-result-price">₱${parseFloat(book.price).toFixed(2)}</div>
                            </div>
                        </a>
                    `).join('');
                    searchDropdown.classList.add('active');
                } else {
                    searchDropdown.innerHTML = `
                        <div class="search-no-results">
                            <i class="fas fa-search"></i>
                            <p><strong>No books found</strong></p>
                            <p>Try searching with different keywords</p>
                        </div>
                    `;
                    searchDropdown.classList.add('active');
                }
            })
            .catch(err => {
                console.error('Search error:', err);
            });
    }, 300);
});

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.header-search')) {
        searchDropdown.classList.remove('active');
    }
});

// Dark mode toggle
const darkModeBtn = document.getElementById('darkModeBtn');
const darkModeIcon = darkModeBtn.querySelector('i');
const isDarkMode = localStorage.getItem('darkMode') === 'true';

if (isDarkMode) {
    document.body.classList.add('dark-mode');
    darkModeIcon.classList.replace('fa-moon', 'fa-sun');
}

darkModeBtn.addEventListener('click', function() {
    document.body.classList.toggle('dark-mode');
    const isDark = document.body.classList.contains('dark-mode');
    localStorage.setItem('darkMode', isDark);
    
    if (isDark) {
        darkModeIcon.classList.replace('fa-moon', 'fa-sun');
    } else {
        darkModeIcon.classList.replace('fa-sun', 'fa-moon');
    }
});
</script>
