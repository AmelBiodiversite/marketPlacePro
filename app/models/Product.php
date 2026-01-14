<?php
namespace App\Models;

use Core\Model;
use PDO;

/**
 * MARKETFLOW PRO - PRODUCT MODEL (POSTGRESQL)
 * Fichier : app/models/Product.php
 */

class Product extends Model {

    protected $table = 'products';
    protected $primaryKey = 'id';

    /**
     * Créer un nouveau produit
     */
    public function createProduct($data, $sellerId, $files) {
        try {
            // Générer slug unique
            $slug = $this->generateUniqueSlug($data['title']);

            // Préparer les données (sans tags)
            $productData = [
                'seller_id' => $sellerId,
                'category_id' => $data['category_id'],
                'title' => $data['title'],
                'slug' => $slug,
                'description' => $data['description'],
                'price' => $data['price'],
                'original_price' => !empty($data['original_price']) ? $data['original_price'] : null,
                'file_type' => $data['file_type'] ?? 'digital',
                'demo_url' => $data['demo_url'] ?? null,
                'status' => 'pending'
            ];

            // Upload des fichiers
            if (!empty($files['thumbnail']['name'])) {
                $thumbnailPath = $this->uploadThumbnail($files['thumbnail']);
                $productData['thumbnail_url'] = $thumbnailPath;
            }

            if (!empty($files['product_file']['name'])) {
                $filePath = $this->uploadProductFile($files['product_file']);
                $productData['file_url'] = $filePath;
                $productData['file_size'] = $files['product_file']['size'];
            }

            // Insérer le produit
            $fields = array_keys($productData);
            $placeholders = ':' . implode(', :', $fields);
            $fieldList = implode(', ', $fields);

            $sql = "INSERT INTO products ({$fieldList}) 
                    VALUES ({$placeholders}) 
                    RETURNING id";

            $stmt = $this->db->prepare($sql);

            foreach ($productData as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }

            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $productId = $result['id'];

            // Gérer les tags
            if (!empty($data['tags'])) {
                $this->attachTags($productId, $data['tags']);
            }

            return [
                'success' => true,
                'product_id' => $productId,
                'message' => 'Produit créé avec succès'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Attacher des tags à un produit
     */
    private function attachTags($productId, $tagsString) {
        $tags = explode(',', $tagsString);

        foreach ($tags as $tagName) {
            $tagName = trim($tagName);
            if (empty($tagName)) continue;

            // Vérifier si le tag existe
            $stmt = $this->db->prepare("SELECT id FROM tags WHERE name = :name");
            $stmt->execute(['name' => $tagName]);
            $tag = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tag) {
                // Créer le tag
                $slug = $this->slugify($tagName);
                $stmt = $this->db->prepare("INSERT INTO tags (name, slug) VALUES (:name, :slug) RETURNING id");
                $stmt->execute(['name' => $tagName, 'slug' => $slug]);
                $tag = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            // Attacher le tag au produit
            $stmt = $this->db->prepare("INSERT INTO product_tags (product_id, tag_id) VALUES (:product_id, :tag_id) ON CONFLICT DO NOTHING");
            $stmt->execute(['product_id' => $productId, 'tag_id' => $tag['id']]);
        }
    }

    /**
     * Upload thumbnail
     */
    private function uploadThumbnail($file) {
        // Define allowed MIME types for thumbnails (images only)
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif'];
        $maxSize = 50 * 1024 * 1024; // 50MB
        
        // Validate upload
        $validation = isValidUpload($file, $allowedMimes, $maxSize);
        if ($validation === false) {
            throw new \Exception('Fichier invalide');
        }
        
        // Prepare upload directory
        $uploadDir = rtrim(UPLOAD_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'products/thumbnails/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate secure random filename
        $filename = bin2hex(random_bytes(16)) . '.' . $validation['ext'];
        $filepath = $uploadDir . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new \Exception('Erreur lors de l\'upload');
        }
        
        return '/public/uploads/products/thumbnails/' . $filename;
    }

    /**
     * Upload product file
     */
    private function uploadProductFile($file) {
        // Define allowed MIME types for product files
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'application/zip', 'application/pdf', 'text/plain'];
        $maxSize = 50 * 1024 * 1024; // 50MB
        
        // Validate upload
        $validation = isValidUpload($file, $allowedMimes, $maxSize);
        if ($validation === false) {
            throw new \Exception('Fichier invalide');
        }
        
        // Prepare upload directory
        $uploadDir = rtrim(UPLOAD_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'products/files/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate secure random filename
        $filename = bin2hex(random_bytes(16)) . '.' . $validation['ext'];
        $filepath = $uploadDir . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new \Exception('Erreur lors de l\'upload');
        }
        
        return '/public/uploads/products/files/' . $filename;
    }

    /**
     * Générer un slug unique
     */
    private function generateUniqueSlug($title) {
        $slug = $this->slugify($title);
        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Vérifier si un slug existe
     */
    private function slugExists($slug) {
        $stmt = $this->db->prepare("SELECT id FROM products WHERE slug = :slug");
        $stmt->execute(['slug' => $slug]);
        return $stmt->fetch() !== false;
    }

    /**
     * Slugifier un texte
     */
    private function slugify($text) {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }

    /**
     * Obtenir les produits d'un vendeur
     */
    public function getSellerProducts($sellerId, $status = null, $limit = null) {
        $sql = "SELECT * FROM products WHERE seller_id = :seller_id";

        if ($status) {
            $sql .= " AND status = :status";
        }

        $sql .= " ORDER BY created_at DESC";

        if ($limit) {
            $sql .= " LIMIT :limit";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':seller_id', $sellerId, PDO::PARAM_INT);

        if ($status) {
            $stmt->bindValue(':status', $status);
        }

        if ($limit) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Obtenir un produit par ID avec infos vendeur
     */
    public function getProductWithSeller($id) {
        $sql = "SELECT p.*, 
                u.username as seller_username,
                u.full_name as seller_name,
                u.rating_average as seller_rating,
                c.name as category_name,
                c.slug as category_slug
                FROM products p
                LEFT JOIN users u ON p.seller_id = u.id
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.id = :id
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Obtenir un produit par slug
     */
    public function getProductBySlug($slug) {
        $sql = "SELECT p.*, 
                u.username as seller_username,
                u.full_name as seller_name,
                u.rating_average as seller_rating,
                c.name as category_name
                FROM products p
                LEFT JOIN users u ON p.seller_id = u.id
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.slug = :slug
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['slug' => $slug]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Obtenir les produits avec filtres
     */
    public function getProducts($filters = [], $page = 1, $perPage = 24) {
        $sql = "SELECT p.*, u.username as seller_name, c.name as category_name
                FROM products p
                LEFT JOIN users u ON p.seller_id = u.id
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.status = 'approved'";

        $params = [];

        // Filtres
        if (!empty($filters['category_id'])) {
            $sql .= " AND p.category_id = :category_id";
            $params['category_id'] = $filters['category_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (p.title ILIKE :search OR p.description ILIKE :search)";
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if (isset($filters['min_price'])) {
            $sql .= " AND p.price >= :min_price";
            $params['min_price'] = $filters['min_price'];
        }

        if (isset($filters['max_price'])) {
            $sql .= " AND p.price <= :max_price";
            $params['max_price'] = $filters['max_price'];
        }

        // Tri
        $sort = $filters['sort'] ?? 'newest';
        switch ($sort) {
            case 'price_asc':
                $sql .= " ORDER BY p.price ASC";
                break;
            case 'price_desc':
                $sql .= " ORDER BY p.price DESC";
                break;
            case 'popular':
                $sql .= " ORDER BY p.sales DESC";
                break;
            default:
                $sql .= " ORDER BY p.created_at DESC";
        }

        // Compter le total
        $countSql = "SELECT COUNT(*) FROM products p WHERE p.status = 'approved' ";
        if (!empty($params)) {
            // Ajouter les mêmes WHERE clauses
            foreach ($params as $key => $value) {
                $countSql .= " AND ";
                if ($key === 'search') {
                    $countSql .= "(p.title ILIKE :search OR p.description ILIKE :search)";
                } else {
                    $countSql .= "p.$key = :$key";
                }
            }
        }

        $stmtCount = $this->db->prepare($countSql);
        $stmtCount->execute($params);
        $total = $stmtCount->fetchColumn();

        // Pagination
        $offset = ($page - 1) * $perPage;
        $sql .= " LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $products = $stmt->fetchAll();

        return [
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'total_pages' => ceil($total / $perPage)
        ];
    }

    /**
     * Mettre à jour un produit
     */
    public function updateProduct($id, $sellerId, $data, $files) {
        // Vérifier que le produit appartient au vendeur
        $stmt = $this->db->prepare("SELECT id FROM products WHERE id = :id AND seller_id = :seller_id");
        $stmt->execute(['id' => $id, 'seller_id' => $sellerId]);

        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Produit introuvable'];
        }

        // Mettre à jour
        $updateData = [
            'title' => $data['title'],
            'description' => $data['description'],
            'category_id' => $data['category_id'],
            'price' => $data['price'],
            'original_price' => $data['original_price'] ?? null,
            'demo_url' => $data['demo_url'] ?? null
        ];

        $this->update($id, $updateData);

        // Mettre à jour les tags
        if (!empty($data['tags'])) {
            // Supprimer les anciens tags
            $stmt = $this->db->prepare("DELETE FROM product_tags WHERE product_id = :product_id");
            $stmt->execute(['product_id' => $id]);

            // Ajouter les nouveaux
            $this->attachTags($id, $data['tags']);
        }

        return ['success' => true, 'message' => 'Produit mis à jour'];
    }

    /**
     * Supprimer un produit
     */
    public function deleteProduct($id, $sellerId) {
        $stmt = $this->db->prepare("DELETE FROM products WHERE id = :id AND seller_id = :seller_id");
        $success = $stmt->execute(['id' => $id, 'seller_id' => $sellerId]);

        if ($success) {
            return ['success' => true, 'message' => 'Produit supprimé'];
        }

        return ['success' => false, 'error' => 'Erreur lors de la suppression'];
    }
}