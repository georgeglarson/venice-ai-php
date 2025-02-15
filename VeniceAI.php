<?php

/**
 * Venice AI PHP Examples
 * 
 * Example PHP client for interacting with the Venice AI API.
 * Implements OpenAI API specification for compatibility.
 */
class VeniceAI {
    /** @var string The API key for authentication */
    private string $apiKey;
    
    /** @var string The base URL for the Venice AI API */
    private const BASE_URL = 'https://api.venice.ai/api/v1';
    
    /** @var array Default request headers */
    private array $headers;

    /** @var bool Enable debug mode */
    private bool $debug = false;

    /** @var resource|null Debug output handle */
    private $debugHandle = null;

    /** @var array Valid image sizes */
    private const VALID_SIZES = [
        [1024, 1024],  // Square
        [1024, 1280],  // Portrait
        [1280, 1024]   // Landscape
    ];

    /** @var array Valid style presets */
    private const VALID_STYLES = [
        // Artistic
        "3D Model", "Analog Film", "Anime", "Comic Book", "Digital Art",
        "Enhance", "Fantasy Art", "Isometric Style", "Line Art", "Lowpoly",
        "Neon Punk", "Origami", "Pixel Art", "Texture",
        // Commercial
        "Advertising", "Food Photography", "Real Estate",
        // Fine Art
        "Abstract", "Cubist", "Graffiti", "Hyperrealism", "Impressionist",
        "Pointillism", "Pop Art", "Psychedelic", "Renaissance", "Steampunk",
        "Surrealist", "Typography", "Watercolor",
        // Gaming
        "Fighting Game", "GTA", "Super Mario", "Minecraft", "Pokemon",
        "Retro Arcade", "Retro Game", "RPG Fantasy Game", "Strategy Game",
        "Street Fighter", "Legend of Zelda",
        // Aesthetic
        "Architectural", "Disco", "Dreamscape", "Dystopian", "Fairy Tale",
        "Gothic", "Grunge", "Horror", "Minimalist", "Monochrome", "Nautical",
        "Space", "Stained Glass", "Techwear Fashion", "Tribal", "Zentangle",
        // Paper Art
        "Collage", "Flat Papercut", "Kirigami", "Paper Mache", "Paper Quilling",
        "Papercut Collage", "Papercut Shadow Box", "Stacked Papercut",
        "Thick Layered Papercut",
        // Photography
        "Alien", "Film Noir", "HDR", "Long Exposure", "Neon Noir",
        "Silhouette", "Tilt-Shift"
    ];

    /**
     * Constructor
     * 
     * @param bool $debug Enable debug mode (shows HTTP output)
     * @throws Exception If config file is missing or API key is not set
     */
    public function __construct(bool $debug = false) {
        $configFile = __DIR__ . '/config.php';
        
        if (!file_exists($configFile)) {
            throw new Exception(
                "Configuration file not found. Please copy config.example.php to config.php and set your API key."
            );
        }

        $config = require $configFile;
        
        if (!is_array($config) || !isset($config['api_key']) || empty($config['api_key'])) {
            throw new Exception(
                "Invalid configuration. Please ensure config.php returns an array with 'api_key' set."
            );
        }

        if ($config['api_key'] === 'your-api-key-here') {
            throw new Exception(
                "Please set your API key in config.php. The default value is still in use."
            );
        }

        $this->apiKey = $config['api_key'];
        $this->debug = $debug;
        $this->headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ];

