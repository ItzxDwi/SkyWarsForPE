<?php
/**
 * Adapted from the Wizardry License
 *
 * Copyright (c) 2015-2019 larryTheCoder and contributors
 *
 * Permission is hereby granted to any persons and/or organizations
 * using this software to copy, modify, merge, publish, and distribute it.
 * Said persons and/or organizations are not allowed to use the software or
 * any derivatives of the work for commercial use or any other means to generate
 * income, nor are they allowed to claim this software as their own.
 *
 * The persons and/or organizations are also disallowed from sub-licensing
 * and/or trademarking this software without explicit permission from larryTheCoder.
 *
 * Any persons and/or organizations using this software must disclose their
 * source code and have it publicly available, include this license,
 * provide sufficient credit to the original authors of the project (IE: larryTheCoder),
 * as well as provide a link to the original project.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
 * INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,FITNESS FOR A PARTICULAR
 * PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
 * LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
 * TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE
 * USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

declare(strict_types = 1);

namespace larryTheCoder\arena\api;

use larryTheCoder\arena\api\impl\ArenaListener;
use larryTheCoder\arena\api\impl\ArenaState;
use larryTheCoder\arena\api\impl\Scoreboard;
use larryTheCoder\arena\api\impl\ShutdownSequence;
use larryTheCoder\arena\api\task\ArenaTickTask;
use larryTheCoder\arena\api\task\AsyncDirectoryDelete;
use larryTheCoder\arena\api\task\CompressionAsyncTask;
use larryTheCoder\arena\api\utils\QueueManager;
use larryTheCoder\database\SkyWarsDatabase;
use larryTheCoder\worker\LevelAsyncPool;
use pmmp\thread\ThreadSafeArray;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\world\Position;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\Server;

/**
 * Arena interface, here you will be able to control the arena behaviour and attach them into pre-defined
 * arena data, explicitly, the given function are the skeleton for every operation in a gamemode.
 *
 * <p>This is a server-standard performance based framework, you will see that this as a beautiful piece of work made by me
 * as a favour of my knowledge gained from NetherGamesMC. This framework is written to use least server ticks to aid
 * the server's performance, thus reducing the server stress.
 *
 * @author larryTheCoder
 */
abstract class Arena implements ShutdownSequence {

	// "With artificial intelligence, we are summoning the demon" -- Elon Musk

	// Arena flag constants, you can apply your own constants
	// for arena flags in setFlags() and hasFlags()
	public const WORLD_ATTEMPT_LOAD = 0x1;
	public const ARENA_OFFLINE_MODE = 0x2;
	public const ARENA_IN_SETUP_MODE = 0x3;
	public const ARENA_DISABLED = 0x4;
	public const ARENA_CRASHED = 0x5;

	protected CageManager $cageManager;
	protected ?Scoreboard $scoreboard = null;
	protected ?QueueManager $queueManager = null;
	protected ?string $lobbyName = null;

	private int $arenaStatus = ArenaState::STATE_WAITING;
	private PlayerManager $playerData;
	private ?World $lobbyWorld = null;
	private ?World $world = null;
	private Plugin $plugin;
	private int $deleteTimeout = 0;
	private int $gameFlags = 0x0;

	/** @var ShutdownSequence[] */
	protected array $shutdownSequence = [];

	public function getWorld(): ?World{
	  return $this->lobbyWorld;
	}

	public function __construct(Plugin $plugin){
		$this->plugin = $plugin;

		$task = $this->getArenaTask();
		$signListener = $this->getSignManager();

		$plugin->getScheduler()->scheduleRepeatingTask($task, 20);
		$plugin->getServer()->getPluginManager()->registerEvents($signListener, $plugin);

		$this->shutdownSequence[] = $task;
		$this->shutdownSequence[] = $signListener;

		$this->setFlags(Arena::ARENA_OFFLINE_MODE, true);
	}

	/**
	 * The API codename.
	 *
	 * @return string
	 */
	public abstract function getCodeName(): string;

	/**
	 * Start the arena, begin the match in the
	 * arena provided.
	 */
	public abstract function startArena(): void;

	/**
	 * Stop the arena, rollback to defaults and
	 * reset the arena if possible.
	 */
	public abstract function stopArena(): void;

	/**
	 * Reset the player objects that were set in game.
	 *
	 * @param Player $player
	 * @param bool $isSpectator
	 */
	public abstract function unsetPlayer(Player $player, bool $isSpectator = false): void;

	public abstract function getMinPlayer(): int;

	public abstract function getMaxPlayer(): int;

	public abstract function getMapName(): string;

	public abstract function getWorldName(): string;

	public abstract function getArenaTask(): ArenaTickTask;

	public abstract function getEventListener(): ArenaListener;

	public abstract function getSignManager(): SignManager;

