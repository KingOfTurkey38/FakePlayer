<?php

declare(strict_types=1);

namespace muqsit\fakeplayer;

use muqsit\fakeplayer\behaviour\FakePlayerBehaviour;
use muqsit\fakeplayer\network\FakePlayerNetworkSession;
use muqsit\fakeplayer\network\listener\ClosureFakePlayerPacketListener;
use pocketmine\entity\Entity;
use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\SetActorMotionPacket;
use pocketmine\player\Player;
use ReflectionMethod;
use ReflectionProperty;

final class FakePlayer{

	private FakePlayerNetworkSession $session;
	private Player $player;
	private Vector3 $motion;

	/** @var FakePlayerBehaviour[] */
	private array $behaviours = [];

	/** @var array<string, mixed> */
	private array $metadata = [];

	public function __construct(FakePlayerNetworkSession $session){
		$this->session = $session;
		$this->player = $session->getPlayer();
		$this->init();
	}

	public function getPlayer() : Player{
		return $this->player;
	}

	public function getPlayerNullable() : ?Player{
		return $this->player;
	}

	private function init() : void{
		/** @noinspection PhpUnhandledExceptionInspection */
		$rp = new ReflectionProperty($this->player, "drag");
		$rp->setAccessible(true);
		$rp->setValue($this->player, $rp->getValue($this->player) * 8);

		$this->player->keepMovement = false;
		$this->motion = new Vector3(0.0, 0.0, 0.0);
		$this->session->registerSpecificPacketListener(SetActorMotionPacket::class, new ClosureFakePlayerPacketListener(function(ClientboundPacket $packet, NetworkSession $session) : void{
			/** @var SetActorMotionPacket $packet */
			if($packet->actorRuntimeId === $this->player->getId()){
				$this->motion = $packet->motion->asVector3();
			}
		}));
	}

	public function destroy() : void{
		foreach($this->behaviours as $behaviour){
			$this->removeBehaviour($behaviour);
		}

		$this->metadata = [];
	}

	public function getNetworkSession() : FakePlayerNetworkSession{
		return $this->session;
	}

	public function addBehaviour(FakePlayerBehaviour $behaviour) : void{
		if(!isset($this->behaviours[$id = spl_object_id($behaviour)])){
			$this->behaviours[$id] = $behaviour;
			$behaviour->onAddToPlayer($this);
		}
	}

	/**
	 * @return FakePlayerBehaviour[]
	 */
	public function getBehaviours() : array{
		return $this->behaviours;
	}

	public function removeBehaviour(FakePlayerBehaviour $behaviour) : void{
		if(isset($this->behaviours[$id = spl_object_id($behaviour)])){
			$behaviour = $this->behaviours[$id];
			unset($this->behaviours[$id]);
			$behaviour->onRemoveFromPlayer($this);
		}
	}

	public function tick() : void{
		$this->doMovementUpdates();
	}

	private function doMovementUpdates() : void{
		$this->setPlayerMotion();
		$this->tryChangeMovement();
		foreach($this->behaviours as $behaviour){
			$behaviour->tick($this);
		}
		$this->syncPlayerMotion();

		if($this->player->hasMovementUpdate()){
			if(abs($this->motion->x) <= Entity::MOTION_THRESHOLD){
				$this->motion->x = 0;
			}

			if(abs($this->motion->y) <= Entity::MOTION_THRESHOLD){
				$this->motion->y = 0;
			}

			if(abs($this->motion->z) <= Entity::MOTION_THRESHOLD){
				$this->motion->z = 0;
			}

			if($this->motion->x != 0 or $this->motion->y != 0 or $this->motion->z != 0){
				$location = $this->player->getLocation();

				$this->move($this->motion->x, $this->motion->y, $this->motion->z);

				$new_location = $this->player->getLocation();
				$this->setPlayerLocation($location);
				$this->player->handleMovement($new_location);
			}
			$this->syncPlayerMotion();
		}
	}

	private function getPlayerMotion() : Vector3{
		/** @noinspection PhpUnhandledExceptionInspection */
		$rp = new ReflectionProperty($this->player, "motion");
		$rp->setAccessible(true);
		return $rp->getValue($this->player);
	}

	private function setPlayerMotion() : void{
		/** @noinspection PhpUnhandledExceptionInspection */
		$rp = new ReflectionProperty($this->player, "motion");
		$rp->setAccessible(true);
		$rp->setValue($this->player, $this->motion->asVector3());
	}

	private function syncPlayerMotion() : void{
		$this->motion = $this->getPlayerMotion()->asVector3();
	}

	private function tryChangeMovement() : void{
		static $reflection_method = null;
		if($reflection_method === null){
			$reflection_method = new ReflectionMethod(Human::class, "tryChangeMovement");
			$reflection_method->setAccessible(true);
		}
		$reflection_method->getClosure($this->player)();
	}

	private function setPlayerLocation(Location $location) : void{
		/** @noinspection PhpUnhandledExceptionInspection */
		$rp = new ReflectionProperty($this->player, "location");
		$rp->setAccessible(true);
		$rp->setValue($this->player, $location->asLocation());
	}

	private function move(float $dx, float $dy, float $dz) : void{
		static $reflection_method = null;
		if($reflection_method === null){
			$reflection_method = new ReflectionMethod(Human::class, "move");
			$reflection_method->setAccessible(true);
		}
		$reflection_method->getClosure($this->player)($dx, $dy, $dz);
	}

	public function getMetadata(string $key, mixed $default = null) : mixed{
		return $this->metadata[$key] ?? $default;
	}

	public function setMetadata(string $key, mixed $value) : void{
		$this->metadata[$key] = $value;
	}

	public function deleteMetadata(string $key) : void{
		unset($this->metadata[$key]);
	}
}
