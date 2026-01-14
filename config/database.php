<?php
namespace Core;

use PDO;
use PDOException;
use Exception;

/**
 * MARKETFLOW PRO - CONNEXION POSTGRESQL (REPLIT)
 * Fichier : config/database.php
 */

class Database {

    private static $instance = null;
    private $pdo;

    /**
     * Constructeur privé (Singleton)
     */
    private function __construct() {
        try {
            // Récupérer l'URL de connexion depuis Replit
            $databaseUrl = getenv('DATABASE_URL');

            if ($databaseUrl) {
                // Parser l'URL PostgreSQL
                $parts = parse_url($databaseUrl);

                if (!$parts) {
                    throw new Exception("URL de base de données invalide");
                }

                // Extraire les composants
                $host = $parts['host'] ?? 'localhost';
                $port = $parts['port'] ?? 5432;
                $dbname = ltrim($parts['path'] ?? '', '/');
                $user = $parts['user'] ?? 'postgres';
                $pass = $parts['pass'] ?? '';

                // IMPORTANT : Construire le DSN avec pgsql: (pas postgresql:)
                $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

                // Créer la connexion PDO avec user/pass séparés
                $this->pdo = new PDO($dsn, $user, $pass);

            } else {
                // Fallback : connexion manuelle
                throw new Exception("DATABASE_URL non définie");
            }

            // Configuration PDO pour PostgreSQL
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            // Définir le schéma par défaut
            $this->pdo->exec("SET search_path TO public");

        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Erreur interne. Veuillez réessayer plus tard.");
        } catch (Exception $e) {
            error_log("Database Error: " . $e->getMessage());
            die("Erreur interne. Veuillez réessayer plus tard.");
        }
    }

    /**
     * Obtenir l'instance unique (Singleton)
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtenir la connexion PDO
     */
    public function getPdo() {
        return $this->pdo;
    }

    /**
     * Raccourci : Préparer une requête
     */
    public function prepare($sql) {
        return $this->pdo->prepare($sql);
    }

    /**
     * Raccourci : Exécuter une requête
     */
    public function query($sql) {
        return $this->pdo->query($sql);
    }

    /**
     * Raccourci : Exécuter du SQL
     */
    public function exec($sql) {
        return $this->pdo->exec($sql);
    }

    /**
     * Empêcher le clonage
     */
    private function __clone() {}

    /**
     * Empêcher la désérialisation
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }

    /**
     * Lister les tables
     */
    public function getTables() {
        $sql = "SELECT table_name FROM information_schema.tables 
                WHERE table_schema = 'public' 
                ORDER BY table_name";

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}