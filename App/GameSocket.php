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
    protected Table $playersTable;

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
        $this->gameTable->column('active', Table::TYPE_INT, 1);
        $this->gameTable->create();

        // Create table to track players in each game
        $this->playersTable = new Table(256);
        $this->playersTable->column('player_ids', Table::TYPE_STRING, 1024);
        $this->playersTable->create();

        // Initialize Open Swoole Server
        $this->server = new Server('0.0.0.0', 9002);
        $this->server->on('open', [$this, "onOpen"]);
        $this->server->on('message', [$this, "onMessage"]);
        $this->server->on('close', [$this, "onClose"]);

        self::$config['host'] = $_ENV['DB_HOST'];
        self::$config['port'] = $_ENV['DB_PORT'];
        self::$config['dbname'] = $_ENV['DB_NAME'];
        self::$config['user'] = $_ENV['DB_USER'];
        self::$config['password'] = $_ENV['DB_PASSWORD'];

        if ($_ENV['APP_ENV'] === 'production') {
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
            if (!$this->db || !$this->db->query('SELECT 1')) {
                $this->connectToDatabase();
            }
        } catch (PDOException $e) {
            echo "Database connection check failed: " . $e->getMessage() . "\n";
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

        parse_str($request->server['query_string'], $params);
        if (!isset($params['player_id']) || !isset($params['game_id'])) {
            echo "\nServer disconnected; no player_id and game_id added";
            $server->close($request->fd);
            return;
        }

        $player_id = (int)$params['player_id'];
        $game_id = (int)$params['game_id'];

        try {
            $this->ensureDbConnection();
            $stmt = $this->db->prepare("SELECT * FROM games_players WHERE gameId = ? AND userId = ?");
            $stmt->execute([$game_id, $player_id]);
            $result = $stmt->fetch(PDO::FETCH_OBJ);

            if (!$result) {
                echo "\nInvalid game or player ID provided";
                $server->close($request->fd);
                return;
            }
            // In onOpen method, after getting player_id and game_id:
            if ($player_id <= 0 || $game_id <= 0) {
                echo "\nInvalid player or game ID";
                $server->close($request->fd);
                return;
            }
            // Store connection in the shared table
            $this->connectionTable->set($request->fd, [
                'game_id' => $game_id,
                'player_id' => $player_id
            ]);
            // Update game info and player list
            $this->addPlayerToGame($game_id, $player_id);
            // Log connection info
            echo "\nPlayer {$player_id} connected to game {$game_id} with fd {$request->fd}";
            $this->printConnectionStatus();
            // Get all connected players for this game
            $connectedPlayers = $this->getConnectedPlayers($game_id);
            // Notify player on successful connection
            $server->push($request->fd, json_encode([
                'status' => 'notification',
                'message' => "You have connected to game {$game_id}",
                'action' => 'playerConnected',
                'data' => [
                    'player_id' => $player_id,
                    'game_id' => $game_id,
                    'connected_players' => $connectedPlayers,
                    'player_count' => count($connectedPlayers),
                ]
            ]));
            // Broadcast to other players about the new connection
            $this->broadcastToGame($server, $game_id, [
                'action' => 'playerConnected',
                'status' => 'notification',
                'player_id' => $player_id,
            ], $request->fd);
        } catch (PDOException $e) {
            echo "\nDatabase error during connection: " . $e->getMessage();
            $server->close($request->fd);
            return;
        }
    }

    public function getConnectedPlayers(int $game_id): array
    {
        if (!$this->playersTable->exists($game_id)) {
            return [];
        }

        $players = $this->playersTable->get($game_id);
        if (empty($players['player_ids']) || $players['player_ids'] === '') {
            return [];
        }

        $playerIds = explode(',', $players['player_ids']);
        // Filter out empty strings and convert to integers
        return array_filter(array_map('intval', $playerIds), function ($id) {
            return $id > 0; // Ensure we only return valid player IDs
        });
    }

    private function addPlayerToGame(int $game_id, int $player_id): void
    {
        if (!$this->gameTable->exists($game_id)) {
            $this->gameTable->set($game_id, [
                'player_count' => 1,
                'active' => 1
            ]);
            $this->playersTable->set($game_id, [
                'player_ids' => (string)$player_id
            ]);
        } else {
            $gameInfo = $this->gameTable->get($game_id);
            $this->gameTable->set($game_id, [
                'player_count' => $gameInfo['player_count'] + 1,
                'active' => 1
            ]);

            $players = $this->playersTable->get($game_id);
            $playerIds = [];

            if (!empty($players['player_ids'])) {
                $playerIds = explode(',', $players['player_ids']);
            }

            // Add player if not already present
            if (!in_array($player_id, $playerIds)) {
                $playerIds[] = $player_id;
                $this->playersTable->set($game_id, [
                    'player_ids' => implode(',', array_filter($playerIds)) // Filter empty values
                ]);
            }
        }
    }

    private function removePlayerFromGame(int $game_id, int $player_id): void
    {
        if ($this->playersTable->exists($game_id)) {
            $players = $this->playersTable->get($game_id);
            if (!empty($players['player_ids'])) {
                $playerIds = explode(',', $players['player_ids']);
                $playerIds = array_filter($playerIds, function ($id) use ($player_id) {
                    return $id != $player_id && $id !== '';
                });

                $this->playersTable->set($game_id, [
                    'player_ids' => implode(',', $playerIds)
                ]);
            }
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

        $connection = $this->connectionTable->get($frame->fd);
        if (!$connection || $connection['game_id'] !== $game_id || $connection['player_id'] !== $player_id) {
            $server->push($frame->fd, json_encode([
                "status" => "error",
                "message" => "Unauthorized access to game"
            ]));
            return;
        }

        // Add connected players to the data before processing
        $data['connected_players'] = $this->getConnectedPlayers($game_id);

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

    public function updatePlayers(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id']) || !isset($data['data'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Required fields not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function updateDiceNo(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id']) || !isset($data['data'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function enablePileSelection(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id']) || !isset($data['data'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function updatePlayerChance(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id']) || !isset($data['data'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function enableCellSelection(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id']) || !isset($data['data'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function updateFireworks(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id']) || !isset($data['data'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function updatePlayerPieceValue(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id']) || !isset($data['data'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function unfreezeDice(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id']) || !isset($data['data'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function disableTouch(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id']) || !isset($data['data'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function announceWinner(Server $server, int $fd, $data)
    {
        if (!isset($data['game_id']) || !isset($data['player_id']) || !isset($data['data'])) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcastToGame($server, $data['game_id'], $data, $fd);
    }

    public function start()
    {
        // Clean up inactive games every 60 seconds
        $this->server->tick(60000, function () {
            foreach ($this->gameTable as $game_id => $info) {
                if ($info['player_count'] === 0) {
                    $this->gameTable->del($game_id);
                    $this->playersTable->del($game_id);
                }
            }
        });

        echo "WebSocket server started on port 9002\n";
        $this->server->start();
    }

    public function onClose(Server $server, int $fd)
    {
        echo "\nConnection closed: fd={$fd}";

        if (!$this->connectionTable->exists($fd)) {
            echo "\nConnection not found in table: fd={$fd}";
            return;
        }

        $connection = $this->connectionTable->get($fd);
        $game_id = $connection['game_id'];
        $player_id = $connection['player_id'];

        echo "\nPlayer {$player_id} disconnected from game {$game_id}";

        $this->connectionTable->del($fd);
        $this->removePlayerFromGame($game_id, $player_id);

        if ($this->gameTable->exists($game_id)) {
            $gameInfo = $this->gameTable->get($game_id);
            $newCount = max(0, $gameInfo['player_count'] - 1);

            if ($newCount > 0) {
                $this->gameTable->set($game_id, [
                    'player_count' => $newCount,
                    'active' => 1
                ]);

                $connectedPlayers = $this->getConnectedPlayers($game_id);

                $this->broadcastToGame($server, $game_id, [
                    'action' => 'playerDisconnected',
                    'status' => 'notification',
                    'player_id' => $player_id,
                    'connected_players' => $connectedPlayers,
                    'player_count' => $newCount,
                    'message' => "Player {$player_id} has left the game",
                    'live_games' => $this->getLiveGames()
                ]);
            } else {
                $this->gameTable->set($game_id, [
                    'player_count' => 0,
                    'active' => 0
                ]);
                echo "\nGame {$game_id} is now inactive (no players)";
            }
        }
        $this->printConnectionStatus();
    }

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

        echo "\nPlayers per game:";
        foreach ($this->playersTable as $game_id => $info) {
            $players = $this->getConnectedPlayers($game_id);
            echo "\n  game: {$game_id}, players: " . implode(', ', $players);
        }
        echo "\n--------------------------";
    }

    public function getLiveGames()
    {
        $liveGames = [];
        foreach ($this->gameTable as $game_id => $info) {
            if ($info['active'] === 1) {
                $liveGames[] = $game_id;
            }
        }
        return $liveGames;
    }

    public function broadcastToGame($server, $game_id, $data, $exclude = null)
    {
        echo "\nBroadcasting to game {$game_id}";

        $player_id = $data['player_id'] ?? 'unknown';
        $connectedPlayers = $this->getConnectedPlayers($game_id);

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

        $recipients = 0;
        foreach ($this->connectionTable as $fd => $info) {
            if ($info['game_id'] == $game_id && ($exclude === null || $fd != $exclude)) {
                $recipients++;
                echo "\nBroadcasting to fd = {$fd}, player = {$info['player_id']}: {$message}";

                $server->push($fd, json_encode([
                    "status" => $data['status'] ?? 'notification',
                    "message" => $message,
                    "action" => $data['action'] ?? 'unknown',
                    "data" =>  [
                        'player_id' =>  $player_id,
                        'game_id' => $game_id,
                        'connected_players' => $connectedPlayers,
                        'player_count' => count($connectedPlayers),
                        ...array_diff_key($data, array_flip(['status', 'message', 'action', 'player_id']))
                    ],
                ]));
            }
        }

        echo "\nBroadcast completed: {$recipients} recipient(s)";
        echo "\n ---- ==================================================== ----";
    }
}

$socket = new GameSocket();
$socket->start();
