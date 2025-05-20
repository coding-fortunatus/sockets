<?php

namespace App;

require_once __DIR__ . '/../vendor/autoload.php';

use OpenSwoole\Http\Request;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;
use OpenSwoole\Table;
use PDO;
use Dotenv\Dotenv;
use PDOException;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

class GameSocket
{
    private PDO $db;
    protected Server $server;
    protected Table $connectionTable;
    protected Table $gameTable;

    private static array $config = [
        'host' => '',
        'dbname' => '',
        'port' => '',
        'user' => '',
        'password' => '',
        'driver' => 'mysql',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    ];

    public function __construct()
    {
        // Create shared table for connection tracking
        $this->connectionTable = new Table(1024);
        $this->connectionTable->column('game_id', Table::TYPE_INT);
        $this->connectionTable->column('player_id', Table::TYPE_INT);
        $this->connectionTable->create();

        // Create shared table for game tracking
        $this->gameTable = new Table(256);
        $this->gameTable->column('player_count', Table::TYPE_INT);
        $this->gameTable->column('active', Table::TYPE_INT, 1); // 1 = active, 0 = inactive
        $this->gameTable->create();

        // Initialize Open Swoole Server
        $this->server = new Server('0.0.0.0', 9002);
        $this->server->on('open', [$this, "onOpen"]);
        $this->server->on('message', [$this, "onMessage"]);
        $this->server->on('close', [$this, "onClose"]);

        self::$config['host'] = $_ENV['DB_HOST'];
        self::$config['port']     = $_ENV['DB_PORT'];
        self::$config['dbname'] = $_ENV['DB_NAME'];
        self::$config['user'] = $_ENV['DB_USER'];
        self::$config['password'] = $_ENV['DB_PASSWORD'];

        if ($_ENV['APP_ENV'] === 'production') {
            // Use the full path to the certificate
            $certPath = __DIR__ . '/BaltimoreCyberTrustRoot.crt.pem';
            if (file_exists($certPath)) {
                self::$config['options'][PDO::MYSQL_ATTR_SSL_CA] = $certPath;
                self::$config['options'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            } else {
                echo "Warning: SSL certificate file not found at {$certPath}\n";
            }
        }

        $this->connectToDatabase();
    }

    private function connectToDatabase()
    {
        try {
            $dsn = self::$config['driver'] . ":host=" . self::$config['host'] . ";port=" . self::$config['port'] . ";dbname=" . self::$config['dbname'] . ";charset=utf8mb4";
            $this->db = new PDO($dsn, self::$config['user'], self::$config['password'], self::$config['options']);
            echo "Database connection established.\n";
        } catch (PDOException $e) {
            echo "Database Connection Failed: " . $e->getMessage() . "\n";
            exit;
        }
    }

    private function ensureDbConnection()
    {
        try {
            // Check if connection is still alive
            if (!$this->db || !$this->db->query('SELECT 1')) {
                // Reconnect if query fails
                $this->connectToDatabase();
            }
        } catch (PDOException $e) {
            echo "Database connection check failed: " . $e->getMessage() . "\n";
            // Reconnect
            $this->connectToDatabase();
        }
    }

    public function onOpen(Server $server, Request $request)
    {
        if (!isset($request->server['query_string'])) {
            echo "\nServer disconnected; no query_param added";
            $server->close($request->fd);
            return;
        }
        // Extract gameId and PlayerId on connection to game
        parse_str($request->server['query_string'], $params);
        if (!isset($params['player_id']) || !isset($params['game_id'])) {
            echo "\nServer disconnected; no player_id and game_id added";
            $server->close($request->fd);
            return;
        }
        // Initialize and set the params
        $player_id = (int)$params['player_id'];
        $game_id = (int)$params['game_id'];

        try {
            // Ensure database connection is active before querying
            $this->ensureDbConnection();

            // Use prepared statements to prevent SQL injection
            $stmt = $this->db->prepare("SELECT * FROM games_players WHERE gameId = ? AND userId = ?");
            $stmt->execute([$game_id, $player_id]);
            $result = $stmt->fetch(PDO::FETCH_OBJ);

            if (!$result) {
                echo "\nInvalid game or player ID provided";
                $server->close($request->fd);
                return;
            }

            // Store connection in the shared table
            $this->connectionTable->set($request->fd, [
                'game_id' => $game_id,
                'player_id' => $player_id
            ]);

            // Update or initialize game info
            if (!$this->gameTable->exists($game_id)) {
                $this->gameTable->set($game_id, [
                    'player_count' => 1,
                    'active' => 1
                ]);
            } else {
                $gameInfo = $this->gameTable->get($game_id);
                $this->gameTable->set($game_id, [
                    'player_count' => $gameInfo['player_count'] + 1,
                    'active' => 1
                ]);
            }

            // Log connection info
            echo "\nPlayer {$player_id} connected to game {$game_id} with fd {$request->fd}";
            $this->printConnectionStatus();

            // Notify player on successful connection
            $server->push($request->fd, json_encode([
                'status' => 'Connected',
                'message' => "Player connected to game {$game_id}",
            ]));

            // Broadcast to other players about the new connection
            $this->broadcastToGame($server, $game_id, [
                'action' => 'playerConnected',
                'status' => 'connection',
                'player_id' => $player_id,
                'message' => "New player {$player_id} connected"
            ], $request->fd);
        } catch (PDOException $e) {
            echo "\nDatabase error during connection: " . $e->getMessage();
            $server->close($request->fd);
            return;
        }
    }

    public function onMessage(Server $server, Frame $frame)
    {
        $data = json_decode($frame->data, true);
        if (!isset($data['action']) || !isset($data['game_id']) || !isset($data['player_id']) || !isset($data['data'])) {
            $server->push($frame->fd, json_encode(["status" => "error", "message" => "Invalid request"]));
            return;
        }

        $game_id = (int)$data['game_id'];
        $player_id = (int)$data['player_id'];

        // Verify that the player is connected to this game
        $connection = $this->connectionTable->get($frame->fd);
        if (!$connection || $connection['game_id'] !== $game_id || $connection['player_id'] !== $player_id) {
            $server->push($frame->fd, json_encode([
                "status" => "error",
                "message" => "Unauthorized access to game"
            ]));
            return;
        }

        // Push messages based on actions
        switch ($data['action']) {
            case "updatePlayers":
                $this->updatePlayers($server, $frame->fd, $data);
                break;
            case "updateDiceNo":
                $this->updateDiceNo($server, $frame->fd, $data);
                break;
            case "enablePileSelection":
                $this->enablePileSelection($server, $frame->fd, $data);
                break;
            case "updatePlayerChance":
                $this->updatePlayerChance($server, $frame->fd, $data);
                break;
            case "enableCellSelection":
                $this->enableCellSelection($server, $frame->fd, $data);
                break;
            case "updateFireworks":
                $this->updateFireworks($server, $frame->fd, $data);
                break;
            case "updatePlayerPieceValue":
                $this->updatePlayerPieceValue($server, $frame->fd, $data);
                break;
            case "unfreezeDice":
                $this->unfreezeDice($server, $frame->fd, $data);
                break;
            case "disableTouch":
                $this->disableTouch($server, $frame->fd, $data);
                break;
            case "announceWinner":
                $this->announceWinner($server, $frame->fd, $data);
                break;
            default:
                $server->push($frame->fd, json_encode(["status" => "error", "message" => "Unknown action"]));
        }
    }

    // Game Actions Methods
    public function updatePlayers(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id']) || !isset($data['dice_value'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Required fields not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function updateDiceNo(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function enablePileSelection(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function updatePlayerChance(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function enableCellSelection(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function updateFireworks(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function updatePlayerPieceValue(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function unfreezeDice(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function disableTouch(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function announceWinner(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    // Method to start the socket server
    public function start()
    {
        echo "WebSocket server started on port 9002\n";
        $this->server->start();
    }

    // Method that handles disconnections
    public function onClose(Server $server, int $fd)
    {
        echo "\nConnection closed: fd={$fd}";

        // Check if this connection exists in our table
        if (!$this->connectionTable->exists($fd)) {
            echo "\nConnection not found in table: fd={$fd}";
            return;
        }

        // Get connection details
        $connection = $this->connectionTable->get($fd);
        $game_id = $connection['game_id'];
        $player_id = $connection['player_id'];

        echo "\nPlayer {$player_id} disconnected from game {$game_id}";

        // Remove from connection table
        $this->connectionTable->del($fd);

        // Update game player count
        if ($this->gameTable->exists($game_id)) {
            $gameInfo = $this->gameTable->get($game_id);
            $newCount = max(0, $gameInfo['player_count'] - 1);

            if ($newCount > 0) {
                $this->gameTable->set($game_id, [
                    'player_count' => $newCount,
                    'active' => 1
                ]);

                // Notify remaining players
                $this->broadcastToGame($server, $game_id, [
                    'action' => 'playerDisconnected',
                    'status' => 'Disconnected',
                    'player_id' => $player_id,
                    'message' => "Player {$player_id} has left the game"
                ]);
            } else {
                // If no players left, mark game as inactive or remove it
                $this->gameTable->set($game_id, [
                    'player_count' => 0,
                    'active' => 0
                ]);
                echo "\nGame {$game_id} is now inactive (no players)";
            }
        }
        $this->printConnectionStatus();
    }

    // Helper method to print current connection status
    private function printConnectionStatus()
    {
        echo "\n--- Current Connections ---";
        echo "\nConnections:";
        foreach ($this->connectionTable as $fd => $info) {
            echo "\n  fd: {$fd}, game: {$info['game_id']}, player: {$info['player_id']}";
        }

        echo "\nGames:";
        foreach ($this->gameTable as $game_id => $info) {
            echo "\n  game: {$game_id}, players: {$info['player_count']}, active: {$info['active']}";
        }
        echo "\n--------------------------";
    }

    // Method to broadcast data to all players in a game
    public function broadcastToGame($server, $game_id, $data, $exclude = null)
    {
        echo "\nBroadcasting to game {$game_id}";

        $player_id = $data['player_id'] ?? 'unknown';

        // Determine the broadcast message based on the action
        $message = match ($data['action'] ?? '') {
            'playerConnected' => "Player {$player_id} has joined the game",
            'playerDisconnected' => "Player {$player_id} has left the game",
            'updatePlayers' => "Players updated",
            'updateDiceNo' => "Player dice number updated",
            'enablePileSelection' => "Pile selection enabled",
            'updatePlayerChance' => "Update player chance",
            'enableCellSelection' => "Cell selection enabled",
            'updateFireworks' => "Fireworks updated",
            'updatePlayerPieceValue' => "Player piece value updated",
            'unfreezeDice' => "Dice unfrozen",
            'disableTouch' => "Touch disabled",
            'announceWinner' => "Winner",
            default => "Player {$player_id} performed an action"
        };

        // Find all connections for this game
        $recipients = 0;
        foreach ($this->connectionTable as $fd => $info) {
            if ($info['game_id'] == $game_id && ($exclude === null || $fd != $exclude)) {
                $recipients++;
                echo "\nBroadcasting to fd = {$fd}, player = {$info['player_id']}: {$message}";
                // Send the data along with a user-friendly message
                $server->push($fd, json_encode([
                    "status" => "notification",
                    "message" => $message,
                    "action" => $data['action'],
                    "data" => $data['data'] ?? null,
                ]));
            }
        }

        echo "\nBroadcast completed: {$recipients} recipient(s)";
        echo "\n ---- ==================================================== ----";
    }
}

$socket = new GameSocket();
$socket->start();
