<?php

namespace pocketmine\entity;

use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\item\Item;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\level\Level;
use pocketmine\level\particle\BubbleParticle;
use pocketmine\level\particle\WaterParticle;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\AddEntityPacket;
use pocketmine\network\mcpe\protocol\EntityEventPacket;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\utils\Random;

class FishingHook extends Projectile {
    const NETWORK_ID = 77;

    /** @var float */
    public $width = 0.2;
    public $length = 0.2;
    public $height = 0.2;

    /** @var float */
    protected $gravity = 0.07;
    protected $drag = 0.05;

    public $waitChance = 120;
    public $waitTimer = 240;
    public $attracted = false;
    public $attractTimer = 0;
    public $caught = false;
    public $caughtTimer = 0;
    public $canCollide = true;
    public $caughtEntity = null;
    private $target = 0;

    public $fish = null;

    public $rod = null;

    /**
     * FishingHook constructor.
     *
     * @param Level       $level
     * @param CompoundTag $nbt
     * @param Entity|null $shootingEntity
     */
    public function __construct(Level $level, CompoundTag $nbt, Entity $shootingEntity = null) {
        parent::__construct($level, $nbt, $shootingEntity);
    }

    protected function initEntity(){
        parent::initEntity();

        if ($this->age > 0) {
            $this->close();
            return;
        }

        $this->setDataProperty(self::DATA_OWNER_EID, self::DATA_TYPE_LONG, $this->shootingEntity instanceof Entity ? $this->shootingEntity->getId() : -1);
        $this->setDataProperty(self::DATA_TARGET_EID, self::DATA_TYPE_LONG, 0);
    }

    public function setRod(Item $rod){
        $this->rod = $rod;
        $this->checkLure();
    }

    public function checkLure(){
        if ($this->rod !== null) {
            $lure = $this->rod->getEnchantment(Enchantment::TYPE_FISHING_LURE);
            if ($lure !== null) {
                $this->waitChance = 120 - (25 * $lure->getLevel());
                if ($this->waitChance < 20) $this->waitChance = 20;
            }
        }
    }

	/**
	 * @param $currentTick
	 *
	 * @return bool
	 */
	public function onUpdate($currentTick){
		if($this->closed){
			return false;
		}

		$this->timings->startTiming();

        if ($this->target !== 0) {
            $entity = $this->level->getEntity($this->target);
            if ($entity === null || !$entity->isAlive()) {
                $this->caughtEntity = null;
                $this->setTarget(0);
            } else {
                $this->setPosition(new Vector3($entity->x, $entity->y + $entity->height, $entity->z));
                $this->updateMovement();
                return false;
            }
        }

        $hasUpdate = parent::onUpdate($currentTick);

        $inWater = $this->isInsideOfWater();

        if ($inWater) {
            $this->motionX = 0;
            $this->motionY -= $this->gravity * -0.04;
            $this->motionZ = 0;
            $hasUpdate = true;

            if ($this->waitTimer == 240) {
                $this->waitTimer = $this->waitChance << 1;
            } elseif ($this->waitTimer == 360) {
                $this->waitTimer = $this->waitChance * 3;
            }

            if (!$this->attracted) {
                if ($this->waitTimer > 0) {
                    --$this->waitTimer;
                }
                if ($this->waitTimer == 0) {
                    if (mt_rand(0, 99) < 90) {
                        $this->attractTimer = mt_rand(20, 59);
                        $this->spawnFish();
                        $this->caught = false;
                        $this->attracted = true;
                    } else {
                        $this->waitTimer = $this->waitChance;
                    }
                }
            } elseif (!$this->caught) {
                if ($this->attractFish()) {
                    $this->caughtTimer = mt_rand(30, 49);
                    $this->fishBites();
                    $this->caught = true;
                }
            } else {
                if ($this->caughtTimer > 0) {
                    --$this->caughtTimer;
                }
                if ($this->caughtTimer == 0) {
                    $this->attracted = false;
                    $this->caught = false;
                    $this->waitTimer = $this->waitChance * 3;
                }
            }
        } elseif ($this->isCollided && $this->keepMovement) {
            $this->motionX = 0;
            $this->motionY = 0;
            $this->motionZ = 0;
            $this->keepMovement = false;
            $hasUpdate = true;
        }
        $this->timings->stopTiming();
        return $hasUpdate;
    }

