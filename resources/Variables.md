# Variables
This document explains the structure of variables used in inventory
## Magic variables
 * `:item:id` refers an item by its id  (e. g. `item:1` refers a stone)
 * `:item:id:meta` refers an item by its id and its meta (e. g. `item:1:0` refers a stone)
 * `:item:id:meta:count` refers an item by its id and its meta and its count(`1`) or refers items by their id and their meta and their count(greater than `1`) (e. g. `item:0:0:1` refers a stone)
## Declaring variables
 * Every variable is an unordered collection of items except air
 * Start the declaration of a variable by referring other variable: `"foo": "storage"` (Now `foo` equals to `storage`)
 * Code is evaluated per space, left to right
 * Add(merge) two variables using `+`: `"foo": "storage +hotbar +armor"` (Now `foo` equals with `any`)
 * Remove the items of original variable using `-`: `"foo": "storage +hotbar +armor -holding"` (Now `foo` equals with `any -holding`)
 * If the number of the items that should be removed equals or is greater than the number of the items that the original variable has, the items will be gone from the variable and the variable won't have further effects
 * Using whitelist:itemid1,itemid2,... does whitelist
## Using variables
 * Refer an item of the variable by its name: `foo` will refer a random item of the variable `foo` and remove the referred item
 * Use ! after the name of the variable to keep the item: `foo!` will refer a random item of the variable `foo` and will not remove the referred item
## Reserved variables
 * any
 * offhand
 * armor
 * helmet
 * chestplate
 * leggings
 * boots
 * storage
 * hotbar
 * holding
