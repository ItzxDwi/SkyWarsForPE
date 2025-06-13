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

namespace larryTheCoder\arena;


use larryTheCoder\arena\api\Arena;
use larryTheCoder\arena\api\impl\ArenaState;
use larryTheCoder\utils\Utils;
use pocketmine\world\Position;
use pocketmine\math\Vector3;
use pocketmine\Server;

/**
 * Stores everything about the arena config file into a set of variables.
 */
abstract class ArenaData extends Arena {

	public int $configVersion = 1;

	public string $gameAPICodename = "Default API";

	public bool $configChecked = false;

	// The root of the config.
	public bool $arenaEnable = false;

	public string $arenaFileName = "";

	public int $arenaMode = ArenaState::MODE_SOLO;

	// Winners section
	/** @var string[][] */
	public array $winnersCommand = [];

	// Signs section.
	public bool $enableJoinSign = false;

	public ?Vector3 $joinSignVec = null;

	public string $statusLine1 = "";

	public string $statusLine2 = "";

	public string $statusLine3 = "";

	public string $statusLine4 = "";

	public string $joinSignWorld = "";

	public int $statusLineUpdate = 2;

	// Chest section.

	public bool $refillChest = true;
	/** @var int[] */
	public array $refillAverage = [240];

	// Arena section.

	public int $arenaTime = 0;

	public int $arenaMatchTime = 0;

	public string $arenaWorld = "";

	public ?Vector3 $arenaSpecPos = null;
	/** @var Vector3[] */
	public array $spawnPedestals = [];

	public int $maximumPlayers = 0;

	public int $minimumPlayers = 0;

	public int $arenaGraceTime = 0;

	public bool $enableSpectator = false;

	public bool $arenaStartOnFull = false;
	/** @var string[] */
	public array $arenaBroadcastTM = [];

	public int $arenaMoneyReward = 0;

	public int $arenaStartingTime = 0;

	/**
	 * Parses the data for the arena
	 */
	public function parseData(): void{
		$data = $this->getArenaData();

		try{
			if(!isset($data['version']) || $data['version'] !== $this->configVersion){
				throw new \InvalidArgumentException("Unsupported config version for {$this->gameAPICodename}");
			}

			// Root of the config.
			$this->arenaEnable = boolval($data["enabled"]);
			$this->arenaFileName = $data['arena-name'];
			$this->arenaMode = $data['arena-mode'];

			// Signs config.
			$signs = $data['signs'];
			$this->enableJoinSign = boolval($signs['enable-status']);
			$this->joinSignVec = new Vector3($signs['join-sign-x'], $signs['join-sign-y'], $signs['join-sign-z']);
			$this->statusLine1 = $signs['status-line-1'];
			$this->statusLine2 = $signs['status-line-2'];
			$this->statusLine3 = $signs['status-line-3'];
			$this->statusLine4 = $signs['status-line-4'];
			$this->joinSignWorld = $signs['join-sign-world'];
			$this->statusLineUpdate = $signs['sign-update-time'];

			// Chest config
			$chest = $data['chest'];
			$this->refillChest = boolval($chest['refill']);
			$this->refillAverage = $chest['refill-average'];

			// Winner config
			$this->winnersCommand = $data['command-execute'];

			// Arena config
			$arena = $data['arena'];
			$this->arenaWorld = $arena['arena-world'];
			$this->arenaSpecPos = new Vector3($arena['spec-spawn-x'], $arena['spec-spawn-y'], $arena['spec-spawn-z']);
			$this->arenaGraceTime = intval($arena['grace-time']);
			$this->enableSpectator = boolval($arena['spectator-mode']);
			if(is_int($arena['time'])){
				$this->arenaTime = (int)$arena['time'];
			}else{
				$this->arenaTime = (int)str_replace(['true', 'day', 'night'], [-1, 6000, 18000], $arena['time']);
			}
			$this->arenaMoneyReward = intval($arena['money-reward']);
			$this->arenaBroadcastTM = explode(':', $arena['finish-msg-levels']);
			$this->arenaStartOnFull = boolval($arena['start-when-full']);
			$this->maximumPlayers = intval($arena['max-players']);
			$this->minimumPlayers = intval($arena['min-players']);
			$this->arenaStartingTime = intval($arena['starting-time']);
			$this->arenaMatchTime = $arena['match-time'] ?? 300;

			$this->spawnPedestals = []; // Reset spawn pedestals.
			foreach($arena['spawn-positions'] as $val => $pos){
				$strPos = explode(':', $pos);

				$this->spawnPedestals[] = new Vector3(intval($strPos[0]), intval($strPos[1]), intval($strPos[2]));
			}

			// Team data(s)
			$pm = $this->getPlayerManager();
			if($data['arena-mode'] === ArenaState::MODE_TEAM){
				$teamData = $data["team-settings"];
				$pm->teamMode = true;
				$pm->maximumTeams = (int)$teamData['maximum-teams'];                        // Maximum teams   in arena
				$pm->maximumMembers = (int)$teamData['players-per-team'];                   // Maximum members in a team.
				$pm->allowedTeams = (array)$teamData['team-colours'];                       // Available teams that will be chosen.
				$this->maximumPlayers = $pm->maximumMembers * $pm->maximumTeams;            // Maximum players in arena
				$this->minimumPlayers = $pm->maximumMembers * $teamData['minimum-teams'];   // Minimum players in arena
				if(count($pm->allowedTeams) < $pm->maximumTeams){
					Utils::send("§6" . ucwords($this->arenaFileName) . " §a§l-§r§c Team colours is not configured correctly.");
					$this->arenaEnable = false;
				}
			}

			// Verify spawn pedestals.
			$spawnPedestals = count($this->spawnPedestals);
			if($this->maximumPlayers > $spawnPedestals){
				Utils::send("§6" . ucwords($this->arenaFileName) . " §a§l-§r§c Spawn pedestals is not configured correctly.");
				$this->arenaEnable = false;
			}elseif($this->maximumPlayers < $spawnPedestals){
				Utils::send("§6" . ucwords($this->arenaFileName) . " §a§l-§r§e Spawn pedestals is over configured.");
			}
		}catch(\Exception $ex){
			Utils::send("§6" . ucwords($this->arenaFileName) . " §a§l-§r§c Failed to verify config files.");
			$this->arenaEnable = false;

			Server::getInstance()->getLogger()->logException($ex);
		}
		$this->configChecked = true;

		if($this->arenaEnable){
			Utils::send("§6" . ucwords($this->arenaFileName) . " §a§l-§r§a Arena loaded and enabled");
		}else{
			Utils::send("§6" . ucwords($this->arenaFileName) . " §a§l-§r§c Arena disabled");
		}
	}

	/**
	 * @return array<mixed>
	 */
	public abstract function getArenaData(): array;

	public function getSignPosition(): Position{
		Utils::loadFirst($this->joinSignWorld);

		$world = Server::getInstance()->getWorldManager()->getWorldByName($this->joinSignWorld);

		return Position::fromObject($this->joinSignVec, $world);
	}

}