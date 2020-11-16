<?php

namespace mcpepc\pocketmusic;

use pocketmine\item\ItemFactory;
use pocketmine\Player;

class DeadPlayerHandler {
	protected $owner;
	protected $killer;

	protected $inventory;

	protected $retrievable = [];
	protected $gaveBack = false;

	function __construct(KillToSteal $plugin, Player $player, ?Player $lastDamager) {
		$this->owner = strtolower($player->getName());
		$this->killer = strtolower($lastDamager->getName());
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
					$retrievable[$f][1] -= $stolen[$s][1];
				}
			}
		}

		$this->retrievable = VariableParser::itemSetMapToItems($retrievable);

		$slotEmpty = $plugin->getConfig()->get('empty-slot-item');
		$slotEmpty = ItemFactory::get($slotEmpty['id'], $slotEmpty['meta'], $slotEmpty['count']);

		foreach ($plugin->getInventoryConfig()->get('inventory') as $index => $name) {
			$this->inventory->setItem($index, count($variables[$name]) ? array_shift($variables[$name]) : $slotEmpty);
		}
		// 만들던 거를 수정하시면 구조 변경에 한계가 발생하게 됩니다
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
