<?php

namespace mcpepc\killtosteal;

use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\Player;

class DeadPlayerHandler {
	/** @var string */
	protected $owner;

	/** @var string|null */
	protected $killer;

	/** @var StealInventory */
	protected $inventory;

	/** @var Item[] */
	protected $retrievable = [];

	protected $gaveBack = false;

	function __construct(KillToSteal $plugin, Player $player, ?Player $lastDamager) {
		$this->owner = strtolower($player->getName());

		if ($lastDamager !== null) {
			$this->killer = strtolower($lastDamager->getName());
		}

		$this->inventory = new StealInventory();

		$variables = $plugin->getVariableParser()->applyToPlayer($player);
		$retrievable = VariableParser::itemsToItemSetMap($player->getInventory()->getContents());
		$stolen = [];

		foreach (array_intersect_key($variables, array_flip($plugin->getInventoryConfig()->get('stealable'))) as $stolenItems) {
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

		$slotEmpty = $plugin->getConfig()->get('empty-slot-item');
		$slotEmpty = ItemFactory::get($slotEmpty['id'], $slotEmpty['meta'], $slotEmpty['count']);

		foreach ($plugin->getInventoryConfig()->get('inventory') as $index => $name) {
			$item = $slotEmpty;

			if (isset($variables[$name]) && count($variables[$name])) {
				$item = array_shift($variables[$name]);
			} else if (preg_match(VariableParser::MAGIC_NAME_REGEX, $name)) {
				$item = VariableParser::parseMagicVariable($name, [])[0];
			}

			$this->inventory->setItem($index, $item, false);
		}
	}

	function showInventoryTo(Player $player): int {
		return $player->addWindow($this->inventory);
	}

	function giveItemBack(Player $player, bool $force = false): bool {
		if ($this->gaveBack) {
			return false;
		}

		if ($force || strtolower($player->getName()) === $this->owner) {
			$player->getInventory()->addItem($this->retrievable);
			$this->gaveBack = true;
			return true;
		}

		return false;
	}
}
