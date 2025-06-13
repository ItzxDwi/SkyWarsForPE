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

declare(strict_types=1);

namespace larryTheCoder\utils;

use InvalidArgumentException;
use larryTheCoder\SkyWarsPE;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\item\LegacyStringToItemParser;
use pocketmine\item\ItemTypeIds;
use pocketmine\plugin\PluginException;
use RuntimeException;

class LootGenerator {

    /** @var null|LootGenerator */
    public static $generator = null;

    /** @var mixed[] */
    private $lootFile;

    public static function init(): void {
        $contents = file_get_contents(SkyWarsPE::getInstance()->getDataFolder() . "looting-tables.json");

        self::$generator = new LootGenerator(json_decode($contents, true));
    }

    /**
     * Attempt to generate item loot tables from a given data. This function can use either internal
     * looting tables or external looting tables (Special thanks to @XenialDan).
     *
     * @param bool $useNatural
     * @return Item[]
     */
    public static function getLoot(bool $useNatural = true): array {
        $selectedRows = [];
        if(!$useNatural){
            $contents = self::$generator->getRandomLoot();
        }else{
            $contents = [];
            $raw = Utils::getChestContents();
            foreach(array_shift($raw) as $key => $val){
                $item = StringToItemParser::getInstance()->parse($val[0]) ?? LegacyStringToItemParser::getInstance()->parse($val[0]);
                $item->setCount($val[1]);
                
                if($item->getTypeId() === ItemTypeIds::IRON_SWORD ||
                    $item->getTypeId() === ItemTypeIds::DIAMOND_SWORD){
                    $item->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), mt_rand(1, 2)));
                }elseif($item->getTypeId() === ItemTypeIds::LEATHER_CHESTPLATE ||
                    $item->getTypeId() === ItemTypeIds::CHAINMAIL_CHESTPLATE ||
                    $item->getTypeId() === ItemTypeIds::IRON_CHESTPLATE ||
                    $item->getTypeId() === ItemTypeIds::GOLDEN_CHESTPLATE ||
                    $item->getTypeId() === ItemTypeIds::DIAMOND_CHESTPLATE ||
                    $item->getTypeId() === ItemTypeIds::DIAMOND_LEGGINGS ||
                    $item->getTypeId() === ItemTypeIds::DIAMOND_HELMET){
                    $item->addEnchantment(new EnchantmentInstance(VanillaEnchantments::PROTECTION(), mt_rand(1, 2)));
                }elseif($item->getTypeId() === ItemTypeIds::BOW){
                    $item->addEnchantment(new EnchantmentInstance(VanillaEnchantments::POWER(), mt_rand(1, 2)));
                }

                $contents[] = $item;
            }
        }

        foreach($contents as $item){
            // Keep on searching for available rows.
            $selectedRow = mt_rand(0, 27);
            while(isset($selectedRows[$selectedRow])){
                $selectedRow = mt_rand(0, 27);
            }

            $selectedRows[$selectedRow] = $item;
        }

        return $selectedRows;
    }

    /**
     * LootGenerator constructor.
     *
     * @param mixed[] $lootTable
     * @throws InvalidArgumentException
     */
    private function __construct(array $lootTable = []){
        $this->lootFile = $lootTable;
    }

    /**
     * @return Item[]
     * @throws InvalidArgumentException
     * @throws PluginException
     * @throws RuntimeException
     */
    private function getRandomLoot(): array {
        $items = [];
        if(!isset($this->lootFile["pools"])){
            return $items;
        }

        foreach($this->lootFile["pools"] as $rolls){
            $array = [];
            if(is_array($rolls["rolls"])){
                $maxRolls = rand($rolls["rolls"]["min"], $rolls["rolls"]["max"]);
            }else{
                $maxRolls = (int)$rolls["rolls"];
            }

            while($maxRolls > 0){
                $maxRolls--;
                foreach($rolls["entries"] as $index => $entries){
                    $array[] = $entries["weight"] ?? 1;
                }
            }

            if(count($array) > 1){
                $val = $rolls["entries"][self::getRandomWeightedElement($array)] ?? [];
            }else{
                $val = $rolls["entries"][0] ?? [];
            }

            if(($val["type"] ?? "") === "item"){
                print $val["name"] . PHP_EOL;

                $item = StringToItemParser::getInstance()->parse($val["name"]) ?? LegacyStringToItemParser::getInstance()->parse($val["name"]);
                if($item === null){
                    continue;
                }

                if(isset($val["functions"])){
                    foreach($val["functions"] as $function){
                        switch($functionName = str_replace("minecraft:", "", $function["function"])){
                            case "set_damage":
                                if($item instanceof Durable){
                                    $damage = mt_rand(
                                        (int)($function["damage"]["min"] * $item->getMaxDurability()), 
                                        (int)($function["damage"]["max"] * $item->getMaxDurability())
                                    );
                                    $item->setDamage($damage);
                                }
                                break;
                            case "set_count":
                                $item->setCount(mt_rand($function["count"]["min"], $function["count"]["max"]));
                                break;
                            case "looting_enchant":
                                $item->setCount($item->getCount() + mt_rand($function["count"]["min"], $function["count"]["max"]));
                                break;
                            default:
                                assert("Unknown looting table function $functionName, skipping");
                        }
                    }
                }
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param int[] $weightedValues
     * @return int
     */
    public static function getRandomWeightedElement(array $weightedValues): int {
        if(empty($weightedValues)){
            throw new PluginException("The weighted values are empty");
        }
        $rand = mt_rand(1, (int)array_sum($weightedValues));

        foreach($weightedValues as $key => $value){
            $rand -= $value;
            if($rand <= 0){
                return $key;
            }
        }

        return -1;
    }
}