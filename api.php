<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

class BibleVerseAPI {
    private $verses = [];
    private $totalVerses = 0;
    
    public function __construct() {
        $this->loadVerses();
    }
    
    private function loadVerses() {
        $jsonFile = 'bible_verses.json';
        if (!file_exists($jsonFile)) {
            $this->sendError('Bible verses file not found', 404);
        }
        
        $jsonContent = file_get_contents($jsonFile);
        $data = json_decode($jsonContent, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError('Invalid JSON format in bible verses file', 500);
        }
        
        if (!isset($data['bible_verses']) || !is_array($data['bible_verses'])) {
            $this->sendError('Invalid structure in bible verses file', 500);
        }
        
        $this->verses = $data['bible_verses'];
        $this->totalVerses = count($this->verses);
        
        if ($this->totalVerses < 365) {
            $this->sendError('Not enough verses for 365 days (need at least 365, found ' . $this->totalVerses . ')', 500);
        }
    }
    
    public function getDailyVerse($date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->sendError('Invalid date format. Use YYYY-MM-DD', 400);
        }
        
        // Calculate day of year (1-365)
        $dateObj = new DateTime($date);
        $dayOfYear = (int)$dateObj->format('z') + 1;
        
        // Use a deterministic algorithm to select verse based on date
        // This ensures the same verse is returned for the same date
        $verseIndex = $this->getVerseIndexForDay($dayOfYear, $dateObj->format('Y'));
        
        if ($verseIndex >= $this->totalVerses) {
            $this->sendError('Verse index out of bounds', 500);
        }
        
        $verse = $this->verses[$verseIndex];
        
        return [
            'success' => true,
            'date' => $date,
            'day_of_year' => $dayOfYear,
            'verse' => $verse,
            'total_verses' => $this->totalVerses
        ];
    }
    
    private function getVerseIndexForDay($dayOfYear, $year) {
        // Use a combination of year and day to create a unique seed
        // This ensures different years get different verse sequences
        $seed = $year * 1000 + $dayOfYear;
        
        // Use a simple but effective algorithm to distribute verses
        // This ensures no repetition within a year and different sequences for different years
        $index = ($seed * 9301 + 49297) % $this->totalVerses;
        
        return $index;
    }
    
    public function getRandomVerse() {
        $randomIndex = rand(0, $this->totalVerses - 1);
        return [
            'success' => true,
            'verse' => $this->verses[$randomIndex],
            'total_verses' => $this->totalVerses
        ];
    }
    
    public function getVerseById($id) {
        foreach ($this->verses as $verse) {
            if ($verse['id'] == $id) {
                return [
                    'success' => true,
                    'verse' => $verse,
                    'total_verses' => $this->totalVerses
                ];
            }
        }
        
        $this->sendError('Verse with ID ' . $id . ' not found', 404);
    }
    
    public function getStats() {
        return [
            'success' => true,
            'total_verses' => $this->totalVerses,
            'current_date' => date('Y-m-d'),
            'day_of_year' => (int)date('z') + 1
        ];
    }
    
    public function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'code' => $code
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

// Initialize API
$api = new BibleVerseAPI();

// Handle different endpoints
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$path = trim($path, '/');

// Extract endpoint from path
$pathParts = explode('/', $path);
$endpoint = end($pathParts);

// Get query parameters
$queryParams = $_GET;

try {
    switch ($requestMethod) {
        case 'GET':
            if (empty($path) || $path === 'api.php' || $endpoint === 'api.php') {
                // Default endpoint - get today's verse
                $date = isset($queryParams['date']) ? $queryParams['date'] : null;
                $result = $api->getDailyVerse($date);
            } elseif ($endpoint === 'daily') {
                // Daily verse endpoint
                $date = isset($queryParams['date']) ? $queryParams['date'] : null;
                $result = $api->getDailyVerse($date);
            } elseif ($endpoint === 'random') {
                // Random verse endpoint
                $result = $api->getRandomVerse();
            } elseif ($endpoint === 'verse') {
                // Get verse by ID
                if (!isset($queryParams['id'])) {
                    $api->sendError('Verse ID is required', 400);
                }
                $result = $api->getVerseById($queryParams['id']);
            } elseif ($endpoint === 'stats') {
                // Get API statistics
                $result = $api->getStats();
            } else {
                $api->sendError('Endpoint not found', 404);
            }
            break;
            
        default:
            $api->sendError('Method not allowed', 405);
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $api->sendError('Internal server error: ' . $e->getMessage(), 500);
}
?> 