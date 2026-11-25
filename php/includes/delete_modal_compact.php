<?php
/**
 * Compact Delete Modal Template
 * Usage: include this file and set $modal_config before including
 * 
 * Required $modal_config keys:
 * - form_id: ID for the form element
 * - user_type: 'admin' or 'customer'
 * - name_field_id: ID for the username display field
 * - id_field_id: ID for the hidden ID field
 */

$form_id = $modal_config['form_id'] ?? 'deleteForm';
$user_type = $modal_config['user_type'] ?? 'user';
$name_field_id = $modal_config['name_field_id'] ?? 'delete_user_name';
$id_field_id = $modal_config['id_field_id'] ?? 'delete_user_id';
$user_type_display = ucfirst($user_type);
?>

<!-- Compact Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header delete-modal-header">
                <h5 class="modal-title" id="deleteModalLabel">
                    <i class="fas fa-exclamation-triangle me-2 text-danger"></i>
                    Confirm Deletion with Proof
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="<?php echo $form_id; ?>" enctype="multipart/form-data">
                <div class="modal-body delete-modal-body-compact">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="<?php echo $user_type; ?>_id" id="<?php echo $id_field_id; ?>">
                    
                    <div class="row g-3">
                        <!-- Left Column: Warning & Info -->
                        <div class="col-md-6">
                            <div class="delete-warning-box-compact">
                                <div class="delete-icon-compact">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <h6 class="delete-title-compact">Delete <?php echo $user_type_display; ?></h6>
                                <div class="delete-user-info-compact">
                                    <i class="fas fa-user me-2"></i>
                                    <span id="<?php echo $name_field_id; ?>"></span>
                                </div>
                            </div>
                            
                            <div class="delete-consequences-compact">
                                <div class="consequence-item-compact">
                                    <i class="fas fa-times-circle text-danger"></i>
                                    <span>Revokes system access</span>
                                </div>
                                <div class="consequence-item-compact">
                                    <i class="fas fa-exclamation-circle text-warning"></i>
                                    <span>Cannot be undone</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Right Column: Proof Upload -->
                        <div class="col-md-6">
                            <div class="proof-upload-compact">
                                <label class="proof-label-compact">
                                    <i class="fas fa-shield-alt text-primary me-2"></i>
                                    <strong>Proof Required</strong>
                                </label>
                                
                                <label for="deletion_proof_image" class="proof-dropzone-compact">
                                    <div id="proof_preview_container" class="proof-preview-compact" style="display: none;">
                                        <img id="proof_preview_content" src="" alt="Preview" style="max-width: 100%; max-height: 120px; border-radius: 6px;">
                                        <button type="button" class="proof-remove-compact" onclick="removeProofImage()">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    <div id="proof_upload_placeholder" class="proof-placeholder-compact">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <span>Click to upload</span>
                                        <small>PNG, JPG, PDF (Max 5MB)</small>
                                    </div>
                                    <input type="file" 
                                           id="deletion_proof_image" 
                                           name="deletion_proof_image" 
                                           accept="image/png,image/jpeg,image/jpg,application/pdf" 
                                           style="display: none;"
                                           required>
                                </label>
                                
                                <textarea class="form-control form-control-sm mt-2" 
                                          id="deletion_reason" 
                                          name="deletion_reason" 
                                          rows="2" 
                                          placeholder="Reason (optional)"
                                          style="font-size: 12px;"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Confirmation Checkbox -->
                    <div class="delete-confirm-compact">
                        <label class="form-check-label">
                            <input type="checkbox" id="confirmDeleteCheckbox" class="form-check-input me-2">
                            I have attached valid proof and confirm deletion
                        </label>
                    </div>
                </div>
                
                <div class="modal-footer delete-modal-footer-compact">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-danger btn-sm" id="confirmDeleteBtn" disabled>
                        <i class="fas fa-trash me-1"></i>
                        Delete with Proof
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<link rel="stylesheet" href="../css/delete_modal_compact.css">
