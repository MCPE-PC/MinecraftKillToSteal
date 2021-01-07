<?php

namespace mcpepc\killtosteal;

use muqsit\invmenu\InvMenuHandler;
use muqsit\invmenu\transaction\InvMenuTransaction;
use muqsit\invmenu\transaction\InvMenuTransactionResult;
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
use function in_array;
use function spl_object_hash;
use function uasort;

class KillToSteal extends PluginBase implements Listener {
	/** @var string[] */
	private $clickIds = [];

	/** @var DeadPlayerHandler[] */
	private $handlers = [];

	/** @var array[] */
	private $stealData = [];

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

		InvMenuHandler::register($this);

		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	function onPreLogin(PlayerPreLoginEvent $event): void {
		if ($this->banManager->isBanned($event->getPlayer())) {
			$event->setKickMessage($this->getConfig()->getNested('on-death.ban.reason'));
			$event->setCancelled();
		}
	}

	function onJoin(PlayerJoinEvent $event): void {
		if (($handler = $this->getHandler($event->getPlayer())) !== null) {
			$handler->giveItemBack();
		}
	}

	/** @priority LOW */
	function onDamageByEntity(EntityDamageByEntityEvent $event): void {
		$entity = $event->getEntity();
		$eid = $entity->getId();
		$damager = $event->getDamager();

		if (isset($this->clickIds[$eid]) && ($handler = $this->getHandler($this->clickIds[$eid])) !== null && $damager instanceof Player && $entity instanceof Human) {
			$event->setCancelled();
			$handler->getMenu()->send($damager);
		}
	}

	/**
	 * @priority HIGHEST
	 * @ignoreCancelled
	 */
	function onDeath(PlayerDeathEvent $event): void {
		$player = $event->getPlayer();

		$deathCause = $player->getLastDamageCause();
		$lastDamager = null;
		if ($deathCause instanceof EntityDamageByEntityEvent && $deathCause->getDamager() instanceof Player) {
			$lastDamager = $deathCause->getDamager();
		}

		$handler = new DeadPlayerHandler($this, $player, $lastDamager);
		$this->handlers[$handler->getLowerCasePlayerName()] = $handler;
		$this->clickIds[Entity::$entityCount - 1] = $handler->getLowerCasePlayerName();
		$this->stealData[spl_object_hash($handler->getMenu()->getInventory())] = ['' => $handler->getLowerCasePlayerName()];

		$onDeath = $this->getConfig()->get('on-death');
		uasort($onDeath, function ($a, $b): int {
			return $a['index'] === $b['index'] ? 0 : (($a['index'] < $b['index']) ? -1 : 1);
		});

		foreach ($onDeath as $what => $how) {
			if (isset($how['enable']) && !$how['enable']) {
				continue;
			}

			if ($what === 'ban') {
				$until = $how['time'] ?? null;

				if (is_int($until) || $until >= 0) {
					$until += time();
				} else {
					$until = null;
				}

				$this->banManager->ban($player, $until);
			}

			if ($what === 'transfer') {
				$this->transferQueue[$player->getLowerCaseName()] = [$how['ip'], $how['port']];
			}
		}

		$event->setDrops([]);
		$event->setKeepInventory(false);
	}

	function onRespawn(PlayerRespawnEvent $event): void {
		$player = $event->getPlayer();

		if (isset($this->transferQueue[$player->getLowerCaseName()])) {
			$player->transfer(...$this->transferQueue[$player->getLowerCaseName()]);
			return;
		}

		if (($handler = $this->getHandler($event->getPlayer()->getLowerCaseName())) !== null) {
			$handler->giveItemBack();
		}
	}

	function handleTransaction(InvMenuTransaction $transaction): InvMenuTransactionResult {
		$continue = false;

		$action = $transaction->getAction();
		$playerName = $transaction->getPlayer()->getLowerCaseName();

		$stealData = &$this->stealData[spl_object_hash($action->getInventory())];
		$handler = $this->handlers[$stealData['']];
		$capturedCount = $handler->getCapturedCounts()[$action->getSlot()];
		if ($capturedCount !== false) {
			if ($handler->getLowerCaseKillerName() === $playerName) {
				$continue = true;
			} else if ($capturedCount * 0.5 >= ($stealData[$playerName] = $stealData[$playerName] ?? 0)
				+ ($takeCount = $action->getSourceItem()->getCount() - ($action->getSourceItem()->equals($action->getTargetItem()) ? $action->getTargetItem()->getCount() : 0))) {
				$stealData[$playerName] += $takeCount;
				$continue = true;
			}
		}

		return $continue ? $transaction->continue() : $transaction->discard();
	}

	function getHandler($player): ?DeadPlayerHandler {
		if ($player instanceof Player) {
			$player = $player->getLowerCaseName();
		}

		return $this->handlers[$player] ?? null;
	}

	function cleanupHandler(): int {
		$count = 0;

		foreach ($this->handlers as $playerName => $handler) {
			if ($handler->isClosed()) {
				unset($this->handlers[$playerName]);
				unset($this->stealData[spl_object_hash($handler->getMenu()->getInventory())]);

				$count += 1;
			}
		}

		return $count;
	}

	function getInventoryConfig(): Config {
		return $this->inventoryConfig;
	}

	function getVariableParser(): VariableParser {
		return $this->variableParser;
	}
}
