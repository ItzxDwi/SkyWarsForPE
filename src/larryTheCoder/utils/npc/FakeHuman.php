<?php
/**
 * Adapted from the Wizardry License
 *
 * Copyright (c) 2015-2018 larryTheCoder and contributors
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

namespace larryTheCoder\utils\npc;

use larryTheCoder\arena\api\translation\TranslationContainer;
use larryTheCoder\SkyWarsPE;
use pocketmine\entity\Location;
use pocketmine\entity\Human;
use pocketmine\entity\Skin;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\world\World;
use pocketmine\world\particle\FloatingTextParticle;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\player\Player;
use pocketmine\Server;

/**
 * Faster implementation of Fake Entities or well known as NPCs.
 * Uses BatchPacket to send data to the player much faster.
 */
class FakeHuman extends Human {

	/** @var FloatingTextParticle|null */
	private $particleCache = null;
	/** @var string */
	private $messageCache = "";
	/** @var int */
	private $levelPedestal;

	public function __construct(Location $location, ?CompoundTag $nbt = null, int $pedestalLevel){
		$nbtNew = new BigEndianNBTStream();
		$compound = $nbtNew->read(@stream_get_contents(SkyWarsPE::getInstance()->getResource("metadata-fix.dat")))->mustGetCompoundTag();
		if(!($compound instanceof CompoundTag)){
			throw new \RuntimeException("Unable to read skin metadata from SkyWarsForPE resources folder, corrupted build?");
		}

		$this->setSkin(Human::parseSkinNBT($compound));

		parent::__construct($location, $nbt);

		$this->setCanSaveWithChunk(false);
		$this->setNoClientPredictions(false);
		$this->setScale(0.8);
		$this->setNameTagAlwaysVisible(false);

		$this->levelPedestal = $pedestalLevel;

		$this->fetchData();
	}

	public function attack(EntityDamageEvent $source): void{
		$source->cancel();
	}

	public function onUpdate(int $currentTick): bool{
		if($this->closed){
			return false;
		}

		if($currentTick % 3 === 0){
			// Look at the player, and sent the packet only
			// to the player who looked at it
			foreach($this->getWorlx()->getPlayers() as $player){
				if($player->getPosition()->distance($this->getPosition()) <= 15){
					$this->lookAtInto($player);
				}
			}
		}elseif($currentTick % 200 === 0){
			$this->fetchData();
		}

		return true;
	}

	private function fetchData(): void{
		$pedestal = SkyWarsPE::getInstance()->getPedestals();
		if($pedestal === null && !$this->isFlaggedForDespawn()){
			$this->flagForDespawn();

			return;
		}

		$object = $pedestal->getPedestalObject($this->levelPedestal);


		// Send the skin (Only use the .dat skin data)
		if(file_exists(Server::getInstance()->getDataPath() . "players/" . strtolower($object[0]) . ".dat")){
			$nbt = Server::getInstance()->getOfflinePlayerData($object[0]);
			$this->setSkin(Human::parseSkinNBT($nbt));
		}

		// The text packets
		$msg1 = str_replace(["{PLAYER}", "{VAL}", "{WINS}"], [$object[0], $this->levelPedestal, $object[1]], TranslationContainer::getTranslation(null, 'top-winner-1'));
		$msg2 = str_replace(["{PLAYER}", "{VAL}", "{WINS}"], [$object[0], $this->levelPedestal, $object[1]], TranslationContainer::getTranslation(null, 'top-winner-2'));
		$msg3 = str_replace(["{PLAYER}", "{VAL}", "{WINS}"], [$object[0], $this->levelPedestal, $object[1]], TranslationContainer::getTranslation(null, 'top-winner-3'));
		$array = [$msg1, $msg2, $msg3];
		$this->sendText($array);
	}

	/**
	 * Changes the entity's yaw and pitch to make it look at the specified Vector3 position. For mobs, this will cause
	 * their heads to turn.
	 *
	 * @param Player $target
	 */
	public function lookAtInto(Player $target): void{
	  $targetPos = $target->getPosition();
	  $myPos = $this->getPosition();

	  $horizontal = sqrt(($targetPos->x - $myPos->x) ** 2 + ($targetPos->z - $myPos->z) ** 2);
	  $vertical = ($targetPos->y - $myPos->y) + 0.55;

	  $this->location->pitch = -atan2($vertical, $horizontal) / M_PI * 180; //negative is up, positive is down
	  
	  $xDist = $targetPos->x - $myPos->x;
	  $zDist = $targetPos->z - $myPos->z;
	  $this->location->yaw = atan2($zDist, $xDist) / M_PI * 180 - 90;
	  if($this->location->yaw < 0){
	    $this->location->yaw += 360.0;
	  }
	  $this->updateMovementInto($target);
	}

	private function updateMovementInto(Player $player): void{
		$player->sendDataPacket(MoveActorAbsolutePacket::create(
		   $entity->getId(),
		   $this->getOffsetPosition($this->getPosition()), 
		   $this->location->pitch, 
		   $this->location->yaw, 
		   $this->location->yaw, 
		   0
		));
	}

	protected function onDispose(): void{
		$this->despawnText($this->getViewers());

		parent::close();
	}

	public function spawnTo(Player $player): void{
		parent::spawnTo($player);

		$this->sendText([], true, $player);
	}

	public function despawnFrom(Player $player, bool $send = true): void{
		parent::despawnFrom($player, $send);

		$this->despawnText([$player]);
	}

	/**
	 * @param Player[] $players
	 */
	public function despawnText(array $players): void{
	  if($this->particleCache === null) return;

	  $this->particleCache->setInvisible(true);

	  foreach($players as $player){
	    $player->getWorld()->addParticle($this->getOffsetPosition($this->getPosition()), $this->particleCache, [$player]);
	  }

	  $this->particleCache->setInvisible(false);
	}

	/**
	 * @param string[] $messages
	 * @param bool $resend
	 * @param Player|null $player
	 */
	public function sendText(array $messages, bool $resend = false, ?Player $player = null): void{
	  if($resend && $this->particleCache !== null){
	    $particle = $this->particleCache;
	  }else{
	    if($this->particleCache === null){
	      $this->particleCache = new FloatingTextParticle(implode("\n", $msg = implode("\n", $messages)));
	      $particle = $this->particleCache;
	      $this->messageCache = $msg;
	    }else{
	      $msg = implode("\n", $messages);
	      if($this->messageCache === $msg){
	        return;
	      }

	      $this->messageCache = $msg;
	      $this->particleCache->setText($msg);
	      $particle = $this->particleCache;
	    }
	  }

	  $targets = $player !== null ? [$player] : $this->getViewers();
	  foreach($targets as $target){
	    $target->getWorld()->addParticle($this->getOffsetPosition($this->getPosition()), $particle, [$target]);
	  }
	}
}