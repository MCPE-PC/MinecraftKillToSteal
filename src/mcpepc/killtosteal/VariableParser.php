<?php

namespace mcpepc\killtosteal;

use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\ItemFactory;
use pocketmine\Player;
use function array_filter;
use function array_merge;
use function array_search;
use function array_slice;
use function array_values;
use function array_walk;
use function count;
use function is_int;
use function is_string;
use function mt_rand;
use function preg_match;
use function preg_match_all;
use function shuffle;
use function strpos;
use function substr;

class VariableParser {
	const RESERVED_NAMES = ['any', 'offhand', 'armor', 'helmet', 'chestplate', 'leggings', 'boots', 'storage', 'hotbar', 'holding'];

	const MAGIC_NAME_REGEX = '/^:(item:-?[0-9]+(:[0-9]+)?)$/';
	const NAME_REGEX = '/^[a-z0-9]+$/i';

	protected $variables;

	protected $lengthLimits = [];

	function __construct(array $variables, array $lengthLimits = []) {
		$this->variables = $this->parse($variables);
		$this->lengthLimits = $lengthLimits;
	}

	protected function parse(array $variables): array {
		$definedNames = [];
		$definedVariables = [];
		$failures = [];
		$parsed = [];
		$referees = [];

		foreach ($variables as $name => $syntax) {
			if (is_string($name) && is_string($syntax) && !self::isIntegerString($name) && !in_array($name, self::RESERVED_NAMES, true) && preg_match(self::NAME_REGEX, $name) && self::checkSyntax($syntax)) {
				$definedNames[] = $name;
				$definedVariables[$name] = $syntax;
			} else {
				$failures[] = $name;
			}
		}

		$index = $definedNames;

		foreach ($definedVariables as $name => $syntax) {
			preg_match_all('/(^([a-z0-9]+)( |$)|\+([a-z0-9]+)( |$)|-([a-z0-9]+)( |$))/i', $syntax, $matches);
			foreach (($referees[$name] = array_filter(array_merge($matches[2], $matches[4], $matches[6]))) as $referee) {
				if (in_array($referee, $definedNames, true)) {
					if (($offset = array_search($referee, $index, true)) > ($to = array_search($name, $index, true))) {
						for (; $offset > $to; $offset--) {
							$index[$offset] = $index[$offset - 1];
						}
						$index[$to] = $referee;
					}
				} else if (!in_array($referee, self::RESERVED_NAMES, true)) {
					$failures[] = $name;
				}
			}
		}

		foreach ($index as $name) {
			if (!array_search($name, $failures, true)) {
				$parsed[$name] = $definedVariables[$name];
			}
		}

		return $parsed;
	}

