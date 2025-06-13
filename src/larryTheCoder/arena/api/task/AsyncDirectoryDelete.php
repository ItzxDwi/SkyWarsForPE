<?php
/*
 * Adapted from the Wizardry License
 *
 * Copyright (c) 2015-2020 larryTheCoder and contributors
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

namespace larryTheCoder\arena\api\task;

use pmmp\thread\ThreadSafeArray;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\world\World;

class AsyncDirectoryDelete extends AsyncTask {

	/** @var string */
	private string $worldTable;

	/**
	 * AsyncDirectoryDelete constructor.
	 *
	 * @param ThreadSafeArray $worldToDelete
	 * @param callable|null $onComplete
	 */
	public function __construct(ThreadSafeArray $worldToDelete, ?callable $onComplete = null){
	  $server = Server::getInstance();
	  $worldManager = $server->getWorldManager();
		$worlds = [];
		foreach($worldToDelete as $worldName){
		  $worlds[] = $server->getDataPath() . "worlds/" . $worldName;

		  $world = $worldManager->getWorldByName($worldName);
		  if ($world === null || $world->isClosed()) {
		    continue;
		  }

		   $worldManager->unloadWorld($world, true);
		}
		$this->worldTable = igbinary_serialize($worlds);

		$this->storeLocal("onCompletion", $onCompletion);
	}

	public function onRun(): void{
		$worldToDelete = igbinary_unserialize($this->worldTable);

		foreach($worldToDelete as $world){
			self::deleteDirectory($world);
		}
	}

	public static function deleteDirectory(string $dir): bool{
		if(!file_exists($dir)){
			return true;
		}

		if(!is_dir($dir)){
			return unlink($dir);
		}

		foreach(scandir($dir) as $item){
			if($item == '.' || $item == '..'){
				continue;
			}

			if(!self::deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)){
				return false;
			}

		}

		return rmdir($dir);
	}

	public function onCompletion(): void{
		$onCompletion = $this->fetchLocal("onCompletion");
		if($onCompletion === null) return;

		$onCompletion();
	}
}