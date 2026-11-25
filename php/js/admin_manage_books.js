// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('Admin Books JS loaded');
    
    // Image preview for add form
    const addCoverImage = document.getElementById('addCoverImage');
    if (addCoverImage) {
        addCoverImage.addEventListener('change', function(e) {
            const file = e.target.files[0];
            
            if (file) {
                // Validate file size (5MB max)
                if (file.size > 5242880) {
                    alert('File size must be less than 5MB');
                    this.value = '';
                    return;
                }
                
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Only JPG, PNG, GIF, and WEBP images are allowed');
                    this.value = '';
                    return;
                }
                
                // Show preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    const previewDiv = document.getElementById('addImagePreview');
                    const previewImg = document.getElementById('addPreviewImg');
                    if (previewDiv && previewImg) {
                        previewImg.src = e.target.result;
                        previewDiv.style.display = 'block';
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    }
    
    // Form validation for add book - NORMAL SUBMISSION (like Edit)
    const addBookForm = document.getElementById('addBookForm');
    if (addBookForm) {
        addBookForm.addEventListener('submit', function(e) {
            const fileInput = document.getElementById('addCoverImage');
            
            if (!fileInput.files || fileInput.files.length === 0) {
                e.preventDefault();
                alert('Please select a book cover image before submitting.');
                return false;
            }
            
            // Let form submit normally (no AJAX)
            console.log('Form submitting normally with file:', fileInput.files[0].name);
        });
    }

    // Image preview for edit form
    const editCoverImage = document.getElementById('editCoverImage');
    if (editCoverImage) {
        editCoverImage.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Validate file size (5MB max)
                if (file.size > 5242880) {
                    alert('File size must be less than 5MB');
                    this.value = '';
                    return;
                }
                
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Only JPG, PNG, GIF, and WEBP images are allowed');
                    this.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('editImagePreview').innerHTML = `
                        <img src="${e.target.result}" alt="Preview" style="max-width: 100%; max-height: 300px; border-radius: 8px;">
                        <p class="mt-2 mb-0 text-success"><i class="fas fa-check-circle me-1"></i>New image selected!</p>
                        <small class="text-muted">This will replace the current image</small>
                    `;
                    document.getElementById('editImagePreview').style.border = '2px solid #28a745';
                };
                reader.readAsDataURL(file);
            }
        });
    }


    // Reset add form when modal opens
    const addBookModal = document.getElementById('addBookModal');
    if (addBookModal) {
        addBookModal.addEventListener('show.bs.modal', function() {
            document.getElementById('addBookForm').reset();
            const warningEl = document.getElementById('imageRequiredWarning');
            if (warningEl) warningEl.classList.add('d-none');
            
            const previewEl = document.getElementById('addImagePreview');
            previewEl.innerHTML = `
                <div class="image-preview-placeholder">
                    <i class="fas fa-cloud-upload-alt" style="font-size: 3rem; color: #007bff;"></i>
                    <p class="mb-1 mt-2"><strong>Click here to upload book cover</strong></p>
                    <small class="text-muted">Max 5MB (JPG, PNG, GIF)</small>
                </div>
            `;
            previewEl.style.border = '2px dashed #007bff';
        });
    }

    // Search functionality
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#booksTable tbody tr:not(#emptyRow)');
            
            rows.forEach(row => {
                const title = row.dataset.title || '';
                const author = row.dataset.author || '';
                
                if (title.includes(searchTerm) || author.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }

    // Filter functionality
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            const filter = this.dataset.filter;
            const rows = document.querySelectorAll('#booksTable tbody tr:not(#emptyRow)');
            
            rows.forEach(row => {
                const status = row.dataset.status;
                const stock = parseInt(row.dataset.stock);
                
                if (filter === 'all') {
                    row.style.display = '';
                } else if (filter === 'active' && status === 'active') {
                    row.style.display = '';
                } else if (filter === 'inactive' && status === 'inactive') {
                    row.style.display = '';
                } else if (filter === 'low-stock' && stock < 10) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    });

    // Delete confirmation
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', function() {
            if (window.deleteBookId) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="book_id" value="${window.deleteBookId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    // Initialize stats
    updateStats();
});

