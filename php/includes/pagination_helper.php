<?php
/**
 * Professional Pagination Helper
 * Generates consistent, accessible pagination HTML across all admin pages
 */

/**
 * Generate professional pagination HTML
 * 
 * @param int $current_page Current page number
 * @param int $total_pages Total number of pages
 * @param int $total_items Total number of items
 * @param int $items_per_page Items per page
 * @param string $base_url Base URL for pagination links (without page parameter)
 * @param string $item_name Name of items being paginated (e.g., "books", "orders")
 * @return string HTML for pagination
 */
function render_pagination($current_page, $total_pages, $total_items, $items_per_page, $base_url = '?', $item_name = 'items') {
    if ($total_pages <= 1) {
        return '';
    }
    
    $offset = ($current_page - 1) * $items_per_page;
    $start_item = min($offset + 1, $total_items);
    $end_item = min($offset + $items_per_page, $total_items);
    
    // Add separator to base URL if needed
    $separator = (strpos($base_url, '?') !== false) ? '&' : '?';
    
    ob_start();
    ?>
    <div class="card-footer pagination-footer">
        <div class="pagination-wrapper">
            <nav aria-label="<?php echo ucfirst($item_name); ?> pagination">
                <ul class="pagination pagination-sm mb-0">
                    <?php if ($current_page > 1): ?>
                        <li class="page-item">
                            <a class="page-link page-nav" href="<?php echo $base_url . $separator; ?>page=1" aria-label="First page">
                                <i class="fas fa-angle-double-left"></i>
                                <span class="d-none d-sm-inline">First</span>
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link page-nav" href="<?php echo $base_url . $separator; ?>page=<?php echo $current_page - 1; ?>" aria-label="Previous page">
                                <i class="fas fa-angle-left"></i>
                                <span class="d-none d-sm-inline">Previous</span>
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="page-item disabled">
                            <span class="page-link page-nav">
                                <i class="fas fa-angle-double-left"></i>
                                <span class="d-none d-sm-inline">First</span>
                            </span>
                        </li>
                        <li class="page-item disabled">
                            <span class="page-link page-nav">
                                <i class="fas fa-angle-left"></i>
                                <span class="d-none d-sm-inline">Previous</span>
                            </span>
                        </li>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    
                    // Show first page and ellipsis if needed
                    if ($start_page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo $base_url . $separator; ?>page=1">1</a>
                        </li>
                        <?php if ($start_page > 2): ?>
                            <li class="page-item ellipsis">
                                <span class="page-link">...</span>
                            </li>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo $base_url . $separator; ?>page=<?php echo $i; ?>" <?php echo $i == $current_page ? 'aria-current="page"' : ''; ?>><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php 
                    // Show ellipsis and last page if needed
                    if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                            <li class="page-item ellipsis">
                                <span class="page-link">...</span>
                            </li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo $base_url . $separator; ?>page=<?php echo $total_pages; ?>"><?php echo $total_pages; ?></a>
                        </li>
                    <?php endif; ?>
                    
                    <?php if ($current_page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link page-nav" href="<?php echo $base_url . $separator; ?>page=<?php echo $current_page + 1; ?>" aria-label="Next page">
                                <span class="d-none d-sm-inline">Next</span>
                                <i class="fas fa-angle-right"></i>
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link page-nav" href="<?php echo $base_url . $separator; ?>page=<?php echo $total_pages; ?>" aria-label="Last page">
                                <span class="d-none d-sm-inline">Last</span>
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="page-item disabled">
                            <span class="page-link page-nav">
                                <span class="d-none d-sm-inline">Next</span>
                                <i class="fas fa-angle-right"></i>
                            </span>
                        </li>
                        <li class="page-item disabled">
                            <span class="page-link page-nav">
                                <span class="d-none d-sm-inline">Last</span>
                                <i class="fas fa-angle-double-right"></i>
                            </span>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Build URL with existing query parameters
 * 
 * @param array $exclude_params Parameters to exclude from URL
 * @return string Base URL with existing parameters
 */
function build_pagination_url($exclude_params = ['page']) {
    $params = $_GET;
    foreach ($exclude_params as $param) {
        unset($params[$param]);
    }
    
    if (empty($params)) {
        return '?';
    }
    
    return '?' . http_build_query($params);
}
?>
