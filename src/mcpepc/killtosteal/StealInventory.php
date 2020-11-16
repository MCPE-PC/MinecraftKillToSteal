<?php

namespace mcpepc\pocketmusic;

use pocketmine\inventory\BaseInventory;

class StealInventory extends BaseInventory {
	function getName(): string {
		return 'Robbery';
	}

	function getDefaultSize(): int {
		return 54;
	}
}
