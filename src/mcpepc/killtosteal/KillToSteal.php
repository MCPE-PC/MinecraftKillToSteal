<?php

namespace mcpepc\killtosteal;

use muqsit\invmenu\InvMenuHandler;
use muqsit\invmenu\InvMenuTransaction;
use muqsit\invmenu\InvMenuTransactionResult;
use pocketmine\entity\Entity;
use pocketmine\entity\Human;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\inventory\ChestInventory;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\event\inventory\InventoryTransactionEvent;
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
		$player = $event->getPlayer();
		$playerName = strtolower($player->getName());

		if (isset($this->handlers[$playerName]) && $this->handlers[$playerName]->giveItemBack($player)) {
			unset($this->handlers[$playerName]);
		}
	}

	/** @priority LOW */
	function onDamageByEntity(EntityDamageByEntityEvent $event): void {
		$entity = $event->getEntity();
		$eid = $entity->getId();
		$damager = $event->getDamager();

		if (isset($this->handlers[$this->clickIds[$eid] ?? '']) && $damager instanceof Player && $entity instanceof Human) {
			$event->setCancelled();
			$this->handlers[$this->clickIds[$eid]]->getMenu()->send($damager);
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

		$handler = new DeadPlayerHandler($this, $player, $player->getLevelNonNull()->getEntity(Entity::$entityCount - 1), $lastDamager);
		$this->handlers[$handler->getPlayerName()] = $handler;
		$this->clickIds[Entity::$entityCount - 1] = $handler->getPlayerName();
		$this->stealData[spl_object_hash($handler->getMenu()->getInventory())] = ['' => $handler->getPlayerName()];

		$onDeath = $this->getConfig()->get('on-death');
		uasort($onDeath, function ($a, $b): bool {
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
				$this->transferQueue[strtolower($player->getName())] = [$how['ip'], $how['port']];
			}
		}

		$event->setDrops([]);
		$event->setKeepInventory(false);
	}

	function onRespawn(PlayerRespawnEvent $event): void {
		$player = $event->getPlayer();

		if (isset($this->transferQueue[strtolower($player->getName())])) {
			$player->transfer(...$this->transferQueue[strtolower($player->getName())]);
		}
	}

	function handleTransaction(InvMenuTransaction $transaction): InvMenuTransactionResult {
		$action = $transaction->getAction();

		$stealData = $this->stealData[spl_object_hash($action->getInventory())];
		$handler = $this->handlers[$stealData['']];
		$takeCount = $action->getSourceItem()->getCount() - ($action->getSourceItem()->equals($action->getTargetItem()) ? $action->getTargetItem()->getCount() : 0);
		$transaction->getPlayer()->sendMessage('[InvMenu] 개수차 ' . $takeCount . ' ||슬롯 ' . $action->getSlot() . ' ||인벤토리 ' . $action->getInventory()->getName());

		return $transaction->discard();
	}

	function getInventoryConfig(): Config {
		return $this->inventoryConfig;
	}

	function getVariableParser(): VariableParser {
		return $this->variableParser;
	}
}