// Global functions (need to be outside DOMContentLoaded for onclick attributes)
// Global functions (need to be outside DOMContentLoaded for onclick attributes)

// Calculate and update statistics
function updateStats() {
    const rows = document.querySelectorAll('#booksTable tbody tr:not(#emptyRow)');
    let total = 0, active = 0, lowStock = 0;
    
    rows.forEach(row => {
        total++;
        if (row.dataset.status === 'active') active++;
        if (parseInt(row.dataset.stock) < 10) lowStock++;
    });
    
    const totalEl = document.getElementById('totalBooks');
    const activeEl = document.getElementById('activeBooks');
    const lowStockEl = document.getElementById('lowStockBooks');
    
    if (totalEl) totalEl.textContent = total;
    if (activeEl) activeEl.textContent = active;
    if (lowStockEl) lowStockEl.textContent = lowStock;
}


// View book details
function viewBook(bookId) {
    console.log('viewBook called with ID:', bookId);
    fetch(`admin_manage_books.php?ajax=get_book&id=${bookId}`)
        .then(response => response.json())
        .then(book => {
            console.log('Book data received:', book);
            document.getElementById('viewTitle').textContent = book.title;
            document.getElementById('viewAuthor').textContent = book.author;
            document.getElementById('viewPrice').textContent = '₱' + parseFloat(book.price).toFixed(2);
            document.getElementById('viewStock').textContent = book.stock_quantity + ' units';
            document.getElementById('viewStatus').innerHTML = book.is_active == 1 
                ? '<span class="badge bg-success">Active</span>' 
                : '<span class="badge bg-secondary">Inactive</span>';
            document.getElementById('viewDescription').textContent = book.description || 'No description available';
            
            if (book.cover_image && book.cover_image !== '0' && book.cover_image !== null) {
                document.getElementById('viewCoverImage').src = '../uploads/books/' + book.cover_image + '?v=' + new Date().getTime();
            } else {
                document.getElementById('viewCoverImage').src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="200" height="300"%3E%3Crect fill="%23667eea" width="200" height="300"/%3E%3Ctext fill="white" font-size="24" x="50%25" y="50%25" text-anchor="middle" dominant-baseline="middle"%3ENo Image%3C/text%3E%3C/svg%3E';
            }
            
            new bootstrap.Modal(document.getElementById('viewBookModal')).show();
        })
        .catch(error => {
            console.error('Error loading book:', error);
            alert('Failed to load book details. Check console for details.');
        });
}

// Edit book
function editBook(bookId) {
    console.log('editBook called with ID:', bookId);
    fetch(`admin_manage_books.php?ajax=get_book&id=${bookId}`)
        .then(response => response.json())
        .then(book => {
            console.log('Book data received:', book);
            document.getElementById('editBookId').value = book.id;
            document.getElementById('editTitle').value = book.title;
            document.getElementById('editAuthor').value = book.author;
            document.getElementById('editPrice').value = book.price;
            document.getElementById('editStock').value = book.stock_quantity;
            document.getElementById('editStatus').value = book.is_active;
            document.getElementById('editDescription').value = book.description || '';
            
            if (book.cover_image && book.cover_image !== '0' && book.cover_image !== null) {
                document.getElementById('editImagePreview').innerHTML = 
                    `<img src="../uploads/books/${book.cover_image}?v=${new Date().getTime()}" alt="Current Cover" style="max-width: 100%; max-height: 300px; border-radius: 8px;">
                     <p class="mt-2 mb-0"><i class="fas fa-image me-1"></i>Current book cover</p>
                     <small class="text-muted">Click to upload a new image</small>`;
                document.getElementById('editImagePreview').style.border = '2px dashed #ffc107';
            } else {
                document.getElementById('editImagePreview').innerHTML = `
                    <div class="image-preview-placeholder">
                        <i class="fas fa-exclamation-triangle" style="font-size: 3rem; color: #dc3545;"></i>
                        <p class="mb-1 mt-2 text-danger"><strong>No image found!</strong></p>
                        <small class="text-muted">Click here to upload a cover image</small>
                    </div>
                `;
                document.getElementById('editImagePreview').style.border = '2px dashed #dc3545';
            }
            
            new bootstrap.Modal(document.getElementById('editBookModal')).show();
        })
        .catch(error => {
            console.error('Error loading book:', error);
            alert('Failed to load book details. Check console for details.');
        });
}


