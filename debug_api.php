<?php
    /**
     *DEBUG version of the api.php
     * Contains error logging and debugging outputs
     */


    // Enables error reporting for debugging
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    //Log function for debugging
    function debug_log($message){
        error_log("[DEBUG]" . $message);
        // Uncomment the line below to see debug messages in browser(for testing only)
        // echo "<!-- DEBUG" . $message . " --> \n";
    }

    debug_log("Script started");

    class DBAccess
    {
        private $host = "localhost";
        private $user = "rk738_retake_user";
        private $pass = "UnicornEggs2003!";
        private $db = "rk738_retake_database";
        private $conn;

        public function __construct()
        {
            debug_log("Attempting to connect to DB");

            try {
                mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
                $this->conn = new mysqli($this->host, $this->user, $this->pass, $this->db);
                $this->conn->set_charset("utf8mb4");
                debug_log("Database connected to DB succesfully");
            } catch (mysqli_sql_exception $e) {
                debug_log("Database connection failed: " . $e->getMessage());

                // Output error information for debugging
                header('Content-type: application/json');
                http_response_code(500);
                echo json_encode([
                    'error' => 'Database connection has fialed',
                    'details' => $e->getMessage(),
                    'host' => $this->host,
                    'database' => $this->db
                ], JSON_PRETTY_PRINT);
                exit;
            }
        }

        public function get_connection()
        {
            return $this->conn ? $this->conn : null;
        }

        public function __destruct()
        {
            if ($this->conn) {
                $this->conn->close();
                debug_log("DB connection closed");
            }
        }

    }

    class MessageService {
        private $conn;

        public function __construct(DBAccess $dbConnection) {
            debug_log(" Initializing MessageService class");

            $this-> conn = $dbConnection-> get_connection();
            if (!$this->conn){
                debug_log("Failed to connect to DB inside the MessageService class");
                header('Content-type: application/json');
                http_response_code(500);
                echo json_encode(['error'=> 'No database connection available comming from the MessageService class']);
                exit;

            }
            debug_log("Succesfully initlialized MessageService class");
        }

        private function isValidUsername($username) {
            debug_log("Validating username: '" . $username . "'");

            if (!is_string($username) || empty($username)) {
                debug_log("Username validation failed: not string or empty");
                return false;
            }

            $isValid = preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username);
            debug_log("Username validation result " . ($isValid ? 'valid' : 'invalid due to it containting special characters'));
            return $isValid;
        }

        private function isValidMessage($message) {
            debug_log("Validating message: isset=" . (isset($message) ? 'true' : 'false') .
                ", is_string=". (is_string($message) ? 'true' : 'false'));

            return isset($message) && isset($message);
        }

        public function GET() {
            debug_log("Processing GET Request");

            try {
                $source = isset($_GET["source"]) ? $_GET["source"] : "";
                $target = isset($_GET["target"]) ? $_GET["target"] : "";

                debug_log("GET Parameters: source ='$source', target='$target'");

                if(empty($source) && empty($target)) {
                    debug_log("GET failed: no parameters provdided");
                    http_response_code(400);
                    exit;
                }

                if(!empty($source) && !$this->isValidUsername($source)) {
                    debug_log("GET failed: invalid source username");
                    http_response_code(400);
                    exit;
                }

                if(!empty($target) && !this->isValidMessage($target)) {
                    debug_log("GET failed: invalid target username");
                    http_response_code(400);
                    exit;
                }

                if(!empty($source) && !empty($target) && $source === $target) {
                    debug_log("GET failed: source and target are the same");
                    http_response_code(400);
                    exit;
                }
                $stmt = null;
                $messages = [];

                if(!empty($source) && !empty($target)) {
                    debug_log("Querying messages between $source and $target");
                    $stmt = $this->conn->prepare("SELECT id, target, source, text, sent FROM message WHERE source = ? and target = ? ORDER by sent ASC");
                    $stmt->bind_param("ss", $source, $target);
                } else if (!empty($source)) {
                    debug_log("Querying ,messges sent by $source");
                    $stmt = $this->conn->prepare("SELECT id, target, source, text, sent FROM message WHERE source = ? ORDER BY sent ASC");
                    $stmt->bind_param("s", $source);
                } else {
                    debug_log("Querying messages sent by $target");
                    $stmt = $this->conn->prepare("SELECT id, target, source, text, sent FROM message WHERE target = ? ORDER by sent ASC ");
                    $stmt->bind_param("s", $target);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                $messageData = $result->fetch_all(MYSQLI_ASSOC);

                debug_log("Query returned " . count($messageData) . " messages");

                if(empty($messageData)) {
                    debug_log("No messages found, retunring 204");
                    http_response_code(204);
                    exit;
                }

                foreach($messageData as $row) {
                    $messages[] = [
                        'id'=> (int)$row['id'],
                        'sent'=> $row['sent'],
                        'source'=> $row['source'],
                        'target'=> $row['target'],
                        'message'=> $row['text'],
                    ];
                }

                debug_log("Returning succesful response with " . count($messages) . " messages");
                http_response_code(200);
                header('Content-type: application/json');
                echo json_encode(['messages' => $messages], JSON_PRETTY_PRINT);

                return true;

            } catch(mysqli_sql_exception $e) {
                debug_log("Database error in GET : " . $e->getMessage());
                header('Content-type: application/json');
                http_response_code(500);
                echo json_encode([ 'error'=> 'Database error', 'details' => $e->getMessage() ], JSON_PRETTY_PRINT);
                exit;
            } finally {
                if(isset($stmt) && $stmt){
                    $stmt->close();
                }
            }
        }

        public function POST() {
            debug_log("Processing POST Request");

            // Logging all post data for debugging
            debug_log("POST data: " . print_r($_POST, true));

            try {
                if(!isset($_POST['source']) || !isset($_POST['target'])) {
                    debug_log("POST failed: missing parameters");
                    debug_log("source isset: " . (isset($_POST['source']) ? "true" : "false"));
                    debug_log("target isset: " . (isset($_POST['target']) ? "true" : "false"));
                    debug_log("message isset: " . (isset($_POST['message']) ? "true" : "false"));
                    http_response_code(400);
                    exit;
                }

                $source = $_POST['source'];
                $target = $_POST['target'];
                $message = $_POST['message'];

                debug_log("POST values: source = '$source', target = '$target', message = '$message'");

                if(!$this->isValidUsername($source)) {
                    debug_log("POST failed: invalid source username");
                    http_response_code(400);
                    exit;
                }

                if(!$this->isValidUsername($target)) {
                    debug_log("POST failed: invalid target username");
                    http_response_code(400);
                    exit;
                }

                if(!$this -> isValidMessage($message)) {
                    debug_log("POST failed: invalid message");
                    http_response_code(400);
                    exit;
                }

                if($source === $target) {
                    debug_log("POST failed: source and target are the same");
                    http_response_code(400);
                    exit;
                }

                debug_log("All validations passed, inserting into database");
                $stmt = $this->conn->prepare("INSERT INTO message (source, target, message) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $source, $target, $message);
                $stmt->execute();

                if($stmt->affected_rows > 0) {
                    $newId = $stmt->conn->insert_id;
                    debug_log("Message inserted succesfully with ID: $newId");

                    http_response_code(201);
                    header('Content-type: application/json');
                    echo json_encode(['message'=> $newId], JSON_PRETTY_PRINT);

                    return true;
                } else {
                    debug_log("POST failed: no rows affected in insert");
                    http_response_code(500);
                    exit;
                }
            } catch(mysqli_sql_exception $e) {
                debug_log("Database error in POST : " . $e->getMessage());
                header('Content-type: application/json');
                http_response_code(500);
                echo json_encode([ 'error'=> 'Database error', 'details' => $e->getMessage() ], JSON_PRETTY_PRINT);
                exit;
            } finally {
                if(isset($stmt) && $stmt){
                    $stmt->close();
                }
            }
        }
    }
    class Main {
        private $messages;

        public function __construct(MessageService  $messageService) {
            debug_log("Initializing Main controller");
            $this->messages = $messageService;

            header('Content-type: application/json');

            $method = $_SERVER['REQUEST_METHOD'];
            debug_log("HTTP method: $method");

            if(!in_array($method, ['GET', 'POST'])) {
                debug_log("Method not allowed: $method");
                http_response_code(405);
                exit;
            }

            if($method === 'GET') {
                debug_log("Routing to GET handler");
                $this->messages->GET();
            } else if ($method === 'POST') {
                debug_log("Routing to POST handler");
                $this->messages->POST();
            }
        }
    }

    // Application entry point
    debug_log("Starting application");
    try {
        $db = new DBAccess();
        $messageService = new MessageService($db);
        $main = new Main($messageService);
        debug_log("Application completed succesfully");
    } catch(Exception $e) {
        debug_log("Fatal error: " . $e->getMessage());
        header('Content-type: application/json');
        http_response_code(500);
        echo json_encode(['error'=> 'Fatal error', 'details' => $e->getMessage()], JSON_PRETTY_PRINT);
        exit;
    }
?>