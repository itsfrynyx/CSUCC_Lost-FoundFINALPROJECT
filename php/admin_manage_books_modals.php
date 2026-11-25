<!-- Add Book Modal -->
<div class="modal fade" id="addBookModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Add New Book</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addBookForm" method="POST" action="admin_manage_books.php" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="fas fa-book me-2"></i>Book Title</label>
                            <input type="text" class="form-control" name="title" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="fas fa-user me-2"></i>Author</label>
                            <input type="text" class="form-control" name="author" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="fas fa-peso-sign me-2"></i>Price</label>
                            <input type="number" class="form-control" name="price" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="fas fa-boxes me-2"></i>Stock Quantity</label>
                            <input type="number" class="form-control" name="stock_quantity" min="0" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="fas fa-barcode me-2"></i>ISBN (Optional)</label>
                            <input type="text" class="form-control" name="isbn">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="fas fa-toggle-on me-2"></i>Status</label>
                            <select class="form-select" name="is_active" required>
                                <option value="1" selected>Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-align-left me-2"></i>Description (Optional)</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-image me-2"></i>Book Cover Image <span class="text-danger">*</span></label>
                        <div class="alert alert-info py-2 mb-2">
                            <i class="fas fa-info-circle me-2"></i>
                            <small><strong>Important:</strong> Please upload a cover image for better presentation</small>
                        </div>
                        
                        <!-- Simple file input - NO HIDING -->
                        <input type="file" class="form-control" id="addCoverImage" name="cover_image" accept="image/*" required>
                        
                        <!-- Preview area -->
                        <div id="addImagePreview" class="mt-3" style="display:none; text-align:center;">
                            <img id="addPreviewImg" src="" style="max-width: 100%; max-height: 300px; border-radius: 8px; border: 2px solid #28a745;">
                            <p class="mt-2 mb-0 text-success"><i class="fas fa-check-circle me-1"></i>Image selected successfully!</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">
                        <i class="class="fas fa-save""></i>Add Book
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Book Modal - Balanced Layout -->
<div class="modal fade" id="editBookModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 12px; border: 1px solid #E2E8F0; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);">
            <div class="modal-header" style="background: white; padding: 20px 28px; border-bottom: 1px solid #E2E8F0;">
                <div>
                    <h5 class="modal-title" style="font-size: 18px; font-weight: 600; color: #0F172A; margin-bottom: 2px;">Edit Book</h5>
                    <p class="mb-0" style="font-size: 12px; color: #64748B;">Update book information</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <form id="editBookForm" method="POST" action="admin_manage_books.php" enctype="multipart/form-data">
                <div class="modal-body" style="padding: 28px; background: white;">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" id="editBookId" name="book_id">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" style="font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Title</label>
                            <input type="text" class="form-control" id="editTitle" name="title" required style="padding: 10px 14px; border: 1px solid #E2E8F0; border-radius: 6px; font-size: 14px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" style="font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Author</label>
                            <input type="text" class="form-control" id="editAuthor" name="author" required style="padding: 10px 14px; border: 1px solid #E2E8F0; border-radius: 6px; font-size: 14px;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Price (₱)</label>
                            <input type="number" class="form-control" id="editPrice" name="price" step="0.01" required style="padding: 10px 14px; border: 1px solid #E2E8F0; border-radius: 6px; font-size: 14px;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Stock</label>
                            <input type="number" class="form-control" id="editStock" name="stock_quantity" required style="padding: 10px 14px; border: 1px solid #E2E8F0; border-radius: 6px; font-size: 14px;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Status</label>
                            <select class="form-select" id="editStatus" name="is_active" required style="padding: 10px 14px; border: 1px solid #E2E8F0; border-radius: 6px; font-size: 14px;">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" style="font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Description</label>
                            <textarea class="form-control" id="editDescription" name="description" rows="3" style="padding: 10px 14px; border: 1px solid #E2E8F0; border-radius: 6px; font-size: 13px; line-height: 1.5; resize: vertical;"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label" style="font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Cover Image</label>
                            <div class="image-preview-container" id="editImagePreview" onclick="document.getElementById('editCoverImage').click()" style="cursor: pointer; border: 2px dashed #E2E8F0; border-radius: 6px; padding: 20px; text-align: center; background: #F8FAFC; transition: all 0.2s;" onmouseover="this.style.borderColor='#CBD5E1';" onmouseout="this.style.borderColor='#E2E8F0';">
                                <i class="fas fa-image" style="font-size: 28px; color: #CBD5E1; margin-bottom: 6px;"></i>
                                <p class="mb-0" style="font-size: 13px; color: #475569; font-weight: 500;">Click to upload or keep current</p>
                            </div>
                            <input type="file" class="form-control d-none" id="editCoverImage" name="cover_image" accept="image/*">
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer" style="border-top: 1px solid #E2E8F0; padding: 16px 28px; background: white; gap: 10px;">
                    <button type="button" class="btn" data-bs-dismiss="modal" style="flex: 1; padding: 10px; border-radius: 6px; font-weight: 500; font-size: 14px; border: 1px solid #E2E8F0; background: white; color: #475569;">
                        Cancel
                    </button>
                    <button type="submit" class="btn" style="flex: 1; padding: 10px; border-radius: 6px; font-weight: 500; font-size: 14px; background: #2563EB; border: none; color: white;">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- View Book Modal - No Scroll Layout -->