	/**
	 * Called when a player leaves the arena.
	 *
	 * @param Player $player
	 * @param bool $onQuit
	 */
	public function leaveArena(Player $player, bool $onQuit = false): void{
		$this->unsetPlayer($player, $this->getPlayerManager()->isSpectator($player));

		$this->getPlayerManager()->removePlayer($player);
		$this->getScoreboard()->removePlayer($player);
		$this->getCageManager()->removeCage($player);
	}

	/**
	 * @param Player $player
	 */
	public function onKitSelection(Player $player): void{
		// NOOP
	}

	/**
	 * @param Player $player
	 */
	public function onSpectatorSelection(Player $player): void{
		// NOOP
	}
	
	/**
	 * @param Player $player
	*/
	public function onRejoinSelection(Player $player): void{
		// NOOP
	}

	/**
	 * Return the object where the players are being queued into
	 * the arena, this storage unit stores player and spectators.
	 *
	 * @return PlayerManager
	 */
	public function getPlayerManager(): PlayerManager{
		return $this->playerData === null ? $this->playerData = new PlayerManager($this) : $this->playerData;
	}

	public function getCageManager(): CageManager{
		return $this->cageManager;
	}

	public function getQueueManager(): QueueManager{
		return $this->queueManager === null ? $this->queueManager = new QueueManager() : $this->queueManager;
	}

	/**
	 * Attempt to process queue of a player.
	 */
	public function processQueue(): void{
		// Wait until the async task has successfully deletes it file, then we can add more queue.
		if($this->deleteTimeout > 30){
			return;
		}

		$pm = $this->getPlayerManager();
		$qm = $this->getQueueManager();

		// Attempt to load the world while the world is offline.
		if($this->hasFlags(self::ARENA_OFFLINE_MODE)){
			// Only load world when the queue is not empty-
			if(!$this->hasFlags(self::WORLD_ATTEMPT_LOAD) && $qm->hasQueue()){
				$this->loadWorld();
			}

			return;
		}
		$queue = $qm->getQueue();

		// Process queue for players attempting to join as a "contestant" when the arena is not running and
		// the player alive size is below max player.
		if($this->getStatus() === ArenaState::STATE_WAITING || $this->getStatus() === ArenaState::STATE_STARTING){
			foreach($queue as $id => $player){
				if(count($pm->getAlivePlayers()) < $this->getMaxPlayer()){
					$this->joinToArena($player);

					unset($queue[$id]);
				}else{
					break;
				}
			}
		}

		// Otherwise use up the leftover queue for spectators
		if(!empty($queue)){
			foreach($queue as $id => $player){
				$this->setSpectator($player);

				unset($queue[$id]);
			}
		}

		// Attempt to reset the world when there is no players in the arena,
		// this is to avoid unnecessary ticks on this world.
		if(!$this->hasFlags(self::ARENA_OFFLINE_MODE) && $pm->getPlayersCount() === 0){
			if($this->deleteTimeout >= 30 && !$this->world->isClosed()){
				$this->resetWorld();
			}

			$this->deleteTimeout++;
		}else{
			$this->deleteTimeout = 0;
		}
	}

	public function hasFlags(int $flagId): bool{
		return (($this->gameFlags >> $flagId) & 1) === 1;
	}

	final public function resetWorld(): void{
		// The sequence of deleting the arena.
		$task = new AsyncDirectoryDelete(ThreadSafeArray::fromArray([$this->lobbyWorld, $this->world]), function(){
			$this->setFlags(self::ARENA_OFFLINE_MODE, true);

			$this->world = null;
			$this->lobbyWorld = null;
			$this->deleteTimeout = 0;
		});
		LevelAsyncPool::getAsyncPool()->submitTask($task);

		$this->deleteTimeout = 30;
	}

	final public function loadWorld(bool $onStart = true): void{
		// Lobby/Arena pre loading.
		if($onStart){
			if($this->lobbyName === null){
				$fromPath = $this->plugin->getDataFolder() . 'arenas/worlds/' . $this->getWorldName() . ".zip";
				$toPath = $this->plugin->getServer()->getDataPath() . "worlds/" . $this->getWorldName();
			}else{
				$fromPath = $this->plugin->getDataFolder() . 'arenas/worlds/' . $this->lobbyName . ".zip";
				$toPath = $this->plugin->getServer()->getDataPath() . "worlds/" . $this->lobbyName;
			}
		}else{
			if($this->lobbyName === null) return; // Do nothing because the world has already been loaded.

			$fromPath = $this->plugin->getDataFolder() . 'arenas/worlds/' . $this->getWorldName() . ".zip";
			$toPath = $this->plugin->getServer()->getDataPath() . "worlds/" . $this->getWorldName();
		}

		if(is_file($this->plugin->getDataFolder() . 'arenas/worlds/')) return;
		if(!file_exists($toPath)) @mkdir($toPath, 0755);

		$task = new CompressionAsyncTask(ThreadSafeArray::fromArray([$fromPath, $toPath, false]), function() use ($onStart){
			if($this->lobbyName !== null && $onStart){
				Server::getInstance()->getWorldManager()->loadWorld($this->lobbyName);

				$world = $this->lobbyWorld = Server::getInstance()->getWorldManager()->getWorldByName($this->lobbyName);
				$this->lobbyWorld->setAutoSave(false);

				$isLobby = true;
			}else{
				Server::getInstance()->getWorldManager()->loadWorld($this->getWorldName());

				$world = $this->world = Server::getInstance()->getWorldManager()->getWorldByName($this->getWorldName());
				$this->world->setAutoSave(false);

				$isLobby = false;
			}

			$world->setTime(World::TIME_DAY);
			$world->stopTime();

			$this->setFlags(self::ARENA_OFFLINE_MODE, false);
			$this->setFlags(self::WORLD_ATTEMPT_LOAD, false);

			$this->initArena($world, $isLobby);

			// Process queue immediately...
			$this->processQueue();
		});

		LevelAsyncPool::getAsyncPool()->submitTask($task);

		$this->setFlags(self::WORLD_ATTEMPT_LOAD, true);
	}

