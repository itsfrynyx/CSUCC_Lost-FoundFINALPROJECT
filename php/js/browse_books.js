// Browse Books - All Functionality

// View Book Details in Modal
function viewBook(bookId) {
    fetch(`get_book.php?id=${bookId}`)
        .then(response => response.json())
        .then(book => {
            if (book.error) {
                showToast('Failed to load book details', 'error');
                return;
            }
            
            const modalBody = document.getElementById('modalBody');
            modalBody.innerHTML = `
                <div class="row">
                    <div class="col-md-4 text-center">
                        ${book.cover_image && book.cover_image !== '0' 
                            ? `<img src="../uploads/books/${book.cover_image}" 
                                    class="img-fluid rounded shadow modal-book-image" 
                                    alt="${book.title}">`
                            : `<div class="modal-book-placeholder">
                                    <i class="fas fa-book"></i>
                                </div>`
                        }
                    </div>
                    <div class="col-md-8">
                        <h3 class="modal-book-title">${book.title}</h3>
                        <p class="text-muted mb-3 fst-italic">by ${book.author}</p>
                        <hr>
                        
                        <div class="mb-3">
                            <strong class="modal-label">Price:</strong>
                            <p class="modal-price">₱${parseFloat(book.price).toFixed(2)}</p>
                        </div>
                        
                        <div class="mb-3">
                            <strong class="modal-label">Stock:</strong>
                            <p class="modal-stock">${book.stock_quantity} units available</p>
                        </div>
                        
                        ${book.description ? `
                            <div class="mb-3">
                                <strong class="modal-label">Description:</strong>
                                <p class="modal-description">${book.description}</p>
                            </div>
                        ` : ''}
                        
                        ${book.stock_quantity > 0 ? `
                            <div class="mb-4">
                                <label class="modal-label">Quantity:</label>
                                <div class="quantity-selector">
                                    <button class="qty-btn qty-minus" onclick="decreaseQuantity()">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                    <input type="number" 
                                           class="qty-input" 
                                           id="bookQuantity" 
                                           value="1" 
                                           min="1" 
                                           max="${book.stock_quantity}" 
                                           readonly>
                                    <button class="qty-btn qty-plus" onclick="increaseQuantity(${book.stock_quantity})">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button class="btn-add-to-cart-modal" 
                                        onclick="addToCartFromModal(${book.id})">
                                    <i class="fas fa-shopping-cart me-2"></i>
                                    Add to Cart
                                </button>
                            </div>
                        ` : `
                            <div class="alert alert-danger">
                                <i class="fas fa-times-circle me-2"></i>
                                This book is currently out of stock
                            </div>
                        `}
                    </div>
                </div>
            `;
            
            const modal = new bootstrap.Modal(document.getElementById('viewBookModal'));
            modal.show();
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to load book details', 'error');
        });
}

// Quantity Controls
function decreaseQuantity() {
    const input = document.getElementById('bookQuantity');
    if (input && parseInt(input.value) > 1) {
        input.value = parseInt(input.value) - 1;
    }
}

function increaseQuantity(max) {
    const input = document.getElementById('bookQuantity');
    if (input && parseInt(input.value) < max) {
        input.value = parseInt(input.value) + 1;
    }
}

// Add to Cart from Modal
function addToCartFromModal(bookId) {
    const quantityInput = document.getElementById('bookQuantity');
    const quantity = quantityInput ? parseInt(quantityInput.value) : 1;
    
    addToCart(bookId, quantity);
    
    // Close modal after adding
    const modalElement = document.getElementById('viewBookModal');
    const modal = bootstrap.Modal.getInstance(modalElement);
    if (modal) {
        modal.hide();
    }
}

// Add to Cart
function addToCart(bookId, quantity = 1) {
    fetch('add_to_cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `book_id=${bookId}&quantity=${quantity}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Book added to cart successfully!', 'success');
            updateCartCount();
        } else {
            showToast(data.message || 'Failed to add to cart', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to add to cart', 'error');
    });
}

// Toggle Wishlist
function toggleWishlist(bookId) {
    fetch('toggle_wishlist.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `book_id=${bookId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            
            // Toggle heart icon
            const button = event.target.closest('button');
            const icon = button.querySelector('i');
            if (icon) {
                if (data.action === 'added') {
                    icon.classList.remove('far');
                    icon.classList.add('fas');
                } else {
                    icon.classList.remove('fas');
                    icon.classList.add('far');
                }
            }
        } else {
            showToast(data.message || 'Failed to update wishlist', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to update wishlist', 'error');
    });
}

// Set View (Grid/List)
function setView(view) {
    const grid = document.querySelector('.books-grid');
    const buttons = document.querySelectorAll('.view-btn');
    
    buttons.forEach(btn => btn.classList.remove('active'));
    event.target.closest('.view-btn').classList.add('active');
    
    if (view === 'list') {
        grid.classList.add('list-view');
    } else {
        grid.classList.remove('list-view');
    }
}

// Apply Price Filter
function applyPriceFilter() {
    const minPrice = parseFloat(document.getElementById('minPrice').value) || 0;
    const maxPrice = parseFloat(document.getElementById('maxPrice').value) || Infinity;
    
    let visibleCount = 0;
    const cards = document.querySelectorAll('.book-card');
    
    cards.forEach(card => {
        const price = parseFloat(card.dataset.price);
        
        if (price >= minPrice && price <= maxPrice) {
            card.style.display = '';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });
    
    if (visibleCount === 0) {
        showToast('No books found in this price range', 'warning');
    } else {
        showToast(`Showing ${visibleCount} book${visibleCount !== 1 ? 's' : ''}`, 'success');
    }
}

// Clear Price Filter
function clearPriceFilter() {
    document.getElementById('minPrice').value = '';
    document.getElementById('maxPrice').value = '';
    
    document.querySelectorAll('.book-card').forEach(card => {
        card.style.display = '';
    });
    
    showToast('Filter cleared', 'success');
}

// Update Cart Count
function updateCartCount() {
    fetch('get_cart_count.php')
        .then(response => response.json())
        .then(data => {
            const badge = document.getElementById('cartCount');
            if (badge) {
                badge.textContent = data.count || 0;
                
                // Animate badge
                badge.style.transform = 'scale(1.3)';
                setTimeout(() => {
                    badge.style.transform = 'scale(1)';
                }, 200);
            }
        })
        .catch(error => console.error('Error updating cart count:', error));
}

// Show Toast Notification
function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer') || createToastContainer();
    
    const icons = {
        success: 'check-circle',
        error: 'exclamation-circle',
        warning: 'exclamation-triangle',
        info: 'info-circle'
    };
    
    const colors = {
        success: '#28a745',
        error: '#dc3545',
        warning: '#ffc107',
        info: '#17a2b8'
    };
    
    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    toast.innerHTML = `
        <i class="fas fa-${icons[type]}" style="color: ${colors[type]}"></i>
        <span>${message}</span>
    `;
    
    container.appendChild(toast);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease forwards';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Create Toast Container
function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toastContainer';
    container.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
    `;
    document.body.appendChild(container);
    return container;
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateCartCount();
});

// Toggle Wishlist View (placeholder function)
function toggleWishlistView() {
    showToast('Wishlist feature - Coming soon!', 'info');
    // TODO: Implement wishlist page or modal
}
