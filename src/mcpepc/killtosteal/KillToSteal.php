<?php

namespace mcpepc\killtosteal;

use pocketmine\entity\Entity;
use pocketmine\entity\Human;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use function count;
use function usort;

class KillToSteal extends PluginBase implements Listener {
	/** @var string[] */
	private $clickIds = [];

	/** @var DeadPlayerHandler[] */
	private $handlers = [];

	/** @var array[] */
	private $transferQueue = [];

	/** @var BanManager */
	private $banManager;

	/** @var Config */
	private $inventoryConfig;

	/** @var VariableParser */
	private $variableParser;

	function onLoad(): void {
		$this->saveDefaultConfig();
		$this->saveResource('inventory.json');

		$this->banManager = new BanManager(new Config($this->getDataFolder() . 'banlist.yml'));
		$this->inventoryConfig = new Config($this->getDataFolder() . 'inventory.json');
	}

	function onEnable(): void {
		$this->variableParser = new VariableParser($this->inventoryConfig->get('variables'));

		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	function onDisable(): void {
		$this->banlistConfig->save();
	}

	function onPreLogin(PlayerPreLoginEvent $event): void {
		if ($this->banManager->isBanned($event->getPlayer())) {
			$event->setKickMessage($this->getConfig()->getNested('on-death.ban.reason'));
			$event->setCancelled();
		}
	}

	function onJoin(PlayerJoinEvent $event): void {
		$player = $event->getPlayer();
		$playerName = strtolower($player->getName());

		if (isset($this->handlers[$playerName]) && $this->handlers[$playerName]->giveItemBack($player)) {
			unset($this->handlers[$playerName]);
		}
	}

	/**
	 * @priority LOW
	 */
	function onDamageByEntity(EntityDamageByEntityEvent $event): void {
		$entity = $event->getEntity();
		$eid = $entity->getId();
		$damager = $event->getDamager();

		if (isset($this->clickIds[$eid]) && $damager instanceof Player && isset($this->handlers[$this->clickIds[$eid]]) && $entity instanceof Human) {
			$event->setCancelled();
			$this->handlers[$this->clickIds[$eid]]->showInventoryTo($damager);
		}
	}

	/**
	 * @priority HIGHEST
	 * @ignoreCancelled
	 */
	function onDeath(PlayerDeathEvent $event): void {
		$player = $event->getPlayer();
		$this->clickIds[Entity::$entityCount] = strtolower($player->getName());

		$deathCause = $player->getLastDamageCause();
		$lastDamager = null;
		if ($deathCause instanceof EntityDamageByEntityEvent && $deathCause->getDamager() instanceof Player) {
			$lastDamager = $deathCause->getDamager();
		}

		$onDeath = $this->getConfig()->get('on-death');
		usort($onDeath, function ($a, $b) {
			return $a['index'] === $b['index'] ? 0 : (($a['index'] < $b['index']) ? -1 : 1);
		});

		foreach ($onDeath as $what => $how) {
			if (isset($how['enable']) && !$how['enable']) {
				continue;
			}

			if ($what === 'ban') {
				$until = $how['time'] ?? null;

				if (!is_int($until) || $until < 0) {
					$until = null;
				} else {
					$until += time();
				}

				$this->banManager->ban($player, $until);
			}

			if ($what === 'transfer') {
				$this->transferQueue[strtolower($player->getName())] = [$how['ip'], $how['port']]; // TODO: 밴 1시간, 직접 구현  및 리스폰시로 이동
			}
		}

		$this->handlers[strtolower($player->getName())] = new DeadPlayerHandler($this, $player, $lastDamager);
		$event->setDrops([]);
		$event->setKeepInventory(false);
	}

	function onRespawn(PlayerRespawnEvent $event): void {
		$player = $event->getPlayer();

		if (isset($this->transferQueue[strtolower($player->getName())])) {
			$transferData = $this->transferQueue[strtolower($player->getName())];
			$player->transfer($transferData[0], $transferData[1]);
		}
	}

	function getInventoryConfig(): Config {
		return $this->inventoryConfig;
	}

	function getVariableParser(): VariableParser {
		return $this->variableParser;
	}
}