// Delete book
function deleteBook(bookId, bookTitle) {
    console.log('deleteBook called with ID:', bookId, 'Title:', bookTitle);
    window.deleteBookId = bookId;
    document.getElementById('deleteBookTitle').textContent = bookTitle;
    new bootstrap.Modal(document.getElementById('deleteBookModal')).show();
}

// Refresh statistics
function refreshStats() {
    const totalEl = document.getElementById('totalBooks');
    const activeEl = document.getElementById('activeBooks');
    const lowStockEl = document.getElementById('lowStockBooks');
    
    // Add loading animation
    [totalEl, activeEl, lowStockEl].forEach(el => {
        if (el) {
            el.style.opacity = '0.5';
            el.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        }
    });
    
    // Simulate refresh (in real app, fetch from server)
    setTimeout(() => {
        updateStats();
        [totalEl, activeEl, lowStockEl].forEach(el => {
            if (el) el.style.opacity = '1';
        });
        
        // Show success message
        showToast('Statistics refreshed successfully!', 'success');
    }, 500);
}

// Export books to CSV
function exportBooks() {
    const rows = document.querySelectorAll('#booksTable tbody tr:not(#emptyRow)');
    if (rows.length === 0) {
        showToast('No books to export', 'warning');
        return;
    }
    
    let csv = 'Title,Author,Price,Stock,Status\n';
    
    rows.forEach(row => {
        if (row.style.display !== 'none') {
            const cells = row.querySelectorAll('td');
            const title = cells[1]?.textContent.trim() || '';
            const author = cells[2]?.textContent.trim() || '';
            const price = cells[3]?.textContent.trim() || '';
            const stock = cells[4]?.textContent.trim() || '';
            const status = cells[5]?.textContent.trim() || '';
            
            csv += `"${title}","${author}","${price}","${stock}","${status}"\n`;
        }
    });
    
    // Create download link
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `books_export_${new Date().toISOString().split('T')[0]}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
    
    showToast('Books exported successfully!', 'success');
}

// Print book list
function printBookList() {
    window.print();
}

// Show toast notification
function showToast(message, type = 'info') {
    const toastContainer = document.getElementById('toastContainer') || createToastContainer();
    
    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    toast.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
        <span>${message}</span>
    `;
    
    toastContainer.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease forwards';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toastContainer';
    container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999;';
    document.body.appendChild(container);
    return container;
}


// Download CSV template
function downloadTemplate() {
    const csv = 'Title,Author,Price,Stock,Status\n"Sample Book","John Doe",29.99,50,Active\n';
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'book_import_template.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// Process import (placeholder - would need backend implementation)
function processImport() {
    const fileInput = document.getElementById('importFile');
    if (!fileInput.files.length) {
        showToast('Please select a file to import', 'warning');
        return;
    }
    
    const file = fileInput.files[0];
    if (!file.name.endsWith('.csv')) {
        showToast('Please select a valid CSV file', 'warning');
        return;
    }
    
    // In a real implementation, you would:
    // 1. Read the CSV file
    // 2. Parse the data
    // 3. Send to backend via AJAX
    // 4. Handle response
    
    showToast('Import feature requires backend implementation', 'info');
    bootstrap.Modal.getInstance(document.getElementById('importModal')).hide();
}
