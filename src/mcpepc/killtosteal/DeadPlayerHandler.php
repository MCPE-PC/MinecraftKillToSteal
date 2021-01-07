<?php

namespace mcpepc\killtosteal;

use muqsit\invmenu\InvMenu;
use muqsit\invmenu\transaction\DeterministicInvMenuTransaction;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\math\Vector3;
use pocketmine\Player;
use function array_map;
use function count;
use function shuffle;

class DeadPlayerHandler {
	private $closed = false;

	/** @var KillToSteal */
	private $plugin;

	/** @var string */
	protected $owner;

	/** @var string|null */
	protected $killer;

	/** @var InvMenu */
	protected $menu;

	/** @var int[] */
	protected $capturedCounts;

	/** @var Item[] */
	protected $retrieveQueue = [];

	function __construct(KillToSteal $plugin, Player $player, ?Player $lastDamager) {
		$this->plugin = $plugin;

		$this->owner = $player->getLowerCaseName();

		if ($lastDamager !== null) {
			$this->killer = $lastDamager->getLowerCaseName();
		}

		$menuConfig = $plugin->getInventoryConfig()->get('inventory');
		$menu = $this->menu = InvMenu::create($menuConfig['type']);
		$menu->setName(str_replace('@player@', $this->owner, $menuConfig['name']));
		$menu->setListener(function ($transaction) use ($plugin) {
			return $plugin->handleTransaction($transaction);
		});

		$variables = $plugin->getVariableParser()->applyToPlayer($player);
		$stealableVariableNames = $plugin->getInventoryConfig()->get('stealable');
		$retrievable = VariableParser::itemsToItemSetMap($player->getInventory()->getContents());
		$stolen = [];

		foreach (array_intersect_key($variables, array_flip($stealableVariableNames)) as $stolenItems) {
			$stolen = array_merge($stolen, $stolenItems);
		}

		$stolen = VariableParser::itemsToItemSetMap($stolen);

		for ($i = 0; $i < count($stolen); $i++) {
			for ($j = 0; $j < count($retrievable); $j++) {
				if ($stolen[$i][0]->equals($retrievable[$j][0])) {
					$retrievable[$j][1] -= $stolen[$i][1];
				}
			}
		}

		$this->retrieveQueue = VariableParser::itemSetMapToItems($retrievable);

		$contents = [];
		$counts = [];

		$slotEmpty = $plugin->getConfig()->get('empty-slot-item');
		$slotEmpty = ItemFactory::get($slotEmpty['id'], $slotEmpty['meta'], $slotEmpty['count']);

		foreach ($menuConfig['contents'] as $name) {
			$item = $slotEmpty;
			$count = false;

			if (isset($variables[$name]) && count($variables[$name])) {
				$item = array_shift($variables[$name]);

				if (in_array($name, $stealableVariableNames, true)) {
					$count = $item->getCount();
				}
			} else if (preg_match(VariableParser::MAGIC_NAME_REGEX, $name)) {
				$item = VariableParser::parseMagicVariable($name, $variables['any'])[0];

				if (in_array($name, $stealableVariableNames, true)) {
					$count = $item->getCount();
				}
			}

			$contents[] = $item;
			$counts[] = $count;
		}

		$menu->getInventory()->setContents($contents);
		$this->capturedCounts = $counts;
	}

	function giveItemBack(): bool {
		$player = $this->plugin->getServer()->getPlayerExact($this->owner);
		if ($player === null) {
			return false;
		}

		$this->retrieveTo($player->getInventory());
		return true;
	}

	function retrieveTo(Inventory $inventory): void {
		shuffle($this->retrieveQueue);
		$this->retrieveQueue = $inventory->addItem(...$this->retrieveQueue);
	}

	function prepareClose(): void {
		$this->menu->getInventory()->removeAllViewers();
		$this->menu->setListener(InvMenu::readonly(function (DeterministicInvMenuTransaction $transaction): void {
			$player = $transaction->getPlayer();

			$player->sendMessage('Access to the closed menu blocked');
			$this->plugin->getLogger()->info('Tried to transact with closed menu by ' . $player->getName());
		}));

		$stealableVariableNames = $this->plugin->getInventoryConfig()->get('stealable');
		foreach ($this->plugin->getInventoryConfig()->get('inventory.contents') as $slot => $variable) {
			if (in_array($variable, $stealableVariableNames, true) && !($item = $this->menu->getInventory()->getItem($slot))->isNull()) {
				$this->retrieveQueue[] = $item;
			}
		}

		$this->giveItemBack();
		$this->closed = true;
	}

	function isClosed(): bool {
		return $this->closed && count($this->retrieveQueue) === 0;
	}

	function getPlugin(): KillToSteal {
		return $this->plugin;
	}

	function getLowerCasePlayerName(): string {
		return $this->owner;
	}

	function getLowerCaseKillerName(): ?string {
		return $this->killer;
	}

	function getMenu(): InvMenu {
		return $this->menu;
	}

	function getCapturedCounts(): array {
		return $this->capturedCounts;
	}
}