	protected function updateMotion() {
        if ($this->isInsideOfWater()) {
            $waterHeight = $this->getWaterHeight();
            if ($this->y < $waterHeight - 2) {
                $this->motionX = 0;
                $this->motionY += $this->gravity;
                $this->motionZ = 0;
            } elseif ($this->y >= $waterHeight - 2) {
                $this->motionX = 0;
                $this->motionY = 0;
                $this->motionZ = 0;
            }
        }
    }

    public function getWaterHeight() : int{
        $x = (int) floor($this->x);
        $z = (int) floor($this->z);
        for ($y = (int) floor($this->y); $y < 256; $y++) {
            $block = $this->level->getBlock(new Vector3($x, $y, $z));
            if ($block->getId() === 0) {
                return $y;
            }
        }
        return (int) floor($this->y);
    }

    public function spawnFish(){
        $this->fish = new Vector3(
            $this->x + (mt_rand(0, 100) / 100 * 1.2 + 1) * (mt_rand(0, 1) ? -1 : 1),
            $this->getWaterHeight(),
            $this->z + (mt_rand(0, 100) / 100 * 1.2 + 1) * (mt_rand(0, 1) ? -1 : 1)
        );
    }

    public function attractFish(){
        $multiply = 0.1;
        $this->fish->setComponents(
            $this->fish->x + ($this->x - $this->fish->x) * $multiply,
            $this->fish->y,
            $this->fish->z + ($this->z - $this->fish->z) * $multiply
        );

        if (mt_rand(0, 99) < 85) {
            $this->level->addParticle(new WaterParticle($this->fish));
        }

        $dist = abs(sqrt($this->x * $this->x + $this->z * $this->z) - sqrt($this->fish->x * $this->fish->x + $this->fish->z * $this->fish->z));
        return $dist < 0.15;
    }

    public function fishBites(){
        $pk = new EntityEventPacket();
        $pk->eid = $this->getId();
        $pk->event = EntityEventPacket::FISH_HOOK_HOOK;
        $this->server->broadcastPacket($this->shootingEntity->hasSpawned, $pk);

        $pk = new EntityEventPacket();
        $pk->eid = $this->getId();
        $pk->event = EntityEventPacket::FISH_HOOK_BUBBLE;
        $this->server->broadcastPacket($this->shootingEntity->hasSpawned, $pk);

        $pk = new EntityEventPacket();
        $pk->eid = $this->getId();
        $pk->event = EntityEventPacket::FISH_HOOK_TEASE;
        $this->server->broadcastPacket($this->shootingEntity->hasSpawned, $pk);

        for ($i = 0; $i < 5; $i++) {
            $this->level->addParticle(new BubbleParticle(
                new Vector3(
                    $this->x + (mt_rand(0, 100) / 100) * 0.5 - 0.25,
                    $this->getWaterHeight(),
                    $this->z + (mt_rand(0, 100) / 100) * 0.5 - 0.25
                )
            ));
        }
    }

    public function reelLine(){
        $player = $this->shootingEntity;
        if (!($player instanceof Player)) {
            $this->close();
            return;
        }

        if ($this->caught) {
            $item = $this->getFishingResult();
            $experience = mt_rand(1, 3);
            $pos = new Vector3($this->x, $this->getWaterHeight(), $this->z);
            $motion = $player->subtract($pos)->multiply(0.2);
            $motion->y += sqrt($player->add(0, $player->getEyeHeight(), 0)->distance($pos)) * 0.08;
            /*            
            $event = new ProjectileHitEvent($this, null, null, null);
            $this->server->getPluginManager()->callEvent($event);
            if ($event->isCancelled()) {
                $this->close();
                return;
            }*/

            $itemEntity = $this->level->dropItem($pos, $item, $motion);
            if ($itemEntity !== null) {
                $itemEntity->setPickupDelay(1);
                $itemEntity->setOwner($player->getName());
                $itemEntity->spawnToAll();
            }

            $this->level->spawnXPOrb($player, $experience);
        } elseif ($this->caughtEntity !== null) {
            $motion = $player->subtract($this)->multiply(0.1);
            $motion->y += sqrt($player->distance($this)) * 0.08;
            $this->caughtEntity->setMotion($motion);
        }

        $this->close();
    }