        // Set up debug output
        if ($this->debug) {
            $this->debugHandle = fopen('php://stderr', 'w');
        }
    }

    /**
     * Destructor
     */
    public function __destruct() {
        if ($this->debugHandle) {
            fclose($this->debugHandle);
        }
    }

    /**
     * List available models
     * 
     * @param string|null $type Optional filter for model type ('text' or 'image')
     * @return array List of available models
     * @throws InvalidArgumentException If type is invalid
     * @throws Exception If the request fails
     */
    public function listModels(?string $type = null): array {
        $endpoint = '/models';
        if ($type !== null) {
            if (!in_array($type, ['text', 'image'])) {
                throw new InvalidArgumentException("Type must be either 'text' or 'image'");
            }
            $endpoint .= '?type=' . urlencode($type);
        }
        return $this->request('GET', $endpoint);
    }

    /**
     * List available text models
     * 
     * @return array List of available text models
     * @throws Exception If the request fails
     */
    public function listTextModels(): array {
        return $this->listModels('text');
    }

    /**
     * List available image models
     * 
     * @return array List of available image models
     * @throws Exception If the request fails
     */
    public function listImageModels(): array {
        return $this->listModels('image');
    }

    /**
     * Create a chat completion
     * 
     * @param array $messages Array of message objects
     * @param string $model Model to use (e.g., 'qwen-2.5-vl' for vision)
     * @param array $options Additional options
     * @return array The API response
     * @throws InvalidArgumentException If parameters are invalid
     * @throws Exception If the request fails
     */
    public function createChatCompletion(array $messages, string $model = 'qwen-2.5-vl', array $options = []): array {
        if (empty($messages)) {
            throw new InvalidArgumentException('messages array is required');
        }

        // Validate each message
        foreach ($messages as $message) {
            if (!isset($message['role'])) {
                throw new InvalidArgumentException('Each message must have a role');
            }
            if (!in_array($message['role'], ['system', 'user', 'assistant'])) {
                throw new InvalidArgumentException('Invalid role: ' . $message['role']);
            }
            if (!isset($message['content'])) {
                throw new InvalidArgumentException('Each message must have content');
            }
        }

        // Build request data
        $data = [
            'model' => $model,
            'messages' => $messages
        ];

        // Add optional parameters
        $optionalParams = [
            'temperature',
            'top_p',
            'n',
            'stream',
            'stop',
            'max_tokens',
            'presence_penalty',
            'frequency_penalty',
            'logit_bias',
            'user'
        ];

        foreach ($optionalParams as $param) {
            if (isset($options[$param])) {
                $data[$param] = $options[$param];
            }
        }

        // Make the request
        return $this->request('POST', '/chat/completions', $data);
    }

    /**
     * Generate an image
     * 
     * @param array $options Image generation options
     * @return array The API response
     * @throws InvalidArgumentException If required options are missing or invalid
     * @throws Exception If the request fails
     */
    public function generateImage(array $options): array {
        // Validate required parameters
        if (empty($options['prompt'])) {
            throw new InvalidArgumentException('prompt is required for image generation');
        }
        if (strlen($options['prompt']) > 1500) {
            throw new InvalidArgumentException('prompt must not exceed 1500 characters');
        }

        // Convert width and height to numbers
        if (isset($options['width'])) {
            $options['width'] = (int)$options['width'];
        }
        if (isset($options['height'])) {
            $options['height'] = (int)$options['height'];
        }

        // Validate size if both width and height are provided
        if (isset($options['width']) && isset($options['height'])) {
            $size = [$options['width'], $options['height']];
            $valid = false;
            foreach (self::VALID_SIZES as $validSize) {
                if ($size[0] === $validSize[0] && $size[1] === $validSize[1]) {
                    $valid = true;
                    break;
                }
            }
            if (!$valid) {
                throw new InvalidArgumentException(
                    'Invalid image size. Must be one of: ' . implode(' ', array_map(function($size) {
                        return $size[0] . 'x' . $size[1];
                    }, self::VALID_SIZES))
                );
            }
        }

        // Validate steps if provided
        if (isset($options['steps'])) {
            $steps = (int)$options['steps'];
            if ($steps < 1 || $steps > 50) {
                throw new InvalidArgumentException('steps must be between 1 and 50');
            }
            $options['steps'] = $steps;
        }

        // Validate style preset if provided
        if (isset($options['style_preset'])) {
            if (!in_array($options['style_preset'], self::VALID_STYLES)) {
                throw new InvalidArgumentException(
                    'Invalid style_preset. Must be one of: ' . implode(' ', self::VALID_STYLES)
                );
            }
        }

        // Validate negative prompt if provided
        if (isset($options['negative_prompt'])) {
            if (strlen($options['negative_prompt']) > 1500) {
                throw new InvalidArgumentException('negative_prompt must not exceed 1500 characters');
            }
        }

        // Convert numeric parameters to numbers
        if (isset($options['seed'])) {
            $options['seed'] = (int)$options['seed'];
        }
        if (isset($options['cfg_scale'])) {
            $options['cfg_scale'] = (float)$options['cfg_scale'];
            if ($options['cfg_scale'] < 0) {
                throw new InvalidArgumentException('cfg_scale must be a positive number');
            }
        }

        // Build request data
        $data = [
            'model' => $options['model'] ?? 'fluently-xl',
            'prompt' => $options['prompt']
        ];

        // Add optional parameters
        $optionalParams = [
            'width',
            'height',
            'steps',
            'hide_watermark',
            'return_binary',
            'seed',
            'cfg_scale',
            'style_preset',
            'negative_prompt',
            'safe_mode'
        ];

        foreach ($optionalParams as $param) {
            if (isset($options[$param])) {
                $data[$param] = $options[$param];
            }
        }

        // Set Accept header for binary response if requested
        $originalHeaders = $this->headers;
        if (isset($data['return_binary']) && $data['return_binary']) {
            $this->headers['Accept'] = 'image/*';
        }

        try {
            // Make the request
            $response = $this->request('POST', '/image/generate', $data);

            // Handle binary response
            if (isset($data['return_binary']) && $data['return_binary']) {
                return [
                    'data' => base64_encode($response)
                ];
            }

            // Handle JSON response
            if (isset($response['images']) && is_array($response['images']) && !empty($response['images'])) {
                return [
                    'data' => $response['images'][0]
                ];
            }

            throw new Exception('No image data in response');
        } finally {
            $this->headers = $originalHeaders;
        }
    }

    /**
     * Upscale an image
     * 
     * @param array $options Image upscaling options
     * @return array The API response
     * @throws InvalidArgumentException If required options are missing or invalid
     * @throws Exception If the request fails
     */
    public function upscaleImage(array $options): array {
        if (empty($options['image'])) {
            throw new InvalidArgumentException('image is required for upscaling');
        }

        if (!isset($options['scale'])) {
            throw new InvalidArgumentException('scale is required for upscaling');
        }

        // Convert scale to string and validate
        $scale = (string)$options['scale'];
        if (!in_array($scale, ['2', '4'], true)) {
            throw new InvalidArgumentException('scale must be "2" or "4"');
        }

        // Validate image file exists
        if (is_string($options['image']) && !file_exists($options['image'])) {
            throw new InvalidArgumentException('image file not found: ' . $options['image']);
        }

        // Prepare request data
        $data = [
            'scale' => $scale,
            'return_binary' => true
        ];

        // Add image file
        if (is_string($options['image'])) {
            $data['image'] = $options['image'];
        } else {
            throw new InvalidArgumentException('image must be a file path');
        }

        // Set headers for multipart form data and binary response
        $originalHeaders = $this->headers;
        $this->headers = array_filter($this->headers, function($key) {
            return strtolower($key) !== 'content-type' && strtolower($key) !== 'accept';
        }, ARRAY_FILTER_USE_KEY);
        $this->headers['Accept'] = 'image/*';
        
        try {
            $response = $this->request('POST', '/image/upscale', $data);
            
            // Ensure we have binary data in the response
            if (!isset($response['data']) || empty($response['data'])) {
                throw new Exception('No image data received from upscaling request');
            }
            
            return $response;
        } finally {
            $this->headers = $originalHeaders;
        }
    }

    /**
     * Make an HTTP request to the Venice AI API
     * 
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $endpoint API endpoint
     * @param array $data Request data (optional)
     * @return array|string The API response (array for JSON, string for binary)
     * @throws Exception If the request fails
     */
    private function request(string $method, string $endpoint, array $data = []): array|string {
        $url = self::BASE_URL . $endpoint;
        
        // Show request data in debug mode
        if ($this->debug) {
            echo "\nRequest URL: " . $url . "\n";
            echo "Method: " . $method . "\n";
            echo "Headers: " . json_encode($this->headers, JSON_PRETTY_PRINT) . "\n";
            if (!empty($data)) {
                echo "Request Data: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";
            }
        }

        $ch = curl_init($url);
        
        $headers = [];
        foreach ($this->headers as $key => $value) {
            $headers[] = "$key: $value";
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 30
        ];

        // Only set verbose mode and stderr if debug is enabled
        if ($this->debug) {
            $options[CURLOPT_VERBOSE] = true;
            $options[CURLOPT_STDERR] = $this->debugHandle;
        }

        if (!empty($data)) {
            // If we're sending an image file, use multipart form data
            if (isset($data['image']) && file_exists($data['image'])) {
                $postFields = [];
                
                // Add image file
                $postFields['image'] = new \CURLFile(
                    $data['image'],
                    'image/png',
                    basename($data['image'])
                );
                
                // Add all other fields except 'image'
                foreach ($data as $key => $value) {
                    if ($key !== 'image') {
                        $postFields[$key] = (string)$value;  // Convert all values to string for form data
                    }
                }
                
                $options[CURLOPT_POSTFIELDS] = $postFields;
                
                // Update headers for multipart form data
                $headers = array_filter($headers, function($header) {
                    return !preg_match('/^(Content-Type:|Accept:)/i', $header);
                });
                $headers[] = 'Content-Type: multipart/form-data';
                if (strpos($endpoint, '/image/') === 0) {
                    $headers[] = 'Accept: image/*';
                }
                $options[CURLOPT_HTTPHEADER] = $headers;
            } else {
                $options[CURLOPT_POSTFIELDS] = json_encode($data);
            }
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('Request failed: ' . $error);
        }
        
        curl_close($ch);

        if (!$response) {
            throw new Exception('Empty response received from API');
        }

        if ($statusCode >= 400) {
            // Try to parse error response
            $errorData = json_decode($response, true);
            if ($errorData && isset($errorData['error'])) {
                // Show full error response in debug mode
                if ($this->debug) {
                    echo "\nError Response: " . json_encode($errorData, JSON_PRETTY_PRINT) . "\n";
                }
                throw new Exception($errorData['error']);
            }
            throw new Exception('API request failed with status ' . $statusCode . ': ' . $response);
        }

        // Handle binary responses (images)
        if (strpos($contentType, 'image/') === 0) {
            // Verify we got image data
            if (empty($response)) {
                throw new Exception('No data received from API');
            }
            
            // Check if response starts with PNG or JPEG magic numbers
            $isPNG = substr($response, 0, 8) === "\x89PNG\r\n\x1a\n";
            $isJPEG = substr($response, 0, 2) === "\xFF\xD8";
            
            if (!$isPNG && !$isJPEG) {
                // If not an image, try to parse as JSON error response
                $errorData = json_decode($response, true);
                if ($errorData && isset($errorData['error'])) {
                    throw new Exception($errorData['error']);
                }
                throw new Exception('Invalid image data received');
            }
            
            // Return binary image data
            return $response;
        }

        // Handle JSON responses
        $responseData = json_decode($response, true);
        if ($responseData === null) {
            throw new Exception('Failed to parse JSON response: ' . json_last_error_msg());
        }

        return $responseData;
    }
}