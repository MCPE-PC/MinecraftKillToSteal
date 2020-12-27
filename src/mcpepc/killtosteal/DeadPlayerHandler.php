<?php

namespace mcpepc\killtosteal;

use muqsit\invmenu\InvMenu;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\math\Vector3;
use pocketmine\Player;
use function array_map;

class DeadPlayerHandler {
	/** @var string */
	protected $owner;

	/** @var string|null */
	protected $killer;

	/** @var InvMenu */
	protected $menu;

	/** @var int[] */
	protected $capturedCounts;

	/** @var Item[] */
	protected $retrievable = [];

	function __construct(KillToSteal $plugin, Player $player, ?Player $lastDamager) {
		$this->owner = strtolower($player->getName());

		if ($lastDamager !== null) {
			$this->killer = strtolower($lastDamager->getName());
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

		$this->retrievable = VariableParser::itemSetMapToItems($retrievable);

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

	function giveItemBack(Player $player): bool {
		if (strtolower($player->getName()) === $this->owner) {
			$player->getInventory()->addItem(...$this->retrievable);
			$this->retrievable = [];
			return true;
		}

		return false;
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