    private function getFishingResult() : Item {
        $rand = mt_rand(1, 100);
        if ($rand <= 85) { # 鱼
            $fishTypes = [Item::RAW_FISH, Item::RAW_SALMON, Item::CLOWN_FISH, Item::PUFFER_FISH];
            return Item::get($fishTypes[array_rand($fishTypes)], 0, 1);
        } elseif ($rand <= 95) { # 宝藏
            $treasures = [Item::BOW, Item::ENCHANTED_BOOK, Item::NAMETAG, Item::SADDLE];
            $item = Item::get($treasures[array_rand($treasures)]);
            if ($item->getId() === Item::BOW) {
                $item->addEnchantment(Enchantment::getEnchantment(Enchantment::TYPE_BOW_POWER)->setLevel(mt_rand(1, 3)));
            }
            if ($item->getId() === Item::ENCHANTED_BOOK) { # 随机附魔书
                $item = Item::get(Item::BOOK);
                $ench = Enchantment::getEnchantment(mt_rand(0, 24));
                if ($ench !== null) {
                    $item->addEnchantment($ench->setLevel(mt_rand(1, 5)));
                }
            }
            return $item;
        } else { # 垃圾
            $junks = [Item::LILY_PAD, Item::BOWL, Item::LEATHER_BOOTS, Item::ROTTEN_FLESH, Item::STRING, Item::BONE];
            return Item::get($junks[array_rand($junks)], 0, 1);
        }
    }

    public function setTarget(int $eid){
        $this->target = $eid;
        $this->setDataProperty(self::DATA_TARGET_EID, self::DATA_TYPE_LONG, $eid);
        $this->setDataFlag(self::DATA_FLAGS, self::DATA_FLAG_NO_AI, true);
        $this->canCollide = ($eid === 0);
    }

    public function getTarget(){
        return $this->target;
    }

    protected function onCollideWithEntity(Entity $entityHit){
        if ($entityHit === $this->shootingEntity) {
            return;
        }

        $damage = ceil(sqrt($this->motionX ** 2 + $this->motionY ** 2 + $this->motionZ ** 2) * $this->damage);

        $ev = new EntityDamageByChildEntityEvent($this->shootingEntity, $this, $entityHit, EntityDamageEvent::CAUSE_PROJECTILE, $damage);
        $entityHit->attack($ev->getFinalDamage(), $ev);
        $this->hadCollision = true;
        $this->caughtEntity = $entityHit;
        $this->setTarget($entityHit->getId());
    }

	/**
	 * @param Player $player
	 */
    public function spawnTo(Player $player){
        $pk = new AddEntityPacket();
        $pk->eid = $this->getId();
        $pk->type = FishingHook::NETWORK_ID;
        $pk->x = $this->x;
        $pk->y = $this->y;
        $pk->z = $this->z;
        $pk->speedX = $this->motionX;
        $pk->speedY = $this->motionY;
        $pk->speedZ = $this->motionZ;
        $pk->yaw = $this->yaw;
        $pk->pitch = $this->pitch;

        $pk->metadata = $this->dataProperties;
        $pk->metadata[self::DATA_OWNER_EID] = [self::DATA_TYPE_LONG, $this->shootingEntity instanceof Entity ? $this->shootingEntity->getId() : -1];
        $pk->metadata[self::DATA_TARGET_EID] = [self::DATA_TYPE_LONG, $this->target];

        $player->dataPacket($pk);
        parent::spawnTo($player);
    }
}