	function applyToPlayer(Player $player): array {
		$armorInventory = $player->getArmorInventory();
		$inventory = $player->getInventory();

		$armorContents = $armorInventory->getContents();
		$contents = $inventory->getContents();
		$hotbarSize = $inventory->getHotbarSize();
		$offhandContents = $player->getCursorInventory()->getContents();

		$result = [
			'any' => array_merge(array_values($contents), $armorContents, $offhandContents),
			'offhand' => $offhandContents,
			'armor' => array_merge($armorContents, $offhandContents),
			'helmet' => [],
			'chestplate' => [],
			'leggings' => [],
			'boots' => [],
			'storage' => self::sliceNumericKeyAssociatedArray($contents, $hotbarSize, $inventory->getSize()),
			'hotbar' => self::sliceNumericKeyAssociatedArray($contents, 0, $hotbarSize - 1),
			'holding' => []
		];

		if (isset($armorContents[ArmorInventory::SLOT_HEAD])) {
			$result['helmet'][] = $armorContents[ArmorInventory::SLOT_HEAD];
		}
		if (isset($armorContents[ArmorInventory::SLOT_CHEST])) {
			$result['chestplate'][] = $armorContents[ArmorInventory::SLOT_CHEST];
		}
		if (isset($armorContents[ArmorInventory::SLOT_LEGS])) {
			$result['leggings'][] = $armorContents[ArmorInventory::SLOT_LEGS];
		}
		if (isset($armorContents[ArmorInventory::SLOT_FEET])) {
			$result['boots'][] = $armorContents[ArmorInventory::SLOT_FEET];
		}
		if (isset($contents[$inventory->getHeldItemIndex()])) {
			$result['holding'][] = $contents[$inventory->getHeldItemIndex()];
		}

		foreach ($this->variables as $name => $syntax) {
			$syntax = explode(' ', $syntax);
			$variable = array_shift($syntax);
			$variable = preg_match(self::NAME_REGEX, $variable) ? $result[$variable] : self::parseMagicVariable($variable, $result['any']);

			foreach ($syntax as $expression) {
				if (strpos($expression, 'whitelist:') === 0) {
					$newVariable = [];
					$filter = explode(',', explode(':', $expression)[1]);

					foreach ($variable as $item) {
						if (in_array((string) $item->getId(), $filter, true)) {
							$newVariable[] = $item;
						}
					}

					$variable = $newVariable;
				} else if (strpos($expression, '+') === 0) {
					$variable = array_merge($variable, $result[substr($expression, 1)]);
				} else if (strpos($expression, '-') === 0) {
					$subtract = self::itemsToItemSetMap($result[substr($expression, 1)]);
					$from = self::itemsToItemSetMap($variable);

					for ($s = 0; $s < count($subtract); $s++) {
						for ($f = 0; $f < count($from); $f++) {
							if ($subtract[$s][0]->equals($from[$f][0])) {
								$from[$f][1] -= $subtract[$s][1];
							}
						}
					}

					$variable = self::itemSetMapToItems($from);
				}
			}

			shuffle($variable);
			$result[$name] = array_slice($variable, 0, $this->lengthLimits[$name] ?? null);
		}

		array_walk($result, function (array &$items) {
			$items = self::itemSetMapToItems(self::itemsToItemSetMap($items));
			shuffle($items);
		});

		return $result;
	}

	static function checkSyntax(string $syntax): bool {
		$syntax = explode(' ', $syntax);
		$names = [array_shift($syntax)];

		foreach ($syntax as $expression) {
			if (strpos($expression, 'whitelist:') === 0 && count($itemIds = explode(':', $expression)) === 2) {
				$itemIds = explode(',', $itemIds[1]);

				foreach ($itemIds as $itemId) {
					if (!preg_match('/^-?[0-9]+$/', $itemId)) {
						return false;
					}
				}

				continue;
			}

			if (strpos($expression, '+') === false && strpos($expression, '-') === false) {
				return false;
			}

			$names[] = substr($expression, 1);
		}

		foreach ($names as $name) {
			if (!preg_match(self::NAME_REGEX, $name) && !preg_match(self::MAGIC_NAME_REGEX, $name)) {
				return false;
			}
		}

		return true;
	}

	static function itemsToItemSetMap(array $items): array {
		$map = [];

		foreach ($items as $item) {
			$found = false;

			for ($i = 0; $i < count($map); $i++) {
				if ($item->equals($map[$i][0])) {
					$found = true;
					$map[$i][1] += $item->count;
				}
			}

			if (!$found) {
				$map[] = [$item, $item->count];
			}
		}

		return $map;
	}

	static function itemSetMapToItems(array $map): array {
		$items = [];

		foreach ($map as $itemSet) {
			$item = $itemSet[0];
			$count = $itemSet[1];

			$maxStackSize = $item->getMaxStackSize();
			$item->count = $maxStackSize;

			for (; $count >= $maxStackSize; $count -= $maxStackSize) {
				$items[] = clone $item;
			}

			if ($count > 0) {
				$item = clone $item;
				$item->count = $count;
				$items[] = $item;
			}
		}

		return $items;
	}

	static function parseMagicVariable(string $magic, array $anyContents): array {
		$magic = explode(':', $magic);

		if ($magic[1] === 'item' && $magic[2] !== 0) {
			return [ItemFactory::get((int) $magic[2], (int) ($magic[3] ?? 0))];
		}

		return [];
	}

	static function sliceNumericKeyAssociatedArray(array $array, int $ge, int $le, bool $keepKey = false): array {
		$sliced = [];

		foreach ($array as $key => $value) {
			if (is_int($key) && $key >= $ge && $key <= $le) {
				if ($keepKey) {
					$sliced[$key] = $value;
				} else {
					$sliced[] = $value;
				}
			}
		}

		return $sliced;
	}

	function isIntegerString(string $string): bool {
		return $string === ((string) ((int) $string));
	}
}
