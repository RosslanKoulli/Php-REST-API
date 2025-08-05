<?php
    /**
     * REST api
     *
     * This server provides a REST API for creating and retrieving messages
     * between users in a messaging system.
     *
     * @Author Rosslan Koulli
     * @version 3.0
    **/


    /**
     * Database Access Layer
     *
     * Handles database connection using MySQL with proper error handling.
     * Implement a singleton-like pattern through constructor/destructor.
     */
    class DBAccess
    {
        private $host = "localhost";
        private $user = "rk738_retake_user";
        private $pass = "UnicornEggs2003!";
        private $db = "rk738_retake_database";
        private $conn;

        /**
         * Establish database connection
         * Setting proper error reporting and character set
         */
        public function __construct()
        {
            try {
                // Create connection with error reporting enabled
                mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
                $this->conn = new mysqli($this->host, $this->user, $this->pass, $this->db);

                // Set character set to prevent encoding issues
                $this->conn->set_charset("utf8mb4");
            } catch (mysqli_sql_exception $e) {
                // Log error (in production, log to file instead of displaying)
                error_log("Database connection failed: " . $e->getMessage());
                http_response_code(500);
                exit;
            }
        }

        /**
         * Get the database connection object
         * @return mysqli|null Database connection or null if failed
         */
        public function get_connection()
        {
            return $this->conn ? $this->conn : null;
        }

        /**
         * Clean up database connection
         */
        public function __destruct()
        {
            if ($this->conn) {
                $this->conn->close();
            }
        }
    }

    /**
     * Message Service Layer
     *
     * Handles all message-related operations including validation,
     * database interactions, and business logic.
     */
    class MessageService
    {
        private $conn;

        /**
         * Initialize service with database connection
         * @param DBAccess $dbConnection Database access object
         */
        public function __construct(DBAccess $dbConnection)
        {
            $this->conn = $dbConnection->get_connection();
            if (!$this->conn) {
                http_response_code(500);
                exit;
            }
        }

        /**
         * Validate username according to specification
         *
         * Username must be:
         * - Alphanumeric only (0-9, a-z, A-Z)
         * - Minimum 4 characters
         * - Maximum 16 characters
         *
         * @param string $username Username to validate
         * @return bool True if valid, false otherwise
         */
        private function isValidUsername($username)
        {
            // Check if username is string and not empty
            if (!is_string($username) || empty($username)) {
                return false;
            }

            // Check length (4-16 characters)
            $length = strlen($username);
            if ($length < 4 || $length > 16) {
                return false;
            }

            // Check if alphanumeric only
            return preg_match('/^[A-Za-z0-9]+$/', $username);
        }

        /**
         * Validate message content
         *
         * Message can be any content but cannot be empty/null
         *
         * @param mixed $message Message content to validate
         * @return bool True if valid, false otherwise
         */
        private function isValidMessage($message)
        {
            // Message must exist and be a string (can be empty string)
            return isset($message) && is_string($message);
        }

        /**
         * Handle GET requests - Retrieve messages
         *
         * Supports three query patterns:
         * 1. ?source=username - Get all messages sent by user
         * 2. ?target=username - Get all messages sent to user
         * 3. ?source=username&target=username - Get messages between two users
         *
         * @return bool True on success, exits on error
         */
        public function GET()
        {
            try {
                // Get and validate query parameters
                $source = isset($_GET['source']) ? $_GET['source'] : '';
                $target = isset($_GET['target']) ? $_GET['target'] : '';

                // At least one parameter must be provided
                if (empty($source) && empty($target)) {
                    http_response_code(400);
                    exit;
                }

                // Validate usernames if provided
                if (!empty($source) && !$this->isValidUsername($source)) {
                    http_response_code(400);
                    exit;
                }

                if (!empty($target) && !$this->isValidUsername($target)) {
                    http_response_code(400);
                    exit;
                }

                // Source and target cannot be the same
                if (!empty($source) && !empty($target) && $source === $target) {
                    http_response_code(400);
                    exit;
                }

                $stmt = null;
                $messages = [];

                // Build query based on provided parameters
                if (!empty($source) && !empty($target)) {
                    // Get messages between specific users
                    $stmt = $this->conn->prepare("SELECT id, target, source, message, sent FROM message WHERE source = ? AND target = ? ORDER BY sent ASC");
                    $stmt->bind_param("ss", $source, $target);
                } else if (!empty($source)) {
                    // Get all messages sent by source
                    $stmt = $this->conn->prepare("SELECT id, target, source, message, sent FROM message WHERE source = ? ORDER BY sent ASC");
                    $stmt->bind_param("s", $source);
                } else {
                    // Get all messages sent to target
                    $stmt = $this->conn->prepare("SELECT id, target, source, message, sent FROM message WHERE target = ? ORDER BY sent ASC");
                    $stmt->bind_param("s", $target);
                }

                // Execute query
                $stmt->execute();
                $result = $stmt->get_result();

                // Fetch all results
                $messageData = $result->fetch_all(MYSQLI_ASSOC);

                // Check if any messages found
                if (empty($messageData)) {
                    http_response_code(204); // No Content
                    exit;
                }

                // Format messages according to API specification
                foreach ($messageData as $row) {
                    $messages[] = [
                        'id' => (int)$row['id'],
                        'sent' => $row['sent'],
                        'source' => $row['source'],
                        'target' => $row['target'],
                        'message' => $row['message']
                    ];
                }

                // Return successful response
                http_response_code(200);
                header('Content-Type: application/json');
                echo json_encode(['messages' => $messages], JSON_PRETTY_PRINT);

                return true;

            } catch (mysqli_sql_exception $e) {
                // Log database errors
                error_log("Database error in GET: " . $e->getMessage());
                http_response_code(500);
                exit;
            } finally {
                // Clean up prepared statement
                if (isset($stmt) && $stmt) {
                    $stmt->close();
                }
            }
        }

        /**
         * Handle POST requests - Create new messages
         *
         * Expected POST data:
         * - source: Username of sender (4-16 alphanumeric chars)
         * - target: Username of recipient (4-16 alphanumeric chars)
         * - message: Message content (any string)
         *
         * @return bool True on success, exits on error
         */
        public function POST()
        {
            try {
                // Validate required parameters exist
                if (!isset($_POST['source']) || !isset($_POST['target']) || !isset($_POST['message'])) {
                    http_response_code(400);
                    exit;
                }

                $source = $_POST['source'];
                $target = $_POST['target'];
                $message = $_POST['message'];

                // Validate source username
                if (!$this->isValidUsername($source)) {
                    http_response_code(400);
                    exit;
                }

                // Validate target username
                if (!$this->isValidUsername($target)) {
                    http_response_code(400);
                    exit;
                }

                // Validate message content
                if (!$this->isValidMessage($message)) {
                    http_response_code(400);
                    exit;
                }

                // Source and target cannot be the same
                if ($source === $target) {
                    http_response_code(400);
                    exit;
                }

                // Insert message into database
                $stmt = $this->conn->prepare("INSERT INTO message (target, source, message) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $target, $source, $message);
                $stmt->execute();

                // Check if insertion was successful
                if ($stmt->affected_rows > 0) {
                    // Get the ID of the newly created message
                    $newId = $this->conn->insert_id;

                    // Return success response with message ID
                    http_response_code(201);
                    header('Content-Type: application/json');
                    echo json_encode(['id' => $newId], JSON_PRETTY_PRINT);

                    return true;
                } else {
                    // Insert failed for unknown reason
                    http_response_code(500);
                    exit;
                }

            } catch (mysqli_sql_exception $e) {
                // Log database errors
                error_log("Database error in POST: " . $e->getMessage());
                http_response_code(500);
                exit;
            } finally {
                // Clean up prepared statement
                if (isset($stmt) && $stmt) {
                    $stmt->close();
                }
            }
        }
    }

    /**
     * Main Application Controller
     *
     * Handles HTTP request routing, method validation, and coordinates
     * between the web server and the message service.
     */
    class Main
    {
        private $messages;

        /**
         * Initialize application and route requests
         *
         * @param MessageService $messageService Service layer for message operations
         */
        public function __construct(MessageService $messageService)
        {
            $this->messages = $messageService;

            // Set JSON content type for all responses
            header('Content-Type: application/json');

            // Get HTTP request method
            $method = $_SERVER['REQUEST_METHOD'];

            // Validate HTTP method - only GET and POST allowed
            if (!in_array($method, ['GET', 'POST'])) {
                http_response_code(405); // Method Not Allowed
                exit;
            }

            // Route request to appropriate handler
            if ($method === 'GET') {
                $this->messages->GET();
            } else if ($method === 'POST') {
                $this->messages->POST();
            }
        }
    }

    // Application Entry Point
    // Initialize database connection, message service, and main controller
    try {
        $db = new DBAccess();
        $messageService = new MessageService($db);
        $main = new Main($messageService);
    } catch (Exception $e) {
        // Handle any uncaught exceptions
        error_log("Fatal error: " . $e->getMessage());
        http_response_code(500);
        exit;
    }

?>