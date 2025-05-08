<?php
    /**
     * REST api
     *
     * This is a REST API for Web development. It handles GET, POST
     * , PUT, and DELETE requests to perform CRUD(Create, Read, Update,
     * Delete) operations.
     * @ Author Rosslan Koulli
     * @version 2.3
    **/

    // Set the appropriate header for the API's repsonses
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: GET, POST, PUT, DELETE');
    header('Access-Control-Allow-Methods: Content-Type, Authorization');

    // Handle preflight options requests
    if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    /**
     *Class PeopleAPI
     *Handles CRUD Operations for the people table
     */
    class PeopleAPI {
        // DB connections
        private $db = null;

        // HTTP status code for the response
        private $statusCode = 200;

        //Repsonse array

        private $result = [];

        /**
         *Constructor - establishes database connection
         */

        public function __construct() {
            try {
                // Initlialize db connection using PDO
                $host = 'localhost';
                $dbname = 'rk738_ci527_test';
                $username = 'rk738_User';
                $password = 'UnicornEggs2003!';

                $this-> db = new PDO("mysql:host=$host;dbname=$dbname; charset=utf8", $username, $password);

                // Set PDO to throw exceptions if an error occurs
                $this-> db ->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // Set default fetch mode to associative array
                $this->db ->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            } catch (PDOException $e) {
                // Log dtabase connection error
                $this -> statusCode = 500;
                $this -> result = [
                    'error' => true,
                    'message' => 'Database connection Error',
                    'details' => $e->getMessage()

                ];

            }
        }
        /**
         * Destructor - closes the database connections
        */
        public function __destruct() {
            //Close the database connection
            $this->db = null;
        }

        /**
         * Handle API requests
         *
         * Finds out which is the appropriate HTTP request
         * method and calls the necessary method to handle
         * the request
        */
        public function handleRequest() {
            try {
                // Stop processing if database connection failed
                if ($this-> statusCode === 500) {
                    $this-> sendResponse();
                    return;
                }

                // Get request method
                $method = $_SERVER['REQUEST_METHOD'];

                // Determines the request ID if it's specified
                $id = null;
                if (isset($_GET['id'])) {
                    $id = $this->sanitizeInput($_GET['id']);
                    if(!is_numeric($id)) {
                        $this -> statusCode = 400;
                        $this -> result = [
                            'error' => true,
                            'message' => 'Invalid ID Format'
                        ];
                        $this-> sendResponse();
                        return;
                    }
                }
                switch ($method) {
                    case 'GET':
                        $this->handleGet($id);
                        break;
                    case 'POST':
                        $this->handlePost();
                        break;
                    case 'PUT':
                        $this->handlePut($id);
                        break;
                    case 'DELETE':
                        $this-> handleDelete($id);
                        break;
                    default:
                        // Method not allowed
                        $this -> statusCode =  405;
                        $this -> result = [
                            'error' => true,
                            'message' => 'Method not allowed'
                        ];
                        break;
                }
            } catch (Exception $e) {
                // Catch any exceptions
                $this -> statusCode = 500;
                $this -> result = [
                    'error' => true,
                    'message' => 'Internal Server Error',
                    'details' => $e->getMessage()

                ];
            }
            // Send response
            $this -> sendResponse();
        }
        /**
         * Handling of GET requests
         *
         * @param int|null $id Optional ID of the person to retrieve
        */
        private function handleGet($id = null) {
            try {
                if($id === null){

                    // Retrieve all the people
                    $stmt = $this->db -> prepare("SELECT * FROM people");
                    $stmt -> execute();
                    $people = $stmt->fetchAll();

                    if(count($people)>0) {
                        $this-> statusCode = 200;
                        $this->result = $people;
                    } else {
                        $this-> statusCode = 204; // No content
                        $this-> result = [
                            'message' => 'No people found'
                        ];
                    }
                } else {
                    // Retrieve specific person by ID
                    $stmt = $this->db -> prepare("SELECT * FROM people WHERE id = :id");
                    $stmt -> bindParam(':id', $id, PDO::PARAM_INT);
                    $stmt -> execute();
                    $person = $stmt->fetchAll();

                    if($person) {
                        $this->statusCode = 200;
                        $this->result = $person;
                    } else {
                        $this->statusCode = 404;
                        $this->result = [
                            'error' => true,
                            'message' => 'Person not found'
                        ];
                    }
                }
            } catch (PDOException $e) {
                $this -> statusCode = 500;
                $this -> result = [
                    'error' => true,
                    'message' => 'DB Error',
                    'details' => $e->getMessage()
                ];
            }
        }
        /**
         * Handles POST requests (create)
        */
        private function handlePost() {
            // Get input data
            $inputData = $this->getInputData();

            // Validating required fields
            if(!$this -> valudatePersonData($inputData)){
                $this -> statusCode = 400;
                $this -> result = [
                    'error' => true,
                    'message' => 'Missing required fields(firstname, lastname, phone)'
                ];
                return;
            }
            try {
                // Inset new person
                $stmt = $this->db->prepare('
                INSERT INTO people (firstname, lastname, phone)
                VALUES (:firstname, :lastname, :phone)
                ');
                $stmt->bindParam(':firstname', $inputData['firstname'], PDO::PARAM_STR);
                $stmt->bindParam(':lastname', $inputData['lastname'], PDO::PARAM_STR);
                $stmt->bindParam(':phone', $inputData['phone'], PDO::PARAM_STR);

                if($stmt->execute()) {
                    $id = $this->db->lastInsertId();
                    $this-> statusCode = 201; // Created

                    // Set location header for newly created resource
                    header("Location". $this->getBaseUrl(). "?id=$id");

                    $this->result = [
                        'id' => $id,
                        'message' =>  'Succesfully created person entry'
                    ];
                } else {
                    $this->statusCode = 500;
                    $this->result = [
                        'error' => true,
                        'message' => 'Failed to create person entry'
                    ];
                }
            } catch (PDOException $e) {
                $this -> statusCode = 500;
                $this -> result = [
                    'error' => true,
                    'message' => 'Database Error',
                    'details' => $e->getMessage()
                ];
            }
        }
        /**
         * Handle PUT requests (Update)
        */

        private function handlePut($id = null) {
            // Check if ID is provided
            if ($id === null) {
                $this->statusCode = 400;
                $this->result = [
                    'error' => true,
                    'message' => 'ID is required for PUT requests'
                ];
                return;
            }
            // Get input data
            $inputData = $this->getInputData();

            // Validate required fields
            if(!$this->validatePersonData($inputData)) {
                $this->statusCode = 400;
                $this->result = [
                    'error' => true,
                    'message' => 'Missing required fields(firstname, lastname, phone)'
                ];
                return;
            }

            try {
                // Check if person exists
                $checkStmt = $this->db->prepare('SELECT id FROM people WHERE id = :id');
                $checkStmt -> bindParam(':id', $id, PDO::PARAM_INT);
                $checkStmt -> execute();

                if(!$checkStmt->fetch()){
                    $this->statusCode = 404;
                    $this->result = [
                        'error' => true,
                        'message' => 'Person not found'
                    ];
                    return;
                }
                // Update person
                $stmt = $this->db->prepare('
                UPDATE people
                SET firstname = :firstname, lastname = :lastname, phone = :phone
                WHERE id = :id
                ');

                $stmt -> bindParam(':id, $id, PDO::PARAM_INT');
                $stmt ->bindParam(':firstname', $inputData['firstname'], PDO::PARAM_STR);
                $stmt ->bindParam(':lastname', $inputData['lastname'], PDO::PARAM_STR);
                $stmt ->bindParam(':phone', $inputData['phone'], PDO::PARAM_STR);

                if($stmt->execute()) {
                    $this-> statusCode = 200;
                    $this->result = [
                        'id' => $id,
                        'message' =>  'Succesfully updated person entry'
                    ];
                } else {
                    $this->statusCode = 500;
                    $this->result = [
                        'error' => true,
                        'message' => 'Failed to update person entry'
                    ];
                }
            } catch(PDOException $e) {
                $this -> statusCode = 500;
                $this -> result = [
                    'error' => true,
                    'message' => 'Database Error',
                    'details' => $e->getMessage()
                ];
            }
        }

        /**
         * Handle DELETE requests
         *
         * @param int|null $id ID of the person to delete
        */

        private function handleDelete($id = null) {
            // Check if ID is provided
            if($id === null) {
                $this -> statusCode = 400;
                $this->result = [
                    'error' => true,
                    'message' => 'ID is required for DELETE requests'
                ];
                return;
            }
            try {
                // Check if person exists
                $checkStmt = $this->db->prepare('SELECT id FROM people WHERE id = :id');
                $checkStmt -> bindParam(':id', $id, PDO::PARAM_INT);
                $checkStmt -> execute();

                if(!$checkStmt->fetch()){
                    $this->statusCode = 404;
                    $this->result = [
                        'error' => true,
                        'message' => 'Person not found'
                    ];
                    return;
                }
                // Delete person
                $stmt = $this->db->prepare('DELETE FROM people WHERE id = :id');
                $stmt -> bindParam(':id', $id, PDO::PARAM_INT);

                if($stmt->execute()) {
                    $this-> statusCode = 200;
                    $this->result = [
                        'message'  =>  'Succesfully deleted person entry'
                    ];
                } else {
                    $this->statusCode = 500;
                    $this->result = [
                        'error' => true,
                        'message' => 'Failed to delete person entry'
                    ];
                }
            } catch(PDOException $e) {
                $this -> statusCode = 500;
                $this -> result = [
                    'error' => true,
                    'message' => 'Database Error',
                    'details' => $e->getMessage()
                ];
            }
        }

        /**
         * Send the HTTP response
        */

        private function sendResponse() {
            // Set HTTP status code
            http_response_code($this->statusCode);

            // Output result as JSON

            echo json_encode($this->result, JSON_PRETTY_PRINT);
        }

        /**
         * Get and parse input data from request body
         *
         * @return array Parsed input data
        */

        private function getInputData() {
            $inputData = [];

            // Get request body
            $inputJSON = file_get_contents('php://input');

            // Try to decode JSON data
            if(!empty($inputJSON)) {
                $decoded = json_decode($inputJSON, true);
                if($decoded !== null) {
                    $inputData = $decoded;
                }

            }
            // if JSON decoding failed, check for POST data
            if(empty($inputData) && !empty($_POST)) {
                $inputData = $_POST;
            }

            // Sanitize all input data
            foreach($inputData as $key => $value) {
                $inputData[$key] = $this->sanitizeInput($value);
            }
            return $inputData;
        }
        /**
         * Validating person data
         *
         * @param array $data Data to validate
         * @return bool True if valid, false otherwise
        */

        private function validatePersonData($data) {
            // Check required fields
            $requiredFields = ['firstname', 'lastname', 'phone'];
            foreach($requiredFields as $field) {
                if(!isset($data[$field]) || trim($data[$field])==='') {
                    return false;
                }
            }

            return true;
        }

        /**
         * Sanitize user input
         * @ param mixed $input Input to sanitize
         * @return mixed Sanatized input
        */

        private function sanitizeInput($input) {
            if(is_array($input)) {
                foreach($input as $key => $value) {
                    $input[$key] = $this->sanitizeInput($value);
                }
                return $input;
            }

            // For string inputs
            if(is_string($input)) {

                // Remove any HTML tags
                $input = strip_tags($input);

                // Convert special characters to HTML entites
                $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');

            }
            return $input;

        }

        /**
         * GEt the base URL for the API
         *
         * @return string Base URL
        */

        private function getBaseUrl() {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $script =  $_SERVER['SCRIPT_NAME'];

            return "$protocol://$host$script";
        }
    }
    // Create and run the API
    $api = new PeopleApi();
    $api->handleRequest();
?>
