<?php

namespace App;

require_once __DIR__ . '/../vendor/autoload.php';

use OpenSwoole\Http\Request;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;
use PDO;
use Dotenv\Dotenv;
use PDOException;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

class GameSocket
{
    private PDO $db;
    private array $connections = [];
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
    private Server $server;
    public function __construct()
    {
        // Initialize Open Swoole Server
        $this->server = new Server('0.0.0.0', 9001);

        self::$config['host'] = $_ENV['DB_HOST'];
        self::$config['port']     = $_ENV['DB_PORT'];
        self::$config['dbname'] = $_ENV['DB_NAME'];
        self::$config['user'] = $_ENV['DB_USER'];
        self::$config['password'] = $_ENV['DB_PASSWORD'];

        if ($_ENV['APP_ENV'] === 'production') {
            self::$config['options'][PDO::MYSQL_ATTR_SSL_CA] = 'App/BaltimoreCyberTrustRoot.crt.pem';
            self::$config['options'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        try {
            $dsn = self::$config['driver'] . ":host=" . self::$config['host'] . ";port=" . self::$config['port'] . ";dbname=" . self::$config['dbname'] . ";charset=utf8mb4";
            $this->db = new PDO($dsn, self::$config['user'], self::$config['password'], self::$config['options']);
            echo "Database connection established.\n";
        } catch (PDOException $e) {
            echo "Database Connection Failed: " . $e->getMessage() . "\n";
            exit;
        }

        $this->server->on('open', [$this, "onOpen"]);
        $this->server->on('message', [$this, "onMessage"]);
        $this->server->on('close', [$this, "onClose"]);
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
        $player_id = $params['player_id'];
        $game_id   = $params['game_id'];

        $query = $this->db->query("SELECT * FROM games_players WHERE gameId={$game_id}", PDO::FETCH_DEFAULT);
        $query->execute();
        $result = $query->fetch(PDO::FETCH_OBJ);
        if ($result->userId != $player_id) {
            echo "\nInvalid game or player ID provided";
            $server->close($request->fd);
            return;
        }

        // Check if the game exist already and store playerId, else otherwise
        if (!isset($this->connections[$game_id])) {
            $this->connections[$game_id] = [];
        }
        $this->connections[$game_id][$player_id] = $request->fd;
        // Notify player on a successful connection
        $server->push($request->fd, json_encode([
            'status' => 'Connected',
            'message' => "Player connected to {$game_id}",
        ]));

        $this->broadcast($server, $game_id, [
            'status' => 'connection',
            'message' => "New player {$player_id} connected"
        ], $request->fd);
    }

    public function onMessage(Server $server, Frame $frame)
    {
        $data = json_decode($frame->data, false);
        if (!isset($data->action) || !isset($data->game_id) || !isset($data->player_id)) {
            $server->push($frame->fd, json_encode(["status" => "error", "message" => "Invalid request"]));
            return;
        }
        $game_id = $data->game_id;
        $player_id = $data->player_id;
        // Validate if player is part of game
        if (!isset($this->connections[$game_id][$player_id]) || $this->connections[$game_id][$player_id] !== $frame->fd) {
            $server->push($frame->fd, json_encode(["status" => "error", "message" => "Unauthorized access to game"]));
            return;
        }

        // Push messages based on actions
        switch ($data->action) {
            case "roledDice":
                $this->roledDice($server, $frame->fd, $data);
                break;
            case "pieceEnter":
                $this->pieceEnter($server, $frame->fd, $data);
                break;
            case "pieceMove":
                $this->pieceMove($server, $frame->fd, $data);
                break;
            case "capturePiece":
                $this->capturePiece($server, $frame->fd, $data);
                break;
            case "safeZone":
                $this->safeZone($server, $frame->fd, $data);
                break;
            case "extraTurn":
                $this->extraTurn($server, $frame->fd, $data);
                break;
            case "pieceHome":
                $this->pieceHome($server, $frame->fd, $data);
                break;
            case "gameWin":
                $this->gameWin($server, $frame->fd, $data);
                break;
            case "skipTurn":
                $this->skipTurn($server, $frame->fd, $data);
                break;
            case "loseTurn":
                $this->loseTurn($server, $frame->fd, $data);
                break;
            default:
                $server->push($frame->fd, json_encode(["status" => "error", "message" => "Unknown action"]));
        }
    }
    // Game Actions Methods
    public function roledDice(Server $server, int $fd, $data)
    {
        if (!isset($data->game_id) || !isset($data->player_id)) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID and Player ID not supplied"]));
            return;
        }
        $this->broadcast($server, $data->game_id, $data, $fd);
    }

    public function pieceEnter(Server $server, int $fd, $data)
    {
        if (!isset($data->game_id) || !isset($data->player_id)) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcast($server, $data->game_id, $data, $fd);
    }

    public function pieceMove(Server $server, int $fd, $data)
    {
        if (!isset($data->game_id) || !isset($data->player_id)) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcast($server, $data->game_id, $data, $fd);
    }

    public function capturePiece(Server $server, int $fd, $data)
    {
        if (!isset($data->game_id) || !isset($data->player_id)) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcast($server, $data->game_id, $data, $fd);
    }

    public function safeZone(Server $server, int $fd, $data)
    {
        if (!isset($data->game_id) || !isset($data->player_id)) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcast($server, $data->game_id, $data, $fd);
    }

    public function extraTurn(Server $server, int $fd, $data)
    {
        if (!isset($data->game_id) || !isset($data->player_id)) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcast($server, $data->game_id, $data, $fd);
    }

    public function pieceHome(Server $server, int $fd, $data)
    {
        if (!isset($data->game_id) || !isset($data->player_id)) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcast($server, $data->game_id, $data, $fd);
    }

    public function gameWin(Server $server, int $fd, $data)
    {
        if (!isset($data->game_id) || !isset($data->player_id)) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcast($server, $data->game_id, $data, $fd);
    }

    public function skipTurn(Server $server, int $fd, $data)
    {
        if (!isset($data->game_id) || !isset($data->player_id)) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcast($server, $data->game_id, $data, $fd);
    }

    public function loseTurn(Server $server, int $fd, $data)
    {
        if (!isset($data->game_id) || !isset($data->player_id)) {
            $server->push($fd, json_encode(["status" => "Error", "message" => "Game ID & Player ID not supplied"]));
            return;
        }
        $this->broadcast($server, $data->game_id, $data, $fd);
    }

    // Method to start the socket server
    public function start()
    {
        echo "WebSocket server started";
        $this->server->start();
    }
    // Method that handles disconnections
    public function onClose(Server $server, int $fd)
    {
        foreach ($this->connections as $game_id => &$players) {
            foreach ($players as $player => $player_fd) {
                if ($player_fd === $fd) {
                    // Check if connection exists
                    if ($server->exists($fd)) {
                        $server->push($fd, json_encode([
                            'status' => 'Disconnected',
                            'message' => "Connection lost"
                        ]));
                    }
                    // Remove player from game
                    unset($players[$player]);
                    // Notify remaining players
                    $this->broadcast($server, $game_id, [
                        'status' => 'Disconnected',
                        'message' => "Player {$player} has left the game",
                    ]);
                    // If no players left, remove the game session
                    if (empty($players)) {
                        unset($this->connections[$game_id]);
                    }
                    return;
                }
            }
        }
    }
    // Method help broadcast data
    public function broadcast($server, $game, $data, ?int $exclude = null)
    {
        if (!isset($this->connections[$game])) {
            echo "Game error";
            if ($exclude !== null) {
                $server->push($exclude, json_encode(['status' => 'Error', 'message' => 'Invalid game entry']));
            }
            return;
        }
        // Determine the broadcast message based on the action

        foreach ($this->connections[$game] as $player) {
            if ($exclude !== null && $player == $exclude) {
                continue; // Skip the sender
            }
            $message = match ($data->action ?? '') {
                'roledDice'    => "{$data->player_id} rolled a dice and got {$data->dice_value}",
                'pieceEnter'   => "{$data->player_id} moved a piece onto the board",
                'pieceMove'    => "{$data->player_id} moved a piece",
                'capturePiece' => "{$data->player_id} captured an opponent's piece",
                'safeZone'     => "{$data->player_id} entered a safe zone",
                'extraTurn'    => "{$data->player_id} earned an extra turn",
                'pieceHome'    => "{$data->player_id} moved a piece to home",
                'gameWin'      => "{$data->player_id} has won the game!",
                'skipTurn'     => "{$data->player_id} skipped their turn",
                'loseTurn'     => "{$data->player_id} lost their turn",
                default        => "{$data->player_id} performed an action"
            };
            $server->push($player, json_encode(["status" => "notification", "message" => $message]));
        }
    }
}

$socket = new GameSocket();

$socket->start();
