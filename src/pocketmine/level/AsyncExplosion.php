<?php

/*
 *  _   _    ____     ___
 * | \ | |  / ___|  / ___|
 * |  \| | | |  _  | |
 * | |\  | | |_| | | |___
 * |_| \_|  \____|  \____|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author XinYueNeko
 * @link https://github.com/NewmoomCat
 */

namespace pocketmine\level;

use pocketmine\block\Block;
use pocketmine\entity\Entity;
use pocketmine\event\block\BlockUpdateEvent;
use pocketmine\event\entity\EntityDamageByBlockEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\item\Item;
use pocketmine\level\particle\HugeExplodeSeedParticle;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Math;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\ExplodePacket;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\utils\Random;

class AsyncExplosion extends AsyncTask{

	private $sourceX;
	private $sourceY;
	private $sourceZ;
	private $size;
	private $levelName;
	private $whatType;
	private $whatId;
	private $dropItem;
	private $blockBreaking;
	private $blocksData;

	const RAYS = 16;
	const STEP_LEN = 0.3;

	public function __construct(Position $center, $size, $what = null, bool $dropItem = true, bool $blockBreaking = false){
		$this->sourceX = $center->x;
		$this->sourceY = $center->y;
		$this->sourceZ = $center->z;
		$this->size = max($size, 0);
		$this->levelName = $center->getLevel()->getName();
		$this->dropItem = $dropItem;
		$this->blockBreaking = $blockBreaking;

		if($what instanceof Entity){
			$this->whatType = 'entity';
			$this->whatId = $what->getId();
		}elseif($what instanceof Block){
			$this->whatType = 'block';
			$this->whatId = [$what->x, $what->y, $what->z];
		}else{
			$this->whatType = 'null';
			$this->whatId = null;
		}

		$this->blocksData = $blockBreaking ? $this->loadBlocksData($center->getLevel(), $center, $this->size) : [];
	}

	private function loadBlocksData(Level $level, Position $center, $size) : array {
		$radius = (int) ceil($size * 1.5);
		$minX = (int) floor($center->x - $radius);
		$maxX = (int) ceil($center->x + $radius);
		$minY = max(0, (int) floor($center->y - $radius));
		$maxY = min(Level::Y_MAX - 1, (int) ceil($center->y + $radius));
		$minZ = (int) floor($center->z - $radius);
		$maxZ = (int) ceil($center->z + $radius);

		$radiusSq = $radius * $radius;
		$hardnessArray = Block::$hardness;
		$data = [];

		$minChunkX = $minX >> 4;
		$maxChunkX = $maxX >> 4;
		$minChunkZ = $minZ >> 4;
		$maxChunkZ = $maxZ >> 4;

		for($chunkX = $minChunkX; $chunkX <= $maxChunkX; $chunkX++){
			for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; $chunkZ++){
				$chunk = $level->getChunk($chunkX, $chunkZ);
				if($chunk === null) continue;

				$startX = max($minX, $chunkX << 4);
				$endX = min($maxX, ($chunkX << 4) + 15);
				$startZ = max($minZ, $chunkZ << 4);
				$endZ = min($maxZ, ($chunkZ << 4) + 15);

				for($x = $startX; $x <= $endX; $x++){
					for($z = $startZ; $z <= $endZ; $z++){
						for($y = $minY; $y <= $maxY; $y++){
							$dx = $x + 0.5 - $center->x;
							$dy = $y + 0.5 - $center->y;
							$dz = $z + 0.5 - $center->z;
							if($dx * $dx + $dy * $dy + $dz * $dz > $radiusSq) continue;

							$id = $chunk->getBlockId($x & 0x0F, $y, $z & 0x0F);
							if($id !== 0){
								$resistance = isset($hardnessArray[$id]) ? $hardnessArray[$id] * 5 : 0;
								if($resistance >= 0){
									$key = Level::blockHash($x, $y, $z);
									$data[$key] = [$id, $resistance];
								}
							}
						}
					}
				}
			}
		}

