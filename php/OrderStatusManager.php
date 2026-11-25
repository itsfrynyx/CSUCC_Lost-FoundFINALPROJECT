<?php
/**
 * Professional Order Status Management System
 * Handles order status transitions, history tracking, and notifications
 */

class OrderStatusManager {
    private $conn;
    private $admin_id;
    private $admin_role;
    
    // Valid status transitions
    private $valid_transitions = [
        'pending' => ['confirmed', 'processing', 'cancelled'],
        'confirmed' => ['processing', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered', 'cancelled'],
        'delivered' => ['refunded'],
        'cancelled' => [],
        'refunded' => []
    ];
    
    // Status display configuration
    private $status_config = [
        'pending' => [
            'label' => 'Pending',
            'icon' => 'fa-clock',
            'color' => '#F59E0B',
            'description' => 'Order received and awaiting confirmation'
        ],
        'confirmed' => [
            'label' => 'Confirmed',
            'icon' => 'fa-check-circle',
            'color' => '#3B82F6',
            'description' => 'Order confirmed and ready for processing'
        ],
        'processing' => [
            'label' => 'Processing',
            'icon' => 'fa-cog',
            'color' => '#6366F1',
            'description' => 'Order is being prepared for shipment'
        ],
        'shipped' => [
            'label' => 'Shipped',
            'icon' => 'fa-shipping-fast',
            'color' => '#8B5CF6',
            'description' => 'Order has been shipped and is in transit'
        ],
        'delivered' => [
            'label' => 'Delivered',
            'icon' => 'fa-check-double',
            'color' => '#10B981',
            'description' => 'Order successfully delivered to customer'
        ],
        'cancelled' => [
            'label' => 'Cancelled',
            'icon' => 'fa-times-circle',
            'color' => '#EF4444',
            'description' => 'Order has been cancelled'
        ],
        'refunded' => [
            'label' => 'Refunded',
            'icon' => 'fa-undo',
            'color' => '#6B7280',
            'description' => 'Order refunded to customer'
        ]
    ];
    
    public function __construct($conn, $admin_id = null, $admin_role = null) {
        $this->conn = $conn;
        $this->admin_id = $admin_id;
        $this->admin_role = $admin_role;
    }
    
    /**
     * Update order status with validation and history tracking
     */
    public function updateStatus($order_id, $new_status, $notes = '', $tracking_number = null, $estimated_delivery = null) {
        // Get current order details
        $stmt = $this->conn->prepare("SELECT status, user_id, order_number FROM orders WHERE id = ?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        
        if (!$order) {
            return ['success' => false, 'message' => 'Order not found'];
        }
        
        $old_status = $order['status'];
        
        // Validate status transition
        if (!$this->isValidTransition($old_status, $new_status)) {
            return [
                'success' => false,
                'message' => "Invalid status transition from '$old_status' to '$new_status'"
            ];
        }
        
        // Begin transaction
        $this->conn->begin_transaction();
        
        try {
            // Update order status
            $update_fields = ["status = ?", "last_status_change = NOW()", "updated_at = NOW()"];
            $params = [$new_status];
            $types = "s";
            
            // Add timestamp fields based on status
            if ($new_status === 'shipped') {
                $update_fields[] = "shipped_at = NOW()";
                if ($tracking_number) {
                    $update_fields[] = "tracking_number = ?";
                    $params[] = $tracking_number;
                    $types .= "s";
                }
                if ($estimated_delivery) {
                    $update_fields[] = "estimated_delivery = ?";
                    $params[] = $estimated_delivery;
                    $types .= "s";
                }
            } elseif ($new_status === 'delivered') {
                $update_fields[] = "delivered_at = NOW()";
            } elseif ($new_status === 'cancelled') {
                $update_fields[] = "cancelled_at = NOW()";
                if ($notes) {
                    $update_fields[] = "cancellation_reason = ?";
                    $params[] = $notes;
                    $types .= "s";
                }
            }
            
            if ($notes && $new_status !== 'cancelled') {
                $update_fields[] = "admin_notes = ?";
                $params[] = $notes;
                $types .= "s";
            }
            
            $params[] = $order_id;
            $types .= "i";
            
            $sql = "UPDATE orders SET " . implode(", ", $update_fields) . " WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            
            // Record status history
            $stmt = $this->conn->prepare(
                "INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, changed_by_role, notes) 
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("ississ", $order_id, $old_status, $new_status, $this->admin_id, $this->admin_role, $notes);
            $stmt->execute();
            
            // Create notification for customer
            $this->createNotification($order_id, $order['user_id'], $new_status, $order['order_number']);
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Order status updated successfully',
                'old_status' => $old_status,
                'new_status' => $new_status
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['success' => false, 'message' => 'Error updating status: ' . $e->getMessage()];
        }
    }
    
    /**
     * Check if status transition is valid
     */
    private function isValidTransition($from_status, $to_status) {
        if ($from_status === $to_status) {
            return false;
        }
        return in_array($to_status, $this->valid_transitions[$from_status] ?? []);
    }
    
    /**
     * Get valid next statuses for current status
     */
    public function getValidNextStatuses($current_status) {
        return $this->valid_transitions[$current_status] ?? [];
    }
    
    /**
     * Create notification for customer and send email
     */
    private function createNotification($order_id, $user_id, $status, $order_number) {
        $config = $this->status_config[$status];
        $title = "Order {$config['label']}";
        $message = "Your order #{$order_number} is now {$config['label']}. {$config['description']}.";
        
        // Create notification record
        $stmt = $this->conn->prepare(
            "INSERT INTO order_notifications (order_id, user_id, notification_type, title, message, status) 
             VALUES (?, ?, 'status_update', ?, ?, 'pending')"
        );
        $stmt->bind_param("iiss", $order_id, $user_id, $title, $message);
        $stmt->execute();
        
        // Send email notification
        $this->sendEmailNotification($order_id, $user_id, $status, $order_number);
    }
    
    /**
     * Send email notification to customer
     */
    private function sendEmailNotification($order_id, $user_id, $new_status, $order_number) {
        // Get customer details
        $stmt = $this->conn->prepare("
            SELECT email, COALESCE(full_name, CONCAT(firstname, ' ', lastname)) as name
            FROM infos 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();
        
        if (!$customer || !$customer['email']) {
            return false;
        }
        
        // Get order details for tracking info
        $stmt = $this->conn->prepare("
            SELECT tracking_number, estimated_delivery 
            FROM orders 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        
        // Load email service
        require_once __DIR__ . '/EmailNotificationService.php';
        $emailService = new EmailNotificationService($this->conn);
        
        // Send email
        return $emailService->sendOrderStatusEmail(
            $order_id,
            $customer['email'],
            $customer['name'],
            $order_number,
            null, // old_status not needed for now
            $new_status,
            $order['tracking_number'] ?? null,
            $order['estimated_delivery'] ?? null
        );
    }
    
    /**
     * Get order status history
     */
    public function getStatusHistory($order_id) {
        $stmt = $this->conn->prepare("
            SELECT 
                osh.*,
                i.full_name as changed_by_name,
                i.username as changed_by_username
            FROM order_status_history osh
            LEFT JOIN infos i ON osh.changed_by = i.id
            WHERE osh.order_id = ?
            ORDER BY osh.created_at DESC
        ");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Get status configuration
     */
    public function getStatusConfig($status = null) {
        if ($status) {
            return $this->status_config[$status] ?? null;
        }
        return $this->status_config;
    }
    
    /**
     * Get order statistics by status
     */
    public function getStatusStatistics() {
        $stats = [];
        $result = $this->conn->query("
            SELECT status, COUNT(*) as count, SUM(total_amount) as total_amount
            FROM orders
            GROUP BY status
        ");
        
        while ($row = $result->fetch_assoc()) {
            $stats[$row['status']] = [
                'count' => (int)$row['count'],
                'total_amount' => (float)$row['total_amount'],
                'config' => $this->status_config[$row['status']]
            ];
        }
        
        return $stats;
    }
}
