<?php
declare(strict_types=1);

/**
 * Position data structure for 3D coordinates
 */
class Position {
    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly float $z
    ) {}

    public function toArray(): array {
        return [
            'x' => $this->x,
            'y' => $this->y,
            'z' => $this->z
        ];
    }
}

/**
 * Robot command data structure
 */
class RobotCommand {
    public readonly string $timestamp;

    public function __construct(
        public readonly string $operation,
        public readonly Position $position,
        public readonly int $userId,
        public string $status = 'pending'
    ) {
        $this->timestamp = date('Y-m-d H:i:s');
    }

    public function toJson(): string {
        return json_encode([
            'command' => $this->operation,
            'position' => $this->position->toArray(),
            'user_id' => $this->userId,
            'timestamp' => $this->timestamp
        ]);
    }
}

/**
 * Telemetry data from the robot
 */
class RobotTelemetry {
    public readonly string $timestamp;

    public function __construct(
        public readonly string $robotStatus,
        public readonly Position $position,
        public readonly float $temperature,
        public readonly ?string $error = null
    ) {
        $this->timestamp = date('Y-m-d H:i:s');
    }

    public static function fromJson(string $json): self {
        $data = json_decode($json, true);
        return new self(
            $data['status'],
            new Position($data['position']['x'], $data['position']['y'], $data['position']['z']),
            $data['temperature'],
            $data['error']
        );
    }
}

/**
 * Storage interface for robot operations
 */
interface RobotStorageInterface {
    public function logCommand(RobotCommand $command): bool;
    public function logTelemetry(RobotTelemetry $telemetry): bool;
    public function getLastPosition(): ?Position;
}

/**
 * Simple file-based storage implementation
 */
class FileStorage implements RobotStorageInterface {
    private string $commandsFile;
    private string $telemetryFile;

    public function __construct() {
        $this->commandsFile = __DIR__ . '/robot_commands.json';
        $this->telemetryFile = __DIR__ . '/robot_telemetry.json';
        
        // Initialize files if they don't exist
        foreach ([$this->commandsFile, $this->telemetryFile] as $file) {
            if (!file_exists($file)) {
                file_put_contents($file, json_encode([]));
            }
        }
    }

    public function logCommand(RobotCommand $command): bool {
        $commands = $this->readJsonFile($this->commandsFile);
        $commands[] = [
            'user_id' => $command->userId,
            'command' => $command->operation,
            'x' => $command->position->x,
            'y' => $command->position->y,
            'z' => $command->position->z,
            'timestamp' => $command->timestamp,
            'status' => $command->status
        ];
        return $this->writeJsonFile($this->commandsFile, $commands);
    }

    public function logTelemetry(RobotTelemetry $telemetry): bool {
        $telemetryData = $this->readJsonFile($this->telemetryFile);
        $telemetryData[] = [
            'status' => $telemetry->robotStatus,
            'x' => $telemetry->position->x,
            'y' => $telemetry->position->y,
            'z' => $telemetry->position->z,
            'temperature' => $telemetry->temperature,
            'timestamp' => $telemetry->timestamp
        ];
        return $this->writeJsonFile($this->telemetryFile, $telemetryData);
    }

    public function getLastPosition(): ?Position {
        $telemetryData = $this->readJsonFile($this->telemetryFile);
        if (empty($telemetryData)) {
            return null;
        }
        
        $lastEntry = end($telemetryData);
        return new Position(
            (float)$lastEntry['x'],
            (float)$lastEntry['y'],
            (float)$lastEntry['z']
        );
    }

    private function readJsonFile(string $file): array {
        $content = file_get_contents($file);
        return json_decode($content, true) ?? [];
    }

    private function writeJsonFile(string $file, array $data): bool {
        return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT)) !== false;
    }
}

/**
 * Main Robot Controller class
 */
class RobotController {
    public function __construct(
        private RobotStorageInterface $storage
    ) {}

    public function handleRequest(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'history') {
            $this->handleHistoryRequest();
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendResponse(['error' => 'Invalid request method'], 405);
            return;
        }

        // Validate and sanitize input
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$this->validateInput($input)) {
            $this->sendResponse(['error' => 'Invalid input parameters'], 400);
            return;
        }

        // Create command
        $command = new RobotCommand(
            $input['operation'],
            new Position($input['x_pos'], $input['y_pos'], $input['z_pos']),
            $_SESSION['uid'] ?? 0
        );

        // Log command to storage
        if (!$this->storage->logCommand($command)) {
            $this->sendResponse(['error' => 'Failed to log command'], 500);
            return;
        }

        // Simulate robot API response (in real implementation, this would call the actual robot API)
        $robotResponse = $this->simulateRobotResponse($command);
        
        // Log telemetry
        $telemetry = RobotTelemetry::fromJson($robotResponse);
        $this->storage->logTelemetry($telemetry);

        // Send response back to client
        $this->sendResponse(json_decode($robotResponse, true));
    }

    private function validateInput(array $input): bool {
        $requiredFields = ['operation', 'x_pos', 'y_pos', 'z_pos'];
        foreach ($requiredFields as $field) {
            if (!isset($input[$field])) return false;
        }

        $validOperations = ['pick', 'place', 'weld'];
        if (!in_array($input['operation'], $validOperations)) return false;

        foreach (['x_pos', 'y_pos', 'z_pos'] as $coord) {
            if (!is_numeric($input[$coord]) || 
                $input[$coord] < 0 || 
                $input[$coord] > 100) return false;
        }

        return true;
    }

    private function simulateRobotResponse(RobotCommand $command): string {
        // Simulate some processing time
        usleep(100000);

        // Generate simulated response
        return json_encode([
            'status' => 'completed',
            'position' => $command->position->toArray(),
            'temperature' => 36.5 + (rand(-10, 10) / 10),
            'error' => null
        ]);
    }

    private function sendResponse(array $data, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    private function handleHistoryRequest(): void {
        $commands = json_decode(file_get_contents($this->storage->commandsFile), true) ?? [];
        $this->sendResponse($commands);
    }
}

// Initialize and run the controller when this file is accessed
try {
    session_start();
    
    $storage = new FileStorage();
    $controller = new RobotController($storage);
    $controller->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
