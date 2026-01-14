<?php
/**
 * MARKETFLOW PRO - ORDER CONTROLLER
 * Gestion des commandes (côté acheteur)
 * Fichier : app/controllers/OrderController.php
 */

namespace App\Controllers;

use Core\Controller;
use App\Models\Order;

class OrderController extends Controller {
    private $orderModel;

    public function __construct() {
        parent::__construct();
        $this->orderModel = new Order();
        $this->requireLogin();
    }

    /**
     * Liste des commandes de l'utilisateur
     */
    public function index() {
        $userId = $_SESSION['user_id'];
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $perPage = 10;

        // Récupérer les commandes
        $orders = $this->orderModel->getUserOrders($userId, $perPage * 10);

        // Calculer les statistiques
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN payment_status = 'completed' THEN total_amount ELSE 0 END) as total_spent,
                COUNT(DISTINCT oi.product_id) as total_products
            FROM orders o
            LEFT JOIN order_items oi ON o.id = oi.order_id
            WHERE o.buyer_id = ?
        ");
        $stmt->execute([$userId]);
        $stats = $stmt->fetch();

        $this->view('orders/index', [
            'title' => 'Mes Commandes',
            'orders' => $orders,
            'stats' => $stats,
            'current_page' => $page
        ]);
    }

    /**
     * Détail d'une commande
     */
    public function show($orderNumber) {
        $userId = $_SESSION['user_id'];
        
        // Récupérer la commande avec détails
        $order = $this->orderModel->getOrderDetails($orderNumber, $userId);

        if (!$order) {
            redirectWithMessage('/orders', 'Commande introuvable', 'error');
            return;
        }

        $this->view('orders/show', [
            'title' => 'Commande ' . $orderNumber,
            'order' => $order,
            'csrf_token' => generateCsrfToken()
        ]);
    }

    /**
     * Télécharger un produit
     */
    public function download($orderNumber, $itemId) {
        $userId = $_SESSION['user_id'];

        // Vérifier que la commande appartient à l'utilisateur
        $order = $this->orderModel->getOrderDetails($orderNumber, $userId);

        if (!$order) {
            redirectWithMessage('/orders', 'Commande introuvable', 'error');
            return;
        }

        // Vérifier que la commande est payée
        if ($order['payment_status'] !== 'completed') {
            redirectWithMessage(
                "/orders/{$orderNumber}",
                'Cette commande n\'est pas encore payée',
                'error'
            );
            return;
        }

        // Trouver l'item
        $item = null;
        foreach ($order['items'] as $orderItem) {
            if ($orderItem['id'] == $itemId) {
                $item = $orderItem;
                break;
            }
        }

        if (!$item) {
            redirectWithMessage(
                "/orders/{$orderNumber}",
                'Produit introuvable dans cette commande',
                'error'
            );
            return;
        }

        // Enregistrer le téléchargement
        $result = $this->orderModel->recordDownload($itemId, $userId);

        if (!$result['success']) {
            redirectWithMessage(
                "/orders/{$orderNumber}",
                $result['error'],
                'error'
            );
            return;
        }

        // Servir le fichier
        $this->serveFile($item);
    }

    /**
     * Servir un fichier en téléchargement
     */
    private function serveFile($item) {
        // Verify file_path is set
        if (empty($item['file_path'])) {
            error_log("File path is empty for item: " . json_encode($item));
            redirectWithMessage(
                '/orders',
                'Erreur interne. Contactez le support.',
                'error'
            );
            return;
        }
        
        // Sanitize file path - remove directory traversal sequences and normalize
        $sanitizedPath = str_replace(['..', '\\'], ['', '/'], $item['file_path']);
        $sanitizedPath = ltrim($sanitizedPath, '/');
        
        // Resolve real path and verify it's within UPLOAD_DIR
        $uploadDir = realpath(UPLOAD_DIR);
        $requestedPath = $uploadDir . DIRECTORY_SEPARATOR . $sanitizedPath;
        $filePath = realpath($requestedPath);
        
        // Verify path is valid and within UPLOAD_DIR (path traversal and symlink protection)
        if ($filePath === false || strpos($filePath, $uploadDir) !== 0) {
            error_log("Invalid file path attempted: " . $item['file_path'] . " (sanitized: " . $sanitizedPath . ")");
            redirectWithMessage(
                '/orders',
                'Erreur interne. Contactez le support.',
                'error'
            );
            return;
        }
        
        // Additional check: ensure the resolved path doesn't escape via symlink
        if (!file_exists($filePath) || is_link($requestedPath)) {
            error_log("Symlink or non-existent file detected: " . $requestedPath);
            redirectWithMessage(
                '/orders',
                'Erreur interne. Contactez le support.',
                'error'
            );
            return;
        }

        // Vérifier que le fichier existe et est un fichier régulier
        if (!is_file($filePath)) {
            error_log("Path is not a regular file: " . $filePath);
            redirectWithMessage(
                '/orders',
                'Fichier introuvable. Contactez le support.',
                'error'
            );
            return;
        }

        // Définir les headers pour le téléchargement
        $filename = sanitizeFilename($item['product_title']) . '.' . pathinfo($filePath, PATHINFO_EXTENSION);
        $filesize = filesize($filePath);
        
        // Detect MIME type safely with error handling
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            error_log("Failed to initialize finfo for file serving");
            $mimetype = 'application/octet-stream'; // Fallback MIME type
        } else {
            $mimetype = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            if ($mimetype === false) {
                error_log("Failed to detect MIME type for file: " . $filePath);
                $mimetype = 'application/octet-stream'; // Fallback MIME type
            }
        }

        // Escape filename for Content-Disposition header (RFC 6266 compliant)
        $escapedFilename = str_replace(['\\', '"'], ['\\\\', '\\"'], $filename);

        // Headers de téléchargement
        header('Content-Type: ' . $mimetype);
        header('Content-Disposition: attachment; filename="' . $escapedFilename . '"');
        header('Content-Length: ' . $filesize);
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Expires: 0');

        // Nettoyer le buffer de sortie
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Stream file in chunks to avoid memory issues
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            error_log("Failed to open file: " . $filePath);
            redirectWithMessage(
                '/orders',
                'Erreur lors du téléchargement. Contactez le support.',
                'error'
            );
            return;
        }
        
        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }
        
        fclose($handle);
        exit;
    }

    /**
     * Demander un remboursement
     */
    public function requestRefund() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/orders');
        }

        // Vérifier CSRF
        if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
            $this->json(['success' => false, 'error' => 'Token invalide'], 403);
        }

        $orderNumber = $_POST['order_number'] ?? null;
        $reason = $_POST['reason'] ?? '';

        if (!$orderNumber || empty($reason)) {
            redirectWithMessage('/orders', 'Veuillez fournir un motif', 'error');
            return;
        }

        $userId = $_SESSION['user_id'];
        $order = $this->orderModel->getOrderDetails($orderNumber, $userId);

        if (!$order) {
            redirectWithMessage('/orders', 'Commande introuvable', 'error');
            return;
        }

        // Vérifier que la commande peut être remboursée
        if ($order['payment_status'] !== 'completed') {
            redirectWithMessage(
                "/orders/{$orderNumber}",
                'Cette commande ne peut pas être remboursée',
                'error'
            );
            return;
        }

        // Vérifier le délai (par ex: 14 jours)
        $orderDate = strtotime($order['paid_at']);
        $daysSincePurchase = (time() - $orderDate) / (60 * 60 * 24);

        if ($daysSincePurchase > 14) {
            redirectWithMessage(
                "/orders/{$orderNumber}",
                'Le délai de remboursement (14 jours) est dépassé',
                'error'
            );
            return;
        }

        // Créer la demande de remboursement
        $stmt = $this->db->prepare("
            INSERT INTO refund_requests (
                order_id, buyer_id, reason, status, created_at
            ) VALUES (?, ?, ?, 'pending', ?)
        ");

        try {
            $stmt->execute([
                $order['id'],
                $userId,
                $reason,
                date('Y-m-d H:i:s')
            ]);

            // Notifier l'admin (TODO: email)
            $this->logActivity(
                $userId,
                'refund_requested',
                'order',
                $order['id'],
                ['reason' => $reason]
            );

            redirectWithMessage(
                "/orders/{$orderNumber}",
                'Votre demande de remboursement a été envoyée. Un administrateur va l\'examiner.',
                'success'
            );

        } catch (\PDOException $e) {
            redirectWithMessage(
                "/orders/{$orderNumber}",
                'Erreur lors de l\'envoi de la demande',
                'error'
            );
        }
    }

    /**
     * Soumettre un avis sur un produit
     */
    public function submitReview() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/orders');
        }

        // Vérifier CSRF
        if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
            $this->json(['success' => false, 'error' => 'Token invalide'], 403);
        }

        $orderItemId = $_POST['order_item_id'] ?? null;
        $productId = $_POST['product_id'] ?? null;
        $rating = $_POST['rating'] ?? null;
        $comment = $_POST['comment'] ?? '';

        // Validation
        if (!$orderItemId || !$productId || !$rating) {
            redirectWithMessage($_SERVER['HTTP_REFERER'] ?? '/orders', 'Données manquantes', 'error');
            return;
        }

        if ($rating < 1 || $rating > 5) {
            redirectWithMessage($_SERVER['HTTP_REFERER'] ?? '/orders', 'Note invalide', 'error');
            return;
        }

        $userId = $_SESSION['user_id'];

        // Vérifier que l'utilisateur a bien acheté le produit
        $stmt = $this->db->prepare("
            SELECT oi.seller_id, o.payment_status
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE oi.id = ? AND o.buyer_id = ?
        ");
        $stmt->execute([$orderItemId, $userId]);
        $purchase = $stmt->fetch();

        if (!$purchase || $purchase['payment_status'] !== 'completed') {
            redirectWithMessage($_SERVER['HTTP_REFERER'] ?? '/orders', 'Achat non vérifié', 'error');
            return;
        }

        // Vérifier qu'il n'a pas déjà laissé un avis
        $stmt = $this->db->prepare("
            SELECT id FROM reviews WHERE order_item_id = ?
        ");
        $stmt->execute([$orderItemId]);

        if ($stmt->fetch()) {
            redirectWithMessage($_SERVER['HTTP_REFERER'] ?? '/orders', 'Vous avez déjà laissé un avis', 'error');
            return;
        }

        // Créer l'avis
        $stmt = $this->db->prepare("
            INSERT INTO reviews (
                product_id, order_item_id, buyer_id, seller_id,
                rating, comment, is_verified_purchase, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 1, ?)
        ");

        try {
            $stmt->execute([
                $productId,
                $orderItemId,
                $userId,
                $purchase['seller_id'],
                $rating,
                $comment,
                date('Y-m-d H:i:s')
            ]);

            // Mettre à jour les notes du produit et vendeur
            $this->updateProductRating($productId);
            $this->updateSellerRating($purchase['seller_id']);

            redirectWithMessage(
                $_SERVER['HTTP_REFERER'] ?? '/orders',
                'Merci pour votre avis !',
                'success'
            );

        } catch (\PDOException $e) {
            redirectWithMessage(
                $_SERVER['HTTP_REFERER'] ?? '/orders',
                'Erreur lors de la soumission de l\'avis',
                'error'
            );
        }
    }

    /**
     * Télécharger une facture (PDF)
     */
    public function downloadInvoice($orderNumber) {
        $userId = $_SESSION['user_id'];
        $order = $this->orderModel->getOrderDetails($orderNumber, $userId);

        if (!$order || $order['payment_status'] !== 'completed') {
            redirectWithMessage('/orders', 'Commande introuvable', 'error');
            return;
        }

        // Générer le PDF de facture
        $this->generateInvoicePDF($order);
    }

    /**
     * Générer une facture PDF (simplifié)
     */
    private function generateInvoicePDF($order) {
        // TODO: Intégrer une librairie PDF (TCPDF, FPDF, DomPDF)
        // Pour l'instant, on génère un HTML simple

        header('Content-Type: text/html; charset=utf-8');
        
        echo '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Facture ' . htmlspecialchars($order['order_number']) . '</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 40px; }
                .header { border-bottom: 3px solid #0ea5e9; padding-bottom: 20px; margin-bottom: 30px; }
                .invoice-title { font-size: 28px; color: #0ea5e9; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
                th { background: #f8fafc; font-weight: 600; }
                .total { font-size: 20px; font-weight: 700; text-align: right; }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="invoice-title">FACTURE</div>
                <div>N° ' . htmlspecialchars($order['order_number']) . '</div>
                <div>Date: ' . date('d/m/Y', strtotime($order['paid_at'])) . '</div>
            </div>
            
            <div style="margin-bottom: 30px;">
                <strong>Client:</strong><br>
                ' . htmlspecialchars($order['buyer_email']) . '
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th>Vendeur</th>
                        <th style="text-align: right;">Prix</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($order['items'] as $item) {
            echo '<tr>
                <td>' . htmlspecialchars($item['product_title']) . '</td>
                <td>' . htmlspecialchars($item['shop_name'] ?? $item['seller_username']) . '</td>
                <td style="text-align: right;">' . formatPrice($item['price']) . '</td>
            </tr>';
        }

        echo '</tbody>
            </table>

            <div class="total">
                Total: ' . formatPrice($order['total_amount']) . '
            </div>

            <div style="margin-top: 40px; font-size: 12px; color: #666;">
                MarketFlow Pro - Marketplace digitale<br>
                Paiement sécurisé par Stripe
            </div>
        </body>
        </html>';
        exit;
    }

    /**
     * Mettre à jour la note d'un produit
     */
    private function updateProductRating($productId) {
        $stmt = $this->db->prepare("
            UPDATE products 
            SET rating_average = (
                SELECT AVG(rating) FROM reviews WHERE product_id = ? AND is_approved = 1
            ),
            rating_count = (
                SELECT COUNT(*) FROM reviews WHERE product_id = ? AND is_approved = 1
            )
            WHERE id = ?
        ");
        $stmt->execute([$productId, $productId, $productId]);
    }

    /**
     * Mettre à jour la note d'un vendeur
     */
    private function updateSellerRating($sellerId) {
        $stmt = $this->db->prepare("
            UPDATE seller_profiles 
            SET rating_average = (
                SELECT AVG(rating) FROM reviews WHERE seller_id = ? AND is_approved = 1
            ),
            rating_count = (
                SELECT COUNT(*) FROM reviews WHERE seller_id = ? AND is_approved = 1
            )
            WHERE user_id = ?
        ");
        $stmt->execute([$sellerId, $sellerId, $sellerId]);
    }

    /**
     * Logger une activité
     */
    private function logActivity($userId, $action, $entityType, $entityId, $details = null) {
        $stmt = $this->db->prepare("
            INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $details ? json_encode($details) : null,
            date('Y-m-d H:i:s')
        ]);
    }
}

/**
 * Helper: Nettoyer un nom de fichier
 */
function sanitizeFilename($filename) {
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    return substr($filename, 0, 200);
}