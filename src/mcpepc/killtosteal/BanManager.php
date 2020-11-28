<?php

namespace mcpepc\killtosteal;

use pocketmine\Player;
use pocketmine\utils\Config;
use function is_int;
use function strtolower;
use function time;

class BanManager {
	/** @var Config */
	protected $banlist;

	function __construct(Config $banlist) {
		$this->banlist = $banlist;
	}

	function __destruct() {
		$this->banlist->save();
	}

	function ban(Player $player, ?int $until): bool {
		if ($until === null) {
			$until = true;
		} else if (time() > $until || $this->getBanData($player) > $until) {
			return false;
		}
	}

	function unban(Player $player): void {
		$this->banlist->remove(strtolower($player->getName()));
	}

	function isBanned(Player $player): bool {
		return $this->getBanData($player) === null ? false : true;
	}

	function getBanData(Player $player): ?int {
		$data = $this->banlist->get(strtolower($player->getName()), null);

		if ($data === null || $data === false || (is_int($data) && time() > $data)) {
			$this->unban($player);
			return null;
		}

		return $data;
	}
}