<div class="modal fade" id="viewBookModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 12px; border: 1px solid #E2E8F0; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);">
            <div class="modal-header" style="background: white; padding: 16px 24px; border-bottom: 1px solid #E2E8F0;">
                <div>
                    <h5 class="modal-title" style="font-size: 17px; font-weight: 600; color: #0F172A; margin-bottom: 2px;">Book Details</h5>
                    <p class="mb-0" style="font-size: 11px; color: #64748B;">View information</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 20px 24px; background: white;">
                <div class="row g-3">
                    <div class="col-md-4 text-center">
                        <img id="viewCoverImage" src="" alt="Book Cover" style="max-width: 100%; max-height: 240px; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
                    </div>
                    <div class="col-md-8">
                        <h4 id="viewTitle" style="font-size: 19px; font-weight: 700; color: #0F172A; margin-bottom: 4px;"></h4>
                        <p style="font-size: 13px; color: #64748B; margin-bottom: 16px;">by <span id="viewAuthor" style="font-weight: 600; color: #475569;"></span></p>
                        
                        <div class="row g-2 mb-2">
                            <div class="col-4">
                                <div style="padding: 10px; background: #F8FAFC; border-radius: 6px; border: 1px solid #E2E8F0;">
                                    <div style="font-size: 9px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 3px; font-weight: 600;">Price</div>
                                    <div id="viewPrice" style="font-size: 17px; font-weight: 700; color: #0F172A;"></div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div style="padding: 10px; background: #F8FAFC; border-radius: 6px; border: 1px solid #E2E8F0;">
                                    <div style="font-size: 9px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 3px; font-weight: 600;">Stock</div>
                                    <div id="viewStock" style="font-size: 17px; font-weight: 700; color: #0F172A;"></div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div style="padding: 10px; background: #F8FAFC; border-radius: 6px; border: 1px solid #E2E8F0;">
                                    <div style="font-size: 9px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 3px; font-weight: 600;">Status</div>
                                    <div id="viewStatus" style="font-size: 13px; font-weight: 600;"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <div style="font-size: 9px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; font-weight: 600;">Description</div>
                            <div id="viewDescription" style="font-size: 12px; color: #475569; line-height: 1.5; padding: 10px; background: #F8FAFC; border-radius: 6px; border: 1px solid #E2E8F0; max-height: 100px; overflow-y: auto;"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid #E2E8F0; padding: 12px 24px; background: white;">
                <button type="button" class="btn" data-bs-dismiss="modal" style="padding: 8px 20px; border-radius: 6px; font-weight: 500; font-size: 13px; border: 1px solid #E2E8F0; background: white; color: #475569;">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteBookModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="class="fas fa-exclamation-triangle""></i>Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <i class="class="fas fa-trash-alt" text-danger" style="font-size: 4rem; margin-bottom: 1rem;"></i>
                <h4>Are you sure?</h4>
                <p class="text-muted">You are about to delete:</p>
                <p class="fw-bold" id="deleteBookTitle"></p>
                <div class="alert alert-warning">
                    <i class="class="fas fa-exclamation-circle""></i>
                    This action cannot be undone!
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="class="fas fa-trash-alt""></i>Yes, Delete
                </button>
            </div>
        </div>
    </div>
</div>



<!-- Import Books Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import"></i> Import Books</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Upload a CSV file with columns: Title, Author, Price, Stock, Status
                </div>
                <div class="mb-3">
                    <label class="form-label">Select CSV File</label>
                    <input type="file" class="form-control" accept=".csv" id="importFile">
                </div>
                <div class="mb-3">
                    <a href="#" onclick="downloadTemplate(); return false;" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-download me-2"></i>Download Template
                    </a>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary-custom" onclick="processImport()">
                    <i class="fas fa-upload"></i> Import
                </button>
            </div>
        </div>
    </div>
</div>