		return $data;
	}

	public function onRun(){
		if(!$this->blockBreaking){
			$this->setResult([]);
			return;
		}

		if($this->size < 0.1){
			$this->setResult([]);
			return;
		}

		$blocksData = $this->blocksData;
		$pointerX = $this->sourceX;
		$pointerY = $this->sourceY;
		$pointerZ = $this->sourceZ;
		$yMax = Level::Y_MAX;
		$mRays = self::RAYS - 1;
		$stepLen = self::STEP_LEN;
		$affected = [];

		for($i = 0; $i < self::RAYS; ++$i){
			for($j = 0; $j < self::RAYS; ++$j){
				for($k = 0; $k < self::RAYS; ++$k){
					if($i === 0 or $i === $mRays or $j === 0 or $j === $mRays or $k === 0 or $k === $mRays){
						$dx = ($i / $mRays * 2 - 1);
						$dy = ($j / $mRays * 2 - 1);
						$dz = ($k / $mRays * 2 - 1);
						$len = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
						if($len == 0) continue;

						$dx = ($dx / $len) * $stepLen;
						$dy = ($dy / $len) * $stepLen;
						$dz = ($dz / $len) * $stepLen;

						$pointerX = $this->sourceX;
						$pointerY = $this->sourceY;
						$pointerZ = $this->sourceZ;

						$blastForce = $this->size * (mt_rand(700, 1300) / 1000);
						$maxTravel = $this->size * 1.5;
						$traveled = 0.0;
						while($blastForce > 0 && $traveled < $maxTravel){
							$x = (int) floor($pointerX);
							$y = (int) floor($pointerY);
							$z = (int) floor($pointerZ);

							if($y < 0 or $y >= $yMax) break;

							$key = Level::blockHash($x, $y, $z);
							if(isset($blocksData[$key])){
								$blockData = $blocksData[$key];
								$blastForce -= ($blockData[1] / 5 + 0.3) * $stepLen;
								if($blastForce > 0){
									if(!isset($affected[$key])) $affected[$key] = [$x, $y, $z];
								}
							}

							$pointerX += $dx;
							$pointerY += $dy;
							$pointerZ += $dz;
							$traveled += $stepLen;
						}
					}
				}
			}
		}

		$this->setResult(array_values($affected));
	}

	public function onCompletion(Server $server){
		$affectedCoords = $this->getResult();
		if(!is_array($affectedCoords)){
			return;
		}

		$level = $server->getLevelByName($this->levelName);
		if(!$level instanceof Level){
			return;
		}

		$what = null;
		if($this->whatType === 'entity'){
			$what = $level->getEntity($this->whatId);
		}elseif($this->whatType === 'block'){
			list($bx, $by, $bz) = $this->whatId;
			$what = $level->getBlock(new Vector3($bx, $by, $bz));
		}

		$source = new Position($this->sourceX, $this->sourceY, $this->sourceZ, $level);
		$explosionSize = $this->size * 2;
		$minX = Math::floorFloat($this->sourceX - $explosionSize - 1);
		$maxX = Math::ceilFloat($this->sourceX + $explosionSize + 1);
		$minY = Math::floorFloat($this->sourceY - $explosionSize - 1);
		$maxY = Math::ceilFloat($this->sourceY + $explosionSize + 1);
		$minZ = Math::floorFloat($this->sourceZ - $explosionSize - 1);
		$maxZ = Math::ceilFloat($this->sourceZ + $explosionSize + 1);
		$explosionBB = new AxisAlignedBB($minX, $minY, $minZ, $maxX, $maxY, $maxZ);

		$list = $level->getNearbyEntities($explosionBB, $what instanceof Entity ? $what : null);
		foreach($list as $entity){
			$distance = $entity->distance($source) / $explosionSize;
			if($distance <= 1){
				$motion = $entity->subtract($source)->normalize();
				$impact = (1 - $distance) * 1;
				$damage = (int) ((($impact * $impact + $impact) / 2) * 8 * $explosionSize + 1);

				if($what instanceof Entity){
					$ev = new EntityDamageByEntityEvent($what, $entity, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, $damage);
				}elseif($what instanceof Block){
					$ev = new EntityDamageByBlockEvent($what, $entity, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, $damage);
				}else{
					$ev = new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, $damage);
				}

				if($entity->attack($ev->getFinalDamage(), $ev) === true){
					$ev->useArmors();
				}
				$entity->setMotion($motion->multiply($impact));
			}
		}

		$air = Item::get(Item::AIR);
		$send = [];
		$updateBlocks = [];
		$sourceFloor = $source->floor();

		if($this->blockBreaking){
			$yield = (1 / $this->size) * 100;
			$affectedBlocks = [];
			foreach($affectedCoords as $coord){
				$block = $level->getBlock(new Vector3($coord[0], $coord[1], $coord[2]));
				$affectedBlocks[Level::blockHash($coord[0], $coord[1], $coord[2])] = $block;
			}

			if($what instanceof Entity){
				$ev = new EntityExplodeEvent($what, $source, $affectedBlocks, $yield);
				$server->getPluginManager()->callEvent($ev);
				if($ev->isCancelled()){
					return;
				}
				$yield = $ev->getYield();
				$affectedBlocks = $ev->getBlockList();
			}

			foreach($affectedBlocks as $block){
				if($block->getId() === Block::TNT){
					$mot = (new Random())->nextSignedFloat() * M_PI * 2;
					$tnt = Entity::createEntity("PrimedTNT", $level, new CompoundTag("", [
						"Pos" => new ListTag("Pos", [
							new DoubleTag("", $block->x + 0.5),
							new DoubleTag("", $block->y),
							new DoubleTag("", $block->z + 0.5)
						]),
						"Motion" => new ListTag("Motion", [
							new DoubleTag("", -sin($mot) * 0.02),
							new DoubleTag("", 0.2),
							new DoubleTag("", -cos($mot) * 0.02)
						]),
						"Rotation" => new ListTag("Rotation", [
							new FloatTag("", 0),
							new FloatTag("", 0)
						]),
						"Fuse" => new ByteTag("Fuse", mt_rand(10, 30))
					]));
					$tnt->spawnToAll();
				}elseif($this->dropItem and mt_rand(0, 100) < $yield){
					foreach($block->getDrops($air) as $drop){
						$level->dropItem($block->add(0.5, 0.5, 0.5), Item::get(...$drop));
					}
				}

				$level->setBlockIdAt($block->x, $block->y, $block->z, 0);

				$pos = new Vector3($block->x, $block->y, $block->z);
				for($side = 0; $side < 5; $side++){
					$sideBlock = $pos->getSide($side);
					$index = Level::blockHash($sideBlock->x, $sideBlock->y, $sideBlock->z);
					if(!isset($affectedBlocks[$index]) and !isset($updateBlocks[$index])){
						$ev = new BlockUpdateEvent($level->getBlock($sideBlock));
						$server->getPluginManager()->callEvent($ev);
						if(!$ev->isCancelled()){
							$ev->getBlock()->onUpdate(Level::BLOCK_UPDATE_NORMAL);
						}
						$updateBlocks[$index] = true;
					}
				}
				$send[] = new Vector3($block->x - $sourceFloor->x, $block->y - $sourceFloor->y, $block->z - $sourceFloor->z);
			}
		}

		$pk = new ExplodePacket();
		$pk->x = $this->sourceX;
		$pk->y = $this->sourceY;
		$pk->z = $this->sourceZ;
		$pk->radius = $this->size;
		$pk->records = $send;
		$level->addChunkPacket($sourceFloor->x >> 4, $sourceFloor->z >> 4, $pk);
		$level->addParticle(new HugeExplodeSeedParticle($sourceFloor));
	}
}