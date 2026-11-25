/**
 * Compact Delete Modal with Proof Upload - JavaScript
 * Handles file upload, preview, and validation
 */

// Show proof image preview
function showProofPreview(file) {
    const previewContainer = document.getElementById('proof_preview_container');
    const previewContent = document.getElementById('proof_preview_content');
    const placeholder = document.getElementById('proof_upload_placeholder');
    
    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewContent.src = e.target.result;
            previewContent.style.display = 'block';
            previewContainer.style.display = 'flex';
            if (placeholder) placeholder.style.display = 'none';
        };
        reader.readAsDataURL(file);
    } else if (file.type === 'application/pdf') {
        previewContent.src = '';
        previewContent.style.display = 'none';
        previewContent.insertAdjacentHTML('afterend', '<div id="pdf_icon" style="text-align: center; padding: 20px;"><i class="fas fa-file-pdf" style="font-size: 48px; color: #dc3545;"></i><p class="mt-2 mb-0" style="font-size: 12px;">PDF Document</p></div>');
        previewContainer.style.display = 'flex';
        if (placeholder) placeholder.style.display = 'none';
    }
}

// Remove proof image
function removeProofImage() {
    const proofInput = document.getElementById('deletion_proof_image');
    const previewContainer = document.getElementById('proof_preview_container');
    const previewContent = document.getElementById('proof_preview_content');
    const placeholder = document.getElementById('proof_upload_placeholder');
    const deleteBtn = document.getElementById('confirmDeleteBtn');
    const pdfIcon = document.getElementById('pdf_icon');
    
    if (proofInput) proofInput.value = '';
    if (previewContainer) previewContainer.style.display = 'none';
    if (previewContent) {
        previewContent.src = '';
        previewContent.style.display = 'none';
    }
    if (pdfIcon) pdfIcon.remove();
    if (placeholder) placeholder.style.display = 'flex';
    if (deleteBtn) deleteBtn.disabled = true;
    
    // Uncheck the checkbox
    const checkbox = document.getElementById('confirmDeleteCheckbox');
    if (checkbox) checkbox.checked = false;
}

// Initialize delete modal functionality
document.addEventListener('DOMContentLoaded', function() {
    const proofInput = document.getElementById('deletion_proof_image');
    const checkbox = document.getElementById('confirmDeleteCheckbox');
    const deleteBtn = document.getElementById('confirmDeleteBtn');
    const deleteForms = document.querySelectorAll('form[id*="delete"]');
    
    if (proofInput) {
        proofInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Validate file size (5MB max)
                if (file.size > 5 * 1024 * 1024) {
                    alert('File size must be less than 5MB');
                    proofInput.value = '';
                    return;
                }
                
                // Validate file type
                const validTypes = ['image/png', 'image/jpeg', 'image/jpg', 'application/pdf'];
                if (!validTypes.includes(file.type)) {
                    alert('Only PNG, JPG, JPEG, and PDF files are allowed');
                    proofInput.value = '';
                    return;
                }
                
                // Show preview
                showProofPreview(file);
                
                // Enable button if checkbox is checked
                if (checkbox && checkbox.checked) {
                    deleteBtn.disabled = false;
                }
            }
        });
    }
    
    // Enable/disable delete button based on checkbox and file upload
    if (checkbox && deleteBtn) {
        checkbox.addEventListener('change', function() {
            const hasFile = proofInput && proofInput.files.length > 0;
            deleteBtn.disabled = !(this.checked && hasFile);
        });
    }
    
    // Add loading state and validation on form submit
    deleteForms.forEach(function(deleteForm) {
        if (deleteForm && deleteBtn) {
            deleteForm.addEventListener('submit', function(e) {
                // Validate proof image is attached
                if (!proofInput || proofInput.files.length === 0) {
                    e.preventDefault();
                    alert('Please attach a proof image before proceeding with deletion');
                    return false;
                }
                
                deleteBtn.disabled = true;
                deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Deleting...';
            });
        }
    });
});