	public function setFlags(int $flagId, bool $flags): void{
		if($flags){
			$this->gameFlags |= 1 << $flagId;
		}else{
			$this->gameFlags &= ~(1 << $flagId);
		}
	}

	/**
	 * Perform appropriate execution after the arena world has
	 * successfully being copied and loaded.
	 *
	 * @param World $world
	 * @param bool $isLobby
	 */
	public function initArena(World $world, bool $isLobby): void{
		// NOOP
	}

	public function getStatus(): int{
		return $this->arenaStatus;
	}

	public function getMode(): int{
		return ArenaState::MODE_SOLO;
	}

	/**
	 * Called when a player joins into the arena, this will only be called
	 * by a function and this function will be called when a player joined as a contestant
	 *
	 * @param Player $player
	 */
	public function joinToArena(Player $player): void{
		$this->getPlayerManager()->addPlayer($player);
		$this->getScoreboard()->addPlayer($player);

		$cage = $this->getCageManager()->setCage($player);
		if($this->lobbyName !== null){
			$player->teleport($this->world->getSafeSpawn());
		}else{
			$player->teleport(Position::fromObject($cage, $this->world));
		}

		$player->getInventory()->clearAll();
		$player->getArmorInventory()->clearAll();

		$player->getInventory()->setItem(8, self::getLeaveItem());
	}

	/**
	 * @param Player $player
	 */
	public function setSpectator(Player $player): void{
		$this->getPlayerManager()->setSpectator($player);

		$player->getInventory()->setItem(0, self::getSpectatorItem());
		$player->getInventory()->setItem(4, self::getRejoinItem());
		$player->getInventory()->setItem(8, self::getLeaveItem());

		$player->setGamemode(GameMode::SPECTATOR());
		self::sendAdventureSettings($player);
	}

	final public function resetArena(): void{
		$pm = $this->getPlayerManager()->resetPlayers();
		foreach($pm as $type => $player){
			if($type === "player"){
				foreach($player as $pl){
					$this->unsetPlayer($pl);

					$pl->teleport(SkyWarsDatabase::getLobby());
				}
			}else{
				foreach($player as $pl){
					$this->unsetPlayer($pl, true);

					$pl->teleport(SkyWarsDatabase::getLobby());
				}
			}
		}

		$this->getScoreboard()->resetScoreboard();
		$this->getCageManager()->resetAll();
		$this->resetWorld();

		$this->setStatus(ArenaState::STATE_WAITING);
	}

	public function setStatus(int $status): void{
		$this->arenaStatus = $status;
	}

	/**
	 * Attempt to check for alive players in the arena.
	 */
	final public function checkAlive(): void{
		if($this->getStatus() !== ArenaState::STATE_ARENA_RUNNING) return;

		$pm = $this->getPlayerManager();
		if($pm->isSolo()){
			$playerCount = count($pm->getAlivePlayers());
		}else{
			$playerCount = count(array_unique($pm->getAliveTeam(), SORT_NUMERIC));
		}

		if($playerCount <= 1){
			$this->setStatus(ArenaState::STATE_ARENA_CELEBRATING);
		}
	}

	public function getPlugin(): Plugin{
		return $this->plugin;
	}

	public function shutdown(): void{
		foreach($this->shutdownSequence as $shutdown){
			$shutdown->shutdown();
		}

		$this->shutdownSequence = [];
	}

	public static function getLeaveItem(): Item{
	  return VanillaBlocks::BED()->setColor(DyeColor::RED())->asItem()->setCustomName("§r§cLeave the game.");
	}

	public static function getSpectatorItem(): Item{
	  return VanillaItems::PAPER()->setCustomName("§r§eTeleport to player");
	}
	
	public static function getRejoinItem(): Item{
	  return VanillaItems::BLAZE_POWDER()->setCustomName("§r§bPlay Again.");
	}

	public static function getKitSelector(): Item{
		return VanillaItems::BOOK()->setCustomName("§r§aKits selection");
	}

	public function getScoreboard(): Scoreboard{
		return $this->scoreboard;
	}
}